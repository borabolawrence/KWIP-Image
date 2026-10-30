<?php
/**
 * Savings statistics for the Overview screen.
 *
 * @package kwip-image-plugin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class KWIP_Stats {

	private const TRANSIENT = 'kwip_savings';

	public static function flush(): void {
		delete_transient( self::TRANSIENT );
	}

	/**
	 * Totals across all converted images, cached for 10 minutes.
	 *
	 * @return array{optimized:int,original:int,webp:int}
	 */
	public static function savings(): array {
		$cached = get_transient( self::TRANSIENT );
		if ( is_array( $cached ) && isset( $cached['optimized'], $cached['original'], $cached['webp'] ) ) {
			return $cached;
		}

		$optimized = 0;
		$original  = 0;
		$webp      = 0;
		$page      = 1;

		do {
			$ids = get_posts(
				array(
					'post_type'      => 'attachment',
					'post_status'    => 'inherit',
					'fields'         => 'ids',
					'posts_per_page' => 500,
					'paged'          => $page,
					'orderby'        => 'ID',
					'order'          => 'ASC',
					'no_found_rows'  => true,
					'meta_query'     => array( KWIP_Media::optimized_meta_query() ),
				)
			);

			if ( $ids ) {
				update_meta_cache( 'post', $ids );
				foreach ( $ids as $id ) {
					$record = get_post_meta( (int) $id, KWIP_Converter::META_KEY, true );
					if ( is_array( $record ) ) {
						++$optimized;
						$original += (int) ( $record['original_total'] ?? 0 );
						$webp     += (int) ( $record['webp_total'] ?? 0 );
					}
				}
			}
			++$page;
		} while ( count( $ids ) === 500 );

		$data = array(
			'optimized' => $optimized,
			'original'  => $original,
			'webp'      => $webp,
		);
		set_transient( self::TRANSIENT, $data, 10 * MINUTE_IN_SECONDS );

		return $data;
	}

	/**
	 * Split a byte count into a number and a unit for display and count-up.
	 *
	 * @return array{0:float,1:int,2:string} Value, decimals, unit.
	 */
	public static function bytes_parts( int $bytes ): array {
		$units = array( 'B', 'KB', 'MB', 'GB', 'TB' );
		$i     = 0;
		$value = (float) max( 0, $bytes );

		while ( $value >= 1024 && $i < 4 ) {
			$value /= 1024;
			++$i;
		}

		$decimals = 0 === $i ? 0 : 1;
		return array( round( $value, $decimals ), $decimals, $units[ $i ] );
	}
}