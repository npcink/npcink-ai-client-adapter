#!/usr/bin/env bash

set -euo pipefail

usage() {
	cat <<'EOF'
Usage:
  scripts/publish-pr.sh --title TITLE --body-file PATH [--base BRANCH]
                        [--no-review-because REASON] [--dry-run]

Publishes the current clean topic branch, creates a pull request from a
completed body contract, waits for the delivered advisory OpenCodeReview
round on the exact head SHA, verifies finding triage, and only then
requests squash auto-merge after required checks.

--no-review-because records why the change publishes without a delivered
AI review (provider outage); the reason is appended to the PR body.
EOF
}

fail() {
	echo "[pr-publish] error: $*" >&2
	exit 1
}

quote_command() {
	printf '[pr-publish] would run:'
	printf ' %q' "$@"
	printf '\n'
}

title=''
body_file=''
base_branch='master'
dry_run=0
review_exception=''
invocation_dir="${PWD}"

while [ "$#" -gt 0 ]; do
	case "$1" in
		--)
			shift
			;;
		--title)
			[ "$#" -ge 2 ] || fail '--title requires a value'
			title="$2"
			shift 2
			;;
		--body-file)
			[ "$#" -ge 2 ] || fail '--body-file requires a value'
			body_file="$2"
			shift 2
			;;
		--base)
			[ "$#" -ge 2 ] || fail '--base requires a value'
			base_branch="$2"
			shift 2
			;;
		--no-review-because)
			[ "$#" -ge 2 ] || fail '--no-review-because requires a value'
			[ -n "$(printf '%s' "$2" | tr -d '[:space:]')" ] || fail '--no-review-because requires a non-whitespace value'
			case "$2" in
				*$'\n'*) fail '--no-review-because must be a single line' ;;
			esac
			review_exception="$2"
			shift 2
			;;
		--dry-run)
			dry_run=1
			shift
			;;
		--help|-h)
			usage
			exit 0
			;;
		*)
			fail "unknown argument: $1"
			;;
	esac
done

[ -n "${title}" ] || fail '--title is required'
[ -n "${body_file}" ] || fail '--body-file is required'

command -v git >/dev/null 2>&1 || fail 'git is required'
command -v gh >/dev/null 2>&1 || fail 'GitHub CLI (gh) is required'

repo_root="$(git rev-parse --show-toplevel 2>/dev/null)" || fail 'run inside a Git worktree'
cd "${repo_root}"

[ -f scripts/verify-ai-review.sh ] \
	|| fail 'AI review gate script scripts/verify-ai-review.sh not found'

# Publishing reaches github.com over the network. When a local VPN exposes an
# HTTP proxy on a moving port, detect the working port instead of failing on
# the direct connection. Explicit git or environment proxy settings always win.
detect_local_proxy() {
	if [ -n "${http_proxy:-}${https_proxy:-}${HTTP_PROXY:-}${HTTPS_PROXY:-}" ] \
		|| [ -n "$(git config --get http.proxy 2>/dev/null)" ]; then
		echo '[pr-publish] proxy: using existing environment or git configuration'
		return 0
	fi

	command -v curl >/dev/null 2>&1 || return 0

	proxy_works() {
		[ -n "${1:-}" ] || return 1
		curl -x "http://127.0.0.1:${1}" -s --max-time 4 -o /dev/null -w '%{http_code}' https://api.github.com 2>/dev/null | grep -q '^2'
	}

	local port candidate
	if [ "$(uname -s)" = 'Darwin' ]; then
		port="$(scutil --proxy 2>/dev/null | awk -F' : ' '/^HTTPEnable/ {e=$2} /^HTTPPort/ {p=$2} /^HTTPSEnable/ {se=$2} /^HTTPSPort/ {sp=$2} END {if (e == "1" && p != "" && p != "0") print p; else if (se == "1" && sp != "" && sp != "0") print sp}')"
		if proxy_works "$port"; then
			http_proxy="http://127.0.0.1:${port}"
			export http_proxy HTTP_PROXY="$http_proxy" https_proxy="$http_proxy" HTTPS_PROXY="$http_proxy"
			echo "[pr-publish] proxy: using macOS system proxy on port ${port}"
			return 0
		fi
	fi

	for candidate in 7890 7897 1087 1080 6152 8888 8118; do
		if proxy_works "$candidate"; then
			http_proxy="http://127.0.0.1:${candidate}"
			export http_proxy HTTP_PROXY="$http_proxy" https_proxy="$http_proxy" HTTPS_PROXY="$http_proxy"
			echo "[pr-publish] proxy: detected local proxy on port ${candidate}"
			return 0
		fi
	done

	echo '[pr-publish] proxy: no local proxy detected; publishing over the direct connection'
}

