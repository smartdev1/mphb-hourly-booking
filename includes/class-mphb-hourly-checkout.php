<?php
/**
 * PATCH 2 — Gestion du checkout pour les réservations horaires
 *
 * Deux étapes MPHB posent problème :
 *
 * ── StepCheckout (page de résumé, avant paiement) ──────────────────────────
 *   A) parseCheckOutDate() rejette check_in = check_out (0 nuit)
 *   B) getActiveRates() retourne vide pour 0 nuit → erreur "no rates"
 *   C) isBookingRulesViolated() retourne true pour 0 nuit → erreur rules
 *
 * ── StepBooking (confirmation finale) ───────────────────────────────────────
 *   D) parseCheckOutDate() idem problème A
 *   E) parseBookingData() valide rate_id via getActiveRates() → rejette rate_id=0
 *   F) isBookingRulesViolated() idem problème C
 *   G) getUnavailableRoomIds() appelle findLockedRooms() sans contexte horaire
 *      (GLOBALS vides à ce stade) → peut bloquer des chambres disponibles
 *
 * Solutions :
 *   - filter mphb_sc_checkout_parse_check_out_date         → patches A & D
 *   - filter mphb_sc_checkout_step_checkout_pre_validate_selected_rooms → patch B+C
 *   - filter mphb_sc_checkout_step_checkout_reserved_rooms → patch B
 *   - filter mphb_sc_checkout_step_booking_rooms_details   → patch E & F
 *   - filter mphb_search_available_rooms                   → patch G
 */
defined( 'ABSPATH' ) || exit;

class MPHB_Hourly_Checkout {

    public static function init(): void {

        // ── Lecture des champs horaires depuis POST (priorité 1 = avant tout) ─
        add_action( 'init', [ __CLASS__, 'read_post_time_fields' ], 1 );

        // ── PATCH A & D : autoriser check_in = check_out ─────────────────────
        add_filter( 'mphb_sc_checkout_parse_check_out_date',
            [ __CLASS__, 'allow_same_day_checkout' ], 5, 3 );

        // ── PATCH B & C : StepCheckout — supprimer erreurs avant validation ──
        add_filter( 'mphb_sc_checkout_step_checkout_pre_validate_selected_rooms',
            [ __CLASS__, 'clear_errors_if_hourly' ], 5, 2 );

        // ── PATCH B : StepCheckout — injecter reserved rooms sans rate ───────
        add_filter( 'mphb_sc_checkout_step_checkout_reserved_rooms',
            [ __CLASS__, 'inject_hourly_reserved_rooms' ], 5, 3 );

        // ── PATCH E & F : StepBooking — court-circuiter validation rate/rules ─
        add_filter( 'mphb_sc_checkout_step_booking_rooms_details',
            [ __CLASS__, 'bypass_booking_rate_validation' ], 5 );

        // ── PATCH G : StepBooking — réinjecter le contexte horaire dans GLOBALS
        //    pour que refilter_for_hourly() puisse vérifier les créneaux
        add_filter( 'mphb_search_available_rooms',
            [ __CLASS__, 'inject_hourly_context_for_room_search' ], 5 );

        // ── Champs cachés transmis entre étapes ──────────────────────────────
        add_action( 'mphb_sc_checkout_form', [ __CLASS__, 'render_hidden_fields' ], 5 );
    }

    /* -----------------------------------------------------------------------
     * Lecture des champs POST horaires
     * Alimenter $GLOBALS tôt pour tous les hooks suivants.
     * -------------------------------------------------------------------- */

