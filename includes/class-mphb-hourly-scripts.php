<?php
defined( 'ABSPATH' ) || exit;

class MPHB_Hourly_Scripts {

    public static function init(): void {
        add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue' ] );
    }

    public static function enqueue(): void {
        wp_enqueue_style(
            'mphb-hourly',
            MPHB_HOURLY_URL . 'assets/css/mphb-hourly.css',
            [],
            MPHB_HOURLY_VERSION
        );

        wp_enqueue_script(
            'mphb-hourly',
            MPHB_HOURLY_URL . 'assets/js/mphb-hourly.js',
            [ 'jquery' ],
            MPHB_HOURLY_VERSION,
            true
        );

        // Script dédié au formulaire de recherche natif (single room type)
        wp_enqueue_script(
            'mphb-hourly-search',
            MPHB_HOURLY_URL . 'assets/js/mphb-hourly-search.js',
            [ 'jquery', 'mphb-hourly' ],
            MPHB_HOURLY_VERSION,
            true
        );

        // Détecter le room type courant pour le formulaire de recherche
        $current_rt_id = 0;
        if ( is_singular( MPHB()->postTypes()->roomType()->getPostType() ) ) {
            $current_rt_id = (int) get_the_ID();
        } elseif ( ! empty( $_GET['mphb_room_type_id'] ) ) {
            $current_rt_id = (int) $_GET['mphb_room_type_id'];
        }

        wp_localize_script( 'mphb-hourly', 'MPHBHourly', [
            'ajax'            => admin_url( 'admin-ajax.php' ),
            'nonce'           => wp_create_nonce( 'mphb_hourly' ),
            'currency'        => MPHB()->settings()->currency()->getCurrencySymbol(),
            'currentRtId'     => $current_rt_id,
            'currentRtHourly' => $current_rt_id ? MPHB_Hourly_Helper::is_hourly( $current_rt_id ) : false,
            'i18n'            => [
                'start'         => __( 'Heure de début', 'mphb-hourly' ),
                'end'           => __( 'Heure de fin', 'mphb-hourly' ),
                'duration'      => __( 'Durée :', 'mphb-hourly' ),
                'price'         => __( 'Prix estimé :', 'mphb-hourly' ),
                'booked'        => __( 'Réservé', 'mphb-hourly' ),
                'err_order'     => __( 'L\'heure de fin doit être après le début.', 'mphb-hourly' ),
                'err_min'       => __( 'Durée minimum : %s.', 'mphb-hourly' ),
                'err_max'       => __( 'Durée maximum : %s.', 'mphb-hourly' ),
                'slot_required' => __( 'Veuillez sélectionner un créneau horaire.', 'mphb-hourly' ),
            ],
        ] );
    }
}
