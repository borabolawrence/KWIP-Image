<?php
/**
 * Cleanup tool: delete every generated WebP file from the Settings screen.
 *
 * @package kwip-image-plugin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class KWIP_Cleanup {

	private const NONCE_ACTION  = 'kwip_cleanup';
	private const BATCH_SECONDS = 8;

	public static function init(): void {
		add_action( 'wp_ajax_kwip_cleanup', array( __CLASS__, 'handle_ajax' ) );
	}

	public static function enqueue(): void {
		KWIP_Admin::register_shared_assets();
		wp_enqueue_style( 'kwip-modal' );
		wp_enqueue_script( 'kwip-cleanup', KWIP_URL . 'assets/js/cleanup.js', array( 'jquery', 'wp-a11y', 'kwip-modal' ), KWIP_VERSION, true );
		wp_localize_script(
			'kwip-cleanup',
			'kwipCleanup',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE_ACTION ),
				'total'   => (int) KWIP_Stats::savings()['optimized'],
				'i18n'    => array(
					'confirmTitle'  => __( 'Delete all WebP files?', 'kwip-image-plugin' ),
					'confirm'       => __( 'Every optimized copy will be deleted, and visitors will receive your original images again. Your original images are not changed. You can optimize them again at any time.', 'kwip-image-plugin' ),
					'confirmButton' => __( 'Delete WebP files', 'kwip-image-plugin' ),
					'working'       => __( 'Deleting…', 'kwip-image-plugin' ),
					'progress'      => __( 'Deleting… %s images done', 'kwip-image-plugin' ),
					'done'          => __( 'Done. Removed the WebP files of %1$s images (%2$s files). New uploads are optimized again unless you turn that off above.', 'kwip-image-plugin' ),
					'error'         => __( 'Something went wrong. Please try again.', 'kwip-image-plugin' ),
					'button'        => __( 'Delete all WebP files now', 'kwip-image-plugin' ),
				),
			)
		);
	}

	public static function render_tool(): void {
		$count = (int) KWIP_Stats::savings()['optimized'];
		?>
		<div class="kwip-cleanup__tool">
			<p class="description">
				<?php
				echo esc_html(
					sprintf(
						_n( '%s image currently has WebP copies.', '%s images currently have WebP copies.', $count, 'kwip-image-plugin' ),
						number_format_i18n( $count )
					)
				);
				?>
				<?php esc_html_e( 'Deleting them sends visitors back to your original images. Originals are never deleted.', 'kwip-image-plugin' ); ?>
			</p>
			<button type="button" class="button" id="kwip-cleanup-btn" <?php disabled( 0 === $count ); ?>><?php esc_html_e( 'Delete all WebP files now', 'kwip-image-plugin' ); ?></button>
			<div class="kwip-progress is-running" id="kwip-cleanup-progress" hidden>
				<div class="kwip-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" aria-label="<?php esc_attr_e( 'Deleting WebP files', 'kwip-image-plugin' ); ?>">
					<div class="kwip-bar__fill" id="kwip-cleanup-fill"></div>
				</div>
				<span id="kwip-cleanup-percent">0%</span>
			</div>
			<p id="kwip-cleanup-status" role="status"></p>
		</div>
		<?php
	}

	public static function handle_ajax(): void {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do that.', 'kwip-image-plugin' ) ), 403 );
		}

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 60 );
		}

		$after = isset( $_POST['after'] ) ? absint( $_POST['after'] ) : 0;
		wp_send_json_success( self::step( $after ) );
	}

	/**
	 * Remove the WebP files of as many images as fit in a few seconds.
	 *
	 * @return array{images:int,removed:int,last_id:int,done:bool}
	 */
	private static function step( int $after ): array {
		$start   = microtime( true );
		$images  = 0;
		$removed = 0;
		$last    = $after;
		$done    = false;

		while ( ( microtime( true ) - $start ) < self::BATCH_SECONDS ) {
			$ids = self::next_ids( $last, 20 );
			if ( empty( $ids ) ) {
				$done = true;
				break;
			}
			foreach ( $ids as $id ) {
				$result = KWIP_Converter::remove_attachment_webp( $id );
				if ( ! is_wp_error( $result ) ) {
					++$images;
					$removed += $result;
				}
				$last = $id;
			}
		}

		return array(
			'images'  => $images,
			'removed' => $removed,
			'last_id' => $last,
			'done'    => $done,
		);
	}

	/**
	 * Attachments that still have a conversion record, after a cursor.
	 *
	 * @return int[]
	 */
	private static function next_ids( int $after, int $limit ): array {
		$filter = static function ( $where ) use ( $after ) {
			global $wpdb;
			return $where . $wpdb->prepare( " AND {$wpdb->posts}.ID > %d", $after );
		};

		add_filter( 'posts_where', $filter );
		$ids = get_posts(
			array(
				'post_type'        => 'attachment',
				'post_status'      => 'inherit',
				'fields'           => 'ids',
				'posts_per_page'   => $limit,
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'no_found_rows'    => true,
				'suppress_filters' => false,
				'meta_key'         => KWIP_Converter::META_KEY,
			)
		);
		remove_filter( 'posts_where', $filter );

		return array_map( 'intval', $ids );
	}
}