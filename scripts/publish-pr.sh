#!/usr/bin/env bash

set -euo pipefail

usage() {
	cat <<'EOF'
Usage:
  scripts/publish-pr.sh --title TITLE --body-file PATH [--base BRANCH] [--dry-run]

Publishes the current clean topic branch, creates a pull request from a
completed body contract, and requests squash auto-merge after required checks.
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
	grep -Eiq "^#{1,6}[[:space:]]+.*${required_heading}" "${body_path}" \
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
	quote_command gh pr merge '<created-pr-url>' --auto --squash --match-head-commit "${head_sha}"
	echo '[pr-publish] dry-run passed'
	exit 0
fi

existing_pr="$(
	gh pr list \
		--state open \
		--head "${branch}" \
		--json url \
		--jq '.[0].url // empty'
)"
[ -z "${existing_pr}" ] || fail "an open pull request already exists: ${existing_pr}"

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
pr_url="$(
	retry_network gh pr create \
		--base "${base_branch}" \
		--head "${branch}" \
		--title "${title}" \
		--body-file "${body_path}"
)"

retry_network gh pr merge "${pr_url}" --auto --squash --match-head-commit "${head_sha}"

echo "[pr-publish] pull_request=${pr_url}"
echo '[pr-publish] auto_merge=squash_requested'
