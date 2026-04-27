<?php
const ABSPATH = '/tmp/wordpress/';
const HOUR_IN_SECONDS = 3600;

function add_action( $hook_name, $callback = '', $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['wai_test_actions'][] = func_get_args();
}
function add_filter( $hook_name, $callback = '', $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['wai_test_filters'][] = func_get_args();
}
function apply_filters( $tag, $value ) { return $value; }
function add_menu_page() {
	$GLOBALS['wai_test_menu_page_args'] = func_get_args();
	return 'toplevel_page_wechat-article-importer';
}
function admin_url( $path = '' ) { return 'https://example.test/wp-admin/' . $path; }
function plugin_dir_url() { return 'https://example.test/wp-content/plugins/wechat-article-importer/'; }
function plugin_basename( $file ) { return basename( dirname( $file ) ) . '/' . basename( $file ); }
function load_plugin_textdomain() {
	$GLOBALS['wai_test_textdomain_args'] = func_get_args();
	return true;
}
function wp_enqueue_script() {}
function wp_localize_script() { $GLOBALS['wai_test_localized_script_args'] = func_get_args(); }
function wp_create_nonce() { return 'nonce'; }
function esc_html_e( $text ) { echo $text; }
function esc_attr_e( $text ) { echo $text; }
function esc_url( $url ) { return $url; }
function submit_button() {}
function __( $text ) { return $text; }
function current_time() { return '2026-04-26 00:00:00'; }
function wp_strip_all_tags( $text ) { return trim( strip_tags( $text ) ); }
function esc_url_raw( $url, $protocols = null ) { return trim( html_entity_decode( (string) $url, ENT_QUOTES ) ); }
function wp_parse_url( $url, $component = -1 ) { return -1 === $component ? parse_url( $url ) : parse_url( $url, $component ); }
function wp_kses_bad_protocol( $string, $allowed_protocols ) {
	$probe = preg_replace( '/[\x00-\x20]+/', '', (string) $string );
	if ( preg_match( '/^([a-z][a-z0-9+.-]*):/i', $probe, $matches ) && ! in_array( strtolower( $matches[1] ), $allowed_protocols, true ) ) {
		return '';
	}
	return $string;
}
function sanitize_key( $key ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $key ) ); }
function absint( $value ) { return abs( (int) $value ); }
function wp_unslash( $value ) { return $value; }
function is_admin() { return true; }
function get_current_user_id() { return 1; }
function get_date_from_gmt( $date ) { return $date; }
function get_post_meta( $post_id, $key, $single = false ) {
	$value = $GLOBALS['wai_test_post_meta'][ $post_id ][ $key ] ?? '';
	return $single ? $value : array( $value );
}
function update_post_meta( $post_id, $key, $value ) {
	$GLOBALS['wai_test_post_meta'][ $post_id ][ $key ] = $value;
	$GLOBALS['wai_test_updated_post_meta'][] = func_get_args();
	return true;
}
function get_posts( $args ) {
	$GLOBALS['wai_test_get_posts_args'][] = $args;
	return $GLOBALS['wai_test_get_posts_results'] ?? array();
}
function get_attached_file( $attachment_id ) {
	return $GLOBALS['wai_test_attached_files'][ $attachment_id ] ?? '';
}
function wp_upload_dir() {
	return array(
		'path' => $GLOBALS['wai_test_upload_dir'] ?? sys_get_temp_dir(),
	);
}
function wp_unique_filename( $dir, $filename ) {
	$GLOBALS['wai_test_unique_filename_args'][] = func_get_args();
	return $filename;
}
function wp_check_filetype( $filename, $mimes = null ) {
	$extension = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
	$types     = array(
		'jpg'  => 'image/jpeg',
		'jpeg' => 'image/jpeg',
		'png'  => 'image/png',
		'gif'  => 'image/gif',
		'webp' => 'image/webp',
	);
	return array(
		'ext'  => $extension,
		'type' => $types[ $extension ] ?? '',
	);
}
function wp_delete_file( $file ) {
	$GLOBALS['wai_test_deleted_files'][] = $file;
	if ( is_file( $file ) ) {
		unlink( $file );
	}
}
function wp_insert_attachment( $attachment, $filepath, $post_id = 0 ) {
	$attachment_id = $GLOBALS['wai_test_next_attachment_id'] ?? 987;
	$GLOBALS['wai_test_inserted_attachments'][ $attachment_id ] = array(
		'attachment' => $attachment,
		'filepath'   => $filepath,
		'post_id'    => $post_id,
	);
	$GLOBALS['wai_test_attached_files'][ $attachment_id ] = $filepath;
	return $attachment_id;
}
function wp_generate_attachment_metadata( $attachment_id, $filepath ) {
	$GLOBALS['wai_test_generated_attachment_metadata'][] = func_get_args();
	return array( 'file' => basename( $filepath ) );
}
function wp_update_attachment_metadata( $attachment_id, $metadata ) {
	$GLOBALS['wai_test_updated_attachment_metadata'][] = func_get_args();
	return true;
}
class WP_Error {
	private $message;
	public function __construct( $code = '', $message = '' ) { $this->message = $message; }
	public function get_error_message() { return $this->message; }
}
function is_wp_error( $thing ) { return $thing instanceof WP_Error; }

