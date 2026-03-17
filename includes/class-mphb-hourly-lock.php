<?php

defined( 'ABSPATH' ) || exit;

class MPHB_Hourly_Lock {

    const LOCK_TTL     = 600; 
    const LOCK_PREFIX  = 'mphb_hourly_lock_';

    public static function init(): void {
        
        add_filter( 'mphb_sc_checkout_step_checkout_pre_validate_selected_rooms',
            [ __CLASS__, 'acquire_lock_on_checkout' ], 10, 2 );

      
        add_action( 'mphb_create_booking_by_user', [ __CLASS__, 'release_lock_on_success' ], 5 );
        add_action( 'mphb_booking_confirmed_with_payment', [ __CLASS__, 'release_lock_on_success' ], 5 );
    }

    /* -----------------------------------------------------------------------
     * Acquisition du verrou lors de StepCheckout
     * -------------------------------------------------------------------- */

    public static function acquire_lock_on_checkout( array $errors, array $selected_rooms ): array {
       
        if ( empty( $GLOBALS['mphb_hourly_start'] ) ) return $errors;
        if ( ! empty( $errors ) ) return $errors;

        $start = $GLOBALS['mphb_hourly_start'];
        $end   = $GLOBALS['mphb_hourly_end'];

       
        $date = sanitize_text_field( wp_unslash(
            $_POST['mphb_check_in_date']
            ?? filter_input( INPUT_COOKIE, 'mphb_check_in_date' )
            ?? ''
        ) );

        if ( ! $date || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) return $errors;

        foreach ( array_keys( $selected_rooms ) as $rt_id ) {
            $rt_id = (int) $rt_id;
            if ( ! MPHB_Hourly_Helper::is_hourly( $rt_id ) ) continue;

            $lock_key = self::make_key( $rt_id, $date, $start, $end );
            $existing = get_transient( $lock_key );

            if ( $existing !== false ) {
               
                $errors[] = __( 'Ce créneau est en cours de réservation par un autre client. Veuillez réessayer dans quelques minutes.', 'mphb-hourly' );
                return $errors;
            }

            
            $session_id = MPHB()->getSession()->getId() ?? uniqid( 'mphb_', true );
            set_transient( $lock_key, $session_id, self::LOCK_TTL );

            $GLOBALS['mphb_hourly_lock_key'] = $lock_key;
        }

        return $errors;
    }

    /* -----------------------------------------------------------------------
     * Libération du verrou après création réussie de la réservation
     * -------------------------------------------------------------------- */

    public static function release_lock_on_success( $booking ): void {
        if ( ! empty( $GLOBALS['mphb_hourly_lock_key'] ) ) {
            delete_transient( $GLOBALS['mphb_hourly_lock_key'] );
            unset( $GLOBALS['mphb_hourly_lock_key'] );
        }
    }

    /* -----------------------------------------------------------------------
     * Utilitaires
     * -------------------------------------------------------------------- */

    public static function make_key( int $rt_id, string $date, string $start, string $end ): string {
        
        return self::LOCK_PREFIX . md5( "{$rt_id}_{$date}_{$start}_{$end}" );
    }

   
    public static function is_locked( int $rt_id, string $date, string $start, string $end ): bool {
        return get_transient( self::make_key( $rt_id, $date, $start, $end ) ) !== false;
    }

    public static function force_release( int $rt_id, string $date, string $start, string $end ): void {
        delete_transient( self::make_key( $rt_id, $date, $start, $end ) );
    }
}
