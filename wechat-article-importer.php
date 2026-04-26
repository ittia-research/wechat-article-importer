<?php
/**
 * Plugin Name:       WeChat Article Importer
 * Description:       Import WeChat Official Account articles into WordPress drafts, including content, featured images, and inline images.
 * Version:           0.1.0
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

add_action( 'admin_menu', 'wai_add_admin_menu' );
function wai_add_admin_menu() {
	add_menu_page( 'WeChat Article Importer', 'WeChat Article Importer', 'manage_options', 'wechat-article-importer', 'wai_importer_page_html' );
}

add_action( 'admin_enqueue_scripts', 'wai_enqueue_admin_scripts' );
function wai_enqueue_admin_scripts( $hook ) {
	if ( 'toplevel_page_wechat-article-importer' !== $hook ) {
		return;
	}
	$version = '0.1.0';
	wp_enqueue_script( 'wai-importer-js', plugin_dir_url( __FILE__ ) . 'js/importer.js', array( 'jquery' ), $version, true );
	wp_localize_script(
		'wai-importer-js',
		'wai_ajax',
		array(
			'ajax_url' => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( 'wai_ajax_nonce' ),
		)
	);
}

function wai_importer_page_html() {
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'WeChat Article Importer', 'wechat-article-importer' ); ?></h1>
		<p><?php esc_html_e( '粘贴微信公众号文章链接，自动抓取标题、内容、封面图及文章内图片，并保存为 WordPress 草稿。', 'wechat-article-importer' ); ?></p>

		<form id="wai-importer-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<table class="form-table">
				<tbody>
					<tr>
						<th scope="row"><label for="wechat_url"><?php esc_html_e( '文章链接', 'wechat-article-importer' ); ?></label></th>
						<td><input type="url" id="wechat_url" name="wechat_url" style="width:100%; max-width: 600px;" placeholder="<?php esc_attr_e( '请粘贴完整的 https://mp.weixin.qq.com/... 链接', 'wechat-article-importer' ); ?>" required></td>
					</tr>
					<tr>
						<th scope="row"><label for="wai_gen_thumbs"><?php esc_html_e( '导入选项', 'wechat-article-importer' ); ?></label></th>
						<td>
							<fieldset>
								<label for="wai_gen_thumbs">
									<input type="checkbox" id="wai_gen_thumbs" name="wai_gen_thumbs" checked>
									<span><?php esc_html_e( '为导入的图片生成缩略图', 'wechat-article-importer' ); ?></span>
								</label>
								<p class="description"><?php esc_html_e( '建议开启。如果您的服务器性能较差，或在导入超大图片的文章时卡死/失败，请取消勾选此项。', 'wechat-article-importer' ); ?></p>
							</fieldset>
						</td>
					</tr>
				</tbody>
			</table>
			<?php submit_button( __( '开始导入', 'wechat-article-importer' ), 'primary', 'submit', true, array( 'class' => 'wai-submit-button' ) ); ?>
		</form>

		<div id="wai-feedback" style="margin-top: 20px;"></div>
	</div>
	<?php
}

add_action( 'wp_ajax_wai_start_import', 'wai_handle_ajax_start_import' );
function wai_handle_ajax_start_import() {
	check_ajax_referer( 'wai_ajax_nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'error' => '权限不足。' ) );
	}
	$url = isset( $_POST['wechat_url'] ) ? esc_url_raw( wp_unslash( $_POST['wechat_url'] ) ) : '';
	if ( empty( $url ) ) {
		wp_send_json_error( array( 'error' => '文章链接不能为空。' ) );
	}

	$cookie_jar_path = get_temp_dir() . 'wai_cookies_' . wp_generate_password( 12, false ) . '.txt';
	$ch              = curl_init();
	curl_setopt_array( $ch, array( CURLOPT_URL => $url, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_COOKIEJAR => $cookie_jar_path, CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/108.0.0.0 Safari/537.36', CURLOPT_SSL_VERIFYPEER => false ) );
	$html = curl_exec( $ch );
	curl_close( $ch );

	if ( empty( $html ) ) {
		wp_delete_file( $cookie_jar_path );
		wp_send_json_error( array( 'error' => '无法获取文章内容，可能链接已失效或服务器网络问题。' ) );
	}

	$title = '';
	if ( preg_match( '/<h1[^>]*?id="activity-name"[^>]*?>(.*?)<\/h1>/s', $html, $matches ) ) {
		$title = trim( strip_tags( $matches[1] ) );
	} elseif ( preg_match( '/<h1[^>]*?class="rich_media_title"[^>]*?>(.*?)<\/h1>/s', $html, $matches ) ) {
		$title = trim( strip_tags( $matches[1] ) );
	}
	if ( empty( $title ) ) {
		$title = __( '未命名标题 - ', 'wechat-article-importer' ) . current_time( 'mysql' );
	}

	preg_match( '/<div[^>]*class="rich_media_content[^"]*"[^>]*id="js_content"[^>]*>(.*?)<\/div>/s', $html, $content_matches );
	$content_html = $content_matches[1] ?? '';

	preg_match( '/<meta property="og:image" content="([^"]+)" \/>/', $html, $thumb_matches );
	$thumbnail_url = ! empty( $thumb_matches[1] ) ? esc_url_raw( $thumb_matches[1] ) : '';

	$post_date_gmt = '';
	if ( preg_match( '/var\s+ct\s*=\s*"(\d+)"/', $html, $time_matches ) ) {
		$publish_timestamp = (int) $time_matches[1];
		$post_date_gmt     = gmdate( 'Y-m-d H:i:s', $publish_timestamp );
	}

	$image_urls = array();
	if ( ! empty( $content_html ) ) {
		libxml_use_internal_errors( true );
		$doc = new DOMDocument();
		$doc->loadHTML( '<?xml encoding="utf-8" ?>' . $content_html );
		libxml_clear_errors();
		$xpath = new DOMXPath( $doc );

		$imgs = $doc->getElementsByTagName( 'img' );
		foreach ( iterator_to_array( $imgs ) as $img ) {
			$img_url = $img->getAttribute( 'data-src' ) ?: $img->getAttribute( 'src' );
			if ( $img_url ) {
				$image_urls[] = $img_url;
			}
		}

		$elements_with_bg = $xpath->query( '//*[contains(@style, "background")]' );
		foreach ( $elements_with_bg as $element ) {
			$style = $element->getAttribute( 'style' );
			if ( preg_match( '/background(?:-image)?\s*:\s*[^;]*url\((["\']?)(.*?)\1\)/', $style, $matches ) ) {
				$bg_img_url = $matches[2];
				if ( $bg_img_url ) {
					$image_urls[] = $bg_img_url;
				}
			}
		}
	}

	if ( $thumbnail_url ) {
		$image_urls[] = $thumbnail_url;
	}
	$image_urls = array_values( array_unique( $image_urls ) );

	$task_id      = 'wai_' . md5( uniqid( 'task_', true ) );
	$task_data = array(
		'title'           => $title,
		'content_html'    => $content_html,
		'post_date_gmt'   => $post_date_gmt,
		'thumbnail_url'   => $thumbnail_url,
		'cookie_jar_path' => $cookie_jar_path,
		'image_urls'      => $image_urls,
		'processed_images' => array(),
	);
	set_transient( $task_id, $task_data, HOUR_IN_SECONDS );

	wp_send_json_success(
		array(
			'task_id'    => $task_id,
			'image_urls' => $image_urls,
			'message'    => '文章解析成功，准备下载图片...',
		)
	);
}

add_action( 'wp_ajax_wai_process_image', 'wai_handle_ajax_process_image' );
function wai_handle_ajax_process_image() {
	check_ajax_referer( 'wai_ajax_nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'error' => '权限不足。' ) );
	}
	$task_id   = isset( $_POST['task_id'] ) ? sanitize_key( $_POST['task_id'] ) : '';
	$image_url = isset( $_POST['image_url'] ) ? esc_url_raw( wp_unslash( $_POST['image_url'] ) ) : '';

	$generate_thumbnails = isset( $_POST['generate_thumbnails'] ) && 'true' === $_POST['generate_thumbnails'];

	if ( empty( $task_id ) || empty( $image_url ) ) {
		wp_send_json_error( array( 'error' => '任务ID或图片URL无效。' ) );
	}

	$task_data = get_transient( $task_id );
	if ( false === $task_data ) {
		wp_send_json_error( array( 'error' => '任务已过期或不存在。' ) );
	}

	require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';

	$attachment_id = wai_sideload_image( $image_url, 0, $task_data['cookie_jar_path'], null, $generate_thumbnails );

	if ( is_wp_error( $attachment_id ) ) {
		wp_send_json_error(
			array(
				'error'     => '图片下载失败: ' . $attachment_id->get_error_message(),
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
		wp_send_json_error( array( 'error' => '权限不足。' ) );
	}
	$task_id = isset( $_POST['task_id'] ) ? sanitize_key( $_POST['task_id'] ) : '';
	if ( empty( $task_id ) ) {
		wp_send_json_error( array( 'error' => '任务ID无效。' ) );
	}

	$task_data = get_transient( $task_id );
	if ( false === $task_data ) {
		wp_send_json_error( array( 'error' => '任务已过期或不存在。' ) );
	}

	$content_html       = $task_data['content_html'];
	$processed_images = $task_data['processed_images'];

	if ( ! empty( $content_html ) && ! empty( $processed_images ) ) {
		libxml_use_internal_errors( true );
		$doc = new DOMDocument();
		$doc->loadHTML( '<?xml encoding="utf-8" ?>' . $content_html );
		libxml_clear_errors();
		$xpath = new DOMXPath( $doc );

		$imgs = $doc->getElementsByTagName( 'img' );
		foreach ( iterator_to_array( $imgs ) as $img ) {
			$src_url     = $img->getAttribute( 'src' );
			$datasrc_url = $img->getAttribute( 'data-src' );

			if ( ! empty( $src_url ) && isset( $processed_images[ $src_url ] ) ) {
				$img->setAttribute( 'src', $processed_images[ $src_url ] );
			}
			if ( ! empty( $datasrc_url ) && isset( $processed_images[ $datasrc_url ] ) ) {
				$img->setAttribute( 'src', $processed_images[ $datasrc_url ] );
				$img->removeAttribute( 'data-src' );
			}
		}

		$elements_with_bg = $xpath->query( '//*[contains(@style, "background")]' );
		foreach ( $elements_with_bg as $element ) {
			$style = $element->getAttribute( 'style' );
			if ( preg_match( '/background(?:-image)?\s*:\s*[^;]*url\((["\']?)(.*?)\1\)/', $style, $matches ) ) {
				$bg_img_url = $matches[2];
				if ( $bg_img_url && isset( $processed_images[ $bg_img_url ] ) ) {
					$new_style = str_replace( $bg_img_url, $processed_images[ $bg_img_url ], $style );
					$element->setAttribute( 'style', $new_style );
				}
			}
		}

		$body_node = $doc->getElementsByTagName( 'body' )->item( 0 );
		$content_html = '';
		if ( $body_node ) {
			foreach ( $body_node->childNodes as $childNode ) {
				$content_html .= $doc->saveHTML( $childNode );
			}
		}
	}

	if ( ! empty( $processed_images ) ) {
		$original_urls = array_keys( $processed_images );
		$local_urls    = array_values( $processed_images );
		$content_html  = str_replace( $original_urls, $local_urls, $content_html );
	}

	$content = $content_html;
	if ( ! empty( $content_html ) ) {
		libxml_use_internal_errors( true );
		$doc = new DOMDocument();
		$doc->loadHTML( '<?xml encoding="utf-8" ?>' . $content_html );
		libxml_clear_errors();
		$xpath = new DOMXPath( $doc );
		$attributes_to_remove = array( 'data-tools', 'data-tool', 'data-website' );
		foreach ( $attributes_to_remove as $attr ) {
			$elements_with_attr = $xpath->query( "//*[@{$attr}]" );
			foreach ( $elements_with_attr as $element ) {
				$element->removeAttribute( $attr );
			}
		}
		$body_node = $doc->getElementsByTagName( 'body' )->item( 0 );
		$content   = '';
		if ( $body_node ) {
			foreach ( $body_node->childNodes as $childNode ) {
				$content .= $doc->saveHTML( $childNode );
			}
		}
	}

	$post_data = array(
		'post_title'   => $task_data['title'],
		'post_content' => $content,
		'post_status'  => 'draft',
		'post_author'  => get_current_user_id(),
	);
	if ( ! empty( $task_data['post_date_gmt'] ) ) {
		$post_data['post_date_gmt'] = $task_data['post_date_gmt'];
		$post_data['post_date']     = get_date_from_gmt( $task_data['post_date_gmt'] );
	}
	$post_id = wp_insert_post( $post_data, true );

	if ( is_wp_error( $post_id ) ) {
		wp_send_json_error( array( 'error' => '创建文章失败：' . $post_id->get_error_message() ) );
	}

	$thumbnail_url = $task_data['thumbnail_url'];
	if ( ! empty( $thumbnail_url ) && isset( $processed_images[ $thumbnail_url ] ) ) {
		$thumb_local_url = $processed_images[ $thumbnail_url ];
		$attachment_id   = attachment_url_to_postid( $thumb_local_url );
		if ( $attachment_id ) {
			set_post_thumbnail( $post_id, $attachment_id );
		}
	}

	wp_delete_file( $task_data['cookie_jar_path'] );
	delete_transient( $task_id );

	wp_send_json_success(
		array(
			'post_id'   => $post_id,
			'edit_link' => get_edit_post_link( $post_id, 'raw' ),
		)
	);
}

function wai_sideload_image( $image_url, $post_id, $cookie_jar_path, $desc = null, $generate_thumbnails = false ) {
	if ( empty( $image_url ) ) { return new WP_Error( 'no_url', '图片链接为空。' ); }

	static $sideload_cache = array();
	$image_md5 = md5( $image_url );

	if ( isset( $sideload_cache[ $image_md5 ] ) ) {
		return $sideload_cache[ $image_md5 ];
	}

	$args = array(
		'post_type'      => 'attachment',
		'post_status'    => 'inherit',
		'posts_per_page' => 1,
		'meta_query'     => array(
			array(
				'key'     => '_wai_source_md5',
				'value'   => $image_md5,
				'compare' => '=',
			),
		),
	);
	$found_images = get_posts( $args );

	if ( ! empty( $found_images ) ) {
		$attachment_id = $found_images[0]->ID;
		$sideload_cache[ $image_md5 ] = $attachment_id;
		return $attachment_id;
	}

	$ch = curl_init();
	curl_setopt_array(
		$ch,
		array(
			CURLOPT_URL            => $image_url,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_HEADER         => false,
			CURLOPT_TIMEOUT        => 60,
			CURLOPT_SSL_VERIFYPEER => false,
			CURLOPT_REFERER        => 'https://mp.weixin.qq.com/',
			CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/108.0.0.0 Safari/537.36',
			CURLOPT_COOKIEFILE     => $cookie_jar_path,
		)
	);
	$image_data = curl_exec( $ch );
	$http_code  = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
	curl_close( $ch );

	if ( 200 !== $http_code || empty( $image_data ) ) {
		return new WP_Error( 'download_failed', sprintf( 'cURL 图片下载失败。 HTTP Code: %s', $http_code ) );
	}

	$filename = basename( wp_parse_url( $image_url, PHP_URL_PATH ) );
	parse_str( wp_parse_url( $image_url, PHP_URL_QUERY ), $query_params );
	$wx_fmt = $query_params['wx_fmt'] ?? '';

	if ( 'svg' === $wx_fmt ) {
		$extension = '.svg';
	} elseif ( empty( pathinfo( $filename, PATHINFO_EXTENSION ) ) ) {
		$ext_map   = array(
			'image/jpeg'    => '.jpg',
			'image/png'     => '.png',
			'image/gif'     => '.gif',
			'image/svg+xml' => '.svg',
			'image/webp'    => '.webp',
		);
		$extension = '.jpg';

		if ( function_exists( 'finfo_open' ) ) {
			$finfo     = finfo_open( FILEINFO_MIME_TYPE );
			$mime_type = finfo_buffer( $finfo, $image_data );
			finfo_close( $finfo );
			if ( isset( $ext_map[ $mime_type ] ) ) {
				$extension = $ext_map[ $mime_type ];
			}
		} else {
			$fmt_map = array(
				'jpeg' => '.jpg',
				'png'  => '.png',
				'gif'  => '.gif',
				'webp' => '.webp',
			);
			if ( ! empty( $wx_fmt ) && isset( $fmt_map[ $wx_fmt ] ) ) {
				$extension = $fmt_map[ $wx_fmt ];
			}
		}
	} else {

		$extension = '.' . pathinfo( $filename, PATHINFO_EXTENSION );
	}

	$unique_part = substr( md5( $image_url ), 0, 8 );
	$filename    = pathinfo( $filename, PATHINFO_FILENAME ) . '-' . $unique_part . $extension;

	$upload_dir      = wp_upload_dir();
	$unique_filename = wp_unique_filename( $upload_dir['path'], $filename );
	$filepath        = $upload_dir['path'] . '/' . $unique_filename;

	if ( ! file_put_contents( $filepath, $image_data ) ) {
		return new WP_Error( 'write_failed', __( '无法将图片数据写入文件。', 'wechat-article-importer' ) );
	}

	$filetype       = wp_check_filetype( $unique_filename, null );
	$post_mime_type = ! empty( $filetype['type'] ) ? $filetype['type'] : 'application/octet-stream';

	$attachment    = array(
		'post_mime_type' => $post_mime_type,
		'post_title'     => $desc ?: preg_replace( '/\.[^.]+$/', '', $unique_filename ),
		'post_content'   => '',
		'post_status'    => 'inherit',
	);
	$attachment_id = wp_insert_attachment( $attachment, $filepath, $post_id );

	if ( is_wp_error( $attachment_id ) ) {
		wp_delete_file( $filepath );
		return $attachment_id;
	}

	update_post_meta( $attachment_id, '_wai_source_md5', $image_md5 );

	if ( $generate_thumbnails ) {
		if ( 'image/svg+xml' !== $post_mime_type ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
			$attachment_data = wp_generate_attachment_metadata( $attachment_id, $filepath );
			wp_update_attachment_metadata( $attachment_id, $attachment_data );
		}
	}

	$sideload_cache[ $image_md5 ] = $attachment_id;

	return $attachment_id;
}