require __DIR__ . '/../wechat-article-importer.php';

$failures = 0;
function assert_true( $condition, $message ) {
	global $failures;
	if ( ! $condition ) {
		$failures++;
		echo "FAIL: {$message}\n";
	} else {
		echo "PASS: {$message}\n";
	}
}
function assert_not_contains( $needle, $haystack, $message ) {
	assert_true( false === strpos( $haystack, $needle ), $message );
}
function assert_contains( $needle, $haystack, $message ) {
	assert_true( false !== strpos( $haystack, $needle ), $message );
}

$registered_callbacks = array();
foreach ( $GLOBALS['wai_test_actions'] as $action_args ) {
	$registered_callbacks[] = $action_args[0] . ':' . $action_args[1];
}
$registered_filters = array();
foreach ( $GLOBALS['wai_test_filters'] as $filter_args ) {
	$registered_filters[] = $filter_args[0] . ':' . $filter_args[1];
}
assert_true( in_array( 'plugins_loaded:wai_load_textdomain', $registered_callbacks, true ), 'textdomain loader is registered on plugins_loaded' );
assert_true( in_array( 'add_attachment:wai_update_attachment_content_md5_meta', $registered_callbacks, true ), 'attachment creation records canonical content MD5 metadata' );
assert_true( in_array( 'wp_update_attachment_metadata:wai_update_attachment_content_md5_meta_on_metadata_update', $registered_filters, true ), 'attachment metadata updates refresh canonical content MD5 metadata' );

wai_load_textdomain();
assert_true(
	array( 'wechat-article-importer', false, 'wechat-article-importer/languages' ) === $GLOBALS['wai_test_textdomain_args'],
	'textdomain loader points at the plugin languages directory'
);

wai_add_admin_menu();
assert_true( 'WeChat Article Importer' === $GLOBALS['wai_test_menu_page_args'][0], 'admin page title remains descriptive' );
assert_true( 'Import WeChat' === $GLOBALS['wai_test_menu_page_args'][1], 'admin menu label is Import WeChat' );

wai_enqueue_admin_scripts( 'toplevel_page_wechat-article-importer' );
assert_true( isset( $GLOBALS['wai_test_localized_script_args'][2]['i18n'] ), 'admin script receives localized strings' );
assert_true( 'Start Import' === $GLOBALS['wai_test_localized_script_args'][2]['i18n']['start_import'], 'localized defaults are English source strings' );
assert_true( false !== strpos( $GLOBALS['wai_test_localized_script_args'][2]['i18n']['step_2_message'], '%1$s' ), 'localized progress message keeps status placeholder' );
assert_true( false !== strpos( $GLOBALS['wai_test_localized_script_args'][2]['i18n']['imported_as_draft_template'], '%s' ), 'localized success message keeps link placeholder' );

$source_image = 'https://mmbiz.qpic.cn/sz_mmbiz_png/example/640?wx_fmt=png&from=appmsg';
$bg_image     = 'https://mmbiz.qpic.cn/sz_mmbiz_jpg/example/bg?wx_fmt=jpeg';
$local_image  = 'https://example.test/wp-content/uploads/2026/04/640-test.png';
$local_bg     = 'https://example.test/wp-content/uploads/2026/04/bg-test.jpg';

