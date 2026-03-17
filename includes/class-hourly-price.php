<?php
/**
 * Override/supplement price calculation for hourly bookings.
 *
 * The core calculates: room_rate × nights.
 * For hourly rooms we calculate: hourly_price × (duration_minutes / 60).
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class MPHB_Hourly_Price {

    public static function init(): void {
        add_filter( 'mphb_booking_total_price',   [ __CLASS__, 'override_booking_total' ], 20, 2 );
        add_filter( 'mphb_room_type_period_price', [ __CLASS__, 'override_period_price' ], 20, 4 );
    }

    
    public static function override_booking_total( float $total, $booking ): float {
        $booking_id   = $booking->getId();
        $duration_min = (int) get_post_meta( $booking_id, MPHB_Hourly_Settings::META_BOOKING_DURATION, true );

        if ( ! $duration_min ) {
            return $total; 
        }

        $new_total = 0.0;
        foreach ( $booking->getReservedRooms() as $reserved_room ) {
            $room_id      = $reserved_room->getRoomId();
            $room_type_id = (int) get_post_meta( $room_id, 'mphb_room_type_id', true );

            if ( MPHB_Hourly_Settings::is_hourly_room_type( $room_type_id ) ) {
                $price_per_h  = MPHB_Hourly_Settings::get_hourly_price( $room_type_id );
                $hours        = $duration_min / 60.0;
                $new_total   += $price_per_h * $hours;
            } else {
                
                $new_total += $total; 
            }
        }

        return $new_total;
    }

    public static function override_period_price( float $price, $check_in, $check_out, int $room_type_id ): float {
        if ( ! MPHB_Hourly_Settings::is_hourly_room_type( $room_type_id ) ) {
            return $price;
        }

        $price_per_h = MPHB_Hourly_Settings::get_hourly_price( $room_type_id );
        $min_dur     = MPHB_Hourly_Settings::get_min_duration( $room_type_id );
        $hours       = $min_dur / 60.0;

        return $price_per_h * $hours;
    }

    
    public static function calculate_price( int $room_type_id, int $duration_minutes ): float {
        $price_per_h = MPHB_Hourly_Settings::get_hourly_price( $room_type_id );
        return round( $price_per_h * ( $duration_minutes / 60.0 ), 2 );
    }

    public static function format_duration( int $minutes ): string {
        $h   = intdiv( $minutes, 60 );
        $min = $minutes % 60;

        if ( $h > 0 && $min > 0 ) {
            return sprintf( _x( '%dh %dmin', 'Duration format', 'mphb-hourly' ), $h, $min );
        } elseif ( $h > 0 ) {
            return sprintf( _nx( '%d hour', '%d hours', $h, 'Duration format', 'mphb-hourly' ), $h );
        } else {
            return sprintf( _x( '%d min', 'Duration format', 'mphb-hourly' ), $min );
        }
    }
}
