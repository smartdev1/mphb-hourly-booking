<?php
defined( 'ABSPATH' ) || exit;

class MPHB_Hourly_Confirmation {

    public static function init() {
        add_action( 'mphb_sc_booking_confirmation_booking_details',
            array( __CLASS__, 'render_slot_on_confirmation' ), 10 );
        add_action( 'add_meta_boxes',
            array( __CLASS__, 'add_booking_meta_box' ) );
        add_action( 'mphb_sc_checkout_form',
            array( __CLASS__, 'inject_checkout_slot_details' ), 1 );
        add_filter( 'mphb_booking_details_html',
            array( __CLASS__, 'append_slot_to_booking_details' ), 10, 2 );
    }

    /* confirmation page after booking created */
    public static function render_slot_on_confirmation( $booking = null ) {
        if ( ! $booking instanceof \MPHB\Entities\Booking ) {
            $booking = self::get_booking_from_request();
        }
        if ( ! $booking ) return;
        echo wp_kses_post( self::get_slot_html( $booking->getId() ) );
    }

    /* admin meta box */
    public static function add_booking_meta_box() {
        add_meta_box(
            'mphb_hourly_booking_slot',
            'Creneau horaire',
            array( __CLASS__, 'render_booking_meta_box' ),
            MPHB()->postTypes()->booking()->getPostType(),
            'side',
            'high'
        );
    }

    public static function render_booking_meta_box( $post ) {
        $start    = MPHB_Hourly_Helper::booking_start( $post->ID );
        $end      = MPHB_Hourly_Helper::booking_end( $post->ID );
        $duration = MPHB_Hourly_Helper::booking_duration( $post->ID );
        if ( ! $start || ! $end ) {
            echo '<p style="color:#999">Reservation journaliere.</p>';
            return;
        }
        echo '<table class="form-table" style="margin:0"><tbody>';
        printf( '<tr><th>Creneau</th><td><strong>%s &rarr; %s</strong></td></tr>',
            esc_html( $start ), esc_html( $end ) );
        if ( $duration ) {
            printf( '<tr><th>Duree</th><td>%s</td></tr>',
                esc_html( MPHB_Hourly_Helper::format_duration( $duration ) ) );
        }
        echo '</tbody></table>';
    }

    /* filter on booking details html (emails etc.) */
    public static function append_slot_to_booking_details( $html, $booking ) {
        if ( ! $booking instanceof \MPHB\Entities\Booking ) return $html;
        $start    = MPHB_Hourly_Helper::booking_start( $booking->getId() );
        $end      = MPHB_Hourly_Helper::booking_end( $booking->getId() );
        $duration = MPHB_Hourly_Helper::booking_duration( $booking->getId() );
        if ( ! $start || ! $end ) return $html;
        $slot = $start . ' - ' . $end;
        if ( $duration ) {
            $slot .= ' (' . MPHB_Hourly_Helper::format_duration( $duration ) . ')';
        }
        return $html . '<p class="mphb-hourly-slot-info"><strong>Creneau :</strong> '
             . esc_html( $slot ) . '</p>';
    }

