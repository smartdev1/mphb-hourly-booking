<?php
/**
 * PATCH 1 — room-persistence.php :: findLockedRooms()
 *
 * La méthode originale exécute ce SQL :
 *   WHERE check_in_date < {to_date} AND check_out_date > {from_date}
 *
 * Ce filtre WordPress (`posts_where`) est appliqué par CPTPersistence via WP_Query.
 * MAIS findLockedRooms() utilise $wpdb->get_col() directement, donc on ne peut pas
 * le filtrer via posts_where.
 *
 * Solution : on accroche l'action `mphb_search_rooms` (déclenchée juste après
 * searchRooms()) et on post-filtre la liste retournée pour les chambres horaires.
 *
 * Point d'accroche dans room-persistence.php :
 *   $roomIds = $this->findLockedRooms( $atts );          ← pas filtrable directement
 *   $roomIds = apply_filters('mphb_found_locked_rooms', $roomIds, $atts);   ← on ajoute ce filtre
 *
 * ⚠  Ce filtre n'existe PAS nativement dans MPHB.
 *    Il faut l'ajouter directement dans room-persistence.php (1 ligne).
 *    Voir le fichier PATCH : patches/room-persistence.patch
 */
defined( 'ABSPATH' ) || exit;

class MPHB_Hourly_Availability {

    public static function init(): void {
        /*
         * Filtre ajouté dans room-persistence.php (patch obligatoire) :
         *   $roomIds = apply_filters( 'mphb_found_locked_rooms', $roomIds, $atts );
         */
        add_filter( 'mphb_found_locked_rooms', [ __CLASS__, 'refilter_for_hourly' ], 10, 2 );

        // AJAX : créneaux disponibles pour le front-end
        add_action( 'wp_ajax_mphb_hourly_slots',        [ __CLASS__, 'ajax_slots' ] );
        add_action( 'wp_ajax_nopriv_mphb_hourly_slots', [ __CLASS__, 'ajax_slots' ] );
    }

    /* -----------------------------------------------------------------------
     * REFILTRE PRINCIPAL
     * Appelé après findLockedRooms() dans room-persistence.php.
     * Deux contextes possibles :
     *
     * A) RECHERCHE HORAIRE ($GLOBALS contient start/end)
     *    → Pour chaque chambre horaire bloquée, on vérifie si le conflit
     *      est réel sur le créneau demandé. Si non, on la retire.
     *    → Les chambres journalières restent bloquées telles quelles.
     *
     * B) RECHERCHE JOURNALIÈRE ($GLOBALS vide)
     *    → Pour chaque chambre horaire bloquée, on vérifie si toutes ses
     *      réservations sur la période couvrent vraiment toute la journée
     *      (check_in ≠ check_out, ou réservation horaire plein jour).
     *      Si ce n'est pas le cas, on la retire : la chambre est dispo
     *      pour une réservation à la nuit.
     *    → Les chambres journalières restent bloquées telles quelles.
     * -------------------------------------------------------------------- */

    /**
     * @param int[]  $locked_ids  Chambres que MPHB considère occupées.
     * @param array  $atts        Attributs de searchRooms() (from_date, to_date, …)
     * @return int[]
     */
    public static function refilter_for_hourly( array $locked_ids, array $atts ): array {

        if ( empty( $locked_ids ) ) return [];

        $start = $GLOBALS['mphb_hourly_start'] ?? '';
        $end   = $GLOBALS['mphb_hourly_end']   ?? '';

        /** @var \DateTime $from_date */
        $from_date = $atts['from_date'];
        /** @var \DateTime $to_date */
        $to_date   = $atts['to_date'];

        $still_locked = [];

        foreach ( $locked_ids as $room_id ) {
            $rt_id = MPHB_Hourly_Helper::room_type_of( $room_id );

            if ( ! MPHB_Hourly_Helper::is_hourly( $rt_id ) ) {
                // Chambre journalière → comportement MPHB inchangé
                $still_locked[] = $room_id;
                continue;
            }

            // ── Contexte A : recherche HORAIRE ───────────────────────────
            if ( $start && $end ) {
                $date_str = $from_date->format( 'Y-m-d' );
                $req_s    = MPHB_Hourly_Helper::to_minutes( $start );
                $req_e    = MPHB_Hourly_Helper::to_minutes( $end );

                if ( self::has_time_conflict( $room_id, $date_str, $req_s, $req_e ) ) {
                    $still_locked[] = $room_id; // Vrai conflit horaire
                }
                // Sinon : créneau libre → chambre retirée de la liste bloquée
                continue;
            }

            // ── Contexte B : recherche JOURNALIÈRE ───────────────────────
            // La chambre horaire ne doit bloquer une réservation journalière
            // QUE si toutes ses réservations existantes sur la période sont
            // des réservations journalières (check_in ≠ check_out).
            // Une simple réservation horaire (2h dans la journée) ne doit
            // PAS empêcher une réservation à la nuit.
            if ( self::blocks_full_day_period( $room_id, $from_date, $to_date ) ) {
                $still_locked[] = $room_id;
            }
            // Sinon : seulement des réservations horaires ponctuelles → dispo journalière
        }

        return $still_locked;
    }

