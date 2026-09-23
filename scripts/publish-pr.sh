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

retry_network git push -u origin "${branch}"
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
