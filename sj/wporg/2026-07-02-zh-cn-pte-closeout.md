# WordPress.org zh_CN PTE Closeout

Date: 2026-07-02
Status: complete

This note records the post-PTE Simplified Chinese translation maintenance for
Npcink AI Client Adapter on WordPress.org GlotPress.

This record does not store WordPress.org passwords, cookies, tokens, session
headers, or other account credentials.

## Context

The WordPress.org Polyglots team granted `muze233` Project Translation Editor
access for locale `zh_CN` on the Npcink AI Client Adapter plugin project:

```text
https://translate.wordpress.org/projects/wp-plugins/npcink-ai-client-adapter/
```

Before PTE approval, the project had imported zh_CN suggestions waiting for
review:

```text
Stable: 296 waiting strings
Development: 296 waiting strings
Stable Readme: 54 waiting strings
Project overview: 646 waiting/fuzzy strings
```

The goal after approval was to review the prepared translations, correct local
source mistakes, and import the approved translations as `current` in
WordPress.org GlotPress.

## Local Translation Fixes

The local zh_CN translation files had several copy/paste errors that would have
made the approved WordPress.org language pack misleading. Examples included:

- `core_proxy_execute=false` translated as `commit_execution=false`;
- Core authorization and read-preflight errors translated as unrelated adapter
  messages;
- execution profile IDs such as `update-post-blocks`,
  `update-template-blocks`, `upsert-template-blocks`, and
  `update-template-part-blocks` translated as different operation names;
- `restore-media-backup` translated as `rename-media-file`;
- adapter action payload limit text translated as a proposal write-actions
  limit.

The corrected files were:

```text
languages/npcink-ai-client-adapter-zh_CN.po
languages/npcink-ai-client-adapter-zh_CN.mo
sj/wporg/npcink-ai-client-adapter-dev-zh_CN-glotpress-import.po
sj/wporg/npcink-ai-client-adapter-stable-zh_CN-glotpress-import.po
```

The Stable Readme import file already matched the intended listing translation
and did not need content changes.

## WordPress.org Import

After confirming that the WordPress.org account was logged in and had PTE
access, the prepared GlotPress import files were submitted with status
`current`:

```text
Stable:
https://translate.wordpress.org/projects/wp-plugins/npcink-ai-client-adapter/stable/zh-cn/default/import-translations/

Development:
https://translate.wordpress.org/projects/wp-plugins/npcink-ai-client-adapter/dev/zh-cn/default/import-translations/

Stable Readme:
https://translate.wordpress.org/projects/wp-plugins/npcink-ai-client-adapter/stable-readme/zh-cn/default/import-translations/
```

Final WordPress.org verification:

```text
Stable: All 296, Translated 296, Untranslated 0, Waiting 0, Fuzzy 0, Warnings 0
Development: All 296, Translated 296, Untranslated 0, Waiting 0, Fuzzy 0, Warnings 0
Stable Readme: All 54, Translated 54, Untranslated 0, Waiting 0, Fuzzy 0, Warnings 0
```

## Local Verification

The corrected local files were validated before committing:

```text
msgfmt --check --statistics languages/npcink-ai-client-adapter-zh_CN.po
msgfmt --check --statistics sj/wporg/npcink-ai-client-adapter-dev-zh_CN-glotpress-import.po
msgfmt --check --statistics sj/wporg/npcink-ai-client-adapter-stable-zh_CN-glotpress-import.po
msgfmt --check --statistics sj/wporg/npcink-ai-client-adapter-stable-readme-zh_CN-glotpress-import.po
composer test:all
composer check:wporg
```

The translation source changes were committed as:

```text
03dc4dd Fix zh_CN translation source and wporg imports
```

## Maintenance Guidance

- Keep source strings in English and under the
  `npcink-ai-client-adapter` text domain.
- Refresh `languages/npcink-ai-client-adapter.pot` before release when runtime
  strings change.
- Keep bundled `languages/npcink-ai-client-adapter-zh_CN.po` and `.mo` in sync
  for local/private installs.
- Keep `sj/wporg/*-glotpress-import.po` files as reproducible WordPress.org
  import inputs.
- Use WordPress.org GlotPress status, not only bundled language files, to
  verify public plugin directory language-pack health.
- Do not store WordPress.org credentials, cookies, or session headers in the
  repository.