    public static function read_post_time_fields(): void {
        // Fonctionner aussi bien sur POST (StepCheckout) que GET (AJAX)
        $start = sanitize_text_field( wp_unslash( $_POST['mphb_hourly_start'] ?? $_GET['mphb_hourly_start'] ?? '' ) );
        $end   = sanitize_text_field( wp_unslash( $_POST['mphb_hourly_end']   ?? $_GET['mphb_hourly_end']   ?? '' ) );

        if ( ! self::valid_time( $start ) || ! self::valid_time( $end ) ) return;
        if ( MPHB_Hourly_Helper::to_minutes( $end ) <= MPHB_Hourly_Helper::to_minutes( $start ) ) return;

        $GLOBALS['mphb_hourly_start']    = $start;
        $GLOBALS['mphb_hourly_end']      = $end;
        $GLOBALS['mphb_hourly_duration'] = MPHB_Hourly_Helper::to_minutes( $end )
                                         - MPHB_Hourly_Helper::to_minutes( $start );
    }

    private static function valid_time( string $t ): bool {
        return (bool) preg_match( '/^\d{2}:\d{2}$/', $t );
    }

    private static function is_hourly_request(): bool {
        return ! empty( $GLOBALS['mphb_hourly_start'] );
    }

    /* -----------------------------------------------------------------------
     * PATCH A & D : autoriser check_in = check_out (réservation 0 nuit)
     * -------------------------------------------------------------------- */

    public static function allow_same_day_checkout( $check_out, $date_string, $check_in ) {
        if ( ! self::is_hourly_request() ) return $check_out;

        // MPHB a retourné null car 0 nuits → on clone check_in
        if ( is_null( $check_out ) && $check_in instanceof \DateTime ) {
            $check_out = clone $check_in;
        }

        return $check_out;
    }

    /* -----------------------------------------------------------------------
     * PATCH B & C : StepCheckout — vider les erreurs "no rates" / "rules violated"
     *
     * filter: mphb_sc_checkout_step_checkout_pre_validate_selected_rooms
     *   args: ( $this->errors, $selectedRooms )
     * -------------------------------------------------------------------- */

    public static function clear_errors_if_hourly( array $errors, array $selected_rooms ): array {
        if ( ! self::is_hourly_request() ) return $errors;

        foreach ( array_keys( $selected_rooms ) as $rt_id ) {
            if ( MPHB_Hourly_Helper::is_hourly( (int) $rt_id ) ) {
                return []; // Efface les erreurs pour laisser passer
            }
        }

        return $errors;
    }

    /* -----------------------------------------------------------------------
     * PATCH B : StepCheckout — remplacer les reserved rooms construits avec rate
     *
     * filter: mphb_sc_checkout_step_checkout_reserved_rooms
     *   args: ( $reservedRooms, $roomDetails, $selectedRooms )
     * -------------------------------------------------------------------- */

    public static function inject_hourly_reserved_rooms(
        array $reserved_rooms,
        array $room_details,
        array $selected_rooms
    ): array {
        if ( ! self::is_hourly_request() ) return $reserved_rooms;

        $injected = [];

        foreach ( array_keys( $selected_rooms ) as $rt_id ) {
            $rt_id = (int) $rt_id;

            if ( ! MPHB_Hourly_Helper::is_hourly( $rt_id ) ) {
                // Chambres non-horaires : conserver telles quelles
                foreach ( $reserved_rooms as $rr ) {
                    $injected[] = $rr;
                }
                continue;
            }

            $count = (int) $selected_rooms[ $rt_id ];
            $rt    = MPHB()->getRoomTypeRepository()->findById( $rt_id );
            if ( ! $rt ) continue;

            for ( $i = 0; $i < $count; $i++ ) {
                $injected[] = \MPHB\Entities\ReservedRoom::create( [
                    'rate_id'  => 0,
                    'adults'   => $rt->getAdultsCapacity(),
                    'children' => 0,
                ] );
            }
        }

        return empty( $injected ) ? $reserved_rooms : $injected;
    }