# Network steps fail transiently on some paths to github.com. Retry with a
# growing pause instead of losing the run; commits stay local either way.
retry_network() {
	local attempt=1
	local delay=45
	local max_attempts=4
	until "$@"; do
		status=$?
		if [ "$attempt" -ge "$max_attempts" ]; then
			fail "network step failed after ${max_attempts} attempts: $*"
		fi
		echo "[pr-publish] network step failed (exit ${status}, attempt ${attempt}/${max_attempts}); retrying in ${delay}s" >&2
		sleep "$delay"
		delay=$((delay * 2))
		attempt=$((attempt + 1))
	done
}

detect_local_proxy

case "${body_file}" in
	/*) body_path="${body_file}" ;;
	*) body_path="${invocation_dir}/${body_file}" ;;
esac

[ -f "${body_path}" ] || fail "body file not found: ${body_path}"

for required_heading in Scope Boundary Verification Risk; do
	grep -Eiq "^#{1,6}[[:space:]]+(.*[^A-Za-z])?${required_heading}([^A-Za-z]|$)" "${body_path}" \
		|| fail "body file is missing the ${required_heading} heading"
done

if [ "${base_branch}" = 'production' ]; then
	grep -Fq 'Approved for production validation by operator.' "${body_path}" \
		|| fail 'production PR body is missing operator approval'
fi

branch="$(git branch --show-current)"
[ -n "${branch}" ] || fail 'detached HEAD is not publishable'
[ "${branch}" != "${base_branch}" ] || fail "refusing to publish the base branch: ${base_branch}"

[ -z "$(git status --porcelain)" ] || fail 'worktree must be clean before publishing'

retry_network git fetch origin "${base_branch}"
git rev-parse --verify "origin/${base_branch}" >/dev/null 2>&1 \
	|| fail "origin/${base_branch} is unavailable"
git merge-base --is-ancestor "origin/${base_branch}" HEAD \
	|| fail "branch must include the latest origin/${base_branch}"

commit_count="$(git rev-list --count "origin/${base_branch}..HEAD")"
[ "${commit_count}" -gt 0 ] || fail "branch has no commits beyond origin/${base_branch}"

head_sha="$(git rev-parse HEAD)"

if [ "${dry_run}" = '1' ]; then
	quote_command git push -u origin "${branch}"
	quote_command gh pr create --base "${base_branch}" --head "${branch}" --title "${title}" --body-file "${body_path}"
	if [ -n "${review_exception}" ]; then
		quote_command bash scripts/verify-ai-review.sh --pr '<pr-number>' --head-sha "${head_sha}" --no-review-because "${review_exception}"
	else
		quote_command bash scripts/verify-ai-review.sh --pr '<pr-number>' --head-sha "${head_sha}"
	fi
	quote_command gh pr merge '<created-pr-url>' --auto --squash --match-head-commit "${head_sha}"
	echo '[pr-publish] dry-run passed'
	exit 0
fi

# An open pull request for this branch is reused, not an error: the review
# triage loop pushes fixes or updates the PR body, then re-runs the
# publisher to re-verify and finally request auto-merge.
existing_pr="$(
	retry_network gh pr list \
		--state open \
		--head "${branch}" \
		--base "${base_branch}" \
		--json url \
		--jq '.[0].url // empty'
)"
if [ -n "${existing_pr}" ]; then
	echo "[pr-publish] reusing open pull request: ${existing_pr}"
	echo '[pr-publish] note: --body-file and --title are not re-applied to an existing pull request; edit the body/title with gh pr edit (e.g. triage lines)'
fi

# Direct git pushes can fail for a long time on some paths to github.com
# while api.github.com stays reachable, and some local proxy nodes answer
# git requests from cache, reporting success without transferring. Verify
# the remote ref after pushing; if it did not land, replay the unpushed
# commits through the Git Data API with their exact author, committer, and
# timestamps so the API-created commits reproduce the local SHAs and the
# head-matched squash merge still applies.
github_repo="$(python3 - <<'PY'
import re, subprocess
url = subprocess.check_output(["git", "remote", "get-url", "origin"], text=True).strip()
match = re.search(r"[:/]([^/:]+)/([^/]+?)(?:\.git)?$", url)
print(f"{match.group(1)}/{match.group(2)}")
PY
)"
push_and_verify() {
	retry_network git push -u origin "${branch}"
	local remote_sha
	remote_sha="$(gh api "repos/${github_repo}/git/refs/heads/${branch}" --jq '.object.sha' 2>/dev/null || true)"
	[ "$remote_sha" = "$head_sha" ] && return 0

	echo '[pr-publish] direct push did not land; falling back to the Git Data API' >&2
	fallback_result="$(python3 - "${github_repo}" "${branch}" <<'PY'
import base64, json, subprocess, sys

repo, branch = sys.argv[1], sys.argv[2]

def gh(path, payload=None, method=None):
	command = ["gh", "api"]
	if method:
		command += ["-X", method]
	command.append(path)
	if payload is not None:
		command += ["--input", "-"]
	completed = subprocess.run(
		command,
		input=None if payload is None else json.dumps(payload).encode(),
		capture_output=True,
	)
	if completed.returncode != 0:
		raise RuntimeError(completed.stderr.decode(errors="replace").strip())
	return json.loads(completed.stdout.decode())

def git(*arguments):
	return subprocess.check_output(["git", *arguments], text=True)

def git_bytes(*arguments):
	return subprocess.check_output(["git", *arguments])

head = git("rev-parse", "HEAD").strip()
remote_sha = gh(f"repos/{repo}/git/refs/heads/{branch}")["object"]["sha"]
if remote_sha == head:
	print("already-synced")
	sys.exit(0)

unpushed = git("rev-list", "--reverse", f"{remote_sha}..{head}").split()
if not unpushed:
	print("diverged")
	sys.exit(3)

parent = remote_sha
for commit in unpushed:
	entries = []
	for line in git("diff-tree", "--no-commit-id", "--name-status", "-r", commit).splitlines():
		status, _, path = line.partition("\t")
		if not path or status.startswith("D"):
			continue
		blob_local = git("rev-parse", f"{commit}:{path}").strip()
		mode = git("ls-tree", commit, "--", path).split()[0]
		if mode not in ("100644", "100755"):
			raise SystemExit(f"unsupported file mode {mode} for {path}")
		try:
			gh(f"repos/{repo}/git/blobs/{blob_local}")
			blob_sha = blob_local
		except RuntimeError:
			raw = git_bytes("cat-file", "blob", blob_local)
			created = gh(
				f"repos/{repo}/git/blobs",
				{"content": base64.b64encode(raw).decode(), "encoding": "base64"},
				method="POST",
			)
			blob_sha = created["sha"]
		entries.append({"path": path, "mode": mode, "type": "blob", "sha": blob_sha})

	tree_base = gh(f"repos/{repo}/commits/{parent}")["commit"]["tree"]["sha"]
	tree = gh(
		f"repos/{repo}/git/trees",
		{"base_tree": tree_base, "tree": entries},
		method="POST",
	)

	def fmt(placeholder):
		return git("log", "-1", f"--format={placeholder}", commit).rstrip("\n")

	created = gh(
		f"repos/{repo}/git/commits",
		{
			"message": fmt("%B"),
			"tree": tree["sha"],
			"parents": [parent],
			"author": {"name": fmt("%an"), "email": fmt("%ae"), "date": fmt("%aI")},
			"committer": {"name": fmt("%cn"), "email": fmt("%ce"), "date": fmt("%cI")},
		},
		method="POST",
	)
	parent = created["sha"]

gh(f"repos/{repo}/git/refs/heads/{branch}", {"sha": parent}, method="PATCH")
final = gh(f"repos/{repo}/git/refs/heads/{branch}")["object"]["sha"]
print(("replayed-identical" if final == head else "sha-mismatch") + " " + final)
PY
	)" || fail 'Git Data API fallback failed'
	case "$fallback_result" in
		replayed-identical\ *)
			echo '[pr-publish] fallback: commits replayed through the Git Data API with matching SHAs'
			;;
		already-synced)
			;;
		sha-mismatch\ *)
			fail "the API replay landed ${fallback_result#sha-mismatch } instead of ${head_sha}; the branch was updated, but reconcile locally (git pull --rebase) before publishing"
			;;
		diverged)
			fail 'local and remote histories diverged; the API fallback cannot fast-forward'
			;;
		*)
			fail "unexpected fallback result: ${fallback_result}"
			;;
	esac
	remote_sha="$(gh api "repos/${github_repo}/git/refs/heads/${branch}" --jq '.object.sha' 2>/dev/null || true)"
	[ "$remote_sha" = "$head_sha" ] || fail 'the fallback could not update the remote branch'
}

push_and_verify

# Validate the reused PR only after the push: the triage loop commits
# locally and re-runs the publisher, and this push is what reconciles
# the PR head - checking earlier would abort a recoverable state.
if [ -n "${existing_pr}" ]; then
	existing_head_base="$(
		retry_network gh pr view "${existing_pr}" --json headRefOid,baseRefName \
			--jq '.headRefOid + " " + .baseRefName'
	)"
	[ -n "${existing_head_base}" ] || fail "could not read head/base of existing pull request ${existing_pr}"
	existing_head="${existing_head_base%% *}"
	existing_base="${existing_head_base##* }"
	[ "${existing_head}" = "${head_sha}" ] || {
		# The PR record can lag the ref update; re-read once before failing.
		sleep 10
		existing_head_base="$(
			retry_network gh pr view "${existing_pr}" --json headRefOid,baseRefName \
				--jq '.headRefOid + " " + .baseRefName'
		)"
		[ -n "${existing_head_base}" ] || fail "could not read head/base of existing pull request ${existing_pr}"
		existing_head="${existing_head_base%% *}"
		existing_base="${existing_head_base##* }"
	}
	[ "${existing_head}" = "${head_sha}" ] \
		|| fail "open pull request head ${existing_head} does not match this branch head ${head_sha}"
	[ "${existing_base}" = "${base_branch}" ] \
		|| fail "open pull request base ${existing_base} does not match --base ${base_branch}"
fi

if [ -z "${existing_pr}" ]; then
	# A lost response (proxy drop after GitHub accepted the create) makes
	# every retry fail with "already exists", so treat that error as a
	# signal to reuse the pull request that was in fact created.
	create_attempt=1
	create_delay=45
	until pr_url="$(gh pr create \
		--base "${base_branch}" \
		--head "${branch}" \
		--title "${title}" \
		--body-file "${body_path}" 2>&1)"; do
		if printf '%s\n' "${pr_url}" | grep -qi 'already exists'; then
			pr_url="$(
				retry_network gh pr list \
					--state open \
					--head "${branch}" \
					--base "${base_branch}" \
					--json url \
					--jq '.[0].url // empty'
			)"
			[ -n "${pr_url}" ] \
				|| fail 'gh pr create reported an existing pull request that could not be found'
			echo '[pr-publish] pull request was created despite the failed response; reusing it'
			existing_pr="${pr_url}"
			break
		fi
		if [ "${create_attempt}" -ge 4 ]; then
			fail "gh pr create failed after ${create_attempt} attempts: ${pr_url}"
		fi
		echo "[pr-publish] pr create failed (attempt ${create_attempt}/4); retrying in ${create_delay}s" >&2
		sleep "${create_delay}"
		create_delay=$((create_delay * 2))
		create_attempt=$((create_attempt + 1))
	done
else
	pr_url="${existing_pr}"
fi
pr_number="${pr_url##*/}"
case "${pr_number}" in
	''|*[!0-9]*) fail "could not parse pull request number from ${pr_url}" ;;
