# WeChat Article Importer

WordPress plugin fork for importing WeChat Official Account articles into WordPress drafts.

## Structure

```text
wechat-article-importer.php  WordPress plugin entry point and importer logic
js/importer.js               Admin AJAX importer UI flow
readme.txt                   WordPress.org-style plugin readme
languages/                   Translation files placeholder
```

## Development notes

- Plugin slug/text domain: `wechat-article-importer`
- PHP/JS prefix: `wai`
- Admin menu slug: `wechat-article-importer`
- AJAX actions: `wai_start_import`, `wai_process_image`, `wai_finish_import`

## Import cleanup strategy

The importer stores a WordPress-stable version of the WeChat article HTML rather than the raw source. It keeps layout-critical inline styles, localizes image/background URLs, converts invalid block-wrapping image links to editor-stable image links, rewrites intentional blank spacers into retained inline markers, replaces WeChat placeholder SVGs with hidden standard spans, and removes WeChat-only/editor-only attributes and custom tags.

Media dedupe first recognizes this fork's URL hash metadata, then the upstream plugin's `_iafw_source_md5` metadata, then this fork's content hash metadata for media imported after this version. Server-side fetches are restricted to WeChat article/media hosts, image uploads are verified as JPEG/PNG/GIF/WebP bytes, and unsafe URL/CSS/SVG source is stripped before storage.

Imported posts get a scoped Classic Editor body class as an integration hook, but the plugin does not force theme-specific editor dimensions. Visual consistency should come from stable imported HTML that can survive WordPress editor preprocessing.

## Verification

Run checks after edits:

```bash
php -l wechat-article-importer.php
node --check js/importer.js
php tests/run.php
```

## Acknowledgment

This project acknowledges the WordPress.org plugin "Import Articles from WeChat": https://wordpress.org/plugins/import-articles-from-wechat/.
