# Quality Gates And First Controller Split Closeout - 2026-10-03

Status: closeout record for the repository health pass triggered by the
2026-10-03 systematic review (static analysis, translation coverage,
Controller decomposition start, docs layout). Captures what shipped, the
lessons worth keeping, and the follow-up queue.

## What Shipped

| Milestone | Delivery |
| --- | --- |
| PHPStan level 5 (WordPress stubs) + PHPCS WordPress-Core/PHPCompatibilityWP advisory gates, CI leg on PHP 8.4, real code fixes (yoda flips, `@phpstan-impure` nonce semantics, stale `@param`, reserved-word parameter, short ternary) and phpcbf alignment normalization | PR #56 |
| zh_CN catalog completion (16 new translations, 4 fuzzy corrections, 310 messages) plus `composer check:i18n` release gate: POT/PO coverage, fuzzy rejection, msgctxt-aware keying, `.mo` freshness | PR #57 |
| `Contract_Metadata` extraction: 345 lines of pure contract/policy/posture builders out of `Controller`, byte-identical payloads proven by contract-hash smoke snapshots on a live site | PR #58 |
| Docs layout: `docs/recipes/` (15 guides + boundary index), dated records to `docs/archive/`, all references updated including the packaged CLI | PR #59 |

## Development Lessons

1. **String-pinned tests tax mechanical tooling twice.** phpcbf's
   whitespace-only realignment broke 20+ `tests/run.php` pins that encoded
   exact array/const alignment. Every pin had to be re-derived from the
   reformatted source (automated by a repair loop). The migration rule that
   falls out: public contract strings (error codes, route paths, schema
   versions) may stay pinned; pins on private implementation text or cosmetic
   spacing should convert to behavior assertions as each domain is touched.
2. **Advisory gates caught three latent defects the suite could not.**
   `testVersion` was inert without `phpcompatibility/phpcompatibility-wp`
   (the PHP 8.0 floor was never actually enforced), `mb_substr()` would have
   fataled the i18n guard on hosts without ext-mbstring, and msgctxt
   blindness would have defeated the translation gate the moment `_x()`
   appears. None were visible to `php -l` or the contracts suite.
3. **PHPStan's remembered-return-values needs impurity honesty.** The nonce
   `INSERT IGNORE` retry path was flagged as unreachable because PHPStan
   assumed a pure function returns the same value for the same arguments.
   `@phpstan-impure` with a one-line rationale documents real
   side-effecting semantics instead of suppressing the finding.
4. **Delegators make extraction reviewable and pin-compatible.** Keeping
   one-line delegators in `Controller` preserved every method-name pin while
   bodies moved, and PHPStan's unused-method check then named exactly which
   delegators could die (their callers had moved with the bodies). Contract
   hashes pinned in the suite plus one live smoke snapshot are the right
   equivalence proof for a "payloads unchanged" refactor.

## Follow-up Queue

- Continue the Controller domain splits in this order: signing auth /
  device pairing, proposal flow, execution service, then the dependency
  introspection trio (it reads live REST route state, so it needs a seam
  for `rest_route_available`).
- Promote `composer analyse:php` / `composer lint:standards` from advisory
  (`continue-on-error`) to required once master has a zero-finding streak.
- Slim the root `README.md` (1172 lines) to quickstart plus pointers now
   that `docs/recipes/` and `docs/archive/` exist.
- Revisit the two documented loose array comparisons in
  `validate_implementation_posture_for_execution` under a governed change if
  posture payloads ever become type-stable.