assert_true( wai_is_allowed_article_url( 'https://mp.weixin.qq.com/s/3a0p8hyw6oqK0t2tfe2oGw' ), 'allows canonical WeChat article URL' );
assert_true( ! wai_is_allowed_article_url( 'http://mp.weixin.qq.com/s/3a0p8hyw6oqK0t2tfe2oGw' ), 'rejects non-HTTPS article URL' );
assert_true( ! wai_is_allowed_article_url( 'https://example.test/s/3a0p8hyw6oqK0t2tfe2oGw' ), 'rejects non-WeChat article host' );
assert_true( wai_is_allowed_image_url( $source_image ), 'allows WeChat qpic image URL' );
assert_true( ! wai_is_allowed_image_url( 'https://example.test/image.jpg' ), 'rejects non-WeChat image host for server-side fetch' );
assert_true( is_wp_error( wai_sideload_image( 'https://example.test/image.jpg', 0, '/tmp/no-cookie.txt' ) ), 'sideload rejects non-WeChat image URL before cURL' );
assert_true( 'image/svg+xml' === wai_detect_image_mime_type( '<svg xmlns="http://www.w3.org/2000/svg"><path d="M0 0"/></svg>' ), 'SVG bytes are detected' );
assert_true( '' === wai_extension_for_allowed_image_mime( 'image/svg+xml' ), 'SVG is not an allowed upload type' );

assert_true( WAI_ATTACHMENT_CONTENT_MD5_META === '_wai_attachment_content_md5', 'canonical attachment MD5 metadata key is source-agnostic' );
assert_true( WAI_IMPORTED_ATTACHMENT_META === '_wai_imported_attachment', 'imported attachment marker meta key identifies importer-created media' );
assert_true( WAI_SOURCE_IMAGE_URL_META === '_wai_source_image_url', 'source image URL meta key records importer-created media origin' );
assert_true( ! defined( 'WAI_LEGACY_SOURCE_MD5_META' ), 'legacy fork source MD5 metadata constant is removed' );
assert_true( ! defined( 'WAI_UPSTREAM_SOURCE_MD5_META' ), 'upstream source MD5 metadata constant is removed' );
assert_true( ! defined( 'WAI_IMAGE_CONTENT_MD5_META' ), 'importer-only content MD5 metadata constant is removed' );

$site_wide_md5 = '0123456789abcdef0123456789ABCDEF';
assert_true( '0123456789abcdef0123456789abcdef' === wai_normalize_md5_hash( ' ' . $site_wide_md5 . ' ' ), 'MD5 hashes are normalized before storage and lookup' );
assert_true( '' === wai_normalize_md5_hash( 'not-an-md5' ), 'invalid MD5 hashes are rejected' );

$GLOBALS['wai_test_post_meta'] = array();
$GLOBALS['wai_test_updated_post_meta'] = array();
assert_true( wai_record_attachment_content_md5( 456, $site_wide_md5 ), 'canonical content MD5 metadata is recorded for an attachment' );
assert_true( '0123456789abcdef0123456789abcdef' === $GLOBALS['wai_test_post_meta'][456][ WAI_ATTACHMENT_CONTENT_MD5_META ], 'canonical content MD5 metadata is lowercased in post meta' );
assert_true( ! isset( $GLOBALS['wai_test_post_meta'][456]['_wai_source_md5'] ), 'legacy fork source MD5 metadata is not written' );
assert_true( ! isset( $GLOBALS['wai_test_post_meta'][456]['_iafw_source_md5'] ), 'upstream source MD5 metadata is not written' );

