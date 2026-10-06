#!/usr/bin/env bash

# Advisory AI review delivery + triage gate (AI Code Review Standard v1,
# npcink-workflow-toolbox docs/platform/ai-code-review-standard-v1.md).
#
# Mechanizes the standard's delivery-confirmation and triage rules at the
# publisher: squash auto-merge must not be requested until the
# OpenCodeReview workflow has delivered a review for this exact head SHA,
# and every inline finding from that delivered run has a triage line in
# the pull request body's "AI Review Triage" section:
#
#   - <finding-id> fix: <what changed>
#   - <finding-id> accept: <one-line reason>
#
# Comment-triggered review rounds cannot be correlated to a head SHA
# through the runs API (their head is the default branch), so this gate
# drives retries by re-running the failed pull_request_target run itself,
# which keeps the run id and the head association.
#
# Exit codes:
#   0  review delivered and triaged, or exception recorded via
#      --no-review-because
#   2  review delivered but findings are pending triage; auto-merge
#      must wait
#   1  no delivered review (timeout or repeated failure) without an
#      exception, or a fatal error

set -euo pipefail

usage() {
	cat <<'EOF'
Usage:
  scripts/verify-ai-review.sh --pr N --head-sha SHA [--no-review-because REASON]

Verifies the advisory OpenCodeReview round for one pull request head, then
verifies that every delivered inline finding has a fix:/accept: triage line
in the pull request body's "## AI Review Triage" section.
EOF
}

fail() {
	echo "[ai-review-gate] error: $*" >&2
	exit 1
}

pr_number=''
head_sha=''
review_exception=''

while [ "$#" -gt 0 ]; do
	case "$1" in
		--pr)
			[ "$#" -ge 2 ] || fail '--pr requires a value'
			pr_number="$2"
			shift 2
			;;
		--head-sha)
			[ "$#" -ge 2 ] || fail '--head-sha requires a value'
			head_sha="$2"
			shift 2
			;;
		--no-review-because)
			[ "$#" -ge 2 ] || fail '--no-review-because requires a value'
			review_exception="$2"
			shift 2
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

[ -n "${pr_number}" ] || fail '--pr is required'
[ -n "${head_sha}" ] || fail '--head-sha is required'
case "${pr_number}" in
	''|*[!0-9]*) fail '--pr must be a pull request number' ;;
esac
case "${head_sha}" in
	''|*[!0-9a-fA-F]*) fail '--head-sha must be a commit sha' ;;
esac

command -v gh >/dev/null 2>&1 || fail 'GitHub CLI (gh) is required'
command -v jq >/dev/null 2>&1 || fail 'jq is required'

repo_root="$(git rev-parse --show-toplevel 2>/dev/null)" || fail 'run inside a Git worktree'
cd "${repo_root}"

github_repo="$(python3 - <<'PY'
import re, subprocess
url = subprocess.check_output(["git", "remote", "get-url", "origin"], text=True).strip()
match = re.search(r"[:/]([^/:]+)/([^/]+?)(?:\.git)?$", url)
print(f"{match.group(1)}/{match.group(2)}")
PY
)"

run_poll_seconds=20
run_poll_max=60
run_discovery_max=6

# Latest pull_request_target run of the review workflow for this head.
# A transient API error surfaces as an empty result, which the polling
# loops treat as "not delivered yet" and eventually fail closed.
latest_review_run() {
	gh api "repos/${github_repo}/actions/runs?head_sha=${head_sha}&per_page=100" --jq '
		[.workflow_runs[]
			| select(.path == ".github/workflows/ocr-review.yml")
			| select(.event == "pull_request_target")]
		| max_by(.run_number)
		| select(. != null)
		| {id: .id, status: .status, conclusion: .conclusion, attempt: .run_attempt}
	' || true
}

run_field() {
	printf '%s' "${run_json}" | jq -r --arg field "$1" '.[$field]'
}

wait_for_completion() {
	local waited=0 status
	while [ "${waited}" -lt "${run_poll_max}" ]; do
		run_json="$(latest_review_run)"
		if [ -n "${run_json}" ]; then
			status="$(run_field status)"
			if [ "${status}" = 'completed' ]; then
				return 0
			fi
		fi
		sleep "${run_poll_seconds}"
		waited=$((waited + 1))
	done
	return 1
}

undelivered_exit() {
	local cause="$1"
	if [ -n "${review_exception}" ]; then
		local stamp current new_body
		stamp="$(date -u +%Y-%m-%d)"
		current="$(gh pr view "${pr_number}" --json body --jq .body)"
		new_body="$(printf '%s\n\n## AI Review Exceptions\n\n- %s — merged without a delivered OpenCodeReview round (%s): %s\n' \
			"${current}" "${stamp}" "${cause}" "${review_exception}")"
		printf '%s' "${new_body}" | gh pr edit "${pr_number}" --body-file - >/dev/null
		echo "[ai-review-gate] ${cause}; exception recorded in the PR body, proceeding"
		exit 0
	fi
	echo "[ai-review-gate] ${cause} for PR #${pr_number} head ${head_sha}." >&2
	echo "[ai-review-gate] auto-merge is NOT requested without a delivered review. Options:" >&2
	echo "  - re-run composer pr:publish after provider recovery (this gate re-runs the failed run once itself)," >&2
	echo "  - trigger a fresh round with a '/open-code-review' comment on the pull request, or" >&2
	echo "  - record the exception: composer pr:publish -- --no-review-because \"<reason>\"" >&2
	exit 1
}

