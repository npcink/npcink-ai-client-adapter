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
# "<!-- ocr-<run>-<attempt>-<id> -->" and maintains ONE rolling summary
# comment per PR (edited each round) carrying "<!-- ocr-summary -->"
# plus, for finding rounds, an "ocr-summary-run:<run>-<attempt>" tag
# stating "found **N** issue(s)"; nothing-reviewable rounds say
# "Review skipped", and rounds where a selected item failed internally
# say "Review partially complete" while the run still concludes
# success. The gate reconciles the rolling summary against the parsed
# markers so a format drift fails closed; re-validate this contract
# whenever the action pin is bumped.
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
			case "$2" in
				*$'\n'*) fail '--no-review-because must be a single line' ;;
			esac
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
case "${head_sha}" in
	????????????????????????????????????????) ;;
	*) fail '--head-sha must be a 40-character commit sha' ;;
esac
# The runs API matches head_sha case-sensitively against the lowercase
# commit sha, so normalize instead of timing out on an uppercase input.
head_sha="$(printf '%s' "${head_sha}" | tr '[:upper:]' '[:lower:]')"

command -v gh >/dev/null 2>&1 || fail 'GitHub CLI (gh) is required'
command -v jq >/dev/null 2>&1 || fail 'jq is required'

repo_root="$(git rev-parse --show-toplevel 2>/dev/null)" || fail 'run inside a Git worktree'
cd "${repo_root}"

github_repo="$(gh repo view --json nameWithOwner --jq .nameWithOwner)" \
	|| fail 'could not resolve the repository from the gh context'

run_poll_seconds=20
run_poll_max_iterations=60
discovery_poll_seconds=15
discovery_poll_max=6

# Latest pull_request_target run of the review workflow for this head.
# Only used for discovery; afterwards the run id is pinned so a second
# run for the same head (re-push, re-open) cannot silently switch the
# run under verification. A transient API error surfaces as an empty
# result, which the polling loops treat as "not delivered yet" and
# eventually fail closed.
latest_review_run() {
	# The runs endpoint returns {"total_count":N,"workflow_runs":[...]}
	# per page (an object, unlike the bare-array comment endpoints).
	gh api --paginate "repos/${github_repo}/actions/runs?head_sha=${head_sha}&per_page=100" \
	| jq -s '
		[ .[] | .workflow_runs[]
			| select(.path == ".github/workflows/ocr-review.yml")
			| select(.event == "pull_request_target")
		] | max_by(.run_number) | select(. != null) | {id: .id}
	' || true
}

run_json=''
run_field() {
	printf '%s' "${run_json}" | jq -r --arg field "$1" '.[$field]'
}

