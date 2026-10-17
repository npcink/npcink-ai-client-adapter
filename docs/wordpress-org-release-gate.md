# WordPress.org Release Gate

Status: active release gate.

Before uploading this plugin to WordPress.org, run:

```sh
composer release:verify
```

`release:verify` also builds the package twice from the `.distignore` boundary
and requires identical ZIP bytes. The package builder fixes timestamps,
permissions and archive metadata so the checksum is bound to source content
rather than the checkout's filesystem state.

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

## Verify publication and update distribution separately

After SVN commit, record the revision and verify remote trunk/tag file contents,
the directory/plugin-information version, and the official ZIP's full unpacked
file set against the approved package. WordPress.org can regenerate ZIP metadata,
so record both ZIP digests and compare every unpacked file when their bytes differ.

Also probe `https://api.wordpress.org/plugins/update-check/1.1/` with a previous
installed version. The [official September 2026 release process](https://make.wordpress.org/plugins/2026/09/09/automated-security-review-for-plugin-releases/)
has an approximately six-hour cooldown and automated security review before
update distribution. A published directory page and downloadable ZIP therefore
do not prove that Dashboard updates offer the new version. Record these states
separately, with a follow-up time; do not rewrite a released tag to accelerate it.
If distribution remains unavailable after the window, check the committer's
review email and the actual update API response before deciding on remediation.
Do not infer a security block from the cooldown response alone.

The local `check:wporg` guard blocks recurring review problems:

- direct `wp-admin/includes/*` path construction, except the common
  `upgrade.php` activation helper for `dbDelta()`;
- admin request parameters read directly from `$_GET`;
- inline admin CSS or JS emitted from PHP;
- raw `<script>` or `<style>` tags in PHP admin views.

When WordPress.org sends a review email, decode the current top-level message,
extract every cited file and line, fix the whole pattern class, and add a local
guard when the pattern is statically checkable.