echo "[ai-review-gate] waiting for the OpenCodeReview run on PR #${pr_number} head ${head_sha}"

run_json=''
for discovery_attempt in $(seq 1 "${run_discovery_max}"); do
	run_json="$(latest_review_run)"
	[ -n "${run_json}" ] && break
	sleep 15
done
[ -n "${run_json}" ] || undelivered_exit 'no OpenCodeReview run registered for this head'

if ! wait_for_completion; then
	undelivered_exit 'the OpenCodeReview run did not complete in time'
fi

conclusion="$(run_field conclusion)"
if [ "${conclusion}" != 'success' ]; then
	run_id="$(run_field id)"
	echo "[ai-review-gate] review run ${run_id} failed (${conclusion}); re-running its failed jobs once"
	gh run rerun "${run_id}" --failed >/dev/null 2>&1 || true
	rerun_settled=0
	for settle_attempt in 1 2 3 4 5 6; do
		run_json="$(latest_review_run)"
		if [ -n "${run_json}" ] && [ "$(run_field status)" != 'completed' ]; then
			rerun_settled=1
			break
		fi
		sleep 10
	done
	[ "${rerun_settled}" = '1' ] || undelivered_exit 'the re-run did not register in time'
	if ! wait_for_completion; then
		undelivered_exit 'the re-run OpenCodeReview run did not complete in time'
	fi
	conclusion="$(run_field conclusion)"
	[ "${conclusion}" = 'success' ] || undelivered_exit "the re-run OpenCodeReview run still failed (${conclusion})"
fi

run_id="$(run_field id)"
attempt="$(run_field attempt)"
echo "[ai-review-gate] review delivered (run ${run_id}, attempt ${attempt}); collecting inline findings"

# Findings are the inline comments posted by the delivered run's final
# attempt; markers from earlier attempts or older runs are ignored. The
# per_page cap comfortably covers one review round's inline comments.
# A failed comments fetch must fail the gate: an empty result here would
# otherwise read as "no findings" and let an unreviewed diff through.
if ! comments_tsv="$(gh api "repos/${github_repo}/pulls/${pr_number}/comments?per_page=100" --jq '
	[ .[]
		| select(.user.login == "github-actions[bot]")
		| select(.body | startswith("<!-- ocr-"))
		| .body as $body
		| ($body | capture("^<!-- ocr-(?<run>[0-9]+)-(?<att>[0-9]+)-(?<id>[0-9a-f]+) -->")) as $marker
		| [ $marker.id,
		    $marker.run,
		    $marker.att,
		    ($body | (capture("!?\\[(?<label>[^]]*)\\]")? | .label) // "finding"),
		    ((.path // "?") + ":" + ((.line // .original_line // 0) | tostring))
		  ]
	] | sort_by(.[0])[] | @tsv
')"; then
	fail 'could not fetch inline review comments for the pull request'
fi
findings="$(printf '%s\n' "${comments_tsv}" | awk -F'\t' -v run="${run_id}" -v att="${attempt}" '$2 == run && $3 == att { print $1 "\t" $4 "\t" $5 }')"

if [ -z "${findings}" ]; then
	echo '[ai-review-gate] delivered review raised no findings; triage not required'
	exit 0
fi

finding_count="$(printf '%s\n' "${findings}" | grep -c .)"
echo "[ai-review-gate] ${finding_count} finding(s) from run ${run_id} attempt ${attempt}; verifying triage lines"

pr_body="$(gh pr view "${pr_number}" --json body --jq .body)"

pending=''
pending_count=0
while IFS=$'\t' read -r finding_id finding_label finding_location; do
	[ -n "${finding_id}" ] || continue
	if printf '%s\n' "${pr_body}" | grep -E "${finding_id}[[:space:]]*(fix|accept):" >/dev/null 2>&1; then
		continue
	fi
	pending_count=$((pending_count + 1))
	pending="${pending}$(printf '\n  - %s  [%s]  %s' "${finding_id}" "${finding_label}" "${finding_location}")"
done <<EOF
${findings}
EOF

if [ "${pending_count}" -gt 0 ]; then
	echo "[ai-review-gate] ${pending_count} finding(s) pending triage on PR #${pr_number}:${pending}"
	echo
	echo '[ai-review-gate] auto-merge is NOT requested until every finding has one triage line'
	echo '[ai-review-gate] in the pull request body section "## AI Review Triage":'
	echo '    - <finding-id> fix: <what changed>       (then commit/push the fix)'
	echo '    - <finding-id> accept: <one-line reason>'
	echo '[ai-review-gate] after triage (pushed fixes and/or gh pr edit body), re-run composer pr:publish.'
	exit 2
fi

echo "[ai-review-gate] all ${finding_count} finding(s) triaged; auto-merge may proceed"
exit 0