# Refresh run_json from the pinned run id.
run_state() {
	run_json="$(gh api "repos/${github_repo}/actions/runs/${run_id}" \
		--jq '{status: .status, conclusion: .conclusion, attempt: .run_attempt, created_at: .created_at}' || true)"
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

# Build the body with one exception line inserted under the existing
# AI Review Exceptions header (never at the body end, which could land
# the bullet after later sections). The line goes in through ENVIRON:
# awk -v would process backslash escapes in the free-text reason.
exception_body_from() {
	local body="$1"
	# Idempotent for the bullet itself: an identical exception line means
	# the body already records this exception.
	if grep -Fq -- "${exception_line}" <<< "${body}"; then
		printf '%s\n' "${body}"
		return 0
	fi
	if grep -Eq '^[[:space:]]*##[[:space:]]+AI Review Exceptions[[:space:]]*$' <<< "${body}"; then
		printf '%s\n' "${body}" | EXCEPTION_LINE="${exception_line}" awk '
			BEGIN { line = ENVIRON["EXCEPTION_LINE"] }
			!inserted && /^[[:space:]]*##[[:space:]]+AI Review Exceptions[[:space:]]*$/ { print; print line; inserted = 1; next }
			{ print }
		'
	else
		printf '%s\n\n## AI Review Exceptions\n\n%s\n' "${body}" "${exception_line}"
	fi
}

undelivered_exit() {
	local cause="$1"
	if [ -n "${review_exception}" ]; then
		local stamp current new_body verify_body
		stamp="$(date -u +%Y-%m-%d)"
		exception_line="- ${stamp} — merged without a delivered OpenCodeReview round (${cause}): ${review_exception}"
		# Fetched immediately before the edit; the write is then verified
		# and retried once on a fresh body, so a concurrent edit landing
		# inside the one-round-trip window is recovered instead of lost.
		# Idempotent: a repeated --no-review-because run appends under the
		# existing exceptions section instead of stacking headers.
		current="$(gh pr view "${pr_number}" --json body --jq '.body // ""')"
		new_body="$(exception_body_from "${current}")"
		if ! printf '%s' "${new_body}" | gh pr edit "${pr_number}" --body-file - >/dev/null 2>&1; then
			fail "could not write the exception line to PR #${pr_number}; the exception is NOT recorded and publishing must stop"
		fi
		verify_body="$(gh pr view "${pr_number}" --json body --jq '.body // ""')"
		if ! grep -Fq -- "${exception_line}" <<< "${verify_body}"; then
			echo '[ai-review-gate] the exception line did not land (concurrent body edit); retrying once' >&2
			current="$(gh pr view "${pr_number}" --json body --jq '.body // ""')"
			if ! grep -Fq -- "${exception_line}" <<< "${current}"; then
				new_body="$(exception_body_from "${current}")"
				if ! printf '%s' "${new_body}" | gh pr edit "${pr_number}" --body-file - >/dev/null 2>&1; then
					fail "could not write the retried exception line to PR #${pr_number}; the exception is NOT recorded and publishing must stop"
				fi
				verify_body="$(gh pr view "${pr_number}" --json body --jq '.body // ""')"
				grep -Fq -- "${exception_line}" <<< "${verify_body}" \
					|| fail 'the exception line did not land after retry; the exception is NOT recorded and publishing must stop'
			fi
		fi
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
for discovery_attempt in $(seq 1 "${discovery_poll_max}"); do
	discovery_json="$(latest_review_run)"
	if [ -n "${discovery_json}" ]; then
		run_id="$(printf '%s' "${discovery_json}" | jq -r '.id')"
		break
	fi
	sleep "${discovery_poll_seconds}"
done
[ -n "${run_id}" ] || undelivered_exit 'no OpenCodeReview run registered for this head'

run_state || undelivered_exit 'the OpenCodeReview run state is unreadable'
run_created="$(run_field created_at)"

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
	if ! rerun_output="$(gh run rerun "${run_id}" --failed 2>&1)"; then
		echo "[ai-review-gate] the re-run request itself failed: ${rerun_output}" >&2
		undelivered_exit 'the re-run request failed'
	fi
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

# Delivery-contract reconciliation against the action's single rolling
# summary comment (one per PR, edited each round - freshness is judged
# by updated_at, not created_at). Observed shapes from the pinned action
# (v1.12.10, commit 579b931):
#   "... found **N** issue(s) ..."                    -> expect N
#   "Review complete: N finding(s) across K item(s)"  -> expect N
#   "Review skipped: no items were selected"          -> expect 0
#   "Review partially complete: ..."                  -> fail closed: a
#     selected item failed its review and was never examined, which does
#     not satisfy the standard's delivered-round rule
# Anything else, or a body that predates the run, fails closed as
# unverifiable; a format drift must never read as "no findings".
if ! summary_record="$(
	gh api --paginate "repos/${github_repo}/issues/${pr_number}/comments?per_page=100" \
	| jq -rs '[ .[][] | select(.user.login == "github-actions[bot]") | select(.body | contains("<!-- ocr-summary -->")) ]
		| last | if . == null then empty else (.updated_at + "\t" + .body) end'
)"; then
	fail 'could not fetch pull request comments for the summary reconciliation'
fi
summary_updated="${summary_record%%$'\t'*}"
summary_body="${summary_record#*$'\t'}"
if [ -z "${summary_body}" ]; then
	fail "no OpenCodeReview summary comment for run ${run_id} attempt ${attempt}; delivery contract unverifiable"
fi
if [[ "${summary_updated}" < "${run_created}" ]]; then
	fail "the summary comment body predates run ${run_id}; no summary posted for this run - failing closed"
fi
if grep -qF 'Review partially complete' <<< "${summary_body}"; then
	fail "round ${run_id} is partially complete (a selected item failed its review); re-run composer pr:publish for a full round or record an exception with --no-review-because"
fi
shape_counts="$(
	printf '%s\n' "${summary_body}" \
	| grep -oE 'found \*\*[0-9]+\*\*|Review complete: [0-9]+ finding' \
	| grep -oE '[0-9]+' | sort -u || true
)"
if [ "$(printf '%s\n' "${shape_counts}" | grep -c . || true)" -gt 1 ]; then
	fail "ambiguous OpenCodeReview summary for run ${run_id} attempt ${attempt} (multiple differing counts); failing closed"