$GLOBALS['wai_test_post_meta'] = array();
$GLOBALS['wai_test_updated_post_meta'] = array();
assert_true( wai_record_imported_attachment_meta( 654, $source_image ), 'importer-created attachment metadata is recorded for allowed WeChat image URLs' );
assert_true( '1' === $GLOBALS['wai_test_post_meta'][654][ WAI_IMPORTED_ATTACHMENT_META ], 'importer-created attachment marker is stored' );
assert_true( $source_image === $GLOBALS['wai_test_post_meta'][654][ WAI_SOURCE_IMAGE_URL_META ], 'source WeChat image URL is stored on importer-created attachments' );
assert_true( 2 === count( $GLOBALS['wai_test_updated_post_meta'] ), 'importer-created attachment helper writes only the two importer-specific metadata keys' );
assert_true( ! isset( $GLOBALS['wai_test_post_meta'][654][ WAI_ATTACHMENT_CONTENT_MD5_META ] ), 'importer-created marker helper does not write the source-agnostic MD5 metadata' );
assert_true( ! wai_record_imported_attachment_meta( 655, 'https://example.test/manual-upload.jpg' ), 'importer-created attachment metadata rejects non-WeChat source URLs' );
assert_true( ! isset( $GLOBALS['wai_test_post_meta'][655] ), 'rejected source URL does not mark manual uploads as importer-created media' );

$png_image_data      = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII=' );
$tmp_upload_dir      = sys_get_temp_dir() . '/wai-import-test-' . uniqid( '', true );
$expected_image_md5  = md5( $png_image_data );
$GLOBALS['wai_test_upload_dir'] = $tmp_upload_dir;
mkdir( $tmp_upload_dir );

$GLOBALS['wai_test_get_posts_results'] = array();
$GLOBALS['wai_test_post_meta'] = array();
$GLOBALS['wai_test_updated_post_meta'] = array();
$GLOBALS['wai_test_inserted_attachments'] = array();
$GLOBALS['wai_test_next_attachment_id'] = 987;
$new_attachment_id = wai_create_imported_attachment_from_image_data( $source_image, $png_image_data, 0, null, false );
assert_true( 987 === $new_attachment_id, 'new importer image bytes create a new attachment' );
assert_true( $expected_image_md5 === $GLOBALS['wai_test_post_meta'][987][ WAI_ATTACHMENT_CONTENT_MD5_META ], 'new importer attachment receives canonical content MD5 metadata' );
assert_true( '1' === $GLOBALS['wai_test_post_meta'][987][ WAI_IMPORTED_ATTACHMENT_META ], 'new importer attachment receives imported marker metadata' );
assert_true( $source_image === $GLOBALS['wai_test_post_meta'][987][ WAI_SOURCE_IMAGE_URL_META ], 'new importer attachment receives source image URL metadata' );
assert_true( 1 === count( $GLOBALS['wai_test_inserted_attachments'] ), 'new importer image bytes insert exactly one attachment' );
assert_true( is_file( $GLOBALS['wai_test_inserted_attachments'][987]['filepath'] ), 'new importer image bytes are written into the upload directory' );

$GLOBALS['wai_test_get_posts_results'] = array( 777 );
$GLOBALS['wai_test_post_meta'] = array();
$GLOBALS['wai_test_updated_post_meta'] = array();
$GLOBALS['wai_test_inserted_attachments'] = array();
$deduped_attachment_id = wai_create_imported_attachment_from_image_data( $source_image, $png_image_data, 0, null, false );
assert_true( 777 === $deduped_attachment_id, 'deduped importer image bytes reuse an existing attachment' );
assert_true( $expected_image_md5 === $GLOBALS['wai_test_post_meta'][777][ WAI_ATTACHMENT_CONTENT_MD5_META ], 'deduped existing attachment refreshes only canonical content MD5 metadata' );
assert_true( ! isset( $GLOBALS['wai_test_post_meta'][777][ WAI_IMPORTED_ATTACHMENT_META ] ), 'deduped existing attachment is not marked as importer-created media' );
assert_true( ! isset( $GLOBALS['wai_test_post_meta'][777][ WAI_SOURCE_IMAGE_URL_META ] ), 'deduped existing attachment does not receive source image URL metadata' );
assert_true( 0 === count( $GLOBALS['wai_test_inserted_attachments'] ), 'deduped importer image bytes do not insert another attachment' );

wp_delete_file( $GLOBALS['wai_test_attached_files'][987] );
rmdir( $tmp_upload_dir );
unset( $GLOBALS['wai_test_upload_dir'], $GLOBALS['wai_test_next_attachment_id'], $GLOBALS['wai_test_get_posts_results'] );

