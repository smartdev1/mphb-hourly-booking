<?php

defined( 'ABSPATH' ) || exit;

class MPHB_Hourly_Search {

    public static function init(): void {
        // Contexte A : page single room type
        add_action(
            'mphb_render_single_room_type_after_reservation_form',
            [ __CLASS__, 'inject_mount_point' ]
        );

        // Contexte B : page de résultats
        add_action(
            'mphb_sc_search_results_reservation_cart_before',
            [ __CLASS__, 'inject_hourly_in_reservation_cart' ]
        );
    }

    
    public static function inject_mount_point(): void {
        if ( ! is_singular( MPHB()->postTypes()->roomType()->getPostType() ) ) return;

        $rt_id = (int) get_the_ID();
        if ( ! $rt_id || ! MPHB_Hourly_Helper::is_hourly( $rt_id ) ) return;

        $prev_start = sanitize_text_field( $_GET['mphb_hourly_start'] ?? '' );
        $prev_end   = sanitize_text_field( $_GET['mphb_hourly_end']   ?? '' );

        echo '<div'
           . ' id="mphb-hourly-search-mount-' . esc_attr( $rt_id ) . '"'
           . ' class="mphb-hourly-search-mount"'
           . ' data-mphb-hourly-search="1"'
           . ' data-mphb-room-type-id="' . esc_attr( $rt_id ) . '"'
           . ' data-form-id="booking-form-' . esc_attr( $rt_id ) . '"'
           . ' data-prev-start="' . esc_attr( $prev_start ) . '"'
           . ' data-prev-end="' . esc_attr( $prev_end ) . '"'
           . ' style="display:none">';

        echo '<input type="hidden" name="mphb_hourly_start"'
           . ' class="mphb-hourly-start-value"'
           . ' value="' . esc_attr( $prev_start ) . '">';
        echo '<input type="hidden" name="mphb_hourly_end"'
           . ' class="mphb-hourly-end-value"'
           . ' value="' . esc_attr( $prev_end ) . '">';
        echo '<input type="hidden" name="mphb_room_type_id"'
           . ' value="' . esc_attr( $rt_id ) . '">';

        echo '<div class="mphb-h-picker-search"></div>';
        echo '</div>';
    }

   

    public static function inject_hourly_in_reservation_cart(): void {
        $start = sanitize_text_field( $_GET['mphb_hourly_start'] ?? '' );
        $end   = sanitize_text_field( $_GET['mphb_hourly_end']   ?? '' );

        if ( ! $start || ! $end ) return;
        if ( ! preg_match( '/^\d{2}:\d{2}$/', $start ) ) return;
        if ( ! preg_match( '/^\d{2}:\d{2}$/', $end ) )   return;

        printf( '<input type="hidden" name="mphb_hourly_start" value="%s">', esc_attr( $start ) );
        printf( '<input type="hidden" name="mphb_hourly_end"   value="%s">', esc_attr( $end ) );

        self::render_slot_summary( $start, $end );
    }

    private static function render_slot_summary( string $start, string $end ): void {
        $start_m  = MPHB_Hourly_Helper::to_minutes( $start );
        $end_m    = MPHB_Hourly_Helper::to_minutes( $end );
        $duration = $end_m - $start_m;

        $rt_id     = isset( $_GET['mphb_room_type_id'] ) ? (int) $_GET['mphb_room_type_id'] : 0;
        $price_str = '';

        if ( $rt_id && MPHB_Hourly_Helper::is_hourly( $rt_id ) && $duration > 0 ) {
            $price     = MPHB_Hourly_Price::calc( $rt_id, $duration );
            $price_str = ' &middot; ' . esc_html( MPHB()->settings()->currency()->getCurrencySymbol() )
                       . number_format( $price, 2 );
        }

        $dur_label = $duration
            ? ' (' . esc_html( MPHB_Hourly_Helper::format_duration( $duration ) ) . ')'
            : '';

        echo '<div class="mphb-hourly-cart-summary" style="margin:8px 0;padding:8px 12px;'
           . 'background:#f0f7ff;border-left:3px solid #0073aa;font-size:13px;">'
           . '<strong>' . esc_html__( 'Créneau :', 'mphb-hourly' ) . '</strong> '
           . esc_html( $start . ' – ' . $end )
           . $dur_label
           . $price_str
           . '</div>';
    }
}