fi
if [ -n "${shape_counts}" ]; then
	expected_findings="${shape_counts}"
elif grep -qF 'Review skipped' <<< "${summary_body}"; then
	expected_findings=0
else
	fail "could not parse the OpenCodeReview summary for run ${run_id} attempt ${attempt}; failing closed"
fi
case "${expected_findings}" in
	''|*[!0-9]*)
		fail "could not parse the OpenCodeReview summary for run ${run_id} attempt ${attempt}; failing closed"
		;;
esac
# The finding-round summary splits delivery into "Successfully posted
# inline: M" and "Failed to post inline: K" - findings that could not be
# posted inline (typically anchored outside the diff hunks) are embedded
# in the summary body itself under "### `path` (Lx-Ly)" headers. Those
# are keyed for triage as emb:<path>:<line>. Below-count always fails
# closed; above-count means the workflow's in-run retries left extra
# real findings (triage them all - stricter, never fewer than the
# summary promised).
posted_counts="$(printf '%s\n' "${summary_body}" | grep -oE 'Successfully posted inline: [0-9]+ comments?' | grep -oE '[0-9]+' | sort -u || true)"
failed_counts="$(printf '%s\n' "${summary_body}" | grep -oE 'Failed to post inline: [0-9]+ comments?' | grep -oE '[0-9]+' | sort -u || true)"
if [ "$(printf '%s\n' "${posted_counts}" | grep -c . || true)" -gt 1 ] || [ "$(printf '%s\n' "${failed_counts}" | grep -c . || true)" -gt 1 ]; then
	fail "ambiguous OpenCodeReview inline-posting counts for run ${run_id} attempt ${attempt}; failing closed"
fi
posted_inline="$(printf '%s\n' "${posted_counts}" | head -1 || true)"
failed_inline="$(printf '%s\n' "${failed_counts}" | head -1 || true)"
if [ -n "${posted_inline}" ] || [ -n "${failed_inline}" ]; then
	# Either status line selects the strict split; a failed-line without a
	# posted-line means posted=0 rather than a legacy-shape fallback.
	posted_inline="${posted_inline:-0}"
	failed_inline="${failed_inline:-0}"
	if [ "${expected_findings}" -ne $(( posted_inline + failed_inline )) ]; then
		fail "delivery contract mismatch: summary reports ${expected_findings} finding(s), posted ${posted_inline} + failed ${failed_inline} inline; failing closed"
	fi
	if [ "${finding_count}" -lt "${posted_inline}" ]; then
		fail "delivery contract mismatch: summary posted ${posted_inline} inline, marker parser extracted ${finding_count}; failing closed"
	fi
	if [ "${finding_count}" -gt "${posted_inline}" ]; then
		echo "[ai-review-gate] note: ${finding_count} unique inline finding(s) exceed the summary's ${posted_inline} (earlier retry-step round); all will require triage"
	fi
else
	if [ "${finding_count}" -lt "${expected_findings}" ]; then
		fail "delivery contract mismatch: summary reports ${expected_findings} finding(s), marker parser extracted ${finding_count}; failing closed"
	fi
	if [ "${finding_count}" -gt "${expected_findings}" ]; then
		echo "[ai-review-gate] note: ${finding_count} unique finding(s) exceed the summary's ${expected_findings} (earlier retry-step round); all will require triage"
	fi
fi

