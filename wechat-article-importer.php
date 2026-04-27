<?php
/**
 * Plugin Name:       WeChat Article Importer
 * Description:       Import WeChat Official Account articles into WordPress drafts, including content, featured images, and inline images.
 * Version:           0.2.2
 * Author:            ITTIA
 * Author URI:        https://github.com/ittia-research
 * License:           GPLv2
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wechat-article-importer
 * Domain Path:       /languages
 * Requires at least: 5.0
 * Requires PHP:      7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const WAI_VERSION = '0.2.2';
const WAI_SOURCE_URL_META = '_wai_import_source_url';
const WAI_CONTENT_HASH_META = '_wai_import_content_hash';
const WAI_ATTACHMENT_CONTENT_MD5_META = '_wai_attachment_content_md5';
const WAI_IMPORTED_ATTACHMENT_META = '_wai_imported_attachment';
const WAI_SOURCE_IMAGE_URL_META = '_wai_source_image_url';
const WAI_EDITOR_BODY_CLASS = 'wai-wechat-import-editor';
const WAI_MAX_IMAGE_BYTES = 20971520;


add_action( 'plugins_loaded', 'wai_load_textdomain' );
function wai_load_textdomain() {
	load_plugin_textdomain( 'wechat-article-importer', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}

add_action( 'admin_menu', 'wai_add_admin_menu' );
function wai_add_admin_menu() {
	add_menu_page(
		__( 'WeChat Article Importer', 'wechat-article-importer' ),
		__( 'Import WeChat', 'wechat-article-importer' ),
		'manage_options',
		'wechat-article-importer',
		'wai_importer_page_html'
	);
}

add_action( 'admin_enqueue_scripts', 'wai_enqueue_admin_scripts' );
function wai_enqueue_admin_scripts( $hook ) {
	if ( 'toplevel_page_wechat-article-importer' !== $hook ) {
		return;
	}
	wp_enqueue_script( 'wai-importer-js', plugin_dir_url( __FILE__ ) . 'js/importer.js', array( 'jquery' ), WAI_VERSION, true );
	wp_localize_script(
		'wai-importer-js',
		'wai_ajax',
		array(
			'ajax_url' => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( 'wai_ajax_nonce' ),
			'i18n'     => array(
				'invalid_url'                   => __( 'Please enter a valid WeChat article URL.', 'wechat-article-importer' ),
				'parsing_article'               => __( 'Parsing article...', 'wechat-article-importer' ),
				'step_1_message'                => __( 'Step 1/3: Parsing the article structure. Please wait...', 'wechat-article-importer' ),
				'parse_article_failed'          => __( 'Failed to parse the article.', 'wechat-article-importer' ),
				'parse_server_error'            => __( 'A server error occurred while parsing the article.', 'wechat-article-importer' ),
				'download_and_process_image'    => __( 'Download and process image', 'wechat-article-importer' ),
				'quick_download_image'          => __( 'Fast image download', 'wechat-article-importer' ),
				/* translators: 1: Image processing status, 2: Progress count, for example "1 / 5". */
				'progress_button_label'         => __( '%1$s (%2$s)', 'wechat-article-importer' ),
				/* translators: 1: Image processing status, 2: Progress count, for example "1 / 5". */
				'step_2_message'                => __( 'Step 2/3: %1$s %2$s. Please keep this page open...', 'wechat-article-importer' ),
				'image_download_server_error'   => __( 'A server error occurred while downloading this image.', 'wechat-article-importer' ),
				'creating_post'                 => __( 'Creating post...', 'wechat-article-importer' ),
				'step_3_message'                => __( 'Step 3/3: Images are processed. Finalizing and creating the post...', 'wechat-article-importer' ),
				/* translators: %s: Linked "View or edit it here" text. */
				'imported_as_draft_template'    => __( 'The article was imported as a draft. %s', 'wechat-article-importer' ),
				/* translators: %s: Number of skipped images. */
				'imported_with_skipped_images_template' => __( 'The article was imported as a draft, but %s images could not be downloaded.', 'wechat-article-importer' ),
				'skipped_image_urls_heading'    => __( 'Skipped image URLs', 'wechat-article-importer' ),
				'view_or_edit'                  => __( 'View or edit it here', 'wechat-article-importer' ),
				'create_post_failed'            => __( 'Failed to create the post.', 'wechat-article-importer' ),
				'create_post_server_error'      => __( 'A server error occurred while creating the post.', 'wechat-article-importer' ),
				/* translators: %s: Import error message. */
				'import_failed_message'         => __( 'Import failed: %s', 'wechat-article-importer' ),
				/* translators: %s: Server debug details. */
				'server_debug_info_message'     => __( 'Server debug information: %s', 'wechat-article-importer' ),
				/* translators: %s: Partial raw server response text. */
				'partial_server_response'       => __( 'Partial server response: %s', 'wechat-article-importer' ),
				'start_import'                  => __( 'Start Import', 'wechat-article-importer' ),
			),
		)
	);
}

add_filter( 'tiny_mce_before_init', 'wai_extend_tinymce_for_imported_layouts' );
add_action( 'add_attachment', 'wai_update_attachment_content_md5_meta' );
add_filter( 'wp_update_attachment_metadata', 'wai_update_attachment_content_md5_meta_on_metadata_update', 10, 2 );

/**
 * Keeps imported posts stable in the Classic Editor.
 *
 * The importer removes WeChat-only/custom elements before storage, so this does not whitelist
 * the original WeChat source garbage. It only makes normal HTML5 layout tags predictable when
 * an imported article is opened and saved in the visual editor.
 *
 * A scoped body class is added for imported posts as an integration hook, but the importer does
 * not force a theme-specific editor width. Spacing stability is handled by the stored HTML itself.
 *
 * @param array<string, mixed> $init TinyMCE init settings.
 * @return array<string, mixed>
 */
function wai_extend_tinymce_for_imported_layouts( $init ) {
	if ( ! wai_is_imported_post_for_editor() ) {
		return $init;
	}

	$elements = array(
		'div[style|class]',
		'section[style|class]',
		'span[style|class]',
		'p[style|class]',
		'a[href|target|rel|title|style|class]',
		'img[src|alt|title|style|class|width|height|loading|decoding]',
		'ul[style|class]',
		'ol[style|class]',
		'li[style|class]',
		'blockquote[style|class]',
		'figure[style|class]',
		'figcaption[style|class]',
		'h1[style|class]',
		'h2[style|class]',
		'h3[style|class]',
		'h4[style|class]',
		'h5[style|class]',
		'h6[style|class]',
		'strong[style|class]',
		'em[style|class]',
	);

	$existing = isset( $init['extended_valid_elements'] ) ? trim( (string) $init['extended_valid_elements'] ) : '';
	$init['extended_valid_elements'] = $existing ? $existing . ',' . implode( ',', $elements ) : implode( ',', $elements );

	$init['body_class'] = wai_append_class_name( $init['body_class'] ?? '', WAI_EDITOR_BODY_CLASS );

	return $init;
}

/**
 * @return bool
 */
function wai_is_imported_post_for_editor() {
	if ( function_exists( 'is_admin' ) && ! is_admin() ) {
		return false;
	}

	$post_id = wai_get_current_admin_post_id();
	if ( ! $post_id || ! function_exists( 'get_post_meta' ) ) {
		return false;
	}

	return '' !== (string) get_post_meta( $post_id, WAI_CONTENT_HASH_META, true );
}

/**
 * @return int
 */
function wai_get_current_admin_post_id() {
	if ( isset( $_GET['post'] ) ) {
		return absint( wp_unslash( $_GET['post'] ) );
	}
	if ( isset( $_POST['post_ID'] ) ) {
		return absint( wp_unslash( $_POST['post_ID'] ) );
	}

	global $post;
	if ( is_object( $post ) && isset( $post->ID ) ) {
		return absint( $post->ID );
	}

	foreach ( array( 'post_ID', 'post_id' ) as $global_key ) {
		if ( isset( $GLOBALS[ $global_key ] ) ) {
			return absint( $GLOBALS[ $global_key ] );
		}
	}

	if ( function_exists( 'get_the_ID' ) ) {
		return absint( get_the_ID() );
	}

	return 0;
}

/**
 * @param string $class_list Existing class list.
 * @param string $class_name Class to add.
 * @return string
 */
function wai_append_class_name( $class_list, $class_name ) {
	$classes = preg_split( '/\s+/', trim( (string) $class_list ) );
	$classes = array_filter( is_array( $classes ) ? $classes : array() );
	if ( ! in_array( $class_name, $classes, true ) ) {
		$classes[] = $class_name;
	}
	return implode( ' ', $classes );
}

