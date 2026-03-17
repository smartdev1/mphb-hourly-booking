<?php
/**
 * Affichage du créneau horaire sur la page de confirmation + checkout.
 */
defined( 'ABSPATH' ) || exit;

class MPHB_Hourly_Confirmation {

    public static function init(): void {

        add_action( 'mphb_sc_booking_confirmation_booking_details',
            [ __CLASS__, 'render_slot_on_confirmation' ], 10 );

        add_action( 'add_meta_boxes', [ __CLASS__, 'add_booking_meta_box' ] );

        add_action( 'mphb_sc_checkout_form', [ __CLASS__, 'inject_checkout_slot_details' ], 1 );

        add_filter( 'mphb_booking_details_html', [ __CLASS__, 'append_slot_to_booking_details' ], 10, 2 );

        add_action( 'wp_footer', [ __CLASS__, 'inject_checkout_rewrite_script' ] );
    }

    public static function render_slot_on_confirmation( $booking = null ): void {
        if ( ! $booking instanceof \MPHB\Entities\Booking ) {
            $booking = self::get_booking_from_request();
        }
        if ( ! $booking ) return;

        echo wp_kses_post( self::get_slot_html( $booking->getId() ) );
    }

    public static function add_booking_meta_box(): void {
        add_meta_box(
            'mphb_hourly_booking_slot',
            __( '⏱ Créneau horaire', 'mphb-hourly' ),
            [ __CLASS__, 'render_booking_meta_box' ],
            MPHB()->postTypes()->booking()->getPostType(),
            'side',
            'high'
        );
    }

