# WeChat Article Importer

WordPress plugin fork for importing WeChat Official Account articles into WordPress drafts.

## Structure

```text
VERSION                      Canonical release version
wechat-article-importer.php  WordPress plugin entry point and importer logic
js/importer.js               Admin AJAX importer UI flow
readme.txt                   WordPress.org-style plugin readme
languages/                   Translation template and English/Chinese catalogs
scripts/version.js           Version sync, validation, and release notes helper
.github/workflows/           CI and direct GitHub Release automation
```

## Development notes

- Plugin slug/text domain: `wechat-article-importer`
- PHP/JS prefix: `wai`
- Admin menu slug: `wechat-article-importer`
- AJAX actions: `wai_start_import`, `wai_process_image`, `wai_finish_import`
- Admin access capability: `wai_import_wechat_articles`, granted automatically to Administrators and Editors on activation and versioned capability updates.

## Created metadata

The plugin writes these custom WordPress post-meta keys:

| Object | Meta key | Value | Purpose |
| --- | --- | --- | --- |
| Imported post | `_wai_import_source_url` | Sanitized source WeChat article URL, when available | Preserves where the imported draft came from. |
| Imported post | `_wai_import_content_hash` | MD5 hash of the cleaned HTML stored in `post_content` | Marks the post as a WeChat import and lets the Classic Editor integration apply only to imported posts. |
| Attachment | `_wai_attachment_content_md5` | Lowercase MD5 hash of the attachment file bytes | Enables site-wide media dedupe across imports and regenerated attachments. |
| Attachment | `_wai_imported_attachment` | `1` | Marks media files newly uploaded by this importer so they can be distinguished from manual uploads. |
| Attachment | `_wai_source_image_url` | Sanitized source WeChat image URL | Preserves the remote WeChat image URL for media files newly uploaded by this importer. |

When an imported image becomes the featured image, WordPress also stores the
standard `_thumbnail_id` relationship for the imported post. If thumbnail
generation is enabled, WordPress may update its normal attachment metadata; the
custom plugin-specific attachment hash remains `_wai_attachment_content_md5`.

## Import cleanup strategy

The importer stores a WordPress-stable version of the WeChat article HTML rather than the raw source. It keeps layout-critical inline styles, localizes image/background URLs, converts invalid block-wrapping image links to editor-stable image links, rewrites intentional blank spacers, including blank-line sections that TinyMCE would save empty, into retained inline markers that keep the spacer's number of lines, replaces WeChat placeholder SVGs with hidden standard spans, and removes WeChat-only/editor-only attributes and custom tags.

Typography that the site theme owns is removed by `wai_remove_site_typography()`, so imported articles follow the theme's font, body size and line spacing for each language instead of WeChat's phone layout:

- `font-family` is always removed.
- `font-size` is removed when it is a body text size (14-18px, or the same in pt) and no ancestor keeps a size. Caption, credit and display sizes stay.
- `line-height` is removed when it is text spacing (1.5-2.5, unitless or em), the element sets no `height` or `min-height`, and neither the element nor an ancestor keeps a size or a line-height. Image wrappers with `line-height: 0`, tight badges, centred labels and tall bands keep theirs.
- A style with a `font` shorthand keeps all of its typography, because the shorthand sets the family, size and line-height itself; its children count as keeping a size and line-height. A size or line-height declared twice in one style stays.

Letter spacing and justified alignment stay, because they suit Chinese text; a theme can override them for other languages with `!important` rules. The function works on one style attribute plus what its ancestors keep, so a one-time backfill of existing posts can reuse it with an HTML walker that tracks ancestors.

The style allowlist splits inline CSS on every semicolon, so quotes, parentheses or comments cannot hide a disallowed declaration inside an allowed one. Later steps split the cleaned CSS into the declarations a browser sees, keeping strings, comments, unquoted `url()` values and `()`, `[]` and `{}` blocks whole, so the typography rule removes only whole declarations.

Stored content loads images only from WeChat media, which the import replaces with local copies, or from this site's uploads, on the same host and below the uploads path. An `<img>` with any other source is removed. In inline CSS, `url()` with such a URL is the only way to load an image: a value with a CSS escape is dropped, because an escape can spell any function name, and so is a call to any function other than `url()` and the colour, size, gradient and transform functions, because `image-set()` and similar functions take URLs as plain strings.

Media dedupe now uses source-agnostic attachment content hashes stored in `_wai_attachment_content_md5`. WeChat imports download and verify image bytes, compute the MD5, then reuse any existing attachment with the same canonical hash before creating a new media file. New or regenerated attachments also receive the same canonical MD5 metadata; legacy source-hash keys such as `_iafw_source_md5` and `_wai_source_md5` are no longer read or written. Because the MD5 metadata can exist on manual uploads too, newly uploaded importer media are additionally marked with `_wai_imported_attachment` and `_wai_source_image_url`. Existing attachments reused by dedupe are not marked as importer-created media.

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

## Versioning

`VERSION` is the canonical version source. WordPress and tooling still require
mirrored copies in `package.json`, the plugin header, `WAI_VERSION`,
`readme.txt`'s `Stable tag`, translation catalog `Project-Id-Version` headers,
and the translation command examples in this README, so keep them synchronized
with:

```bash
npm run version:check
npm run version:set -- 0.2.3 --changelog-file /tmp/release-changelog.txt
npm run release:notes -- 0.2.3
```

`version:set` accepts an optional leading `v` (for example `v0.2.3`). When the
target version does not already have a `readme.txt` changelog section, pass a
non-empty changelog file; each non-empty line is normalized to a WordPress
readme bullet.

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

## Release workflow

Releases do not require a release PR. Use either path:

1. Update version metadata locally with `npm run version:set -- X.Y.Z
   --changelog-file /tmp/release-changelog.txt`, review the diff, and push to
   `main`.
2. Or run the **Release** workflow manually with an optional target version and
   changelog. When a version is provided, the workflow commits the synchronized
   version metadata directly to `main`.

The **Release** workflow verifies all mirrored metadata, runs the full
check/build suite, extracts release notes from `readme.txt`, force-refreshes the
`vX.Y.Z` tag at the current `main` commit, deletes any existing GitHub Release
for that tag, and recreates it with a fresh `wechat-article-importer.zip`.

## Translation workflow

JavaScript UI strings are localized through the PHP `wai_ajax.i18n` map so one
gettext catalog covers the classic admin UI. When source strings change,
regenerate the template, update the `.po` catalogs, and compile the `.mo`
binaries:

```bash
xgettext --from-code=UTF-8 --language=PHP \
  --keyword=__ --keyword=_e --keyword=esc_html_e --keyword=esc_attr_e \
  --add-comments=translators: \
  --package-name='WeChat Article Importer' --package-version='0.3.0' \
  --msgid-bugs-address='https://github.com/ittia-research' \
  --copyright-holder='ITTIA' \
  --output=languages/wechat-article-importer.pot \
  wechat-article-importer.php

msgfmt --check --check-format --output-file=languages/wechat-article-importer-en_US.mo languages/wechat-article-importer-en_US.po
msgfmt --check --check-format --output-file=languages/wechat-article-importer-zh_CN.mo languages/wechat-article-importer-zh_CN.po
```

## Acknowledgment

This project acknowledges the WordPress.org plugin "Import Articles from WeChat": https://wordpress.org/plugins/import-articles-from-wechat/.