/**
 * @param string   $url       URL to parse.
 * @param int|null $component Optional parse_url component.
 * @return mixed
 */
function wai_parse_url( $url, $component = null ) {
	if ( function_exists( 'wp_parse_url' ) ) {
		return null === $component ? wp_parse_url( $url ) : wp_parse_url( $url, $component );
	}
	return null === $component ? parse_url( $url ) : parse_url( $url, $component );
}

/**
 * @param string $host Hostname.
 * @return string
 */
function wai_normalize_host( $host ) {
	return strtolower( trim( (string) $host, ". \t\n\r\0\x0B" ) );
}

/**
 * @param string $haystack String to test.
 * @param string $needle   Prefix.
 * @return bool
 */
function wai_str_starts_with( $haystack, $needle ) {
	$haystack = (string) $haystack;
	$needle   = (string) $needle;
	return '' === $needle || 0 === strpos( $haystack, $needle );
}

/**
 * @param string $haystack String to test.
 * @param string $needle   Suffix.
 * @return bool
 */
function wai_str_ends_with( $haystack, $needle ) {
	$haystack = (string) $haystack;
	$needle   = (string) $needle;
	if ( '' === $needle ) {
		return true;
	}
	return substr( $haystack, -strlen( $needle ) ) === $needle;
}

/**
 * @param string $host   Hostname.
 * @param string $suffix Allowed suffix.
 * @return bool
 */
function wai_host_matches_suffix( $host, $suffix ) {
	$host   = wai_normalize_host( $host );
	$suffix = wai_normalize_host( $suffix );
	return $host === $suffix || wai_str_ends_with( $host, '.' . $suffix );
}

/**
 * Restrict server-side article fetches to WeChat's public article host.
 *
 * @param string $url Article URL.
 * @return bool
 */
function wai_is_allowed_article_url( $url ) {
	$url   = wai_normalize_url_attribute( $url );
	$parts = $url ? wai_parse_url( $url ) : false;
	if ( ! is_array( $parts ) ) {
		return false;
	}

	$scheme = strtolower( $parts['scheme'] ?? '' );
	$host   = wai_normalize_host( $parts['host'] ?? '' );
	$path   = (string) ( $parts['path'] ?? '' );

	return 'https' === $scheme
		&& 'mp.weixin.qq.com' === $host
		&& ( '/s' === $path || wai_str_starts_with( $path, '/s/' ) || wai_str_starts_with( $path, '/mp/' ) );
}

/**
 * Restrict server-side image fetches to WeChat media CDNs.
 *
 * @param string $url Image URL.
 * @return bool
 */
function wai_is_allowed_image_url( $url ) {
	$url   = wai_normalize_url_attribute( $url );
	$parts = $url ? wai_parse_url( $url ) : false;
	if ( ! is_array( $parts ) ) {
		return false;
	}

	$scheme = strtolower( $parts['scheme'] ?? '' );
	$host   = wai_normalize_host( $parts['host'] ?? '' );
	if ( 'https' !== $scheme || '' === $host ) {
		return false;
	}

	foreach ( array( 'qpic.cn', 'qlogo.cn' ) as $allowed_suffix ) {
		if ( wai_host_matches_suffix( $host, $allowed_suffix ) ) {
			return true;
		}
	}

	return false;
}

/**
 * @param string $url URL.
 * @return bool
 */
function wai_is_local_upload_url( $url ) {
	$parts = wai_parse_url( $url );
	if ( ! is_array( $parts ) ) {
		return false;
	}

	$path = (string) ( $parts['path'] ?? '' );
	return false !== strpos( $path, '/wp-content/uploads/' );
}

function wai_importer_page_html() {
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'WeChat Article Importer', 'wechat-article-importer' ); ?></h1>
		<p><?php esc_html_e( 'Paste a WeChat Official Account article link to fetch the title, content, cover image, and inline images automatically, then save the result as a WordPress draft.', 'wechat-article-importer' ); ?></p>
		<p class="description"><?php esc_html_e( 'Imported content is converted into WordPress-stable HTML: layout-critical inline styles are preserved, while WeChat editor markers, empty attributes, and unstable placeholder elements are removed.', 'wechat-article-importer' ); ?></p>

		<form id="wai-importer-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<table class="form-table">
				<tbody>
					<tr>
						<th scope="row"><label for="wechat_url"><?php esc_html_e( 'Article URL', 'wechat-article-importer' ); ?></label></th>
						<td><input type="url" id="wechat_url" name="wechat_url" style="width:100%; max-width: 600px;" placeholder="<?php esc_attr_e( 'Paste the full https://mp.weixin.qq.com/... link', 'wechat-article-importer' ); ?>" required></td>
					</tr>
					<tr>
						<th scope="row"><label for="wai_gen_thumbs"><?php esc_html_e( 'Import options', 'wechat-article-importer' ); ?></label></th>
						<td>
							<fieldset>
								<label for="wai_gen_thumbs">
									<input type="checkbox" id="wai_gen_thumbs" name="wai_gen_thumbs" checked>
									<span><?php esc_html_e( 'Generate thumbnails for imported images', 'wechat-article-importer' ); ?></span>
								</label>
								<p class="description"><?php esc_html_e( 'Recommended. Disable this only if your server is slow or fails while importing articles with very large images.', 'wechat-article-importer' ); ?></p>
							</fieldset>
						</td>
					</tr>
				</tbody>
			</table>
			<?php submit_button( __( 'Start Import', 'wechat-article-importer' ), 'primary', 'submit', true, array( 'class' => 'wai-submit-button' ) ); ?>
		</form>

		<div id="wai-feedback" style="margin-top: 20px;"></div>
	</div>
	<?php
}

add_action( 'wp_ajax_wai_start_import', 'wai_handle_ajax_start_import' );
function wai_handle_ajax_start_import() {
	check_ajax_referer( 'wai_ajax_nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'error' => __( 'Insufficient permissions.', 'wechat-article-importer' ) ) );
	}
	$url = isset( $_POST['wechat_url'] ) ? esc_url_raw( wp_unslash( $_POST['wechat_url'] ) ) : '';
	if ( empty( $url ) ) {
		wp_send_json_error( array( 'error' => __( 'Article URL cannot be empty.', 'wechat-article-importer' ) ) );
	}
	if ( ! wai_is_allowed_article_url( $url ) ) {
		wp_send_json_error( array( 'error' => __( 'Only WeChat Official Account article links from https://mp.weixin.qq.com/ are supported.', 'wechat-article-importer' ) ) );
	}

	$cookie_jar_path = wai_create_cookie_jar_path();
	$html            = wai_fetch_remote_url( $url, $cookie_jar_path );

	if ( is_wp_error( $html ) ) {
		wp_delete_file( $cookie_jar_path );
		wp_send_json_error( array( 'error' => $html->get_error_message() ) );
	}

	$article = wai_parse_wechat_article_html( $html );
	if ( empty( $article['content_html'] ) ) {
		wp_delete_file( $cookie_jar_path );
		wp_send_json_error( array( 'error' => __( 'Could not parse the article content. The link may be invalid or the WeChat page structure may have changed.', 'wechat-article-importer' ) ) );
	}

	$task_id   = 'wai_' . md5( uniqid( 'task_', true ) );
	$task_data = array(
		'source_url'        => $url,
		'title'             => $article['title'],
		'content_html'      => $article['content_html'],
		'post_date_gmt'     => $article['post_date_gmt'],
		'thumbnail_url'     => $article['thumbnail_url'],
		'cookie_jar_path'   => $cookie_jar_path,
		'image_urls'        => $article['image_urls'],
		'processed_images'  => array(),
	);
	set_transient( $task_id, $task_data, HOUR_IN_SECONDS );

	wp_send_json_success(
		array(
			'task_id'    => $task_id,
			'image_urls' => $article['image_urls'],
			'message'    => __( 'Article parsed successfully. Preparing to download images...', 'wechat-article-importer' ),
		)
	);
}

