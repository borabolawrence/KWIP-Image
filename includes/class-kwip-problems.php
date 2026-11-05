<?php
/**
 * Problems: images whose conversion failed (or only partly worked), explained
 * in plain language, with a Try again button.
 *
 * The list is read from the conversion records already stored with each image,
 * so it is always current: once an image is fixed it disappears.
 *
 * @package kwip-image-plugin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class KWIP_Problems {

	private const NONCE_ACTION = 'kwip_problems';
	private const COUNT_KEY    = 'kwip_problem_count';
	private const LISTED       = 50;

	public static function init(): void {
		add_action( 'wp_ajax_kwip_problem_retry', array( __CLASS__, 'handle_retry' ) );
	}

	public static function enqueue(): void {
		wp_enqueue_script( 'kwip-problems', KWIP_URL . 'assets/js/problems.js', array( 'jquery', 'wp-a11y' ), KWIP_VERSION, true );
		wp_localize_script(
			'kwip-problems',
			'kwipProblems',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE_ACTION ),
				'i18n'    => array(
					'retry'     => __( 'Try again', 'kwip-image-plugin' ),
					'retrying'  => __( 'Trying…', 'kwip-image-plugin' ),
					'titleOne'  => __( '%s image had a problem', 'kwip-image-plugin' ),
					'titleMany' => __( '%s images had problems', 'kwip-image-plugin' ),
					'titleNone' => __( 'No problems found', 'kwip-image-plugin' ),
					'noneText'  => __( 'Every image that was processed either worked or was skipped for a good reason.', 'kwip-image-plugin' ),
					'fixed'     => __( 'Fixed: %1$s to %2$s.', 'kwip-image-plugin' ),
					'fixedSkip' => __( 'Fixed. The image did not get smaller, so the original stays.', 'kwip-image-plugin' ),
					'error'     => __( 'Something went wrong. Please try again.', 'kwip-image-plugin' ),
					'progress'  => __( 'Trying %1$s of %2$s…', 'kwip-image-plugin' ),
					'allDone'   => __( 'Finished. Any image that still has a problem is listed with its reason.', 'kwip-image-plugin' ),
				),
			)
		);
	}

	/**
	 * Top-level status of the stored record is "failed" or "partial". The
	 * leading "i:1;" (the record version) keeps this from matching the
	 * per-file entries nested inside converted records.
	 *
	 * @return array<string|int, mixed>
	 */
	private static function meta_query(): array {
		return array(
			'relation' => 'OR',
			array(
				'key'     => KWIP_Converter::META_KEY,
				'value'   => 'i:1;s:6:"status";s:6:"failed";',
				'compare' => 'LIKE',
			),
			array(
				'key'     => KWIP_Converter::META_KEY,
				'value'   => 'i:1;s:6:"status";s:7:"partial";',
				'compare' => 'LIKE',
			),
		);
	}

	public static function count(): int {
		$cached = get_transient( self::COUNT_KEY );
		if ( false !== $cached ) {
			return (int) $cached;
		}

		$query = new WP_Query(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'fields'         => 'ids',
				'posts_per_page' => 1,
				'meta_query'     => self::meta_query(),
			)
		);
		$count = (int) $query->found_posts;

		set_transient( self::COUNT_KEY, $count, 10 * MINUTE_IN_SECONDS );
		return $count;
	}

	public static function flush(): void {
		delete_transient( self::COUNT_KEY );
	}

	/** @return array<int, array<string, mixed>> */
	private static function items( int $limit ): array {
		$ids = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'fields'         => 'ids',
				'posts_per_page' => $limit,
				'orderby'        => 'ID',
				'order'          => 'DESC',
				'no_found_rows'  => true,
				'meta_query'     => self::meta_query(),
			)
		);

		$items = array();
		foreach ( $ids as $id ) {
			$item = self::describe( (int) $id );
			if ( null !== $item ) {
				$items[] = $item;
			}
		}
		return $items;
	}

	/** @return array<string, mixed>|null */
	private static function describe( int $id ): ?array {
		$record = get_post_meta( $id, KWIP_Converter::META_KEY, true );
		if ( ! is_array( $record ) || ! in_array( $record['status'] ?? '', array( 'failed', 'partial' ), true ) ) {
			return null;
		}

		$reasons = array();
		if ( ! empty( $record['error'] ) ) {
			$reason                = self::explain( '', (string) $record['error'] );
			$reasons[ $reason[0] ] = $reason;
		}

		$files  = ( isset( $record['files'] ) && is_array( $record['files'] ) ) ? $record['files'] : array();
		$failed = 0;
		foreach ( $files as $file ) {
			if ( is_array( $file ) && 'failed' === ( $file['status'] ?? '' ) ) {
				++$failed;
				$reason                = self::explain( (string) ( $file['code'] ?? '' ), (string) ( $file['message'] ?? '' ) );
				$reasons[ $reason[0] ] = $reason;
			}
		}

		$path  = get_attached_file( $id );
		$thumb = wp_get_attachment_image_url( $id, 'thumbnail' );

		return array(
			'id'      => $id,
			'name'    => $path ? wp_basename( $path ) : get_the_title( $id ),
			'thumb'   => $thumb ? (string) $thumb : '',
			'edit'    => (string) get_edit_post_link( $id, 'raw' ),
			'status'  => (string) $record['status'],
			'ago'     => ! empty( $record['time'] ) ? human_time_diff( (int) $record['time'], time() ) : '',
			'failed'  => $failed,
			'total'   => count( $files ),
			'reasons' => array_values( $reasons ),
		);
	}

	/**
	 * Plain-language reason and what to do about it.
	 *
	 * @return array{0:string,1:string}
	 */
	private static function explain( string $code, string $message ): array {
		switch ( $code ) {
			case 'gwip_disk_space':
				return array(
					__( 'The server ran out of free disk space.', 'kwip-image-plugin' ),
					__( 'Free up some space, then try again.', 'kwip-image-plugin' ),
				);
			case 'gwip_publish_failed':
			case 'gwip_publish_invalid':
			case 'gwip_unwritable':
				return array(
					__( 'The server could not save the optimized file.', 'kwip-image-plugin' ),
					__( 'Check that the uploads folder can be written to, then try again.', 'kwip-image-plugin' ),
				);
			case 'gwip_verify_missing':
			case 'gwip_verify_empty':
			case 'gwip_verify_header':
			case 'gwip_verify_mime':
			case 'gwip_verify_dimensions':
			case 'gwip_verify_mismatch':
				return array(
					__( 'The optimized file did not pass its safety check, so it was not used.', 'kwip-image-plugin' ),
					__( 'Try again. If it keeps happening, the original image may be damaged.', 'kwip-image-plugin' ),
				);
			case 'gwip_unreadable':
			case 'gwip_path':
			case 'gwip_empty':
			case 'gwip_no_file':
				return array(
					__( 'The original file is missing or cannot be read.', 'kwip-image-plugin' ),
					__( 'Upload the image again, or restore it from a backup.', 'kwip-image-plugin' ),
				);
			case 'gwip_no_webp':
				return array(
					__( 'This server cannot create WebP images.', 'kwip-image-plugin' ),
					__( 'Open the Health check to see what is missing.', 'kwip-image-plugin' ),
				);
			case 'gwip_locked':
				return array(
					__( 'The image was busy being processed.', 'kwip-image-plugin' ),
					__( 'Wait a moment, then try again.', 'kwip-image-plugin' ),
				);
		}

		return array(
			'' !== $message ? $message : __( 'An unknown problem occurred.', 'kwip-image-plugin' ),
			__( 'Try again. If it keeps happening, tell your developer or hosting provider.', 'kwip-image-plugin' ),
		);
	}

	public static function render_card(): void {
		$count = self::count();
		$items = $count > 0 ? self::items( self::LISTED ) : array();
		?>
		<section class="kwip-card kwip-problems">
			<div class="kwip-problems__head">
				<?php if ( 0 === $count ) : ?>
					<svg class="kwip-check" viewBox="0 0 52 52" aria-hidden="true">
						<circle class="kwip-check__circle" cx="26" cy="26" r="24"></circle>
						<path class="kwip-check__tick" d="M14 27l8 8 16-17"></path>
					</svg>
					<div>
						<h2 id="kwip-problems-title"><?php esc_html_e( 'No problems found', 'kwip-image-plugin' ); ?></h2>
						<p id="kwip-problems-text"><?php esc_html_e( 'Every image that was processed either worked or was skipped for a good reason.', 'kwip-image-plugin' ); ?></p>
					</div>
				<?php else : ?>
					<span class="dashicons dashicons-warning kwip-health__warn" aria-hidden="true"></span>
					<div>
						<h2 id="kwip-problems-title">
							<?php
							echo esc_html(
								sprintf(
									_n( '%s image had a problem', '%s images had problems', $count, 'kwip-image-plugin' ),
									number_format_i18n( $count )
								)
							);
							?>
						</h2>
						<p id="kwip-problems-text"><?php esc_html_e( 'These images could not be fully optimized. Your visitors still get the original image, so nothing is broken on your site.', 'kwip-image-plugin' ); ?></p>
					</div>
					<div class="kwip-actions">
						<button type="button" class="button button-primary" id="kwip-problems-retry-all"><?php esc_html_e( 'Try all again', 'kwip-image-plugin' ); ?></button>
					</div>
				<?php endif; ?>
			</div>

			<?php if ( $count > 0 ) : ?>
				<p id="kwip-problems-progress" class="kwip-recs__progress" role="status"></p>
				<ul class="kwip-problem-list">
					<?php foreach ( $items as $item ) : ?>
						<li class="kwip-img kwip-problem" data-id="<?php echo esc_attr( (string) $item['id'] ); ?>">
							<?php if ( '' !== $item['thumb'] ) : ?>
								<img class="kwip-img__thumb" src="<?php echo esc_url( $item['thumb'] ); ?>" alt="" width="48" height="48" loading="lazy">
							<?php else : ?>
								<span class="kwip-img__thumb kwip-img__thumb--icon"><span class="dashicons dashicons-format-image" aria-hidden="true"></span></span>
							<?php endif; ?>

							<div class="kwip-img__main">
								<strong class="kwip-img__name"><?php echo esc_html( $item['name'] ); ?></strong>
								<div class="kwip-img__tags">
									<span class="kwip-tag kwip-tag--<?php echo 'partial' === $item['status'] ? 'warn' : 'bad'; ?>">
										<?php echo esc_html( 'partial' === $item['status'] ? __( 'Partly optimized', 'kwip-image-plugin' ) : __( 'Not optimized', 'kwip-image-plugin' ) ); ?>
									</span>
									<?php if ( $item['failed'] > 0 && $item['total'] > 1 ) : ?>
										<span class="kwip-tag kwip-tag--muted">
											<?php
											echo esc_html(
												sprintf(
													__( '%1$s of %2$s sizes failed', 'kwip-image-plugin' ),
													number_format_i18n( $item['failed'] ),
													number_format_i18n( $item['total'] )
												)
											);
											?>
										</span>
									<?php endif; ?>
								</div>
								<div class="kwip-problem__reasons">
									<?php foreach ( $item['reasons'] as $reason ) : ?>
										<p class="kwip-problem__reason">
											<strong><?php echo esc_html( $reason[0] ); ?></strong>
											<span class="description kwip-block"><?php echo esc_html( $reason[1] ); ?></span>
										</p>
									<?php endforeach; ?>
								</div>
								<?php if ( '' !== $item['ago'] ) : ?>
									<span class="description kwip-block">
										<?php
										echo esc_html(
											sprintf(
												__( 'Last tried %s ago', 'kwip-image-plugin' ),
												$item['ago']
											)
										);
										?>
									</span>
								<?php endif; ?>
								<span class="kwip-img__result kwip-problem__result" role="status"></span>
							</div>

							<div class="kwip-img__action">
								<button type="button" class="button kwip-problem-retry"><?php esc_html_e( 'Try again', 'kwip-image-plugin' ); ?></button>
								<?php if ( '' !== $item['edit'] ) : ?>
									<a class="kwip-problem__open" href="<?php echo esc_url( $item['edit'] ); ?>"><?php esc_html_e( 'Open image', 'kwip-image-plugin' ); ?></a>
								<?php endif; ?>
							</div>
						</li>
					<?php endforeach; ?>
				</ul>
				<?php if ( $count > count( $items ) ) : ?>
					<p class="description">
						<?php
						echo esc_html(
							sprintf(
								__( 'Showing %1$s of %2$s. Fix these and the rest will appear.', 'kwip-image-plugin' ),
								number_format_i18n( count( $items ) ),
								number_format_i18n( $count )
							)
						);
						?>
					</p>
				<?php endif; ?>
			<?php endif; ?>

			<p class="description kwip-problems__note"><?php esc_html_e( 'Images that are skipped because the optimized copy would not be smaller are not problems, and are not listed here.', 'kwip-image-plugin' ); ?></p>
		</section>
		<?php
	}

	public static function handle_retry(): void {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		if ( ! current_user_can( 'manage_options' ) || ! $id || 'attachment' !== get_post_type( $id ) || ! current_user_can( 'edit_post', $id ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do that.', 'kwip-image-plugin' ) ), 403 );
		}

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 60 );
		}

		$result = KWIP_Converter::convert_attachment( $id );
		if ( is_wp_error( $result ) ) {
			$reason = self::explain( $result->get_error_code(), $result->get_error_message() );
			wp_send_json_success(
				array(
					'ok'        => false,
					'reasons'   => array( $reason ),
					'remaining' => self::count(),
				)
			);
		}
		KWIP_Queue::dequeue( $id );
		self::flush();

		$status = (string) $result['status'];
		$ok     = in_array( $status, array( 'converted', 'skipped' ), true );

		wp_send_json_success(
			array(
				'ok'        => $ok,
				'status'    => $status,
				'original'  => (int) $result['original_total'],
				'webp'      => (int) $result['webp_total'],
				'reasons'   => $ok ? array() : ( self::describe( $id )['reasons'] ?? array() ),
				'remaining' => self::count(),
			)
		);
	}
}