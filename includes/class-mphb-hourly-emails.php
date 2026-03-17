<?php
/**
 * Injection du créneau horaire dans les emails MPHB.
 *
 * MPHB envoie ses emails via wp_mail() après avoir remplacé ses propres
 * balises (%check_in_date%, %booking_total_price%, etc.).
 * Il n'existe pas de filtre sur les balises individuelles, mais on peut
 * intercepter wp_mail juste avant l'envoi pour injecter les nôtres.
 *
 * Balises ajoutées dans le contenu des emails :
 *   %hourly_start%     → heure de début  (HH:MM)
 *   %hourly_end%       → heure de fin    (HH:MM)
 *   %hourly_duration%  → durée lisible   (ex: 2h 30min)
 *   %hourly_slot%      → créneau complet (ex: 14:00 – 16:30 · 2h 30min)
 *
 * Stratégie :
 *   On accroche 'mphb_create_booking_by_user' et les actions de changement
 *   de statut pour mémoriser le booking_id courant, puis on filtre wp_mail
 *   pour remplacer les balises dans le corps de l'email.
 */
defined( 'ABSPATH' ) || exit;

class MPHB_Hourly_Emails {

   
    private static int $current_booking_id = 0;

    public static function init(): void {
        // Capturer le booking_id lors des événements qui déclenchent des emails
        add_action( 'mphb_create_booking_by_user',       [ __CLASS__, 'capture_booking' ], 1 );
        add_action( 'mphb_booking_confirmed_with_payment', [ __CLASS__, 'capture_booking' ], 1 );
        add_action( 'mphb_customer_confirmed_booking',   [ __CLASS__, 'capture_booking' ], 1 );
        add_action( 'mphb_customer_cancelled_booking',   [ __CLASS__, 'capture_booking' ], 1 );
        add_action( 'transition_post_status',            [ __CLASS__, 'capture_booking_on_status_change' ], 1, 3 );

        // Filtrer wp_mail pour injecter les balises horaires
        add_filter( 'wp_mail', [ __CLASS__, 'inject_hourly_tags' ], 10 );
    }

   

    public static function capture_booking( $booking ): void {
        if ( $booking instanceof \MPHB\Entities\Booking && $booking->getId() ) {
            self::$current_booking_id = $booking->getId();
        }
    }

    public static function capture_booking_on_status_change( string $new_status, string $old_status, \WP_Post $post ): void {
        if ( $post->post_type !== MPHB()->postTypes()->booking()->getPostType() ) return;
        self::$current_booking_id = $post->ID;
    }

  

    public static function inject_hourly_tags( array $args ): array {
        $bid = self::$current_booking_id;
        if ( ! $bid ) return $args;

        $start    = MPHB_Hourly_Helper::booking_start( $bid );
        $end      = MPHB_Hourly_Helper::booking_end( $bid );
        $duration = MPHB_Hourly_Helper::booking_duration( $bid );

        if ( ! $start || ! $end ) return $args;

        $slot_str = $start . ' – ' . $end
                  . ( $duration ? ' · ' . MPHB_Hourly_Helper::format_duration( $duration ) : '' );

        $search  = [ '%hourly_start%', '%hourly_end%', '%hourly_duration%', '%hourly_slot%' ];
        $replace = [
            $start,
            $end,
            $duration ? MPHB_Hourly_Helper::format_duration( $duration ) : '',
            $slot_str,
        ];

        if ( ! empty( $args['message'] ) ) {
            $args['message'] = str_replace( $search, $replace, $args['message'] );

            $args['message'] = self::rewrite_date_lines( $args['message'], $bid, $start, $end );
        }

        if ( ! empty( $args['subject'] ) ) {
            $args['subject'] = str_replace( $search, $replace, $args['subject'] );
        }

        return $args;
    }

   

    private static function rewrite_date_lines( string $body, int $bid, string $start, string $end ): string {
        $booking = MPHB()->getBookingRepository()->findById( $bid );
        if ( ! $booking ) return $body;

        $date_fmt = MPHB()->settings()->dateTime()->getDateFormatWP();
        $date_str = date_i18n( $date_fmt, $booking->getCheckInDate()->getTimestamp() );
        $duration = MPHB_Hourly_Helper::booking_duration( $bid );

        $slot_str = $start . ' – ' . $end;
        if ( $duration ) {
            $slot_str .= ' (' . MPHB_Hourly_Helper::format_duration( $duration ) . ')';
        }

        $body = preg_replace(
            '/Check-in\s*:\s*[^<\n\r]+from[^<\n\r]+/ui',
            sprintf(
                /* translators: 1: date, 2: time slot */
                esc_html__( 'Date: %1$s', 'mphb-hourly' ) . "\n" .
                esc_html__( 'Slot: %2$s', 'mphb-hourly' ),
                $date_str,
                $slot_str
            ),
            $body,
            1 
        );

        
        $body = preg_replace(
            '/Check-out\s*:\s*[^<\n\r]+until[^<\n\r]+(\r?\n)?/ui',
            '',
            $body
        );

        return $body;
    }
}
