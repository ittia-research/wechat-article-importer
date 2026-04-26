=== WeChat Article Importer ===
Contributors: ittia, xiaozhai001
Tags: wechat, wordpress, import, 微信公众号文章, 采集, 导入
Requires at least: 5.0
Tested up to: 6.8
Stable tag: 0.1.0
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

The importer runs as a multi-step admin AJAX flow so large articles can be processed without a single long blocking request.

== Installation ==

1. Upload the `wechat-article-importer` folder to `/wp-content/plugins/`.
2. Activate "WeChat Article Importer" from the WordPress Plugins screen.
3. Open the "WeChat Article Importer" admin menu item and paste a `https://mp.weixin.qq.com/...` article URL.

== Frequently Asked Questions ==

= Does this work for all WeChat articles? =

It should work for many WeChat Official Account articles, but WeChat page structure and anti-hotlinking behavior can change.

= Will this slow down the public site? =

No. Work is only triggered from the WordPress admin importer page.

== Changelog ==

= 0.1.0 =
* Fork scaffolded from Import Articles from WeChat 1.8.6.
* Renamed plugin slug, text domain, PHP/JS identifiers, AJAX actions, nonce, and media metadata keys for independent iteration.
