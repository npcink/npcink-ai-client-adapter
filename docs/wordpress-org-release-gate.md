# WordPress.org Release Gate

Status: active release gate.

Before uploading this plugin to WordPress.org, run:

```sh
composer release:verify
```

Before the SVN upload itself, verify what WordPress.org actually ships today
— a git tag, changelog entry, or readme stable tag alone is not a release.
0.3.3 had all three but was never committed to SVN, so the directory stayed
on 0.3.2 until 0.4.0:

```sh
svn ls https://plugins.svn.wordpress.org/npcink-ai-client-adapter/tags
svn cat https://plugins.svn.wordpress.org/npcink-ai-client-adapter/trunk/npcink-ai-client-adapter.php | grep Version
```

If the trunk `Version:` is older than the newest git tag, the intervening
versions were never published; fold them into the next release notes.

This release gate exists because functional tests and local smoke tests can pass
while WordPress.org rejects the package for review-policy issues.

The local `check:wporg` guard blocks recurring review problems:

- direct `wp-admin/includes/*` path construction, except the common
  `upgrade.php` activation helper for `dbDelta()`;
- admin request parameters read directly from `$_GET`;
- inline admin CSS or JS emitted from PHP;
- raw `<script>` or `<style>` tags in PHP admin views.

When WordPress.org sends a review email, decode the current top-level message,
extract every cited file and line, fix the whole pattern class, and add a local
guard when the pattern is statically checkable.
