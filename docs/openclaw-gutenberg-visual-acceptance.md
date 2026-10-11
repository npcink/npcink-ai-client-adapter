# OpenClaw Gutenberg Visual Acceptance

Status: accepted

This checklist turns governed Gutenberg page and article generation into a
repeatable quality loop without making Adapter a renderer, browser runner, or
workflow runtime.

## Scope

Applies to:

- `openclaw_recipes.pattern_page_plan`
- `openclaw_recipes.article_block_plan`
- `openclaw_recipes.block_theme_site_plan` when the fixture represents a
  rendered Site Editor template such as `front-page`, `single`, or `page`

Adapter exposes the acceptance contract from `GET /help`, forwards the plan to
Core, and executes only approved write actions. Toolkit still owns the rendered
Gutenberg blocks. Browser automation, screenshots, and visual judgment belong to
OpenClaw or the operator environment.

## Smoke Fixture Manifest

The normal WordPress smoke test deletes all fixtures. To create temporary pages
that can be opened in a browser, run smoke with both variables:

```bash
MAA_ADAPTER_VISUAL_ACCEPTANCE_OUT=/tmp/openclaw-gutenberg-visual-acceptance.json \
MAA_ADAPTER_KEEP_VISUAL_ACCEPTANCE_FIXTURES=1 \
composer smoke:wp
```

The JSON manifest includes:

- `front_end_url`
- `block_editor_url`
- `attachment_ids`
- `viewports`
- `manual_checks`
- machine-checked `structure_signals`

Only use retained fixtures in local development. Delete the retained draft posts
and media attachments after the browser pass.

## Automated Browser Runner

For local browser QA, run:

```bash
composer visual:wp
```

The wrapper:

1. runs `composer smoke:wp` with fixture retention enabled;
2. writes the fixture manifest to `build/visual-acceptance/manifest.json`;
3. temporarily publishes retained smoke-only fixtures so anonymous browser
   rendering opens the generated Gutenberg content instead of a 404 page;
4. installs Playwright into `build/visual-acceptance-node`;
5. opens each retained front-end fixture at the required viewports;
6. writes screenshots and `build/visual-acceptance/report.json`;
7. deletes the retained fixture posts and media on exit.

This temporary publish step is local test setup only. The proposal execution
smoke still asserts that the governed create action produces draft content
before the retained browser fixture is prepared.

On macOS the runner defaults to the installed Chrome browser to avoid a slow
first-run Chromium download. Override with
`MAA_ADAPTER_VISUAL_ACCEPTANCE_BROWSER_CHANNEL=chrome` or set
`MAA_ADAPTER_VISUAL_ACCEPTANCE_INSTALL_BROWSER=1` when the local environment
should install Playwright's managed Chromium binary.

To reuse an existing manifest without creating new smoke fixtures:

```bash
MAA_ADAPTER_VISUAL_ACCEPTANCE_SKIP_SMOKE=1 \
MAA_ADAPTER_VISUAL_ACCEPTANCE_OUT=/tmp/openclaw-gutenberg-visual-acceptance.json \
composer visual:wp
```

For block theme template layout acceptance, an operator or OpenClaw harness can
provide a manual manifest instead of smoke-generated post fixtures:

```json
{
  "viewports": [
    { "name": "desktop", "width": 1440, "height": 1000 },
    { "name": "tablet", "width": 768, "height": 1024 },
    { "name": "mobile", "width": 390, "height": 844 }
  ],
  "fixtures": [
    {
      "fixture_type": "block_theme_template",
      "template_slug": "front-page",
      "front_end_url": "https://npcink.local/",
      "required_blocks": ["cta", "latest_posts", "categories"],
      "require_images": false,
      "validate_images": false
    }
  ]
}
```

Run it with:

```bash
MAA_ADAPTER_VISUAL_ACCEPTANCE_SKIP_SMOKE=1 \
MAA_ADAPTER_VISUAL_ACCEPTANCE_OUT=/tmp/openclaw-block-theme-front-page.json \
composer visual:wp
```