add_action( 'wp_ajax_wai_process_image', 'wai_handle_ajax_process_image' );
function wai_handle_ajax_process_image() {
	check_ajax_referer( 'wai_ajax_nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'error' => __( 'Insufficient permissions.', 'wechat-article-importer' ) ) );
	}
	$task_id   = isset( $_POST['task_id'] ) ? sanitize_key( $_POST['task_id'] ) : '';
	$image_url = isset( $_POST['image_url'] ) ? esc_url_raw( wp_unslash( $_POST['image_url'] ) ) : '';

	$generate_thumbnails = isset( $_POST['generate_thumbnails'] ) && 'true' === $_POST['generate_thumbnails'];

	if ( empty( $task_id ) || empty( $image_url ) ) {
		wp_send_json_error( array( 'error' => __( 'Task ID or image URL is invalid.', 'wechat-article-importer' ) ) );
	}

	$task_data = get_transient( $task_id );
	if ( false === $task_data ) {
		wp_send_json_error( array( 'error' => __( 'The task has expired or does not exist.', 'wechat-article-importer' ) ) );
	}
	$allowed_image_urls = isset( $task_data['image_urls'] ) && is_array( $task_data['image_urls'] ) ? array_map( 'wai_normalize_url_attribute', $task_data['image_urls'] ) : array();
	if ( ! wai_is_allowed_image_url( $image_url ) || ! in_array( $image_url, $allowed_image_urls, true ) ) {
		wp_send_json_error( array( 'error' => __( 'The image URL does not belong to the current import task or is not from an allowed WeChat image domain.', 'wechat-article-importer' ) ) );
	}

	require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';

	$attachment_id = wai_sideload_image( $image_url, 0, $task_data['cookie_jar_path'], null, $generate_thumbnails );

	if ( is_wp_error( $attachment_id ) ) {
		wp_send_json_error(
			array(
				/* translators: %s: Image download error message. */
				'error'     => sprintf( __( 'Image download failed: %s', 'wechat-article-importer' ), $attachment_id->get_error_message() ),
				'image_url' => $image_url,
			)
		);
	}

	$local_url = wp_get_attachment_url( $attachment_id );

	$task_data['processed_images'][ $image_url ] = $local_url;
	set_transient( $task_id, $task_data, HOUR_IN_SECONDS );

	wp_send_json_success(
		array(
			'original_url' => $image_url,
			'local_url'    => $local_url,
		)
	);
}

add_action( 'wp_ajax_wai_finish_import', 'wai_handle_ajax_finish_import' );
function wai_handle_ajax_finish_import() {
	check_ajax_referer( 'wai_ajax_nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'error' => __( 'Insufficient permissions.', 'wechat-article-importer' ) ) );
	}
	$task_id = isset( $_POST['task_id'] ) ? sanitize_key( $_POST['task_id'] ) : '';
	if ( empty( $task_id ) ) {
		wp_send_json_error( array( 'error' => __( 'Task ID is invalid.', 'wechat-article-importer' ) ) );
	}

	$task_data = get_transient( $task_id );
	if ( false === $task_data ) {
		wp_send_json_error( array( 'error' => __( 'The task has expired or does not exist.', 'wechat-article-importer' ) ) );
	}

	$post_id = wai_insert_imported_post( $task_data );

	if ( is_wp_error( $post_id ) ) {
		if ( ! empty( $task_data['cookie_jar_path'] ) ) {
			wp_delete_file( $task_data['cookie_jar_path'] );
		}
		delete_transient( $task_id );
		/* translators: %s: Post creation error message. */
		wp_send_json_error( array( 'error' => sprintf( __( 'Failed to create the post: %s', 'wechat-article-importer' ), $post_id->get_error_message() ) ) );
	}

	if ( ! empty( $task_data['cookie_jar_path'] ) ) {
		wp_delete_file( $task_data['cookie_jar_path'] );
	}
	delete_transient( $task_id );

	wp_send_json_success(
		array(
			'post_id'   => $post_id,
			'edit_link' => get_edit_post_link( $post_id, 'raw' ),
		)
	);
}

/**
 * Imports one article in a single request. Intended for WP-CLI/testing and small articles.
 *
 * @param string              $url  WeChat article URL.
 * @param array<string,mixed> $args Options: generate_thumbnails, post_status, post_author.
 * @return int|WP_Error Post ID or error.
 */
function wai_import_wechat_article( $url, $args = array() ) {
	$url = esc_url_raw( $url );
	if ( empty( $url ) ) {
		return new WP_Error( 'wai_no_url', __( 'Article URL cannot be empty.', 'wechat-article-importer' ) );
	}
	if ( ! wai_is_allowed_article_url( $url ) ) {
		return new WP_Error( 'wai_invalid_article_url', __( 'Only WeChat Official Account article links from https://mp.weixin.qq.com/ are supported.', 'wechat-article-importer' ) );
	}

	$cookie_jar_path = wai_create_cookie_jar_path();
	$html            = wai_fetch_remote_url( $url, $cookie_jar_path );
	if ( is_wp_error( $html ) ) {
		wp_delete_file( $cookie_jar_path );
		return $html;
	}

	$article = wai_parse_wechat_article_html( $html );
	if ( empty( $article['content_html'] ) ) {
		wp_delete_file( $cookie_jar_path );
		return new WP_Error( 'wai_parse_failed', __( 'Could not parse the article content. The link may be invalid or the WeChat page structure may have changed.', 'wechat-article-importer' ) );
	}

	$processed_images    = array();
	$generate_thumbnails = isset( $args['generate_thumbnails'] ) ? (bool) $args['generate_thumbnails'] : true;

	require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';

	foreach ( $article['image_urls'] as $image_url ) {
		$attachment_id = wai_sideload_image( $image_url, 0, $cookie_jar_path, null, $generate_thumbnails );
		if ( is_wp_error( $attachment_id ) ) {
			continue;
		}
		$local_url = wp_get_attachment_url( $attachment_id );
		if ( $local_url ) {
			$processed_images[ $image_url ] = $local_url;
		}
	}

	$task_data = array(
		'source_url'       => $url,
		'title'            => $article['title'],
		'content_html'     => $article['content_html'],
		'post_date_gmt'    => $article['post_date_gmt'],
		'thumbnail_url'    => $article['thumbnail_url'],
		'processed_images' => $processed_images,
		'post_status'      => isset( $args['post_status'] ) ? sanitize_key( (string) $args['post_status'] ) : 'draft',
		'post_author'      => isset( $args['post_author'] ) ? (int) $args['post_author'] : get_current_user_id(),
	);

	$post_id = wai_insert_imported_post( $task_data );
	wp_delete_file( $cookie_jar_path );

	return $post_id;
}

/**
 * @return string
 */
function wai_create_cookie_jar_path() {
	return get_temp_dir() . 'wai_cookies_' . wp_generate_password( 12, false ) . '.txt';
}

/**
 * @param string $url             URL to fetch.
 * @param string $cookie_jar_path Cookie jar path.
 * @return string|WP_Error
 */
function wai_fetch_remote_url( $url, $cookie_jar_path ) {
	$url = wai_normalize_url_attribute( $url );
	if ( ! wai_is_allowed_article_url( $url ) ) {
		return new WP_Error( 'wai_invalid_article_url', __( 'Only WeChat Official Account article links from https://mp.weixin.qq.com/ are supported.', 'wechat-article-importer' ) );
	}

	$ch = curl_init();
	$options = array(
		CURLOPT_URL            => $url,
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_TIMEOUT        => 30,
		CURLOPT_CONNECTTIMEOUT => 10,
		CURLOPT_COOKIEJAR      => $cookie_jar_path,
		CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/108.0.0.0 Safari/537.36',
		CURLOPT_SSL_VERIFYPEER => true,
		CURLOPT_SSL_VERIFYHOST => 2,
		CURLOPT_FOLLOWLOCATION => false,
	);
	if ( defined( 'CURLOPT_PROTOCOLS' ) && defined( 'CURLPROTO_HTTPS' ) ) {
		$options[ CURLOPT_PROTOCOLS ] = CURLPROTO_HTTPS;
	}
	if ( defined( 'CURLOPT_REDIR_PROTOCOLS' ) && defined( 'CURLPROTO_HTTPS' ) ) {
		$options[ CURLOPT_REDIR_PROTOCOLS ] = CURLPROTO_HTTPS;
	}
	if ( defined( 'CURLOPT_LOW_SPEED_LIMIT' ) && defined( 'CURLOPT_LOW_SPEED_TIME' ) ) {
		$options[ CURLOPT_LOW_SPEED_LIMIT ] = 1024;
		$options[ CURLOPT_LOW_SPEED_TIME ]  = 20;
	}
	curl_setopt_array( $ch, $options );
	$html      = curl_exec( $ch );
	$http_code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
	$error     = curl_error( $ch );
	curl_close( $ch );

	if ( empty( $html ) || ( $http_code && $http_code >= 400 ) ) {
		$message = $error ? $error : sprintf( 'HTTP Code: %s', $http_code );
		/* translators: %s: HTTP or cURL error message. */
		return new WP_Error( 'wai_fetch_failed', sprintf( __( 'Could not fetch article content. The link may be invalid or the server may have a network problem. %s', 'wechat-article-importer' ), $message ) );
	}

	return $html;
}

