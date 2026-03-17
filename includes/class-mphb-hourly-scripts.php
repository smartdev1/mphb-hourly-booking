<?php
defined( 'ABSPATH' ) || exit;

class MPHB_Hourly_Scripts {

    public static function init() {
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
    }

    public static function enqueue() {
        wp_enqueue_style(
            'mphb-hourly',
            MPHB_HOURLY_URL . 'assets/css/mphb-hourly.css',
            array(),
            MPHB_HOURLY_VERSION
        );

        wp_enqueue_script(
            'mphb-hourly',
            MPHB_HOURLY_URL . 'assets/js/mphb-hourly.js',
            array( 'jquery' ),
            MPHB_HOURLY_VERSION,
            true
        );

        wp_enqueue_script(
            'mphb-hourly-search',
            MPHB_HOURLY_URL . 'assets/js/mphb-hourly-search.js',
            array( 'jquery', 'mphb-hourly' ),
            MPHB_HOURLY_VERSION,
            true
        );

        $current_rt_id = 0;
        if ( is_singular( MPHB()->postTypes()->roomType()->getPostType() ) ) {
            $current_rt_id = (int) get_the_ID();
        } elseif ( ! empty( $_GET['mphb_room_type_id'] ) ) {
            $current_rt_id = (int) $_GET['mphb_room_type_id'];
        }

        wp_localize_script( 'mphb-hourly', 'MPHBHourly', array(
            'ajax'            => admin_url( 'admin-ajax.php' ),
            'nonce'           => wp_create_nonce( 'mphb_hourly' ),
            'currency'        => MPHB()->settings()->currency()->getCurrencySymbol(),
            'currentRtId'     => $current_rt_id,
            'currentRtHourly' => $current_rt_id ? MPHB_Hourly_Helper::is_hourly( $current_rt_id ) : false,
            'i18n'            => array(
                'start'         => __( 'Heure de debut', 'mphb-hourly' ),
                'end'           => __( 'Heure de fin', 'mphb-hourly' ),
                'duration'      => __( 'Duree :', 'mphb-hourly' ),
                'price'         => __( 'Prix estime :', 'mphb-hourly' ),
                'booked'        => __( 'Reserve', 'mphb-hourly' ),
                'err_order'     => __( 'L heure de fin doit etre apres le debut.', 'mphb-hourly' ),
                'err_min'       => __( 'Duree minimum : %s.', 'mphb-hourly' ),
                'err_max'       => __( 'Duree maximum : %s.', 'mphb-hourly' ),
                'slot_required' => __( 'Veuillez selectionner un creneau horaire.', 'mphb-hourly' ),
                'mode_daily'    => __( 'A la journee', 'mphb-hourly' ),
                'mode_hourly'   => __( 'A l heure', 'mphb-hourly' ),
            ),
        ) );
    }
}