esac

# Disarm runs before the body re-verification: every fail below exits
# immediately, and a run that aborts on a drifted body must not leave a
# stale armed auto-merge live against the untriaged head.
if [ -n "${existing_pr}" ]; then
	disarm_auto_merge
fi

# On reuse, the live pull request body is the artifact that merges, and it
# can drift from the validated local --body-file between runs (triage edits,
# gh pr edit). Re-verify the body contract against the live body: the four
# shared headings, plus the operator approval line for production PRs. The
# checks read the body through a herestring rather than a printf pipe:
# grep -q short-circuits a pipe producer, and under pipefail a body larger
# than the pipe buffer would kill printf with SIGPIPE and fail the check.
if [ -n "${existing_pr}" ]; then
	live_body="$(retry_network gh pr view "${pr_number}" --json body --jq '.body // ""')" \
		|| fail 'could not read the existing pull request body for contract re-verification'
	for required_heading in Scope Boundary Verification Risk; do
		grep -Eiq "^#{1,6}[[:space:]]+(.*[^A-Za-z])?${required_heading}([^A-Za-z]|$)" <<< "${live_body}" \
			|| fail "the live pull request body is missing the ${required_heading} heading; edit the body with gh pr edit"
	done
	if [ "${base_branch}" = 'production' ]; then
		grep -Fq 'Approved for production validation by operator.' <<< "${live_body}" \
			|| fail 'the live production pull request body lost the operator approval line; restore it with gh pr edit'
	fi