/**
 * @param string $html Full WeChat page HTML.
 * @return array<string,mixed>
 */
function wai_parse_wechat_article_html( $html ) {
	$doc   = wai_load_html_document( $html );
	$xpath = new DOMXPath( $doc );

	$title = '';
	foreach ( array( '//*[@id="activity-name"]', '//*[contains(concat(" ", normalize-space(@class), " "), " rich_media_title ")]' ) as $query ) {
		$title_node = $xpath->query( $query )->item( 0 );
		if ( $title_node ) {
			$title = trim( wp_strip_all_tags( $title_node->textContent ) );
			if ( '' !== $title ) {
				break;
			}
		}
	}
	if ( empty( $title ) ) {
		/* translators: %s: Current date and time. */
		$title = sprintf( __( 'Untitled - %s', 'wechat-article-importer' ), current_time( 'mysql' ) );
	}

	$content_html = '';
	foreach ( array( '//*[@id="js_content"]', '//*[contains(concat(" ", normalize-space(@class), " "), " rich_media_content ")]' ) as $query ) {
		$content_node = $xpath->query( $query )->item( 0 );
		if ( $content_node ) {
			$content_html = wai_get_node_inner_html( $content_node );
			break;
		}
	}

	$thumbnail_url  = '';
	$thumbnail_node = $xpath->query( '//meta[translate(@property, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz") = "og:image"]/@content' )->item( 0 );
	if ( $thumbnail_node ) {
		$thumbnail_url = wai_normalize_url_attribute( $thumbnail_node->nodeValue );
	}

	$post_date_gmt = '';
	if ( preg_match( '/var\s+ct\s*=\s*"(\d+)"/', $html, $time_matches ) ) {
		$publish_timestamp = (int) $time_matches[1];
		$post_date_gmt     = gmdate( 'Y-m-d H:i:s', $publish_timestamp );
	}

	return array(
		'title'         => $title,
		'content_html'  => $content_html,
		'post_date_gmt' => $post_date_gmt,
		'thumbnail_url' => $thumbnail_url,
		'image_urls'    => wai_collect_image_urls( $content_html, $thumbnail_url ),
	);
}

/**
 * @param string $content_html  Article body fragment.
 * @param string $thumbnail_url Featured image URL.
 * @return string[]
 */
function wai_collect_image_urls( $content_html, $thumbnail_url = '' ) {
	$image_urls = array();
	if ( ! empty( $content_html ) ) {
		$doc = wai_load_html_fragment( $content_html );
		$xpath = new DOMXPath( $doc );

		$imgs = $doc->getElementsByTagName( 'img' );
		foreach ( iterator_to_array( $imgs ) as $img ) {
			$img_url = $img->getAttribute( 'data-src' ) ?: $img->getAttribute( 'src' );
			$img_url = wai_normalize_url_attribute( $img_url );
			if ( $img_url && wai_is_allowed_image_url( $img_url ) ) {
				$image_urls[] = $img_url;
			}
		}

		$elements_with_bg = $xpath->query( '//*[contains(@style, "background")]' );
		foreach ( $elements_with_bg as $element ) {
			foreach ( wai_extract_background_urls( $element->getAttribute( 'style' ) ) as $bg_img_url ) {
				if ( wai_is_allowed_image_url( $bg_img_url ) ) {
					$image_urls[] = $bg_img_url;
				}
			}
		}
	}

	if ( $thumbnail_url && wai_is_allowed_image_url( $thumbnail_url ) ) {
		$image_urls[] = $thumbnail_url;
	}

	$image_urls = array_filter( array_map( 'wai_normalize_url_attribute', $image_urls ) );
	return array_values( array_unique( $image_urls ) );
}

/**
 * @param string $content_html     Article body fragment.
 * @param array<string,string> $processed_images Original URL => local URL map.
 * @return string
 */
function wai_prepare_import_content( $content_html, $processed_images = array() ) {
	if ( '' === trim( $content_html ) ) {
		return '';
	}

	$doc   = wai_load_html_fragment( $content_html );
	$xpath = new DOMXPath( $doc );

	wai_replace_processed_image_urls( $doc, $xpath, $processed_images );
	wai_move_block_anchor_links_to_images( $doc );
	wai_remove_wechat_only_elements( $doc, $xpath );
	wai_replace_placeholder_svgs( $doc );
	wai_clean_dom_tree( $doc );
	wai_unwrap_redundant_spans( $doc );
	wai_normalize_blank_blocks( $doc );

	$content = wai_get_body_inner_html( $doc );
	if ( ! empty( $processed_images ) ) {
		$content = str_replace( array_keys( $processed_images ), array_values( $processed_images ), $content );
	}

	return trim( $content );
}

/**
 * @param array<string,mixed> $task_data Parsed task data.
 * @return int|WP_Error
 */
function wai_insert_imported_post( $task_data ) {
	$content = wai_prepare_import_content( $task_data['content_html'] ?? '', $task_data['processed_images'] ?? array() );

	$post_data = array(
		'post_title'   => $task_data['title'] ?? '',
		'post_content' => $content,
		'post_status'  => ! empty( $task_data['post_status'] ) ? sanitize_key( (string) $task_data['post_status'] ) : 'draft',
		'post_author'  => ! empty( $task_data['post_author'] ) ? (int) $task_data['post_author'] : get_current_user_id(),
	);
	if ( ! empty( $task_data['post_date_gmt'] ) ) {
		$post_data['post_date_gmt'] = $task_data['post_date_gmt'];
		$post_data['post_date']     = get_date_from_gmt( $task_data['post_date_gmt'] );
	}
	$post_id = wp_insert_post( $post_data, true );

	if ( is_wp_error( $post_id ) ) {
		return $post_id;
	}

	if ( ! empty( $task_data['source_url'] ) ) {
		update_post_meta( $post_id, WAI_SOURCE_URL_META, esc_url_raw( $task_data['source_url'] ) );
	}
	update_post_meta( $post_id, WAI_CONTENT_HASH_META, md5( $content ) );

	$thumbnail_url    = $task_data['thumbnail_url'] ?? '';
	$processed_images = $task_data['processed_images'] ?? array();
	if ( ! empty( $thumbnail_url ) && isset( $processed_images[ $thumbnail_url ] ) ) {
		$thumb_local_url = $processed_images[ $thumbnail_url ];
		$attachment_id   = attachment_url_to_postid( $thumb_local_url );
		if ( $attachment_id ) {
			set_post_thumbnail( $post_id, $attachment_id );
		}
	}

	return $post_id;
}

/**
 * @param string $html Full HTML document.
 * @return DOMDocument
 */
function wai_load_html_document( $html ) {
	libxml_use_internal_errors( true );
	$doc = new DOMDocument();
	$doc->loadHTML( '<?xml encoding="utf-8" ?>' . $html );
	libxml_clear_errors();
	return $doc;
}

/**
 * @param string $html HTML fragment.
 * @return DOMDocument
 */
function wai_load_html_fragment( $html ) {
	libxml_use_internal_errors( true );
	$doc = new DOMDocument();
	$doc->loadHTML( '<?xml encoding="utf-8" ?><!DOCTYPE html><html><body>' . $html . '</body></html>', LIBXML_HTML_NODEFDTD | LIBXML_HTML_NOIMPLIED );
	libxml_clear_errors();
	return $doc;
}

/**
 * @param DOMNode $node Node.
 * @return string
 */
function wai_get_node_inner_html( DOMNode $node ) {
	$doc     = $node instanceof DOMDocument ? $node : $node->ownerDocument;
	$content = '';
	if ( ! $doc ) {
		return $content;
	}
	foreach ( $node->childNodes as $child_node ) {
		$content .= $doc->saveHTML( $child_node );
	}
	return $content;
}

/**
 * @param DOMDocument $doc Document.
 * @return string
 */
function wai_get_body_inner_html( DOMDocument $doc ) {
	$body_node = $doc->getElementsByTagName( 'body' )->item( 0 );
	$content   = '';
	if ( $body_node ) {
		foreach ( $body_node->childNodes as $childNode ) {
			$content .= $doc->saveHTML( $childNode );
		}
	}
	return $content;
}

/**
 * @param DOMDocument $doc Document.
 * @param DOMXPath    $xpath XPath.
 * @param array<string,string> $processed_images URL map.
 * @return void
 */
