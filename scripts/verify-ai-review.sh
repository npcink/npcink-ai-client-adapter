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
# Producer contract (observed from alibaba/open-code-review v1.12.10,
# commit 579b931, the pin used by .github/workflows/ocr-review.yml): the
# action posts inline findings prefixed with
# "<!-- ocr-<run>-<attempt>-<id> -->", a summary comment carrying
# "<!-- ocr-summary -->" plus, for finding rounds, an
# "ocr-summary-run:<run>-<attempt>" tag stating "found **N** issue(s)",
# or a "Review skipped" line for nothing-reviewable rounds. The gate
# reconciles the summary against the parsed markers so a format drift
# fails closed; re-validate this contract whenever the action pin is
# bumped.
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

github_repo="$(gh repo view --json nameWithOwner --jq .nameWithOwner)" \
	|| fail 'could not resolve the repository from the gh context'

run_poll_seconds=20
run_poll_max_iterations=60
run_discovery_max=6

# Latest pull_request_target run of the review workflow for this head.
# Only used for discovery; afterwards the run id is pinned so a second
# run for the same head (re-push, re-open) cannot silently switch the
# run under verification. A transient API error surfaces as an empty
# result, which the polling loops treat as "not delivered yet" and
# eventually fail closed.
latest_review_run() {
	gh api "repos/${github_repo}/actions/runs?head_sha=${head_sha}&per_page=100" --jq '
		[.workflow_runs[]
			| select(.path == ".github/workflows/ocr-review.yml")
			| select(.event == "pull_request_target")]
		| max_by(.run_number)
		| select(. != null)
		| {id: .id}
	' || true
}

run_json=''
run_field() {
	printf '%s' "${run_json}" | jq -r --arg field "$1" '.[$field]'
}

# Refresh run_json from the pinned run id.
run_state() {
	run_json="$(gh api "repos/${github_repo}/actions/runs/${run_id}" \
		--jq '{status: .status, conclusion: .conclusion, attempt: .run_attempt}' || true)"
	[ -n "${run_json}" ]
}

wait_for_completion() {
	local waited=0
	while [ "${waited}" -lt "${run_poll_max_iterations}" ]; do
		if run_state && [ "$(run_field status)" = 'completed' ]; then
			return 0
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
		# Fetched immediately before the edit; the write window is one API
		# round trip and the gate is the only writer in its own flow, so a
		# full compare-and-swap retry is not warranted. Idempotent: a
		# repeated --no-review-because run appends to the existing
		# exceptions section instead of stacking headers.
		current="$(gh pr view "${pr_number}" --json body --jq '.body // ""')"
		if printf '%s\n' "${current}" | grep -Eq '^[[:space:]]*##[[:space:]]+AI Review Exceptions[[:space:]]*$'; then
			new_body="$(printf '%s\n- %s — merged without a delivered OpenCodeReview round (%s): %s\n' \
				"${current}" "${stamp}" "${cause}" "${review_exception}")"
		else
			new_body="$(printf '%s\n\n## AI Review Exceptions\n\n- %s — merged without a delivered OpenCodeReview round (%s): %s\n' \
				"${current}" "${stamp}" "${cause}" "${review_exception}")"
		fi
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

run_id=''
for discovery_attempt in $(seq 1 "${run_discovery_max}"); do
	discovery_json="$(latest_review_run)"
	if [ -n "${discovery_json}" ]; then
		run_id="$(printf '%s' "${discovery_json}" | jq -r '.id')"
		break
	fi
	sleep 15
done
[ -n "${run_id}" ] || undelivered_exit 'no OpenCodeReview run registered for this head'

if ! wait_for_completion; then
	undelivered_exit 'the OpenCodeReview run did not complete in time'
fi

conclusion="$(run_field conclusion)"
if [ "${conclusion}" != 'success' ]; then
	attempt_before="$(run_field attempt)"
	case "${attempt_before}" in
		''|*[!0-9]*) undelivered_exit 'the review run state is unreadable' ;;
	esac
	echo "[ai-review-gate] review run ${run_id} failed (${conclusion}); re-running its failed jobs once"
	gh run rerun "${run_id}" --failed >/dev/null 2>&1 || true
	# The re-run registers as an attempt bump, but a short job can also
	# finish within one poll while the attempt field still lags - and the
	# pre-rerun conclusion was not success, so a completed+success read
	# here means the re-run landed. Break on any of the three signals.
	rerun_settled=0
	for settle_attempt in 1 2 3 4 5 6; do
		if run_state; then
			settled=0
			if [ "$(run_field attempt)" -gt "${attempt_before}" ] 2>/dev/null; then
				settled=1
			fi
			if [ "$(run_field status)" != 'completed' ]; then
				settled=1
			fi
			if [ "$(run_field status)" = 'completed' ] && [ "$(run_field conclusion)" = 'success' ]; then
				settled=1
			fi
			if [ "${settled}" = '1' ]; then
				rerun_settled=1
				break
			fi
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

attempt="$(run_field attempt)"
echo "[ai-review-gate] review delivered (run ${run_id}, attempt ${attempt}); collecting inline findings"

