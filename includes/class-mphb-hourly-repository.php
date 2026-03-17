<?php

defined( 'ABSPATH' ) || exit;

class MPHB_Hourly_Repository {

    public static function init(): void {
        add_action( 'save_post', [ __CLASS__, 'save_time_meta' ], 999, 2 );
    }

    public static function save_time_meta( int $post_id, \WP_Post $post ): void {

        if ( $post->post_type !== MPHB()->postTypes()->booking()->getPostType() ) return;
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
        if ( wp_is_post_revision( $post_id ) ) return;

        $start    = $GLOBALS['mphb_hourly_start']    ?? '';
        $end      = $GLOBALS['mphb_hourly_end']      ?? '';
        $duration = $GLOBALS['mphb_hourly_duration'] ?? 0;

        if ( ! $start || ! $end ) return;

        update_post_meta( $post_id, MPHB_Hourly_Helper::BK_START,    $start );
        update_post_meta( $post_id, MPHB_Hourly_Helper::BK_END,      $end );
        update_post_meta( $post_id, MPHB_Hourly_Helper::BK_DURATION, (int) $duration );
    }
}