function wai_replace_processed_image_urls( DOMDocument $doc, DOMXPath $xpath, $processed_images ) {
	$imgs = $doc->getElementsByTagName( 'img' );
	foreach ( iterator_to_array( $imgs ) as $img ) {
		$src_url     = wai_normalize_url_attribute( $img->getAttribute( 'src' ) );
		$datasrc_url = wai_normalize_url_attribute( $img->getAttribute( 'data-src' ) );

		if ( ! empty( $datasrc_url ) && isset( $processed_images[ $datasrc_url ] ) ) {
			$img->setAttribute( 'src', $processed_images[ $datasrc_url ] );
		} elseif ( ! empty( $src_url ) && isset( $processed_images[ $src_url ] ) ) {
			$img->setAttribute( 'src', $processed_images[ $src_url ] );
		}
	}

	$elements_with_bg = $xpath->query( '//*[contains(@style, "background")]' );
	foreach ( $elements_with_bg as $element ) {
		$style = $element->getAttribute( 'style' );
		foreach ( wai_extract_background_urls( $style ) as $bg_img_url ) {
			if ( isset( $processed_images[ $bg_img_url ] ) ) {
				$style = str_replace( $bg_img_url, $processed_images[ $bg_img_url ], $style );
			}
		}
		$element->setAttribute( 'style', $style );
	}
}

/**
 * @param string $style Inline style.
 * @return string[]
 */
function wai_extract_background_urls( $style ) {
	$urls = array();
	if ( preg_match_all( '/background(?:-image)?\s*:\s*[^;]*url\(([^)]*)\)/i', $style, $matches ) ) {
		foreach ( $matches[1] as $raw_url ) {
			$url = trim( $raw_url, " \t\n\r\0\x0B'\"" );
			$url = wai_normalize_url_attribute( $url );
			if ( $url ) {
				$urls[] = $url;
			}
		}
	}
	return $urls;
}

/**
 * Moves invalid block-wrapping anchors down to their images before TinyMCE can discard them.
 *
 * @param DOMDocument $doc Document.
 * @return void
 */
function wai_move_block_anchor_links_to_images( DOMDocument $doc ) {
	$anchors = iterator_to_array( $doc->getElementsByTagName( 'a' ) );
	$block_tags = array( 'address', 'article', 'aside', 'blockquote', 'div', 'figure', 'figcaption', 'footer', 'header', 'main', 'nav', 'ol', 'p', 'section', 'table', 'ul' );

	foreach ( $anchors as $anchor ) {
		$has_block_child = false;
		foreach ( $block_tags as $tag ) {
			if ( $anchor->getElementsByTagName( $tag )->length > 0 ) {
				$has_block_child = true;
				break;
			}
		}
		if ( ! $has_block_child ) {
			continue;
		}

		$imgs = iterator_to_array( $anchor->getElementsByTagName( 'img' ) );
		if ( empty( $imgs ) ) {
			continue;
		}

		foreach ( $imgs as $img ) {
			if ( 'a' === strtolower( $img->parentNode->nodeName ) ) {
				continue;
			}
			$new_anchor = $doc->createElement( 'a' );
			foreach ( array( 'href', 'target', 'rel', 'title', 'style', 'class' ) as $attr ) {
				if ( $anchor->hasAttribute( $attr ) ) {
					$new_anchor->setAttribute( $attr, $anchor->getAttribute( $attr ) );
				}
			}
			$img->parentNode->insertBefore( $new_anchor, $img );
			$new_anchor->appendChild( $img );
		}
		wai_unwrap_element( $anchor );
	}
}

/**
 * @param DOMDocument $doc Document.
 * @param DOMXPath    $xpath XPath.
 * @return void
 */
function wai_remove_wechat_only_elements( DOMDocument $doc, DOMXPath $xpath ) {
	foreach ( array( 'script', 'style', 'iframe', 'mp-common-profile', 'mp-style-type' ) as $tag_name ) {
		$nodes = iterator_to_array( $doc->getElementsByTagName( $tag_name ) );
		foreach ( $nodes as $node ) {
			if ( $node->parentNode ) {
				$node->parentNode->removeChild( $node );
			}
		}
	}

	$hidden_nodes = $xpath->query( '//*[contains(translate(@style, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "display: none")]' );
	foreach ( iterator_to_array( $hidden_nodes ) as $node ) {
		if ( $node->parentNode && '' === trim( $node->textContent ) ) {
			$node->parentNode->removeChild( $node );
		}
	}
}

/**
 * @param DOMDocument $doc Document.
 * @return void
 */
function wai_replace_placeholder_svgs( DOMDocument $doc ) {
	$svgs = iterator_to_array( $doc->getElementsByTagName( 'svg' ) );
	foreach ( $svgs as $svg ) {
		if ( ! wai_is_placeholder_svg( $svg ) ) {
			continue;
		}
		$placeholder = $doc->createElement( 'span', html_entity_decode( '&nbsp;', ENT_QUOTES, 'UTF-8' ) );
		$placeholder->setAttribute( 'style', 'display:inline-block;width:0;height:0;overflow:hidden;line-height:0;vertical-align:top;' );
		$svg->parentNode->replaceChild( $placeholder, $svg );
	}
}

/**
 * @param DOMElement $svg SVG node.
 * @return bool
 */
function wai_is_placeholder_svg( DOMElement $svg ) {
	if ( '' !== trim( $svg->textContent ) ) {
		return false;
	}
	foreach ( array( 'path', 'circle', 'rect', 'line', 'polygon', 'polyline', 'use', 'image' ) as $visible_tag ) {
		if ( $svg->getElementsByTagName( $visible_tag )->length > 0 ) {
			return false;
		}
	}
	return true;
}

/**
 * @param DOMDocument $doc Document.
 * @return void
 */
function wai_clean_dom_tree( DOMDocument $doc ) {
	$xpath = new DOMXPath( $doc );
	$nodes = $xpath->query( '//*' );
	foreach ( iterator_to_array( $nodes ) as $node ) {
		wai_clean_element_attributes( $node );
	}
}

/**
 * @param DOMElement $element Element.
 * @return void
 */
function wai_clean_element_attributes( DOMElement $element ) {
	$allowed_attrs = array(
		'a'          => array( 'href', 'target', 'rel', 'title', 'style', 'class' ),
		'div'        => array( 'style', 'class' ),
		'img'        => array( 'src', 'alt', 'title', 'style', 'class', 'width', 'height', 'loading', 'decoding' ),
		'section'    => array( 'style', 'class' ),
		'p'          => array( 'style', 'class' ),
		'span'       => array( 'style', 'class' ),
		'h1'         => array( 'style', 'class' ),
		'h2'         => array( 'style', 'class' ),
		'h3'         => array( 'style', 'class' ),
		'h4'         => array( 'style', 'class' ),
		'h5'         => array( 'style', 'class' ),
		'h6'         => array( 'style', 'class' ),
		'blockquote' => array( 'style', 'class' ),
		'figure'     => array( 'style', 'class' ),
		'figcaption' => array( 'style', 'class' ),
		'strong'     => array( 'style', 'class' ),
		'em'         => array( 'style', 'class' ),
		'ul'         => array( 'style', 'class' ),
		'ol'         => array( 'style', 'class' ),
		'li'         => array( 'style', 'class' ),
		'br'         => array(),
	);

	$tag = strtolower( $element->tagName );
	if ( ! isset( $allowed_attrs[ $tag ] ) ) {
		if ( ! in_array( $tag, array( 'html', 'body' ), true ) ) {
			wai_unwrap_element( $element );
		}
		return;
	}
	$allowed_for_tag = $allowed_attrs[ $tag ];

	foreach ( iterator_to_array( $element->attributes ) as $attr ) {
		$name  = strtolower( $attr->name );
		$value = trim( $attr->value );

		if ( '' === $value ) {
			$element->removeAttribute( $attr->name );
			continue;
		}

		if ( 0 === strpos( $name, 'data-' ) || in_array( $name, array( 'leaf', 'nodeleaf', 'linktype', 'formlinkparm', 'align', 'border', 'hspace', 'vspace', 'ismap', 'opacity', 'sizes', 'type', 'usemap', 'aria-label', 'aria-braillelabel', 'aria-description' ), true ) ) {
			$element->removeAttribute( $attr->name );
			continue;
		}

		if ( ! in_array( $name, $allowed_for_tag, true ) ) {
			$element->removeAttribute( $attr->name );
			continue;
		}

		if ( 'style' === $name ) {
			$clean_style = wai_clean_style_attribute( $value );
			if ( '' === $clean_style ) {
				$element->removeAttribute( $attr->name );
			} else {
				$element->setAttribute( 'style', $clean_style );
			}
			continue;
		}

		if ( 'class' === $name ) {
			$clean_class = wai_clean_class_attribute( $value );
			if ( '' === $clean_class ) {
				$element->removeAttribute( $attr->name );
			} else {
				$element->setAttribute( 'class', $clean_class );
			}
			continue;
		}

		if ( in_array( $name, array( 'href', 'src' ), true ) ) {
			$clean_url = wai_normalize_url_attribute( $value );
			if ( '' === $clean_url ) {
				$element->removeAttribute( $attr->name );
			} else {
				$element->setAttribute( $attr->name, $clean_url );
			}
			continue;
		}

		if ( in_array( $name, array( 'width', 'height' ), true ) && ! preg_match( '/^\d{1,5}$/', $value ) ) {
			$element->removeAttribute( $attr->name );
			continue;
		}

		if ( 'target' === $name && ! in_array( strtolower( $value ), array( '_blank', '_self', '_parent', '_top' ), true ) ) {
			$element->removeAttribute( $attr->name );
			continue;
		}

		if ( 'loading' === $name && ! in_array( strtolower( $value ), array( 'lazy', 'eager' ), true ) ) {
			$element->removeAttribute( $attr->name );
			continue;
		}

		if ( 'decoding' === $name && ! in_array( strtolower( $value ), array( 'async', 'sync', 'auto' ), true ) ) {
			$element->removeAttribute( $attr->name );
			continue;
		}
	}

	if ( 'a' === $tag && $element->hasAttribute( 'target' ) && '_blank' === strtolower( $element->getAttribute( 'target' ) ) ) {
		$rel = preg_split( '/\s+/', strtolower( $element->getAttribute( 'rel' ) ) );
		$rel = array_filter( array_unique( array_merge( $rel, array( 'noopener', 'noreferrer' ) ) ) );
		$element->setAttribute( 'rel', implode( ' ', $rel ) );
	}
}

