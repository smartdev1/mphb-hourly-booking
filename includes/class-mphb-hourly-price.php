<?php

defined( 'ABSPATH' ) || exit;

class MPHB_Hourly_Price {

    public static function init(): void {
        add_filter( 'mphb_booking_total_price',    [ __CLASS__, 'override_total' ], 20, 2 );
        add_filter( 'mphb_room_type_period_price', [ __CLASS__, 'override_period_price' ], 20, 4 );
    }

    public static function override_total( float $total, $booking ): float {

        $bid = $booking->getId();

        // Priorité 1 : durée enregistrée en base (réservation confirmée)
        $duration = $bid ? MPHB_Hourly_Helper::booking_duration( $bid ) : 0;

        // Priorité 2 : GLOBALS (alimentés par read_post_time_fields au début de la requête)
        if ( ! $duration ) {
            $duration = (int) ( $GLOBALS['mphb_hourly_duration'] ?? 0 );
        }

        // Priorité 3 : calculer depuis POST (étape booking du checkout)
        if ( ! $duration ) {
            $start = sanitize_text_field( wp_unslash( $_POST['mphb_hourly_start'] ?? '' ) );
            $end   = sanitize_text_field( wp_unslash( $_POST['mphb_hourly_end']   ?? '' ) );
            if ( $start && $end && preg_match( '/^\d{2}:\d{2}$/', $start ) && preg_match( '/^\d{2}:\d{2}$/', $end ) ) {
                $duration = max( 0, MPHB_Hourly_Helper::to_minutes( $end ) - MPHB_Hourly_Helper::to_minutes( $start ) );
            }
        }

        if ( ! $duration ) return $total;

        $new_total  = 0.0;
        $has_hourly = false;

        foreach ( $booking->getReservedRooms() as $rr ) {
            $rt_id = MPHB_Hourly_Helper::room_type_of( $rr->getRoomId() );

            if ( ! $rt_id ) {
                $rt_id = $GLOBALS['mphb_hourly_rt_id'] ?? 0;
            }

            if ( $rt_id && MPHB_Hourly_Helper::is_hourly( $rt_id ) ) {
                $has_hourly  = true;
                $hours       = $duration / 60.0;
                $new_total  += MPHB_Hourly_Helper::price( $rt_id ) * $hours;
            } else {
                $new_total += $total;
            }
        }

        return $has_hourly ? round( $new_total, 2 ) : $total;
    }

    public static function override_period_price( float $price, $check_in, $check_out, int $room_type_id ): float {

        if ( ! MPHB_Hourly_Helper::is_hourly( $room_type_id ) ) return $price;

        $min_dur = MPHB_Hourly_Helper::min_duration( $room_type_id );
        return round( MPHB_Hourly_Helper::price( $room_type_id ) * ( $min_dur / 60.0 ), 2 );
    }

    public static function calc( int $room_type_id, int $duration_min ): float {
        return round( MPHB_Hourly_Helper::price( $room_type_id ) * ( $duration_min / 60.0 ), 2 );
    }
}