fi

# Disarm an auto-merge armed by an earlier publisher run BEFORE the review
# gate waits: GitHub keeps auto-merge enabled across head pushes and
# --match-head-commit is only checked when the merge is requested, so a
# stale armed auto-merge could otherwise merge the newer, untriaged head
# while the gate polls (the AI review workflow is advisory and never a
# required check, so it cannot block that merge). The final auto-merge
# request below re-arms it only after the gate passes. The state read is
# retried like every other gh call; an armed merge that cannot be disabled
# FAILS the run (the warning would scroll away during the gate's wait);
# an unreadable state after retries warns on ordinary bases and fails on
# production, where an unnoticed armed merge is unacceptable.
disarm_auto_merge() {
	local armed out attempt delay
	armed=unknown
	delay=10
	for attempt in 1 2 3 4; do
		# Capture stdout separately: an assignment substitution overwrites
		# the sentinel with the (empty) stdout of a failed read, which
		# would silently take the not-armed branch. Four attempts with a
		# growing delay deliberately run shorter than retry_network (faster local
		# retries; retry_network cannot preserve the sentinel-vs-empty-stdout
		# distinction).
		if out="$(gh pr view "${pr_number}" --json autoMergeRequest --jq 'if .autoMergeRequest == null then "" else "armed" end' 2>/dev/null)"; then
			armed="$out"
			break
		fi
		[ "${attempt}" -eq 4 ] || sleep "${delay}"
		delay=$(( delay * 2 ))
	done
	if [ "${armed}" = 'unknown' ]; then
		if [ "${base_branch}" = 'production' ]; then
			fail 'could not read the auto-merge state on a production pull request; an armed merge cannot be ruled out - resolve connectivity and re-run composer pr:publish'
		fi
		echo '[pr-publish] note: could not read the auto-merge state; proceeding without disabling' >&2
		return 0
	fi
	if [ "${armed}" != 'armed' ]; then
		return 0
	fi
	if gh pr merge "${pr_number}" --disable-auto >/dev/null 2>&1; then
		echo '[pr-publish] disarmed a previously armed auto-merge on the pull request'
		return 0
	fi
	sleep 10
	if gh pr merge "${pr_number}" --disable-auto >/dev/null 2>&1; then
		echo '[pr-publish] disarmed a previously armed auto-merge on the pull request (second attempt)'
		return 0
	fi
	fail "an armed auto-merge could not be disabled; it could merge this head once required checks pass - disable it on the pull request and re-run composer pr:publish"
}