/**
 * @param string $style Inline CSS.
 * @return string
 */
function wai_clean_style_attribute( $style ) {
	$declarations = array();
	foreach ( explode( ';', (string) $style ) as $declaration ) {
		$declaration = trim( preg_replace( '/\s+/', ' ', $declaration ) );
		if ( '' === $declaration || false === strpos( $declaration, ':' ) ) {
			continue;
		}
		list( $property, $value ) = array_map( 'trim', explode( ':', $declaration, 2 ) );
		if ( '' === $property || '' === $value ) {
			continue;
		}
		$property = strtolower( $property );
		if ( ! wai_is_allowed_style_property( $property ) ) {
			continue;
		}

		$value = wai_clean_style_value( $value );
		if ( '' === $value ) {
			continue;
		}

		if ( function_exists( 'safecss_filter_attr' ) ) {
			$wp_filtered = trim( (string) safecss_filter_attr( $property . ':' . $value ) );
			if ( '' === $wp_filtered && wai_style_value_has_url( $value ) ) {
				continue;
			}
		}

		$declarations[] = $property . ':' . $value;
	}
	return implode( ';', $declarations );
}

/**
 * @param string $property CSS property.
 * @return bool
 */
function wai_is_allowed_style_property( $property ) {
	$allowed = array(
		'align-items',
		'align-self',
		'background',
		'background-clip',
		'background-color',
		'background-image',
		'background-position',
		'background-position-x',
		'background-position-y',
		'background-repeat',
		'background-size',
		'border',
		'border-bottom',
		'border-bottom-color',
		'border-bottom-left-radius',
		'border-bottom-right-radius',
		'border-bottom-style',
		'border-bottom-width',
		'border-color',
		'border-left',
		'border-left-color',
		'border-left-style',
		'border-left-width',
		'border-radius',
		'border-right',
		'border-right-color',
		'border-right-style',
		'border-right-width',
		'border-style',
		'border-top',
		'border-top-color',
		'border-top-left-radius',
		'border-top-right-radius',
		'border-top-style',
		'border-top-width',
		'border-width',
		'box-sizing',
		'clear',
		'color',
		'display',
		'flex',
		'flex-basis',
		'flex-direction',
		'flex-flow',
		'flex-grow',
		'flex-shrink',
		'flex-wrap',
		'float',
		'font',
		'font-family',
		'font-size',
		'font-style',
		'font-weight',
		'height',
		'justify-content',
		'letter-spacing',
		'line-height',
		'margin',
		'margin-bottom',
		'margin-left',
		'margin-right',
		'margin-top',
		'max-height',
		'max-width',
		'min-height',
		'min-width',
		'object-fit',
		'object-position',
		'opacity',
		'overflow',
		'overflow-wrap',
		'overflow-x',
		'overflow-y',
		'padding',
		'padding-bottom',
		'padding-left',
		'padding-right',
		'padding-top',
		'text-align',
		'text-decoration',
		'text-indent',
		'transform',
		'transform-origin',
		'vertical-align',
		'white-space',
		'width',
		'word-break',
		'-moz-transform',
		'-ms-transform',
		'-o-transform',
		'-webkit-background-clip',
		'-webkit-text-fill-color',
		'-webkit-transform',
		'-webkit-transform-origin',
	);

	return in_array( $property, $allowed, true );
}

/**
 * @param string $value CSS value.
 * @return string
 */
function wai_clean_style_value( $value ) {
	$value = trim( preg_replace( '/\s+/', ' ', (string) $value ) );
	if ( '' === $value ) {
		return '';
	}

	$compact = preg_replace( '/[\x00-\x20]+/', '', strtolower( $value ) );
	if ( preg_match( '/(?:expression|behavior|-moz-binding|binding|javascript|vbscript|data)\s*[:(]/i', $compact ) || false !== strpos( $value, '<' ) || false !== strpos( $value, '>' ) ) {
		return '';
	}

	if ( preg_match_all( '/url\(([^)]*)\)/i', $value, $matches ) ) {
		foreach ( $matches[1] as $index => $raw_url ) {
			$clean_url = wai_normalize_url_attribute( trim( $raw_url, " \t\n\r\0\x0B'\"" ) );
			if ( '' === $clean_url ) {
				return '';
			}
			if ( ! wai_is_allowed_image_url( $clean_url ) && ! wai_is_local_upload_url( $clean_url ) ) {
				return '';
			}
			$value = str_replace( $matches[0][ $index ], 'url(' . $clean_url . ')', $value );
		}
	}

	return $value;
}

/**
 * @param string $value CSS value.
 * @return bool
 */
function wai_style_value_has_url( $value ) {
	return (bool) preg_match( '/url\s*\(/i', (string) $value );
}

/**
 * @param string $class Class attribute.
 * @return string
 */
function wai_clean_class_attribute( $class ) {
	$classes = preg_split( '/\s+/', trim( $class ) );
	$classes = array_filter(
		$classes,
		static function ( $class_name ) {
			return ! in_array( $class_name, array( 'rich_pages', 'wxw-img', 'js_insertlocalimg' ), true );
		}
	);
	return implode( ' ', array_unique( $classes ) );
}

/**
 * @param DOMDocument $doc Document.
 * @return void
 */
function wai_normalize_blank_blocks( DOMDocument $doc ) {
	foreach ( array( 'p', 'section' ) as $tag_name ) {
		$nodes = iterator_to_array( $doc->getElementsByTagName( $tag_name ) );
		foreach ( $nodes as $node ) {
			if ( ! wai_is_visually_empty_element( $node ) ) {
				continue;
			}
			while ( $node->firstChild ) {
				$node->removeChild( $node->firstChild );
			}
			wai_append_blank_block_marker( $doc, $node );
		}
	}
}

/**
 * Adds a tiny real child to visual spacer blocks so WordPress editors do not discard them.
 *
 * WeChat layouts frequently use blank paragraphs as intentional vertical rhythm. A bare
 * non-breaking space can be treated as an empty paragraph by editor preprocessing, so store
 * a styled inline marker instead. Paragraph spacers keep a line box; non-paragraph visual
 * blocks get a zero-size marker so their explicit height/background/border styles remain in
 * control.
 *
 * @param DOMDocument $doc Document.
 * @param DOMElement  $element Element to mark.
 * @return void
 */
function wai_append_blank_block_marker( DOMDocument $doc, DOMElement $element ) {
	$marker = $doc->createElement( 'span', html_entity_decode( '&nbsp;', ENT_QUOTES, 'UTF-8' ) );
	if ( 'p' === strtolower( $element->tagName ) ) {
		$marker->setAttribute( 'style', 'display:inline-block;width:0;height:0;line-height:0;vertical-align:baseline;overflow:hidden;' );
	} else {
		$marker->setAttribute( 'style', 'display:inline-block;width:0;height:0;overflow:hidden;line-height:0;vertical-align:top;' );
	}
	$element->appendChild( $marker );
}

/**
 * @param DOMElement $element Element.
 * @return bool
 */