if [ -n "${failed_inline}" ] && [ "${failed_inline}" -gt 0 ]; then
	embedded="$(printf '%s\n' "${summary_body}" | awk '
		function flush() {
			if (path != "") {
				seen[path ":" line]++
				key = "emb:" path ":" line (seen[path ":" line] > 1 ? "#" seen[path ":" line] : "")
				print key "\t" label "\t" path ":" line
			}
			path = ""
		}
		# Embedded blocks render as: badge line, then the "### `path` (Lx-Ly)"
		# header, then the description. A buffered badge only belongs to a
		# block when the header follows it directly; blank lines and
		# badge-like images inside a description are discarded. Repeated
		# path+line findings gain #N suffixes in summary-body order, and the
		# pending list printed by the gate is the authoritative key spelling.
		/^### .*\(L[0-9]+-L[0-9]+\)$/ {
			flush()
			path = $0
			sub(/^### [^`]*`/, "", path)
			sub(/`.*$/, "", path)
			gsub(/\t/, " ", path)
			if (match($0, /\(L[0-9]+-/)) { line = "L" substr($0, RSTART + 2, RLENGTH - 3) }
			label = pending_badge != "" ? pending_badge : "finding"
			pending_badge = ""
			next
		}
		# A badge is a whole-line image whose header follows; a trailing
		# description screenshot also lands here but is replaced by the
		# next real badge or invalidated by the next content line.
		/^!\[[^]]*\]\([^)]*\)[[:space:]]*$/ {
			pending_badge = substr($0, 3, index($0, "]") - 3)
			next
		}
		{ pending_badge = "" }
		END { flush() }
	')"
	embedded_count="$(printf '%s\n' "${embedded}" | grep -c . || true)"
	# Below-count always fails closed; above-count (earlier retry-step
	# rounds leaving extra embedded blocks) mirrors the inline tolerance:
	# triage them all.
	if [ "${embedded_count}" -lt "${failed_inline}" ]; then
		fail "embedded-findings parse mismatch: summary reports ${failed_inline} failed inline, parser extracted ${embedded_count}; failing closed"
	fi
	if [ "${embedded_count}" -gt "${failed_inline}" ]; then
		echo "[ai-review-gate] note: ${embedded_count} embedded finding(s) exceed the summary's ${failed_inline} (earlier retry-step round); all will require triage"
	fi
	findings="$(printf '%s\n%s\n' "${findings}" "${embedded}")"
	finding_count=$(( finding_count + embedded_count ))
fi

if [ "${finding_count}" -eq 0 ]; then
	echo '[ai-review-gate] delivered review raised no findings; triage not required'
	exit 0
fi

echo "[ai-review-gate] ${finding_count} finding(s) from run ${run_id} attempt ${attempt}; verifying triage lines"

if ! pr_body="$(gh pr view "${pr_number}" --json body --jq '.body // ""')"; then
	fail 'could not fetch the pull request body for triage verification'
fi
# Only the "## AI Review Triage" section counts; a - <id> fix:/accept:
# shaped line elsewhere in the body (quoted example, code block) must
# not satisfy the gate.
triage_slice="$(printf '%s\n' "${pr_body}" | awk '/^## AI Review Triage[[:space:]]*$/ { in_section = 1; next } /^## / { in_section = 0 } in_section { print }')"

pending=''
pending_count=0
while IFS=$'\t' read -r finding_id finding_label finding_location; do
	[ -n "${finding_id}" ] || continue
	# Defense against producer drift: marker ids are hex by contract and
	# embedded-summary keys are emb:<path>:<line>; the escape step below is
	# what makes either shape regex-safe before interpolation.
	case "${finding_id}" in
		emb:*) ;;
		*[!0-9a-f]*|'') fail "finding id '${finding_id}' is not hex; producer format may have drifted" ;;
	esac
	# Anchored line shape ("- <id> fix:" / "- [x] <id> accept:"); finding
	# ids are hex-only, so the id itself is regex-safe. An unanchored
	# match could count an id mentioned anywhere in the body as triaged.
	# awk gsub is portable across BSD and GNU userlands (sed bracket-class
	# parsing is not); the class escapes every ERE metacharacter including
	# the backslash in one pass.
	escaped_id="$(printf '%s' "${finding_id}" | awk '{ gsub(/[][^$()*+?{}.|\\]/, "\\&"); print }')"
	if grep -Eq "^[[:space:]]*[-*][[:space:]]*(\[[ xX]\][[:space:]]*)?${escaped_id}[[:space:]]+(fix|accept):" <<< "${triage_slice}"; then
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
