# WeChat Article Importer

WordPress plugin fork for importing WeChat Official Account articles into WordPress drafts.

## Structure

```text
wechat-article-importer.php  WordPress plugin entry point and importer logic
js/importer.js               Admin AJAX importer UI flow
readme.txt                   WordPress.org-style plugin readme
languages/                   Translation template and English/Chinese catalogs
```

## Development notes

- Plugin slug/text domain: `wechat-article-importer`
- PHP/JS prefix: `wai`
- Admin menu slug: `wechat-article-importer`
- AJAX actions: `wai_start_import`, `wai_process_image`, `wai_finish_import`

## Created metadata

The plugin writes these custom WordPress post-meta keys:

| Object | Meta key | Value | Purpose |
| --- | --- | --- | --- |
| Imported post | `_wai_import_source_url` | Sanitized source WeChat article URL, when available | Preserves where the imported draft came from. |
| Imported post | `_wai_import_content_hash` | MD5 hash of the cleaned HTML stored in `post_content` | Marks the post as a WeChat import and lets the Classic Editor integration apply only to imported posts. |
| Attachment | `_wai_attachment_content_md5` | Lowercase MD5 hash of the attachment file bytes | Enables site-wide media dedupe across imports and regenerated attachments. |

When an imported image becomes the featured image, WordPress also stores the
standard `_thumbnail_id` relationship for the imported post. If thumbnail
generation is enabled, WordPress may update its normal attachment metadata; the
custom plugin-specific attachment hash remains `_wai_attachment_content_md5`.

## Import cleanup strategy

The importer stores a WordPress-stable version of the WeChat article HTML rather than the raw source. It keeps layout-critical inline styles, localizes image/background URLs, converts invalid block-wrapping image links to editor-stable image links, rewrites intentional blank spacers into retained inline markers, replaces WeChat placeholder SVGs with hidden standard spans, and removes WeChat-only/editor-only attributes and custom tags.

Media dedupe now uses source-agnostic attachment content hashes stored in `_wai_attachment_content_md5`. WeChat imports download and verify image bytes, compute the MD5, then reuse any existing attachment with the same canonical hash before creating a new media file. New or regenerated attachments also receive the same canonical MD5 metadata; legacy source-hash keys such as `_iafw_source_md5` and `_wai_source_md5` are no longer read or written.

Existing attachments are not scanned automatically. To populate old media, run a one-time WP-CLI backfill that calls `wai_update_attachment_content_md5_meta( $attachment_id )` for each attachment, or use `wp media regenerate --yes` when thumbnail regeneration is acceptable.

Imported posts get a scoped Classic Editor body class as an integration hook, but the plugin does not force theme-specific editor dimensions. Visual consistency should come from stable imported HTML that can survive WordPress editor preprocessing.

## Verification

Run checks after edits:

```bash
npm run check
```

This wraps the project validation commands:

```bash
php -l wechat-article-importer.php
node --check js/importer.js
msgfmt --check --check-format --output-file=/dev/null languages/wechat-article-importer-en_US.po
msgfmt --check --check-format --output-file=/dev/null languages/wechat-article-importer-zh_CN.po
php tests/run.php
```

## Packaging

Build a WordPress-ready ZIP at `./wechat-article-importer.zip`:

```bash
npm run build
```

The package build runs the verification suite first, checks version metadata
for drift, derives distributable files from tracked source files, excludes
development-only paths such as `tests/` and `scripts/`, and validates the ZIP
contents. Add new runtime files to git before packaging so they are included in
the release artifact.

## Translation workflow

JavaScript UI strings are localized through the PHP `wai_ajax.i18n` map so one
gettext catalog covers the classic admin UI. When source strings change,
regenerate the template, update the `.po` catalogs, and compile the `.mo`
binaries:

```bash
xgettext --from-code=UTF-8 --language=PHP \
  --keyword=__ --keyword=_e --keyword=esc_html_e --keyword=esc_attr_e \
  --add-comments=translators: \
  --package-name='WeChat Article Importer' --package-version='0.2.2' \
  --msgid-bugs-address='https://github.com/ittia-research' \
  --copyright-holder='ITTIA' \
  --output=languages/wechat-article-importer.pot \
  wechat-article-importer.php

msgfmt --check --check-format --output-file=languages/wechat-article-importer-en_US.mo languages/wechat-article-importer-en_US.po
msgfmt --check --check-format --output-file=languages/wechat-article-importer-zh_CN.mo languages/wechat-article-importer-zh_CN.po
```

## Acknowledgment

This project acknowledges the WordPress.org plugin "Import Articles from WeChat": https://wordpress.org/plugins/import-articles-from-wechat/.