# Advisory AI review gate (AI Code Review Standard v1): no auto-merge is
# requested until OpenCodeReview has delivered a review for this exact
# head SHA and every delivered finding carries a fix:/accept: triage line
# in the PR body. The gate re-runs a failed review run once itself; the
# only way past an undelivered review is --no-review-because, which the
# gate records in the PR body.
# Not wrapped in retry_network: the gate already polls for up to fifty
# minutes per completion wait and re-runs a failed review once itself, so
# an outer retry would multiply the bounded waits.
review_gate_args=( --pr "${pr_number}" --head-sha "${head_sha}" )
if [ -n "${review_exception}" ]; then
	review_gate_args+=( --no-review-because "${review_exception}" )
fi
review_gate_status=0
bash scripts/verify-ai-review.sh "${review_gate_args[@]}" || review_gate_status=$?
case "${review_gate_status}" in
	0)
		;;
	2)
		# Propagate the gate's exit contract: 2 means actionable triage
		# pending, not a fatal failure.
		echo "[pr-publish] error: AI review findings pending triage (gate exit 2); auto-merge NOT requested. Complete the triage guidance above, then re-run composer pr:publish." >&2
		exit 2
		;;
	*)
		fail "AI review gate did not pass (exit ${review_gate_status}); no review was delivered or verification failed closed - re-run composer pr:publish after connectivity/provider recovery (the gate re-runs a failed review itself), or record an exception with --no-review-because."
		;;
esac

retry_network gh pr merge "${pr_url}" --auto --squash --match-head-commit "${head_sha}"

echo "[pr-publish] pull_request=${pr_url}"
echo '[pr-publish] ai_review_gate=passed'
echo '[pr-publish] auto_merge=squash_requested'