$GLOBALS['wai_test_get_posts_results'] = array( 789 );
$GLOBALS['wai_test_get_posts_args'] = array();
assert_true( 789 === wai_find_attachment_by_content_md5( $site_wide_md5 ), 'content MD5 lookup can find any attachment by canonical site-wide metadata' );
$last_get_posts_args = $GLOBALS['wai_test_get_posts_args'][ count( $GLOBALS['wai_test_get_posts_args'] ) - 1 ];
assert_true( WAI_ATTACHMENT_CONTENT_MD5_META === $last_get_posts_args['meta_key'], 'content MD5 lookup uses only the canonical metadata key' );
assert_true( ! isset( $last_get_posts_args['meta_query'] ), 'content MD5 lookup no longer checks source-hash compatibility metadata' );
unset( $GLOBALS['wai_test_get_posts_results'] );

$tmp_media_file = tempnam( sys_get_temp_dir(), 'wai-md5-' );
file_put_contents( $tmp_media_file, 'site-wide bytes' );
$GLOBALS['wai_test_attached_files'] = array( 321 => $tmp_media_file );
$expected_tmp_md5 = md5_file( $tmp_media_file );
assert_true( $expected_tmp_md5 === wai_update_attachment_content_md5_meta( 321 ), 'attachment file hashing fills missing canonical MD5 metadata' );
assert_true( $expected_tmp_md5 === $GLOBALS['wai_test_post_meta'][321][ WAI_ATTACHMENT_CONTENT_MD5_META ], 'attachment file hash is stored as canonical MD5 metadata' );
$metadata = array( 'sizes' => array() );
assert_true( $metadata === wai_update_attachment_content_md5_meta_on_metadata_update( $metadata, 321 ), 'attachment metadata update filter preserves WordPress metadata payload' );
unlink( $tmp_media_file );

$fixture = '<section style="line-height: 2.2; letter-spacing: 0.6px; color: rgb(62, 62, 62);" data-pm-slice="0 0 []">'
	. '<p style="margin: 0px;"><span leaf="">谷雨至，雨生百谷。</span></p>'
	. '<p style="margin: 0px;"><span leaf=""><br></span></p>'
	. '<section style="background-color: rgb(4, 116, 61); height: 1px;"><svg viewbox="0 0 1 1" style="float:left;line-height:0;width:0;vertical-align:top;"></svg></section>'
	. '<section style="display: inline-block; width: 20px; height: 55px; background-image: linear-gradient(rgba(33, 136, 45, 0.5) 13%, rgba(186, 237, 134, 0) 100%);" nodeleaf=""></section>'
	. '<mp-common-profile data-id="x">profile garbage</mp-common-profile>'
	. '<a title="" linktype="image" formlinkparm="[{&quot;href&quot;:&quot;https://mp.weixin.qq.com/s/example&quot;}]" href="https://mp.weixin.qq.com/s/example" target="_blank">'
	. '<section style="text-align: center;" nodeleaf=""><img class="rich_pages wxw-img" data-src="' . htmlspecialchars( $source_image, ENT_QUOTES ) . '" data-ratio="0.75" data-w="1080" src="" alt="" style="vertical-align: middle; max-width: 100%; width: 100%; box-sizing: border-box;"></section>'
	. '</a>'
	. '<section style="background: url(' . htmlspecialchars( $bg_image, ENT_QUOTES ) . ') center/cover no-repeat; height: 20px;"></section>'
	. '<p style="display: none;"><mp-style-type data-value="3"></mp-style-type></p>'
	. '</section>';

$clean = wai_prepare_import_content(
	$fixture,
	array(
		$source_image => $local_image,
		$bg_image     => $local_bg,
	)
);

