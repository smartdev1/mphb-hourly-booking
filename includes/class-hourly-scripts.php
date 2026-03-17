<?php
/**
 * Enqueue front-end assets for the hourly time picker UI.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class MPHB_Hourly_Scripts {

    public static function init(): void {
        add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue' ] );
    }

    public static function enqueue(): void {
        wp_enqueue_style(
            'mphb-hourly-css',
            MPHB_HOURLY_URL . 'assets/css/mphb-hourly.css',
            [],
            MPHB_HOURLY_VERSION
        );

        wp_enqueue_script(
            'mphb-hourly-js',
            MPHB_HOURLY_URL . 'assets/js/mphb-hourly.js',
            [ 'jquery' ],
            MPHB_HOURLY_VERSION,
            true
        );

        wp_localize_script( 'mphb-hourly-js', 'MPHBHourly', [
            'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
            'nonce'     => wp_create_nonce( 'mphb_hourly_nonce' ),
            'currency'  => MPHB()->settings()->currency()->getCurrencySymbol(),
            'i18n'      => [
                'select_date'    => __( 'Please select a date first.', 'mphb-hourly' ),
                'select_start'   => __( 'Select start time', 'mphb-hourly' ),
                'select_end'     => __( 'Select end time', 'mphb-hourly' ),
                'duration_label' => __( 'Duration:', 'mphb-hourly' ),
                'price_label'    => __( 'Estimated price:', 'mphb-hourly' ),
                'booked'         => __( 'Booked', 'mphb-hourly' ),
                'available'      => __( 'Available', 'mphb-hourly' ),
                'per_hour'       => __( '/hour', 'mphb-hourly' ),
                'error_min'      => __( 'Minimum booking duration is %s.', 'mphb-hourly' ),
                'error_max'      => __( 'Maximum booking duration is %s.', 'mphb-hourly' ),
                'error_order'    => __( 'End time must be after start time.', 'mphb-hourly' ),
            ],
        ] );
    }
}
