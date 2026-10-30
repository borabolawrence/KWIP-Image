<?php
/**
 * Media Library integration: status column, attachment field, AJAX actions.
 *
 * @package kwip-image-plugin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class KWIP_Media {

	private const NONCE_ACTION = 'kwip_media';
	private const FILTER_VAR = 'kwip_filter';

	private static bool $assets_loaded = false;

	public static function init(): void {
		add_filter( 'manage_media_columns', array( __CLASS__, 'add_column' ) );
		add_action( 'manage_media_custom_column', array( __CLASS__, 'render_column' ), 10, 2 );
		add_filter( 'attachment_fields_to_edit', array( __CLASS__, 'add_field' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'maybe_enqueue' ) );
		add_action( 'wp_enqueue_media', array( __CLASS__, 'enqueue' ) );
		add_action( 'wp_ajax_kwip_media', array( __CLASS__, 'handle_ajax' ) );
		add_filter( 'views_upload', array( __CLASS__, 'add_view' ) );
		add_action( 'pre_get_posts', array( __CLASS__, 'filter_query' ) );
		add_action( 'admin_notices', array( __CLASS__, 'filter_notice' ) );
	}


	public static function add_column( array $columns ): array {
		if ( current_user_can( 'manage_options' ) ) {
			$columns['kwip_webp'] = __( 'WebP', 'kwip-image-plugin' );
		}
		return $columns;
	}

	public static function render_column( string $column_name, int $post_id ): void {
		if ( 'kwip_webp' !== $column_name || ! self::can_manage( $post_id ) ) {
			return;
		}
		if ( ! self::is_candidate( $post_id ) ) {
			echo '<span aria-hidden="true">&mdash;</span><span class="screen-reader-text">' . esc_html__( 'Not applicable', 'kwip-image-plugin' ) . '</span>';
			return;
		}
		echo self::render( $post_id );
	}

	public static function add_field( array $fields, WP_Post $post ): array {
		if ( self::can_manage( $post->ID ) && self::is_candidate( $post->ID ) ) {
			$fields['kwip_webp'] = array(
				'label' => __( 'WebP', 'kwip-image-plugin' ),
				'input' => 'html',
				'html'  => self::render( $post->ID ),
			);
		}
		return $fields;
	}

	public static function maybe_enqueue( string $hook ): void {
		if ( 'upload.php' === $hook ) {
			self::enqueue();
			return;
		}
		if ( in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			$screen = get_current_screen();
			if ( $screen && 'attachment' === $screen->post_type ) {
				self::enqueue();
			}
		}
	}
	
	public static function enqueue(): void {
		if ( self::$assets_loaded || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		self::$assets_loaded = true;

		KWIP_Admin::register_shared_assets();

		wp_enqueue_style( 'kwip-media', KWIP_URL . 'assets/css/media.css', array( 'kwip-modal' ), KWIP_VERSION );
		wp_enqueue_script( 'kwip-media', KWIP_URL . 'assets/js/media.js', array( 'jquery', 'wp-a11y', 'kwip-modal' ), KWIP_VERSION, true );
		wp_localize_script(
			'kwip-media',
			'kwipMedia',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE_ACTION ),
				'i18n'    => array(
					'working'        => __( 'Working…', 'kwip-image-plugin' ),
					'error'          => __( 'Something went wrong. Please try again.', 'kwip-image-plugin' ),
					'restoreTitle'   => __( 'Restore the original image?', 'kwip-image-plugin' ),
					'restoreMessage' => __( 'The WebP files for this image will be deleted, and visitors will receive the original again. The original image is not changed. You can convert it again at any time.', 'kwip-image-plugin' ),
					'restoreButton'  => __( 'Restore', 'kwip-image-plugin' ),
				),
			)
		);
	}

	public static function handle_ajax(): void {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$op = isset( $_POST['op'] ) ? sanitize_key( wp_unslash( $_POST['op'] ) ) : '';

		if ( ! $id || 'attachment' !== get_post_type( $id ) || ! self::can_manage( $id ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do that.', 'kwip-image-plugin' ) ), 403 );
		}

		if ( 'convert' === $op ) {
			$result = KWIP_Converter::convert_attachment( $id );
			if ( is_wp_error( $result ) ) {
				wp_send_json_error( array( 'message' => $result->get_error_message() ) );
			}
			$message = self::result_message( $result );
			KWIP_Queue::dequeue( $id );
		} elseif ( 'restore' === $op ) {
			$removed = KWIP_Converter::remove_attachment_webp( $id );
			if ( is_wp_error( $removed ) ) {
				wp_send_json_error( array( 'message' => $removed->get_error_message() ) );
			}
			KWIP_Queue::dequeue( $id );
			$message = sprintf(
				_n( 'Removed %d WebP file. The original images were not changed.', 'Removed %d WebP files. The original images were not changed.', $removed, 'kwip-image-plugin' ),
				$removed
			);
		} else {
			wp_send_json_error( array( 'message' => __( 'Unknown action.', 'kwip-image-plugin' ) ), 400 );
		}

		wp_send_json_success(
			array(
				'html'    => self::render( $id ),
				'message' => $message,
			)
		);
	}



	private static function is_optimized_view(): bool {
		return isset( $_GET[ self::FILTER_VAR ] ) && 'optimized' === sanitize_key( wp_unslash( $_GET[ self::FILTER_VAR ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Matches attachments whose stored record contains at least one file with
	 * status "converted". The record is a serialized array, so this looks for
	 * the serialized text of that status (per-file entries use the same text).
	 *
	 * @return array<string, string>
	 */
	public  static function optimized_meta_query(): array {
		return array(
			'key'     => KWIP_Converter::META_KEY,
			'value'   => 's:6:"status";s:9:"converted"',
			'compare' => 'LIKE',
		);
	}

	private static function optimized_count(): int {
		$query = new WP_Query(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'fields'         => 'ids',
				'posts_per_page' => 1,
				'meta_query'     => array( self::optimized_meta_query() ),
			)
		);
		return (int) $query->found_posts;
	}

	/**
	 * @param array<string, string> $views Existing view links.
	 * @return array<string, string>
	 */
	public static function add_view( array $views ): array {
		if ( ! current_user_can( 'manage_options' ) ) {
			return $views;
		}

		$current = self::is_optimized_view();

		if ( $current ) {
			foreach ( $views as $key => $html ) {
				$views[ $key ] = (string) preg_replace( '/\s+(class=["\']current["\']|aria-current=["\']page["\'])/', '', (string) $html );
			}
		}

		$url = add_query_arg(
			array(
				'mode'           => 'list',
				self::FILTER_VAR => 'optimized',
			),
			admin_url( 'upload.php' )
		);

		$views['kwip_optimized'] = sprintf(
			'<a href="%1$s"%2$s>%3$s <span class="count">(%4$s)</span></a>',
			esc_url( $url ),
			$current ? ' class="current" aria-current="page"' : '',
			esc_html__( 'KWIP Image Optimized Images', 'kwip-image-plugin' ),
			esc_html( number_format_i18n( self::optimized_count() ) )
		);

		return $views;
	}

	public static function filter_query( WP_Query $query ): void {
		global $pagenow;

		if ( ! is_admin() || 'upload.php' !== $pagenow || ! $query->is_main_query() ) {
			return;
		}
		if ( ! self::is_optimized_view() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$meta_query   = (array) $query->get( 'meta_query' );
		$meta_query[] = self::optimized_meta_query();
		$query->set( 'meta_query', $meta_query );
	}

	public static function filter_notice(): void {
		$screen = get_current_screen();
		if ( ! $screen || 'upload' !== $screen->id || ! self::is_optimized_view() ) {
			return;
		}
		printf(
			'<div class="notice notice-info"><p>%s</p></div>',
			esc_html__( 'Showing images that have at least one WebP file. In the WebP column, open Details to see or open each file.', 'kwip-image-plugin' )
		);
	}


	private static function can_manage( int $id ): bool {
		return current_user_can( 'manage_options' ) && current_user_can( 'edit_post', $id );
	}

	private static function is_candidate( int $id ): bool {
		return in_array( get_post_mime_type( $id ), array( 'image/jpeg', 'image/png' ), true );
	}

	/**
	 * @param array<string, mixed> $files Per-file results.
	 * @return array{total:int,converted:int}
	 */
	private static function counts( array $files ): array {
		$converted = 0;
		foreach ( $files as $file ) {
			if ( is_array( $file ) && 'converted' === ( $file['status'] ?? '' ) ) {
				++$converted;
			}
		}
		return array(
			'total'     => count( $files ),
			'converted' => $converted,
		);
	}

	/**
	 * @param array<string, mixed> $data Conversion record.
	 */
	private static function result_message( array $data ): string {
		$files  = ( isset( $data['files'] ) && is_array( $data['files'] ) ) ? $data['files'] : array();
		$counts = self::counts( $files );

		if ( $counts['converted'] > 0 ) {
			$original = (int) ( $data['original_total'] ?? 0 );
			$webp     = (int) ( $data['webp_total'] ?? 0 );
			$percent  = $original > 0 ? round( ( 1 - $webp / $original ) * 100, 1 ) : 0;
			return sprintf(
				__( 'Converted %1$d of %2$d files, %3$s%% smaller.', 'kwip-image-plugin' ),
				$counts['converted'],
				$counts['total'],
				$percent
			);
		}
		if ( 'failed' === ( $data['status'] ?? '' ) ) {
			return __( 'Conversion failed. Open the details to see why.', 'kwip-image-plugin' );
		}
		return __( 'No files were converted. Open the details to see why.', 'kwip-image-plugin' );
	}

	/**
	 * Build the status box. Everything is escaped here.
	 */
	public static function render( int $id ): string {
		$data = get_post_meta( $id, KWIP_Converter::META_KEY, true );
		$data = is_array( $data ) ? $data : array();

		$badges = array(
			'none'      => array( 'muted', __( 'Not converted', 'kwip-image-plugin' ) ),
			'queued'    => array( 'muted', __( 'Queued', 'kwip-image-plugin' ) ),
			'converted' => array( 'ok', __( 'Converted', 'kwip-image-plugin' ) ),
			'partial'   => array( 'warn', __( 'Partly converted', 'kwip-image-plugin' ) ),
			'skipped'   => array( 'muted', __( 'Skipped', 'kwip-image-plugin' ) ),
			'failed'    => array( 'error', __( 'Failed', 'kwip-image-plugin' ) ),
		);

		$raw    = ( isset( $data['status'] ) && is_string( $data['status'] ) ) ? $data['status'] : 'none';
		$status = isset( $badges[ $raw ] ) ? $raw : 'none';
		if ( 'none' === $status && KWIP_Queue::is_queued( $id ) ) {
			$status = 'queued';
		}

		list( $tone, $label ) = $badges[ $status ];

		$files  = ( isset( $data['files'] ) && is_array( $data['files'] ) ) ? $data['files'] : array();
		$counts = self::counts( $files );

		$summary = '';
		if ( $counts['converted'] > 0 ) {
			$original = (int) ( $data['original_total'] ?? 0 );
			$webp     = (int) ( $data['webp_total'] ?? 0 );
			$percent  = $original > 0 ? round( ( 1 - $webp / $original ) * 100, 1 ) : 0;
			$summary  = sprintf(
				__( '%1$d of %2$d files, %3$s → %4$s (%5$s%% smaller)', 'kwip-image-plugin' ),
				$counts['converted'],
				$counts['total'],
				(string) size_format( $original ),
				(string) size_format( $webp ),
				$percent
			);
		} elseif ( 'queued' === $status ) {
			$summary = __( 'Waiting in the background queue.', 'kwip-image-plugin' );
		} elseif ( 'failed' === $status && ! empty( $data['error'] ) ) {
			$summary = (string) $data['error'];
		} elseif ( 'skipped' === $status || 'failed' === $status ) {
			$summary = __( 'See details for the reason.', 'kwip-image-plugin' );
		}

		$buttons = match ( $status ) {
			'none'                 => array( array( 'convert', __( 'Convert to WebP', 'kwip-image-plugin' ), true ) ),
			'queued'               => array( array( 'convert', __( 'Convert now', 'kwip-image-plugin' ), false ) ),
			'converted', 'partial' => array(
				array( 'convert', __( 'Re-convert', 'kwip-image-plugin' ), false ),
				array( 'restore', __( 'Restore', 'kwip-image-plugin' ), false ),
			),
			'skipped'              => array( array( 'convert', __( 'Re-convert', 'kwip-image-plugin' ), false ) ),
			default                => array( array( 'convert', __( 'Try again', 'kwip-image-plugin' ), true ) ),
		};

		ob_start();
		?>
		<div class="kwip-media" data-id="<?php echo esc_attr( (string) $id ); ?>" data-status="<?php echo esc_attr( $status ); ?>">
			<p class="kwip-media__status">
				<span class="kwip-badge kwip-badge--<?php echo esc_attr( $tone ); ?>"><?php echo esc_html( $label ); ?></span>
				<?php if ( '' !== $summary ) : ?>
					<span class="kwip-media__summary"><?php echo esc_html( $summary ); ?></span>
				<?php endif; ?>
			</p>
			<p class="kwip-media__actions">
				<?php foreach ( $buttons as $button ) : ?>
					<button type="button" class="button button-small kwip-action<?php echo $button[2] ? ' button-primary' : ''; ?>" data-op="<?php echo esc_attr( $button[0] ); ?>"><?php echo esc_html( $button[1] ); ?></button>
				<?php endforeach; ?>
				<span class="spinner"></span>
			</p>
			<p class="kwip-media__message"></p>
			<?php
			if ( $files ) {
				self::render_details( $files, $id );
			}
			?>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * @param array<string, mixed> $files Per-file results.
	 */
	private static function render_details( array $files, int $id ): void {
		$url      = wp_get_attachment_url( $id );
		$base_url = $url ? trailingslashit( dirname( $url ) ) : '';
		?>
		<details class="kwip-media__details">
			<summary><?php esc_html_e( 'Details', 'kwip-image-plugin' ); ?></summary>
			<ul>
				<?php foreach ( $files as $name => $file ) : ?>
					<?php
					if ( ! is_array( $file ) ) {
						continue;
					}
					$status   = (string) ( $file['status'] ?? '' );
					$original = (int) ( $file['original'] ?? 0 );
					$webp     = (int) ( $file['webp'] ?? 0 );

					if ( 'converted' === $status ) {
						$text = sprintf(
							__( 'Converted: %1$s → %2$s (%3$s%% smaller)', 'kwip-image-plugin' ),
							(string) size_format( $original ),
							(string) size_format( $webp ),
							(string) ( $file['saved_percent'] ?? 0 )
						);
					} else {
						$prefix = 'failed' === $status ? __( 'Failed: ', 'kwip-image-plugin' ) : __( 'Skipped: ', 'kwip-image-plugin' );
						$text   = $prefix . self::reason_label( (string) ( $file['code'] ?? '' ), (string) ( $file['message'] ?? '' ) );
						if ( 'skipped' === $status && $webp > 0 ) {
							$text .= ' ' . sprintf(
								__( '(original %1$s, WebP %2$s)', 'kwip-image-plugin' ),
								(string) size_format( $original ),
								(string) size_format( $webp )
							);
						}
					}
					?>
					<li>
						<strong><?php echo esc_html( (string) $name ); ?></strong><br>
						<?php echo esc_html( $text ); ?>
						<?php if ( 'converted' === $status && '' !== $base_url ) : ?>
							<a class="kwip-open" href="<?php echo esc_url( $base_url . rawurlencode( (string) $name ) . '.webp' ); ?>" target="_blank" rel="noopener noreferrer">
								<?php esc_html_e( 'Open WebP', 'kwip-image-plugin' ); ?>
								<span class="screen-reader-text"><?php esc_html_e( '(opens in a new tab)', 'kwip-image-plugin' ); ?></span>
							</a>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ul>
		</details>
		<?php
	}

	private static function reason_label( string $code, string $message ): string {
		$labels = array(
			'not_smaller'          => __( 'the WebP was not smaller than the original.', 'kwip-image-plugin' ),
			'below_threshold'      => __( 'the saving was below the minimum set in Settings.', 'kwip-image-plugin' ),
			'kwip_format_disabled' => __( 'this image type is turned off in Settings.', 'kwip-image-plugin' ),
			'kwip_unsupported'     => __( 'not a supported JPEG or PNG image.', 'kwip-image-plugin' ),
			'kwip_animated'        => __( 'animated images are left unchanged.', 'kwip-image-plugin' ),
		);
		if ( isset( $labels[ $code ] ) ) {
			return $labels[ $code ];
		}
		return '' !== $message ? $message : $code;
	}
}