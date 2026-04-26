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

## Import cleanup strategy

The importer stores a WordPress-stable version of the WeChat article HTML rather than the raw source. It keeps layout-critical inline styles, localizes image/background URLs, converts invalid block-wrapping image links to editor-stable image links, rewrites intentional blank spacers into retained inline markers, replaces WeChat placeholder SVGs with hidden standard spans, and removes WeChat-only/editor-only attributes and custom tags.

Media dedupe first recognizes this fork's URL hash metadata, then the upstream plugin's `_iafw_source_md5` metadata, then this fork's content hash metadata for media imported after this version. Server-side fetches are restricted to WeChat article/media hosts, image uploads are verified as JPEG/PNG/GIF/WebP bytes, and unsafe URL/CSS/SVG source is stripped before storage.

Imported posts get a scoped Classic Editor body class as an integration hook, but the plugin does not force theme-specific editor dimensions. Visual consistency should come from stable imported HTML that can survive WordPress editor preprocessing.

## Verification

Run checks after edits:

```bash
php -l wechat-article-importer.php
node --check js/importer.js
msgfmt --check --check-format languages/wechat-article-importer-en_US.po
msgfmt --check --check-format languages/wechat-article-importer-zh_CN.po
php tests/run.php
```

## Translation workflow

JavaScript UI strings are localized through the PHP `wai_ajax.i18n` map so one
gettext catalog covers the classic admin UI. When source strings change,
regenerate the template, update the `.po` catalogs, and compile the `.mo`
binaries:

```bash
xgettext --from-code=UTF-8 --language=PHP \
  --keyword=__ --keyword=_e --keyword=esc_html_e --keyword=esc_attr_e \
  --add-comments=translators: \
  --package-name='WeChat Article Importer' --package-version='0.2.0' \
  --msgid-bugs-address='https://github.com/ittia-research' \
  --copyright-holder='ITTIA' \
  --output=languages/wechat-article-importer.pot \
  wechat-article-importer.php

msgfmt --check --check-format --output-file=languages/wechat-article-importer-en_US.mo languages/wechat-article-importer-en_US.po
msgfmt --check --check-format --output-file=languages/wechat-article-importer-zh_CN.mo languages/wechat-article-importer-zh_CN.po
```

## Acknowledgment

This project acknowledges the WordPress.org plugin "Import Articles from WeChat": https://wordpress.org/plugins/import-articles-from-wechat/.
