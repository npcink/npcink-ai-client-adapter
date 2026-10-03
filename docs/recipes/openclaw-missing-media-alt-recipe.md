# OpenClaw Missing Media ALT Recipe

Use this recipe only to fill an image attachment whose current ALT is empty.
It reuses the same Toolkit plan and write abilities as the Toolbox UI.

1. Run `npcink-abilities-toolkit/build-media-alt-apply-plan` through
   `POST /run-read-ability` with the reviewed `attachment_id`, final `alt`,
   `expected_current_alt=""`, `operator_visual_review_confirmed=true`, and
   `review_set_contract=media_alt_caption_review_set.v1`.
2. Submit the returned plan through `POST /proposals/from-plan`.
3. Review and approve the proposal in Core.
4. Execute it through Adapter. Core commit preflight must return a valid
   `media_alt_guard` requiring `adapter_toolkit_dry_run_before_commit`.
5. Adapter runs `update-media-details` once with `dry_run=true` immediately
   before the final commit. Toolkit rejects the operation if the live ALT is no
   longer empty. Adapter commits only after that dry-run succeeds.

The execution response includes `media_alt_live_preflight`. Reusing a completed
proposal is rejected by Adapter idempotency. A drift failure requires rebuilding
the review set and creating a new proposal; it is never retried as an overwrite.

This flow does not permit title, caption, description, source, or existing-ALT
changes. Core owns approval truth, Toolkit owns live attachment validation and
the final WordPress write, and Adapter remains the bounded execution channel.