    /* -----------------------------------------------------------------------
     * PATCH E & F : StepBooking — court-circuiter validation rate_id et booking rules
     *
     * StepBooking::parseBookingData() valide chaque room_detail :
     *   1. $rateId doit être > 0 (validateInt)
     *   2. $rateId doit être dans getActiveRates() (vide pour 0 nuit)
     *   3. isBookingRulesViolated() retourne true pour 0 nuit
     *
     * Cette méthode ne peut pas être contournée uniquement via des filtres
     * extérieurs sans modifier step-booking.php.
     *
     * DEUX NIVEAUX DE PROTECTION :
     *
     * Niveau 1 (sans patch core) : on injecte un fake rate_id valide +
     *   on force isBookingRulesForAdmin=true via mphb_is_current_request_for_admin_ui.
     *   Le problème restant : getActiveRates() retourne [] pour 0 nuit → in_array() échoue.
     *
     * Niveau 2 (avec patch step-booking.php) : voir patches/step-booking.patch.
     *   Les 3 vérifications sont conditionnées à _mphb_hourly_enabled != 1.
     *   C'est la solution propre et définitive.
     *
     * On implémente les deux niveaux : le niveau 1 comme best-effort sans patch,
     * le niveau 2 comme solution finale recommandée.
     *
     * filter: mphb_sc_checkout_step_booking_rooms_details
     * -------------------------------------------------------------------- */

    public static function bypass_booking_rate_validation( array $booking_details ): array {
        if ( ! self::is_hourly_request() ) return $booking_details;

        $has_hourly = false;

        foreach ( $booking_details as $index => &$room_detail ) {
            $rt_id = isset( $room_detail['room_type_id'] )
                ? (int) $room_detail['room_type_id']
                : 0;

            if ( ! $rt_id || ! MPHB_Hourly_Helper::is_hourly( $rt_id ) ) continue;
            $has_hourly = true;

            // Injecter un fake rate_id valide pour passer validateInt()
            if ( empty( $room_detail['rate_id'] ) || (int) $room_detail['rate_id'] === 0 ) {
                $fake_rate_id = self::get_any_rate_for( $rt_id );
                if ( $fake_rate_id ) {
                    $room_detail['rate_id']           = $fake_rate_id;
                    $room_detail['_hourly_fake_rate'] = true;
                }
            }
        }
        unset( $room_detail );

        if ( ! $has_hourly ) return $booking_details;

        // Niveau 1 : bypasser isBookingRulesViolated() via le filtre admin_ui
        add_filter( 'mphb_is_current_request_for_admin_ui',
            [ __CLASS__, 'force_admin_rules_disabled' ], 99 );

        // Niveau 1 : bypasser in_array($rateId, $allowedRatesIds) en forçant
        // getActiveRates() à retourner un résultat non-vide pour les room horaires.
        // On filtre la façade de prix pour injecter un rate factice dans la liste.
        add_filter( 'mphb_prices_get_active_rates',
            [ __CLASS__, 'inject_fake_rate_into_allowed_list' ], 99, 4 );

        // Rétablir rate_id=0 dans les booking_details finaux
        add_filter( 'mphb_sc_checkout_step_booking_booking_details',
            [ __CLASS__, 'restore_zero_rate_id' ], 5 );

        return $booking_details;
    }

    /**
     * Force le mode admin pour bypasser isBookingRulesViolated().
     */
    public static function force_admin_rules_disabled( $val ): bool {
        remove_filter( 'mphb_is_current_request_for_admin_ui',
            [ __CLASS__, 'force_admin_rules_disabled' ], 99 );
        return true;
    }

    /**
     * Injecter un rate factice dans la liste des rates autorisés pour
     * les room types horaires, afin de passer le in_array() de StepBooking.
     *
     * Note : ce filtre n'existe peut-être pas nativement dans MPHB.
     * Si MPHB ne l'applique pas, le patch step-booking.patch reste requis.
     */
    public static function inject_fake_rate_into_allowed_list( $rates, $rt_id, $from, $to ) {
        if ( ! MPHB_Hourly_Helper::is_hourly( (int) $rt_id ) ) return $rates;

        remove_filter( 'mphb_prices_get_active_rates',
            [ __CLASS__, 'inject_fake_rate_into_allowed_list' ], 99 );

        // Si des rates existent déjà, on les retourne tels quels
        if ( ! empty( $rates ) ) return $rates;

        // Sinon on retourne un objet Rate minimal avec l'ID du fake rate
        $fake_rate_id = self::get_any_rate_for( (int) $rt_id );
        if ( ! $fake_rate_id ) return $rates;

        $fake_rate = MPHB()->getRateRepository()->findById( $fake_rate_id );
        if ( $fake_rate ) {
            return [ $fake_rate ];
        }

        return $rates;
    }

