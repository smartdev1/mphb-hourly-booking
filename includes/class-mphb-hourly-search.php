<?php

defined( 'ABSPATH' ) || exit;

class MPHB_Hourly_Search {

    public static function init(): void {
        // Contexte A : page single room type
        add_action(
            'mphb_render_single_room_type_after_reservation_form',
            [ __CLASS__, 'inject_mount_point' ]
        );

        // Contexte B : page de résultats — injecter dans le panier de réservation
        add_action(
            'mphb_sc_search_results_reservation_cart_before',
            [ __CLASS__, 'inject_hourly_in_reservation_cart' ]
        );

        // ── CORRECTIF : injecter dans le formulaire de recommandation ──────
        add_action(
            'mphb_sc_search_results_recommendation_after',
            [ __CLASS__, 'inject_hourly_after_recommendation' ]
        );

        // ── CORRECTIF : injecter dans chaque carte de logement (bouton Réserver) ──
        add_action(
            'mphb_sc_search_results_room_after',
            [ __CLASS__, 'inject_hourly_in_room_card' ]
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
           . '>';

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

    /**
     * Injecter dans le formulaire #mphb-reservation-cart (panier latéral).
     * Hook : mphb_sc_search_results_reservation_cart_before
     */
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

    /**
     * ── CORRECTIF ──
     * Injecter les champs horaires dans le formulaire #mphb-recommendation
     * via JS après le rendu, car le hook s'exécute après </form>.
     * Hook : mphb_sc_search_results_recommendation_after
     */
    public static function inject_hourly_after_recommendation(): void {
        $start = sanitize_text_field( $_GET['mphb_hourly_start'] ?? '' );
        $end   = sanitize_text_field( $_GET['mphb_hourly_end']   ?? '' );

        if ( ! $start || ! $end ) return;
        if ( ! preg_match( '/^\d{2}:\d{2}$/', $start ) ) return;
        if ( ! preg_match( '/^\d{2}:\d{2}$/', $end ) )   return;

        $start_js = esc_js( $start );
        $end_js   = esc_js( $end );

        // Injecter les champs directement dans le formulaire via JS
        echo "<script>
(function(){
    function injectHourlyInRecommendation() {
        var form = document.getElementById('mphb-recommendation');
        if ( ! form ) return;

        // Éviter double injection
        if ( form.querySelector('input[name=\"mphb_hourly_start\"]') ) return;

        var s = document.createElement('input');
        s.type = 'hidden';
        s.name = 'mphb_hourly_start';
        s.value = '{$start_js}';
        form.appendChild(s);

        var e = document.createElement('input');
        e.type = 'hidden';
        e.name = 'mphb_hourly_end';
        e.value = '{$end_js}';
        form.appendChild(e);
    }

    if ( document.readyState === 'loading' ) {
        document.addEventListener('DOMContentLoaded', injectHourlyInRecommendation);
    } else {
        injectHourlyInRecommendation();
    }
})();
</script>";
    }

    /**
     * ── CORRECTIF ──
     * Injecter les champs horaires dans chaque formulaire de logement individuel
     * (bouton "Réserver" sur chaque carte).
     * Hook : mphb_sc_search_results_room_after
     */
    public static function inject_hourly_in_room_card(): void {
        $start = sanitize_text_field( $_GET['mphb_hourly_start'] ?? '' );
        $end   = sanitize_text_field( $_GET['mphb_hourly_end']   ?? '' );

        if ( ! $start || ! $end ) return;
        if ( ! preg_match( '/^\d{2}:\d{2}$/', $start ) ) return;
        if ( ! preg_match( '/^\d{2}:\d{2}$/', $end ) )   return;

        $rt_id    = (int) get_the_ID();
        $start_js = esc_js( $start );
        $end_js   = esc_js( $end );

        // Les formulaires individuels n'ont pas d'ID unique standard ;
        // on les cible via le conteneur parent data-room-type-id
        echo "<script>
(function(){
    function injectHourlyInRoomCard() {
        var section = document.querySelector('.mphb-reserve-room-section[data-room-type-id=\"{$rt_id}\"]');
        if ( ! section ) return;

        // Chercher le formulaire parent #mphb-reservation-cart ou le plus proche
        var form = document.getElementById('mphb-reservation-cart');
        if ( ! form ) return;

        // Éviter double injection pour ce rt_id
        if ( form.querySelector('input[name=\"mphb_hourly_start\"]') ) return;

        var s = document.createElement('input');
        s.type = 'hidden';
        s.name = 'mphb_hourly_start';
        s.value = '{$start_js}';
        form.appendChild(s);

        var e = document.createElement('input');
        e.type = 'hidden';
        e.name = 'mphb_hourly_end';
        e.value = '{$end_js}';
        form.appendChild(e);
    }

    if ( document.readyState === 'loading' ) {
        document.addEventListener('DOMContentLoaded', injectHourlyInRoomCard);
    } else {
        injectHourlyInRoomCard();
    }
})();
</script>";
    }

    private static function render_slot_summary( string $start, string $end ): void {
        $start_m  = MPHB_Hourly_Helper::to_minutes( $start );
        $end_m    = MPHB_Hourly_Helper::to_minutes( $end );
        $duration = $end_m - $start_m;

        $rt_id    = isset( $_GET['mphb_room_type_id'] ) ?
                    (int) $_GET['mphb_room_type_id'] : 0;
        $price    = 0.0;
        $currency = MPHB()->settings()->currency()->getCurrencySymbol();

        if ( $rt_id && MPHB_Hourly_Helper::is_hourly( $rt_id ) && $duration > 0 ) {
            $price = MPHB_Hourly_Price::calc( $rt_id, $duration );
        }

        $dur_label = $duration
            ? ' (' . esc_html( MPHB_Hourly_Helper::format_duration( $duration ) ) . ')'
            : '';

        $price_str = $price > 0
            ? ' &middot; ' . esc_html( $currency ) . number_format( $price, 2 )
            : '';

        echo '<div class="mphb-hourly-cart-summary" style="margin:8px 0;padding:8px 12px;'
           . 'background:#f0f7ff;border-left:3px solid #0073aa;font-size:13px;">'
           . '<strong>' . esc_html__( 'Créneau :', 'mphb-hourly' ) . '</strong> '
           . esc_html( $start . ' – ' . $end )
           . $dur_label
           . $price_str
           . '</div>';

        // Corriger via JS les prix €0 que MPHB affiche (0 nuit × tarif = 0)
        if ( $price > 0 && $rt_id ) {
            $price_html = esc_js( number_format( $price, 2 ) . $currency );
            echo '<script>
(function(){
    function fixPrices(){
        // Prix sur la carte du logement
        var card = document.querySelector(".mphb-room-type.post-' . $rt_id . '");
        if(card){
            var priceEl = card.querySelector(".mphb-regular-price .mphb-price");
            if(priceEl){ priceEl.innerHTML = "' . $price_html . '"; }
            var period = card.querySelector(".mphb-price-period");
            if(period){ period.textContent = "pour ce cr\u00e9neau"; }
        }
        // Prix dans le bloc recommandation
        var recSub = document.querySelector(".mphb-recommedation-item-subtotal .mphb-price");
        if(recSub){ recSub.innerHTML = "' . $price_html . '"; }
        var recTot = document.querySelector(".mphb-recommendation-total-value .mphb-price");
        if(recTot){ recTot.innerHTML = "' . $price_html . '"; }
    }
    document.addEventListener("DOMContentLoaded", function(){
        fixPrices();
        setTimeout(fixPrices, 600);
        setTimeout(fixPrices, 1800);
    });
})();
</script>';
        }
    }
}