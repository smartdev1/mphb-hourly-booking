<?php
/**
 * Constantes, getters et utilitaires partagés.
 */
defined( 'ABSPATH' ) || exit;

class MPHB_Hourly_Helper {

    /* ── Métas sur le Room Type ───────────────────────────────────────────── */
    const RT_ENABLED     = '_mphb_hourly_enabled';
    const RT_PRICE       = '_mphb_hourly_price'; 
    const RT_MIN_DUR     = '_mphb_hourly_min_duration';
    const RT_MAX_DUR     = '_mphb_hourly_max_duration'; 
    const RT_STEP        = '_mphb_hourly_step';        
    const RT_OPEN        = '_mphb_hourly_open';        
    const RT_CLOSE       = '_mphb_hourly_close';        

    /* ── Métas sur la Réservation ─────────────────────────────────────────── */
    const BK_START       = '_mphb_hourly_start';     
    const BK_END         = '_mphb_hourly_end';          
    const BK_DURATION    = '_mphb_hourly_duration';     
    /* ── Lecture Room Type ───────────────────────────────────────────────── */

    public static function is_hourly( int $room_type_id ): bool {
        return (bool) get_post_meta( $room_type_id, self::RT_ENABLED, true );
    }

    public static function price( int $room_type_id ): float {
        return (float) get_post_meta( $room_type_id, self::RT_PRICE, true );
    }

    public static function min_duration( int $room_type_id ): int {
        $v = (int) get_post_meta( $room_type_id, self::RT_MIN_DUR, true );
        return $v > 0 ? $v : 60;
    }

    public static function max_duration( int $room_type_id ): int {
        return (int) get_post_meta( $room_type_id, self::RT_MAX_DUR, true );
    }

    public static function step( int $room_type_id ): int {
        $v = (int) get_post_meta( $room_type_id, self::RT_STEP, true );
        return $v > 0 ? $v : 60;
    }

    public static function open( int $room_type_id ): string {
        return get_post_meta( $room_type_id, self::RT_OPEN, true ) ?: '00:00';
    }

    public static function close( int $room_type_id ): string {
        return get_post_meta( $room_type_id, self::RT_CLOSE, true ) ?: '23:59';
    }

    /* ── Lecture Booking ─────────────────────────────────────────────────── */

    public static function booking_start( int $booking_id ): string {
        return (string) get_post_meta( $booking_id, self::BK_START, true );
    }

    public static function booking_end( int $booking_id ): string {
        return (string) get_post_meta( $booking_id, self::BK_END, true );
    }

    public static function booking_duration( int $booking_id ): int {
        return (int) get_post_meta( $booking_id, self::BK_DURATION, true );
    }

    /* ── Utilitaires temps ───────────────────────────────────────────────── */

    public static function to_minutes( string $hhmm ): int {
        [ $h, $m ] = explode( ':', $hhmm );
        return (int) $h * 60 + (int) $m;
    }

    public static function to_hhmm( int $minutes ): string {
        return sprintf( '%02d:%02d', intdiv( $minutes, 60 ), $minutes % 60 );
    }

    /** Durée en chaîne lisible : "2h 30min" */
    public static function format_duration( int $minutes ): string {
        $h = intdiv( $minutes, 60 );
        $m = $minutes % 60;
        if ( $h && $m ) return "{$h}h {$m}min";
        if ( $h )        return $h === 1 ? '1 heure' : "{$h} heures";
        return "{$m} min";
    }

    /**
     * Retourne le room_type_id à partir d'un room_id (physique).
     */
    public static function room_type_of( int $room_id ): int {
        return (int) get_post_meta( $room_id, 'mphb_room_type_id', true );
    }

    /**
     * Vérifie si deux intervalles [a,b[ et [c,d[ se chevauchent.
     */
    public static function intervals_overlap( int $a, int $b, int $c, int $d ): bool {
        return $a < $d && $c < $b;
    }
}