assert_contains( $local_image, $clean, 'image data-src is replaced with local src' );
assert_contains( $local_bg, $clean, 'background image URL is replaced with local URL' );
assert_contains( 'color:rgb(62, 62, 62)', $clean, 'inline color style is preserved' );
assert_contains( 'background-image:linear-gradient', $clean, 'gradient layout style is preserved' );
assert_contains( 'height:55px', $clean, 'dimension style is preserved' );
assert_contains( 'height:0;line-height:0;vertical-align:baseline;overflow:hidden', $clean, 'blank paragraph spacers get non-inflating editor-stable markers' );
assert_contains( '<p style="margin:0px"><span style="display:inline-block;width:0;height:0;line-height:0;vertical-align:baseline;overflow:hidden', $clean, 'blank paragraph spacing is represented by a retained inline marker' );
assert_not_contains( 'min-height:1em;line-height:inherit', $clean, 'blank paragraph marker does not inflate front-end line boxes' );
assert_contains( '<a href="https://mp.weixin.qq.com/s/example"', $clean, 'standard link is preserved' );
assert_contains( '<a href="https://mp.weixin.qq.com/s/example" target="_blank" rel="noopener noreferrer"><img', $clean, 'block-wrapping image link is converted to image-only link' );
assert_not_contains( '<a href="https://mp.weixin.qq.com/s/example" target="_blank" rel="noopener noreferrer"><section', $clean, 'link no longer wraps block sections' );
assert_not_contains( '<svg', $clean, 'placeholder svg is removed' );
assert_not_contains( 'mp-common-profile', $clean, 'WeChat profile element is removed' );
assert_not_contains( 'mp-style-type', $clean, 'hidden WeChat style marker is removed' );
assert_not_contains( 'leaf=', $clean, 'leaf attributes are removed' );
assert_not_contains( 'nodeleaf=', $clean, 'nodeleaf attributes are removed' );
assert_not_contains( 'data-', $clean, 'data attributes are removed from stored content' );
assert_not_contains( 'formlinkparm', $clean, 'WeChat link payload is removed' );
assert_not_contains( 'linktype', $clean, 'WeChat link type is removed' );
assert_not_contains( 'rich_pages', $clean, 'WeChat image class is removed' );
assert_not_contains( 'wxw-img', $clean, 'WeChat image class is removed' );
assert_not_contains( $source_image, $clean, 'remote image URL is not stored in post content' );
assert_not_contains( $bg_image, $clean, 'remote background URL is not stored in post content' );

$unsafe = wai_prepare_import_content(
	'<p style="color:red;position:fixed;background:url(javascript:alert(1));filter:expression(alert(1));width:10px"><a href="javascript:alert(1)" target="_blank">bad</a><svg xmlns="http://www.w3.org/2000/svg"><path d="M0 0"></path></svg></p>'
);
assert_contains( 'color:red', $unsafe, 'safe style declarations are retained when unsafe declarations are removed' );
assert_not_contains( 'javascript', strtolower( $unsafe ), 'javascript URLs are removed from href/style attributes' );
assert_not_contains( 'expression', strtolower( $unsafe ), 'CSS expression payload is removed' );
assert_not_contains( 'position:fixed', strtolower( $unsafe ), 'unsupported positioning CSS is removed' );
assert_not_contains( '<svg', strtolower( $unsafe ), 'inline SVG source is removed from stored content' );
assert_not_contains( '<path', strtolower( $unsafe ), 'inline SVG child source is removed from stored content' );

$safe_style = wai_clean_style_attribute( 'background-image: url(' . $source_image . '); color: red; width: 10px;' );
assert_contains( 'background-image:url(' . $source_image . ')', $safe_style, 'safe WeChat CSS image URLs are retained for replacement' );
assert_contains( 'color:red', $safe_style, 'safe color style survives CSS sanitizer' );

$urls = wai_collect_image_urls( $fixture, 'https://mmbiz.qpic.cn/cover/0?wx_fmt=jpeg' );
assert_true( in_array( $source_image, $urls, true ), 'collector finds img data-src URL' );
assert_true( in_array( $bg_image, $urls, true ), 'collector finds background image URL' );
assert_true( in_array( 'https://mmbiz.qpic.cn/cover/0?wx_fmt=jpeg', $urls, true ), 'collector includes thumbnail URL' );
assert_true( count( $urls ) === count( array_unique( $urls ) ), 'collector deduplicates image URLs' );
assert_true( ! in_array( 'https://example.test/not-allowed.jpg', wai_collect_image_urls( '<p><img data-src="https://example.test/not-allowed.jpg"></p>' ), true ), 'collector ignores non-WeChat image URLs' );