    /* -----------------------------------------------------------------------
     * Conflit temps : est-ce qu'une réservation existante bloque ce créneau ?
     * -------------------------------------------------------------------- */

    public static function has_time_conflict(
        int    $room_id,
        string $date,
        int    $req_s,
        int    $req_e
    ): bool {
        global $wpdb;

        $statuses = MPHB()->postTypes()->booking()->statuses()->getLockedRoomStatuses();
        $s_in     = "'" . implode( "','", array_map( 'esc_sql', $statuses ) ) . "'";

        /*
         * Trouve toutes les réservations pour cette chambre sur cette date.
         * Les réservations horaires ont check_in_date = check_out_date = $date.
         */
        $sql = $wpdb->prepare(
            "SELECT b.ID
               FROM {$wpdb->posts}     AS b
         INNER JOIN {$wpdb->posts}     AS rr  ON rr.post_parent = b.ID
         INNER JOIN {$wpdb->postmeta}  AS rm  ON rm.post_id = rr.ID
                                              AND rm.meta_key = '_mphb_room_id'
                                              AND rm.meta_value = %d
         INNER JOIN {$wpdb->postmeta}  AS ci  ON ci.post_id = b.ID
                                              AND ci.meta_key = 'mphb_check_in_date'
                                              AND ci.meta_value = %s
              WHERE b.post_type   = %s
                AND b.post_status IN ({$s_in})
                AND rr.post_type  = %s
                AND rr.post_status = 'publish'",
            $room_id,
            $date,
            MPHB()->postTypes()->booking()->getPostType(),
            MPHB()->postTypes()->reservedRoom()->getPostType()
        );

        $booking_ids = $wpdb->get_col( $sql );

        foreach ( $booking_ids as $bid ) {
            $s = MPHB_Hourly_Helper::booking_start( (int) $bid );
            $e = MPHB_Hourly_Helper::booking_end( (int) $bid );

            if ( ! $s || ! $e ) {
                return true; // Réservation sans heure → bloque toute la journée
            }

            if ( MPHB_Hourly_Helper::intervals_overlap(
                $req_s, $req_e,
                MPHB_Hourly_Helper::to_minutes( $s ),
                MPHB_Hourly_Helper::to_minutes( $e )
            ) ) {
                return true;
            }
        }

        return false;
    }

    /* -----------------------------------------------------------------------
     * Contexte B : la chambre horaire bloque-t-elle une période journalière ?
     *
     * Une chambre horaire bloque une période journalière UNIQUEMENT si elle
     * possède au moins une réservation journalière (check_in ≠ check_out)
     * qui chevauche la période demandée.
     *
     * Les réservations purement horaires (check_in = check_out = même jour)
     * ne bloquent PAS une nuit complète — elles sont transparentes pour
     * le système de réservation journalière.
     * -------------------------------------------------------------------- */

    /**
     * @param int       $room_id
     * @param \DateTime $from_date  check-in demandé
     * @param \DateTime $to_date    check-out demandé
     * @return bool  true = chambre réellement bloquée pour une réservation journalière
     */
    public static function blocks_full_day_period( int $room_id, \DateTime $from_date, \DateTime $to_date ): bool {
        global $wpdb;

        $statuses = MPHB()->postTypes()->booking()->statuses()->getLockedRoomStatuses();
        $s_in     = "'" . implode( "','", array_map( 'esc_sql', $statuses ) ) . "'";

        $date_format = MPHB()->settings()->dateTime()->getDateTransferFormat();
        $from_str    = $from_date->format( $date_format );
        $to_str      = $to_date->format( $date_format );

        /*
         * Cherche UNE réservation journalière (check_in ≠ check_out)
         * sur cette chambre qui chevauche la période demandée.
         * Si elle existe, la chambre est bloquée pour la journée.
         */
        $sql = $wpdb->prepare(
            "SELECT COUNT(b.ID)
               FROM {$wpdb->posts}    AS b
         INNER JOIN {$wpdb->posts}    AS rr ON rr.post_parent = b.ID
         INNER JOIN {$wpdb->postmeta} AS rm ON rm.post_id = rr.ID
                                            AND rm.meta_key = '_mphb_room_id'
                                            AND rm.meta_value = %d
         INNER JOIN {$wpdb->postmeta} AS ci ON ci.post_id = b.ID
                                            AND ci.meta_key = 'mphb_check_in_date'
         INNER JOIN {$wpdb->postmeta} AS co ON co.post_id = b.ID
                                            AND co.meta_key = 'mphb_check_out_date'
              WHERE b.post_type    = %s
                AND b.post_status  IN ({$s_in})
                AND rr.post_type   = %s
                AND rr.post_status = 'publish'
                AND ci.meta_value  < %s
                AND co.meta_value  > %s
                AND ci.meta_value  != co.meta_value",
            $room_id,
            MPHB()->postTypes()->booking()->getPostType(),
            MPHB()->postTypes()->reservedRoom()->getPostType(),
            $to_str,
            $from_str
        );

        return (int) $wpdb->get_var( $sql ) > 0;
    }

