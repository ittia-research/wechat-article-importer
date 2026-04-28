=== WeChat Article Importer ===
Contributors: ittia, xiaozhai001
Tags: wechat, wordpress, import, 微信公众号文章, 采集, 导入
Requires at least: 5.0
Tested up to: 6.8
Stable tag: 0.2.3
Requires PHP: 7.4
License: GPLv2
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Import WeChat Official Account articles into WordPress drafts, including title, publish date, content, featured image, and inline images.

== Description ==

WeChat Article Importer imports WeChat Official Account articles into WordPress drafts.

Paste a WeChat Official Account article URL and the plugin will fetch:

* Title and original publish date
* Full article content
* Featured image
* Inline images, downloaded into the WordPress media library

The importer runs as a multi-step admin AJAX flow so large articles can be processed without a single long blocking request. Before saving, it converts WeChat source HTML into WordPress-stable HTML: layout-critical inline styles are preserved, image/background URLs are localized, invalid block-wrapping image links are normalized, intentional blank spacers are rewritten into editor-stable inline markers, placeholder SVGs are replaced, and WeChat-only custom tags/attributes are removed. Media de-duplication is site-wide through canonical attachment content MD5 metadata, not WeChat source URL hashes.

== Installation ==

1. Upload the `wechat-article-importer` folder to `/wp-content/plugins/`.
2. Activate "WeChat Article Importer" from the WordPress Plugins screen.
3. Open the "Import WeChat" admin menu item and paste a `https://mp.weixin.qq.com/...` article URL.

On activation, the plugin grants its dedicated `wai_import_wechat_articles` capability to Administrators and Editors, so those roles can use the importer without receiving the broad `manage_options` site-settings capability.

== Frequently Asked Questions ==

= Does this work for all WeChat articles? =

It should work for many WeChat Official Account articles, but WeChat page structure and anti-hotlinking behavior can change.

= Will this slow down the public site? =

No. Work is only triggered from the WordPress admin importer page.

= Can Editor accounts use the importer? =

Yes. The plugin grants Administrators and Editors the dedicated `wai_import_wechat_articles` capability automatically on activation and after plugin updates. If a custom role should use the importer, grant that custom role `wai_import_wechat_articles`.

= How do I populate MD5 metadata for existing media? =

New and regenerated attachments get `_wai_attachment_content_md5` automatically. Existing media need a one-time WP-CLI backfill that calls `wai_update_attachment_content_md5_meta()` per attachment, or `wp media regenerate --yes` if thumbnail regeneration is acceptable.

= How can I distinguish importer-uploaded media from manual uploads? =

Media files newly uploaded by this importer get `_wai_imported_attachment=1` plus `_wai_source_image_url` with the original WeChat image URL. The `_wai_attachment_content_md5` key is source-agnostic dedupe metadata and can also exist on manual uploads.

== Changelog ==

= 0.2.3 =
* Added a plugin-specific import capability that is granted to administrators and editors automatically, so editor accounts can see and run the importer without receiving broad site settings permissions.

= 0.2.2 =
* Changed media de-duplication to use canonical site-wide attachment content MD5 metadata.
* Stopped reading or writing legacy source-hash metadata keys such as `_iafw_source_md5` and `_wai_source_md5`.
* Added automatic MD5 metadata refresh for newly added or regenerated attachments.

= 0.2.1 =
* Added bounded retry-and-skip handling for image AJAX transport failures.
* Added final import warnings that report skipped image counts and URLs.
* Added cURL connect and slow-transfer guards for article and image fetches.

= 0.2.0 =
* Added stable WordPress HTML cleanup that preserves visual WeChat styling while removing WeChat editor/source garbage.
* Added editor-stable image link normalization, intentional blank-spacer handling, placeholder SVG removal, and scoped Classic Editor integration without theme-specific editor sizing.
* Hardened imports with WeChat article/media URL allowlists, TLS verification, verified raster-only media uploads, and unsafe URL/CSS/SVG stripping.
* Replaced fragile regex body parsing with DOM-based content extraction.
* Added media dedupe compatibility with upstream `_iafw_source_md5` media metadata and new content-hash metadata for this fork.
* Added fixture-based regression tests for cleanup, parsing, editor scoping, URL validation, and security hardening.

= 0.1.0 =
* Fork scaffolded from Import Articles from WeChat 1.8.6.
* Renamed plugin slug, text domain, PHP/JS identifiers, AJAX actions, nonce, and media metadata keys for independent iteration.