$legacy_marker_clean = wai_prepare_import_content( '<p style="margin:0px"><span style="display:inline-block;width:0;min-height:1em;line-height:inherit;vertical-align:baseline;overflow:hidden;">&nbsp;</span></p>' );
assert_contains( 'height:0;line-height:0;vertical-align:baseline;overflow:hidden', $legacy_marker_clean, 'legacy spacer markers are normalized to non-inflating markers' );
assert_not_contains( 'min-height:1em', $legacy_marker_clean, 'legacy spacer marker min-height is removed' );

$nested_visual_section = wai_prepare_import_content( '<section style="text-align:right;margin:-15px 0px 0px"><section style="display:inline-block;width:55px;height:15px;vertical-align:top;overflow:hidden;background-image:linear-gradient(red, blue)">&nbsp;</section></section>' );
assert_contains( '<section style="text-align:right;margin:-15px 0px 0px"><section style="display:inline-block;width:55px;height:15px;vertical-align:top;overflow:hidden;background-image:linear-gradient(red, blue)">', $nested_visual_section, 'visual child sections are not collapsed into their parent spacer' );
assert_contains( 'height:0;overflow:hidden;line-height:0;vertical-align:top', $nested_visual_section, 'visual child sections keep a zero-size marker' );

$html = '<html><head><meta property="og:image" content="https://mmbiz.qpic.cn/cover/0?wx_fmt=jpeg" /></head><body><h1 id="activity-name"> 今日谷雨，宜开封 </h1><div class="rich_media_content" id="js_content">' . $fixture . '</div><script>var ct = "1713625275";</script></body></html>';
$article = wai_parse_wechat_article_html( $html );
assert_true( '今日谷雨，宜开封' === $article['title'], 'parser extracts title' );
assert_true( '2024-04-20 15:01:15' === $article['post_date_gmt'], 'parser extracts GMT publish date' );
assert_true( ! empty( $article['content_html'] ), 'parser extracts content HTML' );

$untitled_article = wai_parse_wechat_article_html( '<html><body><div id="js_content"><p>body</p></div></body></html>' );
assert_true( 'Untitled - 2026-04-26 00:00:00' === $untitled_article['title'], 'parser fallback title uses a localized date placeholder' );

$nested_html = '<html><body><h1 id="activity-name">Nested</h1><div class="rich_media_content" id="js_content"><div><p>inner</p></div><p>after nested div</p></div></body></html>';
$nested_article = wai_parse_wechat_article_html( $nested_html );
assert_contains( '<p>inner</p>', $nested_article['content_html'], 'DOM parser keeps nested child content' );
assert_contains( '<p>after nested div</p>', $nested_article['content_html'], 'DOM parser does not truncate after first nested div' );

$GLOBALS['wai_test_post_meta'] = array(
	123 => array(
		WAI_CONTENT_HASH_META => 'stored-import-hash',
	),
);
$_GET['post'] = '123';
$tinymce = wai_extend_tinymce_for_imported_layouts(
	array(
		'body_class'    => 'content post-type-post',
		'content_style' => 'body{color:#111;}',
	)
);
assert_contains( WAI_EDITOR_BODY_CLASS, $tinymce['body_class'], 'imported post editor gets scoped body class' );
assert_true( 'body{color:#111;}' === $tinymce['content_style'], 'existing TinyMCE content style is not rewritten' );
assert_not_contains( 'width:630px!important', $tinymce['content_style'], 'plugin does not force a theme-specific editor canvas width' );
unset( $_GET['post'] );

$GLOBALS['post_ID'] = 123;
$tinymce = wai_extend_tinymce_for_imported_layouts(
	array(
		'body_class' => 'content post-type-post',
	)
);
assert_contains( WAI_EDITOR_BODY_CLASS, $tinymce['body_class'], 'imported post editor is detected from WordPress post_ID global' );
unset( $GLOBALS['post_ID'] );

$_GET['post'] = '124';
$tinymce = wai_extend_tinymce_for_imported_layouts(
	array(
		'body_class' => 'content post-type-post',
	)
);
assert_not_contains( WAI_EDITOR_BODY_CLASS, $tinymce['body_class'], 'non-imported post editor is not scoped' );
assert_true( ! isset( $tinymce['extended_valid_elements'] ), 'non-imported post editor does not get global TinyMCE element extensions' );
unset( $_GET['post'] );

if ( $failures > 0 ) {
	exit( 1 );
}

echo "All tests passed.\n";