    /* -----------------------------------------------------------------------
     * Créneaux occupés sur une date (pour le JS front-end)
     * Inclut : réservations confirmées + créneaux en cours de checkout (verrous)
     * -------------------------------------------------------------------- */

    public static function get_booked_slots( int $room_type_id, string $date ): array {
        global $wpdb;

        $statuses = MPHB()->postTypes()->booking()->statuses()->getLockedRoomStatuses();
        $s_in     = "'" . implode( "','", array_map( 'esc_sql', $statuses ) ) . "'";

        $sql = $wpdb->prepare(
            "SELECT DISTINCT b.ID
               FROM {$wpdb->posts}    AS b
         INNER JOIN {$wpdb->posts}    AS rr   ON rr.post_parent = b.ID
         INNER JOIN {$wpdb->postmeta} AS rm   ON rm.post_id = rr.ID AND rm.meta_key = '_mphb_room_id'
         INNER JOIN {$wpdb->posts}    AS room ON room.ID = rm.meta_value
         INNER JOIN {$wpdb->postmeta} AS rt   ON rt.post_id = room.ID
                                              AND rt.meta_key = 'mphb_room_type_id'
                                              AND rt.meta_value = %d
         INNER JOIN {$wpdb->postmeta} AS ci   ON ci.post_id = b.ID
                                              AND ci.meta_key = 'mphb_check_in_date'
                                              AND ci.meta_value = %s
              WHERE b.post_type   = %s
                AND b.post_status IN ({$s_in})
                AND rr.post_type  = %s
                AND rr.post_status = 'publish'",
            $room_type_id,
            $date,
            MPHB()->postTypes()->booking()->getPostType(),
            MPHB()->postTypes()->reservedRoom()->getPostType()
        );

        $slots = [];
        foreach ( $wpdb->get_col( $sql ) as $bid ) {
            $s = MPHB_Hourly_Helper::booking_start( (int) $bid );
            $e = MPHB_Hourly_Helper::booking_end( (int) $bid );
            if ( $s && $e ) {
                $slots[] = [ 'start' => $s, 'end' => $e ];
            }
        }

        // Ajouter les créneaux en cours de checkout (verrous actifs)
        // On scanne les transients avec le préfixe mphb_hourly_lock_
        // Note : WordPress ne fournit pas de mécanisme natif pour lister les transients
        // par préfixe efficacement, on utilise une approche directe en DB.
        $lock_prefix = MPHB_Hourly_Lock::LOCK_PREFIX . md5( "{$room_type_id}_{$date}_" );
        // On ne peut pas énumérer les verrous par date/rt_id sans scan complet
        // → On laisse le front-end gérer : les verrous empêchent la réservation
        // au niveau serveur même si le slot apparaît "libre" en UI.
        // Un refresh de la page montrera le créneau libéré si le verrou expire.

        return $slots;
    }

    /* -----------------------------------------------------------------------
     * AJAX
     * -------------------------------------------------------------------- */

    public static function ajax_slots(): void {
        check_ajax_referer( 'mphb_hourly', 'nonce' );

        $rt_id = intval( $_GET['room_type_id'] ?? 0 );
        $date  = sanitize_text_field( $_GET['date'] ?? '' );

        if ( ! $rt_id || ! $date || ! MPHB_Hourly_Helper::is_hourly( $rt_id ) ) {
            wp_send_json_error( [ 'msg' => 'Requête invalide.' ] );
        }

        wp_send_json_success( [
            'open'          => MPHB_Hourly_Helper::open( $rt_id ),
            'close'         => MPHB_Hourly_Helper::close( $rt_id ),
            'step'          => MPHB_Hourly_Helper::step( $rt_id ),
            'min_duration'  => MPHB_Hourly_Helper::min_duration( $rt_id ),
            'max_duration'  => MPHB_Hourly_Helper::max_duration( $rt_id ),
            'price_per_h'   => MPHB_Hourly_Helper::price( $rt_id ),
            'currency'      => MPHB()->settings()->currency()->getCurrencySymbol(),
            'booked'        => self::get_booked_slots( $rt_id, $date ),
        ] );
    }
}
