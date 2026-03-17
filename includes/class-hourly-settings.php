<?php
/**
 * Global settings & helpers for hourly booking.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class MPHB_Hourly_Settings {

    const META_ENABLED       = '_mphb_hourly_enabled';
    const META_PRICE_PER_H   = '_mphb_hourly_price';
    const META_MIN_DURATION  = '_mphb_hourly_min_duration'; 
    const META_MAX_DURATION  = '_mphb_hourly_max_duration';  
    const META_SLOT_STEP     = '_mphb_hourly_slot_step';    
    const META_OPEN_TIME     = '_mphb_hourly_open_time';   
    const META_CLOSE_TIME    = '_mphb_hourly_close_time'; 
    const META_BOOKING_START_TIME = '_mphb_hourly_start_time';
    const META_BOOKING_END_TIME   = '_mphb_hourly_end_time';  
    const META_BOOKING_DURATION   = '_mphb_hourly_duration';   

    
    public static function is_hourly_room_type( int $room_type_id ): bool {
        return (bool) get_post_meta( $room_type_id, self::META_ENABLED, true );
    }

    
    public static function get_hourly_price( int $room_type_id ): float {
        return (float) get_post_meta( $room_type_id, self::META_PRICE_PER_H, true );
    }

    public static function get_min_duration( int $room_type_id ): int {
        $v = (int) get_post_meta( $room_type_id, self::META_MIN_DURATION, true );
        return $v > 0 ? $v : 60;
    }

   
    public static function get_max_duration( int $room_type_id ): int {
        return (int) get_post_meta( $room_type_id, self::META_MAX_DURATION, true );
    }

    
    public static function get_slot_step( int $room_type_id ): int {
        $v = (int) get_post_meta( $room_type_id, self::META_SLOT_STEP, true );
        return $v > 0 ? $v : 60;
    }

    
    public static function get_open_time( int $room_type_id ): string {
        $v = get_post_meta( $room_type_id, self::META_OPEN_TIME, true );
        return $v ?: '00:00';
    }

   
    public static function get_close_time( int $room_type_id ): string {
        $v = get_post_meta( $room_type_id, self::META_CLOSE_TIME, true );
        return $v ?: '23:59';
    }

    public static function get_booking_start_time( int $booking_id ): string {
        return (string) get_post_meta( $booking_id, self::META_BOOKING_START_TIME, true );
    }

    public static function get_booking_end_time( int $booking_id ): string {
        return (string) get_post_meta( $booking_id, self::META_BOOKING_END_TIME, true );
    }

    
    public static function make_datetime( string $date, string $time ): \DateTime {
        return new \DateTime( $date . ' ' . $time );
    }

    public static function time_to_minutes( string $time ): int {
        [ $h, $m ] = explode( ':', $time );
        return (int) $h * 60 + (int) $m;
    }

    
    public static function minutes_to_time( int $minutes ): string {
        return sprintf( '%02d:%02d', intdiv( $minutes, 60 ), $minutes % 60 );
    }
}