Set `WP_ADMIN_USER` and `WP_ADMIN_PASSWORD` only in local development when the
runner should also open `block_editor_url` and check for invalid block recovery
prompts. Without those variables, editor checks are skipped and front-end checks
still run.

Alternatively, set `MAA_ADAPTER_VISUAL_ACCEPTANCE_CREATE_TEMP_ADMIN=1` to let
the wrapper create a temporary local administrator for the editor check. The
temporary user is deleted on exit, and the generated password is not printed.

## Required Viewports

Use the same viewport set for pages and articles:

- desktop: `1440x1000`
- tablet: `768x1024`
- mobile: `390x844`

## Browser Checks

For each retained fixture, verify:

- the front-end URL opens the retained fixture, not a theme 404 page;
- the front-end page has no horizontal overflow;
- the block editor opens without invalid block recovery prompts when local
  admin credentials are provided to the runner;
- core blocks remain individually editable;
- mobile layout wraps or stacks without clipping;
- images load from reviewed existing media URLs;
- CTA buttons and long headings do not overflow on mobile.

For `fixture_type=block_theme_template`, the runner also checks that:

- the rendered template has a visible `main` area;
- a main H1 is visible and appears within the first viewport;
- required CTA buttons have non-empty text and usable links;
- required latest-posts and category-links modules are visible;
- image existence checks may be disabled with `require_images=false`, because a
  homepage template can be valid without media;
- visible image loading and alt checks may be disabled with
  `validate_images=false` for template-only layout acceptance. Keep the default
  image validation on when validating the full rendered page experience.

## Acceptance Summary

The browser runner writes `build/visual-acceptance/report.json`. In addition to
raw per-viewport checks, the report includes
`acceptance_summary.artifact_type=openclaw_visual_acceptance_summary` for
OpenClaw or an operator to review after proposal execution. The summary contains:

- `overall_result` (`pass` or `fail`);
- frontend pass/fail counts across desktop, tablet, and mobile;
- editor pass/skip/fail counts;
- template module visibility for `main`, H1, CTA, latest posts, categories,
  post content, header, and footer;
- screenshot paths grouped by viewport;
- warning codes such as `low_background_variety`;
- failed checks and whether human review is recommended.

## Design Quality Checks

Browser acceptance should catch more than technical breakage. For visually
important landing pages, also check:

- the page has at least four distinct section shapes, such as split hero,
  product media panel, bento grid, comparison cards, FAQ, and final CTA band;
- the chosen media role is visible above or near the fold when the customer
  asked for a modern or visual page;
- color story is not only black and white unless the request explicitly asks
  for a monochrome treatment;
- repeated pages for the same site do not share the exact same section order,
  media placement, and CTA arrangement;
- headings and descriptions are aligned intentionally, not just inherited from
  theme defaults;
- the generated page still feels editable as core Gutenberg blocks.

Use
[`openclaw-gutenberg-design-system.md`](openclaw-gutenberg-design-system.md)
for the design vocabulary, anti-template rules, and expected quality signals.

## Structural Baseline

Smoke verifies the preconditions that can be checked without a browser:

- post or page remains `draft`;
- Gutenberg block comments are stored;
- `core/media-text` or `core/image` exists when media is supplied;
- when a reviewed attachment id is supplied, `core/image.attrs.id` or
  `core/media-text.attrs.mediaId` matches that attachment id;
- rendered image markup contains the matching `wp-image-{id}` class when an
  attachment id is supplied;
- media blocks reference final local WordPress media URLs, not temporary Cloud
  derivative preview URLs;
- `core/columns` keeps `isStackedOnMobile=true` where columns are used;
- FAQ output uses `core/details`;
- `POST /run-read-ability` with `npcink-abilities-toolkit/get-post-blocks`
  can read the generated block tree through Adapter.

Failing a browser check should feed back into the Toolkit pattern or article
template. Do not fix visual issues by adding arbitrary CSS, direct WordPress
writes, or Adapter-side rendering logic.