    public static function render_booking_meta_box( \WP_Post $post ): void {
        $start    = MPHB_Hourly_Helper::booking_start( $post->ID );
        $end      = MPHB_Hourly_Helper::booking_end( $post->ID );
        $duration = MPHB_Hourly_Helper::booking_duration( $post->ID );

        if ( ! $start || ! $end ) {
            echo '<p style="color:#999">' . esc_html__( 'Réservation journalière.', 'mphb-hourly' ) . '</p>';
            return;
        }

        echo '<table class="form-table" style="margin:0"><tbody>';
        printf( '<tr><th>%s</th><td><strong>%s → %s</strong></td></tr>',
            esc_html__( 'Créneau', 'mphb-hourly' ),
            esc_html( $start ), esc_html( $end )
        );
        if ( $duration ) {
            printf( '<tr><th>%s</th><td>%s</td></tr>',
                esc_html__( 'Durée', 'mphb-hourly' ),
                esc_html( MPHB_Hourly_Helper::format_duration( $duration ) )
            );
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

    public static function inject_checkout_slot_details(): void {
        $start = sanitize_text_field( wp_unslash( $_POST['mphb_hourly_start'] ?? '' ) );
        $end   = sanitize_text_field( wp_unslash( $_POST['mphb_hourly_end']   ?? '' ) );

        if ( ! $start ) $start = $GLOBALS['mphb_hourly_start'] ?? '';
        if ( ! $end )   $end   = $GLOBALS['mphb_hourly_end']   ?? '';

        if ( ! $start || ! $end ) return;
        if ( ! preg_match( '/^\d{2}:\d{2}$/', $start ) || ! preg_match( '/^\d{2}:\d{2}$/', $end ) ) return;

        $duration = max( 0, MPHB_Hourly_Helper::to_minutes( $end ) - MPHB_Hourly_Helper::to_minutes( $start ) );

        $rt_id = 0;
        if ( ! empty( $_POST['mphb_room_details'] ) && is_array( $_POST['mphb_room_details'] ) ) {
            foreach ( $_POST['mphb_room_details'] as $detail ) {
                if ( ! empty( $detail['room_type_id'] ) ) {
                    $rt_id = (int) $detail['room_type_id'];
                    break;
                }
            }
        }

        $price = ( $rt_id && MPHB_Hourly_Helper::is_hourly( $rt_id ) && $duration > 0 )
            ? MPHB_Hourly_Price::calc( $rt_id, $duration )
            : 0.0;

        $currency     = MPHB()->settings()->currency()->getCurrencySymbol();
        $dur_label    = MPHB_Hourly_Helper::format_duration( $duration );
        $price_fmt    = $price > 0 ? number_format( $price, 2 ) : '—';

        $start_js     = esc_js( $start );
        $end_js       = esc_js( $end );
        $dur_js       = esc_js( $dur_label );
        $currency_js  = esc_js( $currency );
        $price_js     = esc_js( $price_fmt );

        $lbl_date     = esc_js( __( 'Date :', 'mphb-hourly' ) );
        $lbl_arr      = esc_js( __( 'Heure d\'arrivée :', 'mphb-hourly' ) );
        $lbl_dep      = esc_js( __( 'Heure de départ :', 'mphb-hourly' ) );
        $lbl_dur      = esc_js( __( 'Durée :', 'mphb-hourly' ) );
        $lbl_slot     = esc_js( __( 'Créneau :', 'mphb-hourly' ) );
        $lbl_period   = esc_js( __( 'pour ce créneau', 'mphb-hourly' ) );

        echo "<script>
(function(){
    function rewriteCheckoutDetails() {

        var section = document.getElementById('mphb-booking-details');
        if ( ! section ) return;

        var paras = section.querySelectorAll('p.mphb-check-in-date, p.mphb-check-out-date');
        paras.forEach(function(p){ p.style.display = 'none'; });

        if ( section.querySelector('.mphb-hourly-checkout-slot') ) return;

        var dateInput = document.querySelector('input[name=\"mphb_check_in_date\"]');
        var dateVal = dateInput ? dateInput.value : '';

        var dateDisplay = dateVal;
        if ( /^\\d{4}-\\d{2}-\\d{2}$/.test(dateVal) ) {
            var p = dateVal.split('-');
            dateDisplay = p[2] + '/' + p[1] + '/' + p[0];
        }

        var html = '<div class=\"mphb-hourly-checkout-slot\" style=\"margin:12px 0;padding:12px 16px;background:#f0f7ff;border-left:4px solid #060097;border-radius:4px;\">'
            + '<p><strong>{$lbl_date}</strong> ' + dateDisplay + '</p>'
            + '<p><strong>{$lbl_arr}</strong> {$start_js}</p>'
            + '<p><strong>{$lbl_dep}</strong> {$end_js}</p>'
            + '<p><strong>{$lbl_dur}</strong> {$dur_js}</p>'
            + '</div>';

        var title = section.querySelector('.mphb-booking-details-title');
        if ( title ) {
            title.insertAdjacentHTML('afterend', html);
        } else {
            section.insertAdjacentHTML('afterbegin', html);
        }

        if ( '{$price_fmt}' !== '—' ) {
            var priceHTML = '<span class=\"mphb-price\"><span class=\"mphb-currency\">{$currency_js}</span>{$price_js}</span>';

            document.querySelectorAll('.mphb-price-breakdown .mphb-table-price-column .mphb-price').forEach(function(el){
                el.outerHTML = priceHTML;
            });

            document.querySelectorAll('.mphb-price-breakdown-total .mphb-table-price-column .mphb-price').forEach(function(el){
                el.outerHTML = priceHTML;
            });

            var totalField = document.querySelector('.mphb-total-price-field .mphb-price');
            if ( totalField ) totalField.outerHTML = priceHTML;

            /* Libellé \"par nuit\" → \"pour ce créneau\" */
            document.querySelectorAll('.mphb-price-period').forEach(function(el){
                el.textContent = '{$lbl_period}';
            });

            document.querySelectorAll('.mphb-price-breakdown-nights td:last-child').forEach(function(el){
                el.textContent = '{$dur_js}';
            });

            document.querySelectorAll('.mphb-price-breakdown-nights td:first-child').forEach(function(el){
                el.textContent = '{$lbl_slot}';
            });
        }
    }

    document.addEventListener('DOMContentLoaded', function(){
        rewriteCheckoutDetails();
        setTimeout(rewriteCheckoutDetails, 500);
        setTimeout(rewriteCheckoutDetails, 1500);
    });
})();
</script>";
    }

    public static function inject_checkout_rewrite_script(): void {}

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

        return '<div class=\"mphb-hourly-slot-confirmation\" style=\"margin:12px 0;padding:10px 14px;background:#f0f7ff;border-left:4px solid #060097;border-radius:2px\">'
             . '<strong>' . esc_html__( 'Créneau réservé :', 'mphb-hourly' ) . '</strong> '
             . '<span>' . esc_html( $slot_str ) . '</span>'
             . '</div>';
    }
}