function wai_is_visually_empty_element( DOMElement $element ) {
	$text = trim( str_replace( html_entity_decode( '&nbsp;', ENT_QUOTES, 'UTF-8' ), '', $element->textContent ) );
	if ( '' !== $text ) {
		return false;
	}
	foreach ( array( 'img', 'a', 'strong', 'em', 'ul', 'ol', 'li' ) as $tag_name ) {
		if ( $element->getElementsByTagName( $tag_name )->length > 0 ) {
			return false;
		}
	}

	foreach ( $element->childNodes as $child ) {
		if ( XML_ELEMENT_NODE !== $child->nodeType ) {
			continue;
		}
		$child_tag = strtolower( $child->nodeName );
		if ( 'br' === $child_tag ) {
			continue;
		}
		if ( 'span' === $child_tag && wai_is_blank_block_marker_span( $child ) ) {
			continue;
		}
		if ( 'span' === $child_tag && '' === trim( str_replace( html_entity_decode( '&nbsp;', ENT_QUOTES, 'UTF-8' ), '', $child->textContent ) ) && ! $child->hasAttributes() ) {
			continue;
		}
			return false;
	}

	if ( 'p' === strtolower( $element->tagName ) ) {
		return true;
	}
	$style = strtolower( $element->getAttribute( 'style' ) );
	return (bool) preg_match( '/(?:height|background|border|margin|padding)\s*:/', $style );
}

/**
 * @param DOMElement $span Span element.
 * @return bool
 */
function wai_is_blank_block_marker_span( DOMElement $span ) {
	if ( 'span' !== strtolower( $span->tagName ) ) {
		return false;
	}
	$text = trim(
		str_replace(
			array( html_entity_decode( '&nbsp;', ENT_QUOTES, 'UTF-8' ), html_entity_decode( '&#8203;', ENT_QUOTES, 'UTF-8' ) ),
			'',
			$span->textContent
		)
	);
	if ( '' !== $text ) {
		return false;
	}

	$style = strtolower( preg_replace( '/\s+/', '', $span->getAttribute( 'style' ) ) );
	return false !== strpos( $style, 'display:inline-block' )
		&& false !== strpos( $style, 'width:0' )
		&& false !== strpos( $style, 'overflow:hidden' )
		&& ( false !== strpos( $style, 'height:0' ) || false !== strpos( $style, 'min-height:1em' ) );
}

/**
 * @param DOMDocument $doc Document.
 * @return void
 */
function wai_unwrap_redundant_spans( DOMDocument $doc ) {
	$changed = true;
	while ( $changed ) {
		$changed = false;
		$spans = iterator_to_array( $doc->getElementsByTagName( 'span' ) );
		foreach ( $spans as $span ) {
			if ( $span->attributes->length > 0 ) {
				continue;
			}
			wai_unwrap_element( $span );
			$changed = true;
		}
	}
}

/**
 * @param DOMElement $element Element.
 * @return void
 */
function wai_unwrap_element( DOMElement $element ) {
	$parent = $element->parentNode;
	if ( ! $parent ) {
		return;
	}
	while ( $element->firstChild ) {
		$parent->insertBefore( $element->firstChild, $element );
	}
	$parent->removeChild( $element );
}

/**
 * @param string $url URL attribute.
 * @return string
 */
function wai_normalize_url_attribute( $url ) {
	$url = html_entity_decode( trim( (string) $url ), ENT_QUOTES, 'UTF-8' );
	if ( '' === $url ) {
		return '';
	}

	$protocol_probe = preg_replace( '/[\x00-\x20]+/', '', $url );
	if ( preg_match( '/^([a-z][a-z0-9+.-]*):/i', $protocol_probe, $matches ) ) {
		$scheme = strtolower( $matches[1] );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return '';
		}
	}

	if ( function_exists( 'wp_kses_bad_protocol' ) ) {
		$url = wp_kses_bad_protocol( $url, array( 'http', 'https' ) );
		if ( '' === $url ) {
			return '';
		}
	}

	if ( function_exists( 'esc_url_raw' ) ) {
		return esc_url_raw( $url, array( 'http', 'https' ) );
	}

	return filter_var( $url, FILTER_SANITIZE_URL );
}

/**
 * @param string $image_data Raw image bytes.
 * @return string
 */
function wai_detect_image_mime_type( $image_data ) {
	$probe = ltrim( substr( (string) $image_data, 0, 512 ) );
	if ( preg_match( '/^(?:<\?xml\s|<svg[\s>])/i', $probe ) ) {
		return 'image/svg+xml';
	}

	if ( function_exists( 'finfo_open' ) ) {
		$finfo = finfo_open( FILEINFO_MIME_TYPE );
		if ( $finfo ) {
			$mime_type = finfo_buffer( $finfo, $image_data );
			if ( PHP_VERSION_ID < 80500 ) {
				finfo_close( $finfo );
			}
			if ( is_string( $mime_type ) && '' !== $mime_type ) {
				return strtolower( $mime_type );
			}
		}
	}

	if ( function_exists( 'getimagesizefromstring' ) ) {
		$size = getimagesizefromstring( $image_data );
		if ( is_array( $size ) && ! empty( $size['mime'] ) ) {
			return strtolower( $size['mime'] );
		}
	}

	return '';
}

/**
 * @param string $mime_type MIME type.
 * @return string
 */
function wai_extension_for_allowed_image_mime( $mime_type ) {
	$mime_map = array(
		'image/jpeg' => '.jpg',
		'image/png'  => '.png',
		'image/gif'  => '.gif',
		'image/webp' => '.webp',
	);
	return $mime_map[ strtolower( (string) $mime_type ) ] ?? '';
}

/**
 * @param string $filename Candidate filename.
 * @return string
 */
function wai_sanitize_file_name( $filename ) {
	if ( function_exists( 'sanitize_file_name' ) ) {
		return sanitize_file_name( $filename );
	}
	$filename = preg_replace( '/[^A-Za-z0-9_.-]+/', '-', (string) $filename );
	return trim( $filename, '.-' );
}

function wai_sideload_image( $image_url, $post_id, $cookie_jar_path, $desc = null, $generate_thumbnails = false ) {
	$image_url = wai_normalize_url_attribute( $image_url );
	if ( empty( $image_url ) ) {
		return new WP_Error( 'no_url', __( 'Image URL cannot be empty.', 'wechat-article-importer' ) );
	}
	if ( ! wai_is_allowed_image_url( $image_url ) ) {
		return new WP_Error( 'invalid_image_url', __( 'The image URL is not from an allowed WeChat image domain.', 'wechat-article-importer' ) );
	}

	static $sideload_cache = array();
	$source_url_md5 = md5( $image_url );

	if ( isset( $sideload_cache[ $source_url_md5 ] ) ) {
		return $sideload_cache[ $source_url_md5 ];
	}

	$ch      = curl_init();
	$options = array(
		CURLOPT_URL            => $image_url,
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_HEADER         => false,
		CURLOPT_TIMEOUT        => 60,
		CURLOPT_CONNECTTIMEOUT => 10,
		CURLOPT_SSL_VERIFYPEER => true,
		CURLOPT_SSL_VERIFYHOST => 2,
		CURLOPT_FOLLOWLOCATION => false,
		CURLOPT_REFERER        => 'https://mp.weixin.qq.com/',
		CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/108.0.0.0 Safari/537.36',
		CURLOPT_COOKIEFILE     => $cookie_jar_path,
	);
	if ( defined( 'CURLOPT_PROTOCOLS' ) && defined( 'CURLPROTO_HTTPS' ) ) {
		$options[ CURLOPT_PROTOCOLS ] = CURLPROTO_HTTPS;
	}
	if ( defined( 'CURLOPT_REDIR_PROTOCOLS' ) && defined( 'CURLPROTO_HTTPS' ) ) {
		$options[ CURLOPT_REDIR_PROTOCOLS ] = CURLPROTO_HTTPS;
	}
	if ( defined( 'CURLOPT_MAXFILESIZE' ) ) {
		$options[ CURLOPT_MAXFILESIZE ] = WAI_MAX_IMAGE_BYTES;
	}
	if ( defined( 'CURLOPT_LOW_SPEED_LIMIT' ) && defined( 'CURLOPT_LOW_SPEED_TIME' ) ) {
		$options[ CURLOPT_LOW_SPEED_LIMIT ] = 1024;
		$options[ CURLOPT_LOW_SPEED_TIME ]  = 20;
	}
	curl_setopt_array( $ch, $options );
	$image_data = curl_exec( $ch );
	$http_code  = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
	$error      = curl_error( $ch );
	curl_close( $ch );

	if ( 200 !== $http_code || empty( $image_data ) ) {
		$message = $error ? $error : sprintf( 'HTTP Code: %s', $http_code );
		/* translators: %s: HTTP or cURL error message. */
		return new WP_Error( 'download_failed', sprintf( __( 'cURL image download failed. %s', 'wechat-article-importer' ), $message ) );
	}
	if ( strlen( $image_data ) > WAI_MAX_IMAGE_BYTES ) {
		return new WP_Error( 'image_too_large', __( 'The image file exceeds the allowed size.', 'wechat-article-importer' ) );
	}

	$attachment_id = wai_create_imported_attachment_from_image_data( $image_url, $image_data, $post_id, $desc, $generate_thumbnails );
	if ( is_wp_error( $attachment_id ) ) {
		return $attachment_id;
	}

	$sideload_cache[ $source_url_md5 ] = $attachment_id;

	return $attachment_id;
}