    /* inject rewrite script into checkout step booking form */
    public static function inject_checkout_slot_details() {
        $start = isset( $_POST['mphb_hourly_start'] )
            ? sanitize_text_field( wp_unslash( $_POST['mphb_hourly_start'] ) ) : '';
        $end   = isset( $_POST['mphb_hourly_end'] )
            ? sanitize_text_field( wp_unslash( $_POST['mphb_hourly_end'] ) )   : '';

        if ( ! $start ) $start = isset( $GLOBALS['mphb_hourly_start'] ) ? $GLOBALS['mphb_hourly_start'] : '';
        if ( ! $end )   $end   = isset( $GLOBALS['mphb_hourly_end'] )   ? $GLOBALS['mphb_hourly_end']   : '';

        if ( ! $start || ! $end ) return;
        if ( ! preg_match( '/^\d{2}:\d{2}$/', $start ) ) return;
        if ( ! preg_match( '/^\d{2}:\d{2}$/', $end ) )   return;

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
        if ( ! $rt_id ) {
            $rt_id = isset( $GLOBALS['mphb_hourly_rt_id'] ) ? (int) $GLOBALS['mphb_hourly_rt_id'] : 0;
        }

        $price = ( $rt_id && MPHB_Hourly_Helper::is_hourly( $rt_id ) && $duration > 0 )
            ? MPHB_Hourly_Price::calc( $rt_id, $duration )
            : 0.0;

        $currency  = MPHB()->settings()->currency()->getCurrencySymbol();
        $dur_label = MPHB_Hourly_Helper::format_duration( $duration );
        $price_fmt = $price > 0 ? number_format( $price, 2 ) : '0.00';

        /* hidden fields for next step */
        printf( '<input type="hidden" name="mphb_hourly_start" value="%s">', esc_attr( $start ) );
        printf( '<input type="hidden" name="mphb_hourly_end"   value="%s">', esc_attr( $end ) );

        /* all dynamic values encoded as JSON - safe against any special chars */
        $j_start    = wp_json_encode( $start );
        $j_end      = wp_json_encode( $end );
        $j_dur      = wp_json_encode( $dur_label );
        $j_currency = wp_json_encode( $currency );
        $j_price    = wp_json_encode( $price_fmt );

        echo '<script>' . "\n";
        echo '(function(){' . "\n";
        echo '  var START    = ' . $j_start    . ";\n";
        echo '  var END      = ' . $j_end      . ";\n";
        echo '  var DUR      = ' . $j_dur      . ";\n";
        echo '  var CURRENCY = ' . $j_currency . ";\n";
        echo '  var PRICE    = ' . $j_price    . ";\n";
        echo '  var pH = \'<span class="mphb-price"><span class="mphb-currency">\' + CURRENCY + \'</span>\' + PRICE + \'</span>\';' . "\n";
        echo '  function run(){' . "\n";
        echo '    var sec = document.getElementById(\'mphb-booking-details\');' . "\n";
        echo '    if(!sec) return;' . "\n";
        echo '    sec.querySelectorAll(\'p.mphb-check-in-date, p.mphb-check-out-date\').forEach(function(p){ p.style.display=\'none\'; });' . "\n";
        echo '    if(sec.querySelector(\'.mphb-hourly-slot\')) return;' . "\n";
        echo '    var di = document.querySelector(\'input[name="mphb_check_in_date"]\');' . "\n";
        echo '    var dv = di ? di.value : \'\';' . "\n";
        echo '    var dd = dv;' . "\n";
        echo '    if(/^\\d{4}-\\d{2}-\\d{2}$/.test(dv)){ var p=dv.split(\'-\'); dd=p[2]+\'/\'+p[1]+\'/\'+p[0]; }' . "\n";
        echo '    var h = \'<div class="mphb-hourly-slot" style="margin:8px 0">\'' . "\n";
        echo '          + \'<p class="mphb-check-in-date"><span>Arriv\u00e9e :</span> <time><strong>\' + dd + \'</strong></time>, <span>\u00e0 partir de</span> <time>\' + START + \'</time></p>\'' . "\n";
        echo '          + \'<p class="mphb-check-out-date"><span>D\u00e9part :</span> <time><strong>\' + dd + \'</strong></time>, <span>jusqu\u0027\u00e0</span> <time>\' + END + \'</time></p>\'' . "\n";
        echo '          + \'</div>\';' . "\n";
        echo '    var t = sec.querySelector(\'.mphb-booking-details-title\');' . "\n";
        echo '    if(t){ t.insertAdjacentHTML(\'afterend\',h); } else { sec.insertAdjacentHTML(\'afterbegin\',h); }' . "\n";
        echo '    document.querySelectorAll(\'.mphb-price-breakdown .mphb-table-price-column .mphb-price,.mphb-price-breakdown-total .mphb-table-price-column .mphb-price,.mphb-price-breakdown-accommodation-total .mphb-table-price-column .mphb-price,.mphb-price-breakdown-subtotal .mphb-table-price-column .mphb-price\').forEach(function(e){ e.outerHTML=pH; });' . "\n";
        echo '    document.querySelectorAll(\'.mphb-price-breakdown-nights\').forEach(function(r){ var c=r.querySelectorAll(\'td,th\'); if(c[0]) c[0].textContent=\'Cr\u00e9neau :\'; if(c[1]) c[1].textContent=DUR; });' . "\n";
        echo '    document.querySelectorAll(\'.mphb-price-period\').forEach(function(e){ e.textContent=\'pour ce cr\u00e9neau\'; });' . "\n";
        echo '    document.querySelectorAll(\'.mphb-total-price-field .mphb-price,.mphb-total-price .mphb-price\').forEach(function(e){ e.outerHTML=pH; });' . "\n";
        echo '    if(typeof MPHB!==\'undefined\'&&MPHB._data){' . "\n";
        echo '      if(MPHB._data.checkout) MPHB._data.checkout.total=parseFloat(PRICE);' . "\n";
        echo '      if(MPHB._data.gateways){ for(var g in MPHB._data.gateways){ if(MPHB._data.gateways.hasOwnProperty(g)) MPHB._data.gateways[g].amount=parseFloat(PRICE); } }' . "\n";
        echo '    }' . "\n";
        echo '  }' . "\n";
        echo '  document.addEventListener(\'DOMContentLoaded\',function(){ run(); setTimeout(run,400); setTimeout(run,1200); });' . "\n";
        echo '})();' . "\n";
        echo '</script>' . "\n";
    }

    /* helpers */
    private static function get_booking_from_request() {
        if ( ! isset( $_GET['booking_id'], $_GET['booking_key'] ) ) return null;
        $bid = absint( $_GET['booking_id'] );
        $key = sanitize_text_field( wp_unslash( $_GET['booking_key'] ) );
        $booking = MPHB()->getBookingRepository()->findById( $bid );
        if ( ! $booking || $booking->getKey() !== $key ) return null;
        return $booking;
    }

    private static function get_slot_html( $bid ) {
        $start    = MPHB_Hourly_Helper::booking_start( $bid );
        $end      = MPHB_Hourly_Helper::booking_end( $bid );
        $duration = MPHB_Hourly_Helper::booking_duration( $bid );
        if ( ! $start || ! $end ) return '';
        $slot = $start . ' - ' . $end;
        if ( $duration ) {
            $slot .= ' - ' . MPHB_Hourly_Helper::format_duration( $duration );
        }
        return '<div class="mphb-hourly-slot-confirmation" style="margin:12px 0;padding:10px 14px;background:#f0f7ff;border-left:4px solid #060097;border-radius:2px">'
             . '<strong>Creneau reserve :</strong> <span>' . esc_html( $slot ) . '</span></div>';
    }
}