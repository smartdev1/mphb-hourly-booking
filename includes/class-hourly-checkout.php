<?php

if ( ! defined( 'ABSPATH' ) ) exit;

class MPHB_Hourly_Checkout {

    public static function init(): void {
        add_filter( 'mphb_sc_checkout_parse_check_in_date',  [ __CLASS__, 'handle_hourly_check_in' ],  20, 3 );
        add_filter( 'mphb_sc_checkout_parse_check_out_date', [ __CLASS__, 'handle_hourly_check_out' ], 20, 3 );

     
        add_action( 'mphb_booking_created', [ __CLASS__, 'save_time_meta' ], 10, 1 );
        add_action( 'wp_ajax_mphb_hourly_get_slots',        [ __CLASS__, 'ajax_get_slots' ] );
        add_action( 'wp_ajax_nopriv_mphb_hourly_get_slots', [ __CLASS__, 'ajax_get_slots' ] );

        add_action( 'mphb_sc_checkout_form', [ __CLASS__, 'render_hidden_fields' ], 15 );
    }

   
    public static function handle_hourly_check_in( $check_in_date, string $date_string, $today ): ?\DateTime {
        if ( ! $check_in_date ) return $check_in_date;

        $start_time = sanitize_text_field( $_POST['mphb_hourly_start_time'] ?? '' );
        if ( empty( $start_time ) ) return $check_in_date; 

        if ( ! preg_match( '/^\d{2}:\d{2}$/', $start_time ) ) return $check_in_date;

        $GLOBALS['mphb_hourly_start_time'] = $start_time;
        $GLOBALS['mphb_hourly_date']       = $check_in_date->format( 'Y-m-d' );

        return $check_in_date;
    }

    public static function handle_hourly_check_out( $check_out_date, string $date_string, $check_in ): ?\DateTime {
        if ( ! $check_out_date ) return $check_out_date;

        $end_time = sanitize_text_field( $_POST['mphb_hourly_end_time'] ?? '' );
        if ( empty( $end_time ) ) return $check_out_date;

        if ( ! preg_match( '/^\d{2}:\d{2}$/', $end_time ) ) return $check_out_date;

        $GLOBALS['mphb_hourly_end_time'] = $end_time;

        $start = MPHB_Hourly_Settings::time_to_minutes( $GLOBALS['mphb_hourly_start_time'] ?? '00:00' );
        $end   = MPHB_Hourly_Settings::time_to_minutes( $end_time );
        $GLOBALS['mphb_hourly_duration_min'] = max( 0, $end - $start );

        return $check_out_date;
    }

    public static function save_time_meta( $booking ): void {
        $start_time   = $GLOBALS['mphb_hourly_start_time']   ?? '';
        $end_time     = $GLOBALS['mphb_hourly_end_time']     ?? '';
        $duration_min = $GLOBALS['mphb_hourly_duration_min'] ?? 0;

        if ( empty( $start_time ) || empty( $end_time ) ) return;

        $booking_id = $booking->getId();
        update_post_meta( $booking_id, MPHB_Hourly_Settings::META_BOOKING_START_TIME, $start_time );
        update_post_meta( $booking_id, MPHB_Hourly_Settings::META_BOOKING_END_TIME,   $end_time );
        update_post_meta( $booking_id, MPHB_Hourly_Settings::META_BOOKING_DURATION,   $duration_min );
    }

    
    public static function render_hidden_fields( $booking ): void {
        $start = esc_attr( $_POST['mphb_hourly_start_time'] ?? '' );
        $end   = esc_attr( $_POST['mphb_hourly_end_time']   ?? '' );

        if ( empty( $start ) ) return;

        echo '<input type="hidden" name="mphb_hourly_start_time" value="' . $start . '">';
        echo '<input type="hidden" name="mphb_hourly_end_time"   value="' . $end . '">';
    }

    
    public static function ajax_get_slots(): void {
        check_ajax_referer( 'mphb_hourly_nonce', 'nonce' );

        $room_type_id = intval( $_GET['room_type_id'] ?? 0 );
        $date         = sanitize_text_field( $_GET['date'] ?? '' );

        if ( ! $room_type_id || ! $date || ! MPHB_Hourly_Settings::is_hourly_room_type( $room_type_id ) ) {
            wp_send_json_error( [ 'message' => __( 'Invalid request.', 'mphb-hourly' ) ] );
        }

        wp_send_json_success( [
            'open'          => MPHB_Hourly_Settings::get_open_time( $room_type_id ),
            'close'         => MPHB_Hourly_Settings::get_close_time( $room_type_id ),
            'step'          => MPHB_Hourly_Settings::get_slot_step( $room_type_id ),
            'min_duration'  => MPHB_Hourly_Settings::get_min_duration( $room_type_id ),
            'max_duration'  => MPHB_Hourly_Settings::get_max_duration( $room_type_id ),
            'price_per_hour'=> MPHB_Hourly_Settings::get_hourly_price( $room_type_id ),
            'currency'      => MPHB()->settings()->currency()->getCurrencySymbol(),
            'booked'        => MPHB_Hourly_Availability::get_booked_slots( $room_type_id, $date ),
        ] );
    }
}