/**
 * Creates a WordPress attachment from already downloaded importer image bytes.
 *
 * @param string      $image_url           Source WeChat image URL.
 * @param string      $image_data          Raw image bytes.
 * @param int         $post_id             Parent post ID.
 * @param string|null $desc                Attachment title override.
 * @param bool        $generate_thumbnails Whether to generate attachment metadata.
 * @return int|WP_Error
 */
function wai_create_imported_attachment_from_image_data( $image_url, $image_data, $post_id, $desc = null, $generate_thumbnails = false ) {
	$image_url = wai_normalize_url_attribute( $image_url );
	if ( empty( $image_url ) ) {
		return new WP_Error( 'no_url', __( 'Image URL cannot be empty.', 'wechat-article-importer' ) );
	}
	if ( ! wai_is_allowed_image_url( $image_url ) ) {
		return new WP_Error( 'invalid_image_url', __( 'The image URL is not from an allowed WeChat image domain.', 'wechat-article-importer' ) );
	}
	if ( strlen( $image_data ) > WAI_MAX_IMAGE_BYTES ) {
		return new WP_Error( 'image_too_large', __( 'The image file exceeds the allowed size.', 'wechat-article-importer' ) );
	}

	$source_url_md5 = md5( $image_url );
	$mime_type      = wai_detect_image_mime_type( $image_data );
	$extension      = wai_extension_for_allowed_image_mime( $mime_type );
	if ( '' === $extension ) {
		return new WP_Error( 'unsupported_image_type', __( 'The image format is unsupported or cannot be verified.', 'wechat-article-importer' ) );
	}

	$content_md5            = md5( $image_data );
	$existing_attachment_id = wai_find_attachment_by_content_md5( $content_md5 );
	if ( $existing_attachment_id ) {
		wai_record_attachment_content_md5( $existing_attachment_id, $content_md5 );
		return $existing_attachment_id;
	}

	$path          = wai_parse_url( $image_url, PHP_URL_PATH );
	$filename_base = $path ? pathinfo( basename( $path ), PATHINFO_FILENAME ) : 'image';
	$filename_base = '' !== $filename_base ? $filename_base : 'image';
	$unique_part   = substr( $source_url_md5, 0, 8 );
	$filename      = wai_sanitize_file_name( $filename_base . '-' . $unique_part . $extension );

	$upload_dir      = wp_upload_dir();
	$unique_filename = wp_unique_filename( $upload_dir['path'], $filename );
	$filepath        = $upload_dir['path'] . '/' . $unique_filename;

	if ( ! file_put_contents( $filepath, $image_data ) ) {
		return new WP_Error( 'write_failed', __( 'Could not write image data to the file.', 'wechat-article-importer' ) );
	}

	$filetype = wp_check_filetype( $unique_filename, null );
	if ( empty( $filetype['type'] ) || strtolower( $filetype['type'] ) !== $mime_type ) {
		wp_delete_file( $filepath );
		return new WP_Error( 'filetype_mismatch', __( 'The image extension does not match the detected file type.', 'wechat-article-importer' ) );
	}

	$attachment    = array(
		'post_mime_type' => $mime_type,
		'post_title'     => $desc ?: preg_replace( '/\.[^.]+$/', '', $unique_filename ),
		'post_content'   => '',
		'post_status'    => 'inherit',
	);
	$attachment_id = wp_insert_attachment( $attachment, $filepath, $post_id );

	if ( is_wp_error( $attachment_id ) ) {
		wp_delete_file( $filepath );
		return $attachment_id;
	}

	wai_record_attachment_content_md5( $attachment_id, $content_md5 );
	wai_record_imported_attachment_meta( $attachment_id, $image_url );

	if ( $generate_thumbnails ) {
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$attachment_data = wp_generate_attachment_metadata( $attachment_id, $filepath );
		wp_update_attachment_metadata( $attachment_id, $attachment_data );
	}

	return $attachment_id;
}

/**
 * @param string $content_md5 MD5 of attachment file bytes.
 * @return int
 */
function wai_find_attachment_by_content_md5( $content_md5 ) {
	$content_md5 = wai_normalize_md5_hash( $content_md5 );
	if ( '' === $content_md5 ) {
		return 0;
	}

	$found = get_posts(
		array(
			'fields'         => 'ids',
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => 1,
			'meta_key'       => WAI_ATTACHMENT_CONTENT_MD5_META,
			'meta_value'     => $content_md5,
		)
	);
	return ! empty( $found ) ? (int) $found[0] : 0;
}

/**
 * @param int    $attachment_id Attachment ID.
 * @param string $content_md5   MD5 of attachment file bytes.
 * @return bool
 */
function wai_record_attachment_content_md5( $attachment_id, $content_md5 ) {
	$attachment_id = (int) $attachment_id;
	$content_md5   = wai_normalize_md5_hash( $content_md5 );
	if ( $attachment_id <= 0 || '' === $content_md5 || ! function_exists( 'update_post_meta' ) ) {
		return false;
	}

	update_post_meta( $attachment_id, WAI_ATTACHMENT_CONTENT_MD5_META, $content_md5 );
	return true;
}

/**
 * Records markers that identify attachments newly uploaded by this importer.
 *
 * The site-wide content MD5 metadata can exist on manual uploads too, so these
 * importer-specific keys are only written after this plugin creates a new
 * attachment. Reused existing attachments are intentionally not marked here.
 *
 * @param int    $attachment_id    Attachment ID.
 * @param string $source_image_url Source WeChat image URL.
 * @return bool
 */
function wai_record_imported_attachment_meta( $attachment_id, $source_image_url ) {
	$attachment_id    = (int) $attachment_id;
	$source_image_url = wai_normalize_url_attribute( $source_image_url );

	if ( $attachment_id <= 0 || '' === $source_image_url || ! wai_is_allowed_image_url( $source_image_url ) || ! function_exists( 'update_post_meta' ) ) {
		return false;
	}

	update_post_meta( $attachment_id, WAI_IMPORTED_ATTACHMENT_META, '1' );
	update_post_meta( $attachment_id, WAI_SOURCE_IMAGE_URL_META, $source_image_url );
	return true;
}

/**
 * Ensures a WordPress attachment has the canonical site-wide content MD5 metadata.
 *
 * @param int $attachment_id Attachment ID.
 * @return string Stored MD5 hash, or an empty string when the file cannot be hashed.
 */
function wai_update_attachment_content_md5_meta( $attachment_id ) {
	$attachment_id = (int) $attachment_id;
	if ( $attachment_id <= 0 || ! function_exists( 'get_attached_file' ) ) {
		return '';
	}

	$filepath = get_attached_file( $attachment_id );
	if ( ! is_string( $filepath ) || '' === $filepath || ! is_file( $filepath ) || ! is_readable( $filepath ) ) {
		return '';
	}

	$content_md5 = hash_file( 'md5', $filepath );
	if ( ! is_string( $content_md5 ) ) {
		return '';
	}

	$content_md5 = wai_normalize_md5_hash( $content_md5 );
	if ( '' === $content_md5 ) {
		return '';
	}

	wai_record_attachment_content_md5( $attachment_id, $content_md5 );
	return $content_md5;
}

/**
 * @param mixed $metadata      Attachment metadata.
 * @param int   $attachment_id Attachment ID.
 * @return mixed
 */
function wai_update_attachment_content_md5_meta_on_metadata_update( $metadata, $attachment_id ) {
	wai_update_attachment_content_md5_meta( $attachment_id );
	return $metadata;
}

/**
 * @param string $content_md5 Candidate MD5 hash.
 * @return string Lowercase MD5 hash, or an empty string when invalid.
 */
function wai_normalize_md5_hash( $content_md5 ) {
	$content_md5 = strtolower( trim( (string) $content_md5 ) );
	return preg_match( '/^[a-f0-9]{32}$/', $content_md5 ) ? $content_md5 : '';
}
