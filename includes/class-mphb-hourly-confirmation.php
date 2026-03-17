<?php
/**
 * Affichage du créneau horaire sur la page de confirmation de réservation
 * et dans les détails admin.
 *
 * MPHB affiche les détails via le shortcode [mphb_booking_confirmation].
 * Ce shortcode déclenche plusieurs actions :
 *   - mphb_sc_booking_confirmation_booking_details  → sous les infos de réservation
 *   - mphb_sc_booking_confirmation_bottom           → bas de page
 *
 * On injecte le créneau via mphb_sc_booking_confirmation_booking_details.
 *
 * Pour l'admin (page d'édition d'une réservation), on ajoute une meta box
 * supplémentaire affichant les infos horaires.
 */
defined( 'ABSPATH' ) || exit;

class MPHB_Hourly_Confirmation {

    public static function init(): void {
       
        add_action( 'mphb_sc_booking_confirmation_booking_details',
            [ __CLASS__, 'render_slot_on_confirmation' ], 10 );

        add_action( 'mphb_sc_booking_confirmation_bottom',
            [ __CLASS__, 'render_slot_on_confirmation_bottom' ], 5 );

        
        add_action( 'add_meta_boxes', [ __CLASS__, 'add_booking_meta_box' ] );

        
        add_filter( 'mphb_booking_details_html', [ __CLASS__, 'append_slot_to_booking_details' ], 10, 2 );
    }

    /* -----------------------------------------------------------------------
     * Page de confirmation front-end
     * -------------------------------------------------------------------- */

    public static function render_slot_on_confirmation( $booking = null ): void {
        if ( ! $booking instanceof \MPHB\Entities\Booking ) {
            // Tenter de récupérer le booking depuis l'URL
            $booking = self::get_booking_from_request();
        }
        if ( ! $booking ) return;

        $slot_html = self::get_slot_html( $booking->getId() );
        if ( ! $slot_html ) return;

        echo wp_kses_post( $slot_html );
    }

    public static function render_slot_on_confirmation_bottom(): void {
        
        $booking = self::get_booking_from_request();
        if ( ! $booking ) return;

        $slot_html = self::get_slot_html( $booking->getId() );
        if ( ! $slot_html ) return;

        
        static $already_shown = false;
        if ( $already_shown ) return;
        $already_shown = true;

        echo wp_kses_post( $slot_html );
    }

    /* -----------------------------------------------------------------------
     * Meta box admin sur la page d'édition d'un booking
     * -------------------------------------------------------------------- */

    public static function add_booking_meta_box(): void {
        $booking_cpt = MPHB()->postTypes()->booking()->getPostType();
        add_meta_box(
            'mphb_hourly_booking_slot',
            __( '⏱ Créneau horaire', 'mphb-hourly' ),
            [ __CLASS__, 'render_booking_meta_box' ],
            $booking_cpt,
            'side',
            'high'
        );
    }

    public static function render_booking_meta_box( \WP_Post $post ): void {
        $start    = MPHB_Hourly_Helper::booking_start( $post->ID );
        $end      = MPHB_Hourly_Helper::booking_end( $post->ID );
        $duration = MPHB_Hourly_Helper::booking_duration( $post->ID );

        if ( ! $start || ! $end ) {
            echo '<p style="color:#999">' . esc_html__( 'Réservation journalière (sans créneau horaire).', 'mphb-hourly' ) . '</p>';
            return;
        }

        echo '<table class="form-table" style="margin:0"><tbody>';
        printf(
            '<tr><th>%s</th><td><strong>%s → %s</strong></td></tr>',
            esc_html__( 'Créneau', 'mphb-hourly' ),
            esc_html( $start ),
            esc_html( $end )
        );
        if ( $duration ) {
            printf(
                '<tr><th>%s</th><td>%s</td></tr>',
                esc_html__( 'Durée', 'mphb-hourly' ),
                esc_html( MPHB_Hourly_Helper::format_duration( $duration ) )
            );
            // Prix calculé
            $booking  = MPHB()->getBookingRepository()->findById( $post->ID );
            if ( $booking ) {
                $rr    = $booking->getReservedRooms();
                $rt_id = 0;
                if ( ! empty( $rr ) ) {
                    $rt_id = MPHB_Hourly_Helper::room_type_of( reset( $rr )->getRoomId() );
                }
                if ( $rt_id && MPHB_Hourly_Helper::is_hourly( $rt_id ) ) {
                    $price = MPHB_Hourly_Price::calc( $rt_id, $duration );
                    printf(
                        '<tr><th>%s</th><td>%s %s</td></tr>',
                        esc_html__( 'Prix horaire', 'mphb-hourly' ),
                        esc_html( MPHB()->settings()->currency()->getCurrencySymbol() ),
                        esc_html( number_format( $price, 2 ) )
                    );
                }
            }
        }
        echo '</tbody></table>';
    }

   

    public static function append_slot_to_booking_details( string $html, $booking ): string {
        if ( ! $booking instanceof \MPHB\Entities\Booking ) return $html;

        $start    = MPHB_Hourly_Helper::booking_start( $booking->getId() );
        $end      = MPHB_Hourly_Helper::booking_end( $booking->getId() );
        $duration = MPHB_Hourly_Helper::booking_duration( $booking->getId() );

        if ( ! $start || ! $end ) return $html;

        $slot_str = $start . ' – ' . $end;
        if ( $duration ) {
            $slot_str .= ' (' . MPHB_Hourly_Helper::format_duration( $duration ) . ')';
        }

        $insert = '<p class="mphb-hourly-slot-info"><strong>'
                . esc_html__( 'Créneau :', 'mphb-hourly' )
                . '</strong> ' . esc_html( $slot_str ) . '</p>';

        return $html . $insert;
    }

    

    private static function get_booking_from_request(): ?\MPHB\Entities\Booking {
        if ( ! isset( $_GET['booking_id'], $_GET['booking_key'] ) ) return null;

        $bid = absint( $_GET['booking_id'] );
        $key = sanitize_text_field( wp_unslash( $_GET['booking_key'] ) );

        $booking = MPHB()->getBookingRepository()->findById( $bid );
        if ( ! $booking || $booking->getKey() !== $key ) return null;

        return $booking;
    }

   
    private static function get_slot_html( int $bid ): string {
        $start    = MPHB_Hourly_Helper::booking_start( $bid );
        $end      = MPHB_Hourly_Helper::booking_end( $bid );
        $duration = MPHB_Hourly_Helper::booking_duration( $bid );

        if ( ! $start || ! $end ) return '';

        $slot_str = $start . ' – ' . $end;
        if ( $duration ) {
            $slot_str .= ' · ' . MPHB_Hourly_Helper::format_duration( $duration );
        }

        return '<div class="mphb-hourly-slot-confirmation" style="margin:12px 0;padding:10px 14px;background:#f0f7ff;border-left:4px solid #0073aa;border-radius:2px">'
             . '<strong>' . esc_html__( 'Créneau réservé :', 'mphb-hourly' ) . '</strong> '
             . '<span>' . esc_html( $slot_str ) . '</span>'
             . '</div>';
    }
}