# Findings are the inline comments posted by the delivered run's final
# attempt; markers from earlier attempts or older runs are ignored. Both
# comment fetches paginate: the workflow's in-run retries can leave
# near-duplicate inline comments, so a single page must not silently
# truncate the round. gh --jq emits raw text, so JSON filtering stays in
# the downstream jq; --paginate concatenates page arrays, which jq -s
# slurps and .[][] flattens. A failed fetch must fail the gate: an empty
# result would otherwise read as "no findings" and let an unreviewed
# diff through (pipefail surfaces the gh failure through the pipeline).
if ! comments_tsv="$(
	gh api --paginate "repos/${github_repo}/pulls/${pr_number}/comments?per_page=100" \
	| jq -r -s '
		[ .[][]
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
	'
)"; then
	fail 'could not fetch inline review comments for the pull request'
fi
findings="$(printf '%s\n' "${comments_tsv}" | awk -F'\t' -v run="${run_id}" -v att="${attempt}" '$2 == run && $3 == att && !seen[$1]++ { print $1 "\t" $4 "\t" $5 }')"
finding_count="$(printf '%s\n' "${findings}" | grep -c . || true)"

# Delivery-contract reconciliation: the action's own summary comment for
# this exact run+attempt states how many findings it posted ("found N
# issue(s)") or that nothing was reviewable ("Review skipped"). If the
# inline-marker parser and the summary disagree - for example after an
# upstream comment-format change - fail closed instead of reading a
# parse miss as "no findings". Skipped rounds post an untagged summary,
# so a run-tagged body wins; otherwise the newest summary must say
# "Review skipped" and the marker extraction must be empty.
if ! summary_body="$(
	gh api --paginate "repos/${github_repo}/issues/${pr_number}/comments?per_page=100" \
	| jq -rs --arg tag "ocr-summary-run:${run_id}-${attempt}" \
		'[ .[][] | select(.user.login == "github-actions[bot]") | select(.body | contains($tag)) | .body ] | last // empty'
)"; then
	fail 'could not fetch pull request comments for the summary reconciliation'
fi
if [ -z "${summary_body}" ]; then
	if ! summary_body="$(
		gh api --paginate "repos/${github_repo}/issues/${pr_number}/comments?per_page=100" \
		| jq -rs --arg tag "<!-- ocr-summary -->" \
			'[ .[][] | select(.user.login == "github-actions[bot]") | select(.body | contains($tag)) | .body ] | last // empty'
	)"; then
		fail 'could not fetch pull request comments for the summary reconciliation'
	fi
	if printf '%s\n' "${summary_body}" | grep -qF 'Review skipped'; then
		expected_findings=0
	else
		fail "no OpenCodeReview summary comment for run ${run_id} attempt ${attempt}; delivery contract unverifiable"
	fi
else
	found_counts="$(printf '%s\n' "${summary_body}" | grep -oE 'found \*\*[0-9]+\*\*' | grep -oE '[0-9]+' | sort -u)"
	if [ "$(printf '%s\n' "${found_counts}" | grep -c . || true)" -gt 1 ]; then
		fail "ambiguous OpenCodeReview summary for run ${run_id} attempt ${attempt} (multiple differing counts); failing closed"
	fi
	expected_findings="${found_counts}"
	case "${expected_findings}" in
		''|*[!0-9]*)
			fail "could not parse the OpenCodeReview summary for run ${run_id} attempt ${attempt}; failing closed"
			;;
	esac
fi
if [ "${expected_findings}" != "${finding_count}" ]; then
	fail "delivery contract mismatch: summary reports ${expected_findings} finding(s), marker parser extracted ${finding_count}; failing closed"
fi

if [ "${finding_count}" -eq 0 ]; then
	echo '[ai-review-gate] delivered review raised no findings; triage not required'
	exit 0
fi

echo "[ai-review-gate] ${finding_count} finding(s) from run ${run_id} attempt ${attempt}; verifying triage lines"

pr_body="$(gh pr view "${pr_number}" --json body --jq '.body // ""')"
# Only the "## AI Review Triage" section counts; a - <id> fix:/accept:
# shaped line elsewhere in the body (quoted example, code block) must
# not satisfy the gate.
triage_slice="$(printf '%s\n' "${pr_body}" | awk '/^## AI Review Triage[[:space:]]*$/ { in_section = 1; next } /^## / { in_section = 0 } in_section { print }')"

pending=''
pending_count=0
while IFS=$'\t' read -r finding_id finding_label finding_location; do
	[ -n "${finding_id}" ] || continue
	# Defense against producer drift: ids are hex by contract, and the
	# id is interpolated into the triage-line regex below.
	case "${finding_id}" in
		*[!0-9a-f]*|'') fail "finding id '${finding_id}' is not hex; producer format may have drifted" ;;
	esac
	# Anchored line shape ("- <id> fix:" / "- [x] <id> accept:"); finding
	# ids are hex-only, so the id itself is regex-safe. An unanchored
	# match could count an id mentioned anywhere in the body as triaged.
	if printf '%s\n' "${triage_slice}" | grep -Eq "^[[:space:]]*[-*][[:space:]]*(\[[ xX]\][[:space:]]*)?${finding_id}[[:space:]]+(fix|accept):" >/dev/null 2>&1; then
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
