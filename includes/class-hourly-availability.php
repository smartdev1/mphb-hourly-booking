<?php
/**
 * Hourly availability logic.
 *
 * The core plugin compares only dates (Y-m-d).
 * For hourly room types, two bookings on the same day conflict only if
 * their time ranges overlap. We hook into the SQL query that finds
 * "locked rooms" and post-process the results for hourly rooms.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class MPHB_Hourly_Availability {

    public static function init(): void {
        add_filter( 'mphb_search_rooms_atts', [ __CLASS__, 'inject_hourly_context' ], 10, 2 );

        add_filter( 'mphb_found_locked_rooms', [ __CLASS__, 'refilter_locked_rooms' ], 10, 2 );
    }

    public static function inject_hourly_context( array $atts, array $defaults ): array {
        // These are set by our checkout hooks before the search is triggered
        if ( ! empty( $GLOBALS['mphb_hourly_start_time'] ) ) {
            $atts['hourly_start_time'] = $GLOBALS['mphb_hourly_start_time'];
            $atts['hourly_end_time']   = $GLOBALS['mphb_hourly_end_time'];
            $atts['hourly_date']       = $GLOBALS['mphb_hourly_date'];
        }
        return $atts;
    }

    public static function refilter_locked_rooms( array $locked_room_ids, array $atts ): array {
        if ( empty( $atts['hourly_date'] ) || empty( $atts['hourly_start_time'] ) ) {
            return $locked_room_ids; // Not an hourly search — leave unchanged
        }

        $req_start = MPHB_Hourly_Settings::time_to_minutes( $atts['hourly_start_time'] );
        $req_end   = MPHB_Hourly_Settings::time_to_minutes( $atts['hourly_end_time'] );
        $date_str  = $atts['hourly_date']; // "Y-m-d"

        $still_locked = [];

        foreach ( $locked_room_ids as $room_id ) {
            $room_type_id = (int) get_post_meta( $room_id, 'mphb_room_type_id', true );

            if ( ! MPHB_Hourly_Settings::is_hourly_room_type( $room_type_id ) ) {
                $still_locked[] = $room_id;
                continue;
            }

            if ( self::has_hourly_conflict( $room_id, $date_str, $req_start, $req_end ) ) {
                $still_locked[] = $room_id;
            }
        }

        return $still_locked;
    }

    public static function has_hourly_conflict(
        int $room_id,
        string $date_str,
        int $req_start_min,
        int $req_end_min
    ): bool {
        global $wpdb;

        $locking_statuses = MPHB()->postTypes()->booking()->statuses()->getLockedRoomStatuses();
        $status_in        = "'" . implode( "','", array_map( 'esc_sql', $locking_statuses ) ) . "'";

        $sql = $wpdb->prepare(
            "SELECT b.ID
               FROM {$wpdb->posts} AS b
         INNER JOIN {$wpdb->posts} AS rr   ON rr.post_parent = b.ID
         INNER JOIN {$wpdb->postmeta} AS rm ON rm.post_id = rr.ID AND rm.meta_key = '_mphb_room_id' AND rm.meta_value = %d
         INNER JOIN {$wpdb->postmeta} AS ci ON ci.post_id = b.ID  AND ci.meta_key = 'mphb_check_in_date'  AND ci.meta_value = %s
         INNER JOIN {$wpdb->postmeta} AS co ON co.post_id = b.ID  AND co.meta_key = 'mphb_check_out_date' AND co.meta_value = %s
              WHERE b.post_type   = %s
                AND b.post_status IN ({$status_in})
                AND rr.post_type  = %s
                AND rr.post_status = 'publish'",
            $room_id,
            $date_str,
            $date_str,
            MPHB()->postTypes()->booking()->getPostType(),
            MPHB()->postTypes()->reservedRoom()->getPostType()
        );

        $booking_ids = $wpdb->get_col( $sql );

        foreach ( $booking_ids as $booking_id ) {
            $start_time = get_post_meta( $booking_id, MPHB_Hourly_Settings::META_BOOKING_START_TIME, true );
            $end_time   = get_post_meta( $booking_id, MPHB_Hourly_Settings::META_BOOKING_END_TIME,   true );

            if ( empty( $start_time ) || empty( $end_time ) ) {
                return true;
            }

            $existing_start = MPHB_Hourly_Settings::time_to_minutes( $start_time );
            $existing_end   = MPHB_Hourly_Settings::time_to_minutes( $end_time );

            // Overlap check: two intervals [a,b) and [c,d) overlap iff a < d && c < b
            if ( $req_start_min < $existing_end && $existing_start < $req_end_min ) {
                return true;
            }
        }

        return false;
    }

    public static function get_booked_slots( int $room_type_id, string $date_str ): array {
        global $wpdb;

        $locking_statuses = MPHB()->postTypes()->booking()->statuses()->getLockedRoomStatuses();
        $status_in        = "'" . implode( "','", array_map( 'esc_sql', $locking_statuses ) ) . "'";

        $sql = $wpdb->prepare(
            "SELECT b.ID
               FROM {$wpdb->posts} AS b
         INNER JOIN {$wpdb->posts} AS rr   ON rr.post_parent = b.ID
         INNER JOIN {$wpdb->posts} AS room ON room.ID = (
                        SELECT rm2.meta_value FROM {$wpdb->postmeta} rm2
                         WHERE rm2.post_id = rr.ID AND rm2.meta_key = '_mphb_room_id' LIMIT 1
                    )
         INNER JOIN {$wpdb->postmeta} AS rt ON rt.post_id = room.ID AND rt.meta_key = 'mphb_room_type_id' AND rt.meta_value = %d
         INNER JOIN {$wpdb->postmeta} AS ci ON ci.post_id = b.ID AND ci.meta_key = 'mphb_check_in_date'  AND ci.meta_value = %s
         INNER JOIN {$wpdb->postmeta} AS co ON co.post_id = b.ID AND co.meta_key = 'mphb_check_out_date' AND co.meta_value = %s
              WHERE b.post_type   = %s
                AND b.post_status IN ({$status_in})
                AND rr.post_type  = %s
                AND rr.post_status = 'publish'",
            $room_type_id,
            $date_str,
            $date_str,
            MPHB()->postTypes()->booking()->getPostType(),
            MPHB()->postTypes()->reservedRoom()->getPostType()
        );

        $booking_ids = $wpdb->get_col( $sql );
        $slots = [];

        foreach ( $booking_ids as $booking_id ) {
            $start = get_post_meta( $booking_id, MPHB_Hourly_Settings::META_BOOKING_START_TIME, true );
            $end   = get_post_meta( $booking_id, MPHB_Hourly_Settings::META_BOOKING_END_TIME,   true );
            if ( $start && $end ) {
                $slots[] = [ 'start' => $start, 'end' => $end ];
            }
        }

        return $slots;
    }
}