    /**
     * Remettre rate_id=0 dans les booking_details finaux.
     */
    public static function restore_zero_rate_id( array $booking_rooms_details ): array {
        foreach ( $booking_rooms_details as &$detail ) {
            if ( ! empty( $detail['_hourly_fake_rate'] ) ) {
                $detail['rate_id'] = 0;
                unset( $detail['_hourly_fake_rate'] );
            }
        }
        unset( $detail );

        remove_filter( 'mphb_sc_checkout_step_booking_booking_details',
            [ __CLASS__, 'restore_zero_rate_id' ], 5 );

        return $booking_rooms_details;
    }

    /* -----------------------------------------------------------------------
     * PATCH G : StepBooking — réinjecter le contexte horaire dans GLOBALS
     *           avant la recherche de chambres disponibles
     *
     * StepBooking::parseBookingData() appelle :
     *   mphb_availability_facade()->getUnavailableRoomIds()
     *     → findLockedRooms() via room-persistence.php
     *     → notre filtre mphb_found_locked_rooms
     *
     * Si $GLOBALS['mphb_hourly_start'] est vide à ce moment, le filtre
     * MPHB_Hourly_Availability::refilter_for_hourly() ne peut pas distinguer
     * une recherche horaire d'une recherche journalière.
     *
     * On s'assure que le contexte est bien injecté en réécoutant
     * mphb_search_available_rooms (déclenché par StepBooking).
     *
     * filter: mphb_search_available_rooms
     *   args: ( $searchAtts )
     * -------------------------------------------------------------------- */

    public static function inject_hourly_context_for_room_search( array $search_atts ): array {
        // Si les GLOBALS sont déjà remplis (lecture POST faite en priorité 1)
        // on n'a rien à faire
        if ( self::is_hourly_request() ) return $search_atts;

        // Tentative de relecture depuis POST (au cas où init priority 1 aurait raté)
        self::read_post_time_fields();

        return $search_atts;
    }

    /* -----------------------------------------------------------------------
     * Utilitaire : trouver un rate_id existant pour un room type
     * (utilisé comme fake rate pour passer la validation StepBooking)
     * -------------------------------------------------------------------- */

    private static function get_any_rate_for( int $rt_id ): int {
        // On cherche parmi les rates actifs sur une large plage
        $from = new \DateTime();
        $to   = ( new \DateTime() )->modify( '+1 day' );

        // mphb_prices_facade() peut retourner des rates même sans tarif pour 0 nuit
        // car il cherche par room_type_id, pas par nombre de nuits
        $rates = mphb_prices_facade()->getActiveRates( $rt_id, $from, $to, false );

        if ( ! empty( $rates ) ) {
            return (int) reset( $rates )->getOriginalId();
        }

        // Fallback : chercher dans la base le premier rate associé à ce room type
        global $wpdb;
        $rate_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT p.ID FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID
                AND m.meta_key = 'mphb_room_type_id'
                AND m.meta_value = %d
             WHERE p.post_type = %s AND p.post_status = 'publish'
             LIMIT 1",
            $rt_id,
            'mphb_rate'
        ) );

        return $rate_id;
    }

    /* -----------------------------------------------------------------------
     * Champs cachés transmis entre les étapes du checkout
     * -------------------------------------------------------------------- */

    public static function render_hidden_fields(): void {
        $start = $GLOBALS['mphb_hourly_start'] ?? '';
        $end   = $GLOBALS['mphb_hourly_end']   ?? '';

        if ( ! $start ) return;

        printf( '<input type="hidden" name="mphb_hourly_start" value="%s">', esc_attr( $start ) );
        printf( '<input type="hidden" name="mphb_hourly_end"   value="%s">', esc_attr( $end ) );
    }
}
