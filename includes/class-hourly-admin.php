<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class MPHB_Hourly_Admin {

    public static function init(): void {
        $booking_post_type = MPHB()->postTypes()->booking()->getPostType();

        add_filter( "manage_{$booking_post_type}_posts_columns",       [ __CLASS__, 'add_time_column' ] );
        add_action( "manage_{$booking_post_type}_posts_custom_column", [ __CLASS__, 'render_time_column' ], 10, 2 );


        add_action( 'mphb_booking_details_meta_box_after', [ __CLASS__, 'render_time_in_detail' ], 10, 1 );
    }

    public static function add_time_column( array $columns ): array {

        $new = [];
        foreach ( $columns as $key => $label ) {
            $new[ $key ] = $label;
            if ( $key === 'check_out_date' ) {
                $new['mphb_hourly_time'] = __( 'Time Slot', 'mphb-hourly' );
            }
        }

        if ( ! isset( $new['mphb_hourly_time'] ) ) {
            $new['mphb_hourly_time'] = __( 'Time Slot', 'mphb-hourly' );
        }
        return $new;
    }

    public static function render_time_column( string $column, int $post_id ): void {
        if ( $column !== 'mphb_hourly_time' ) return;

        $start    = MPHB_Hourly_Settings::get_booking_start_time( $post_id );
        $end      = MPHB_Hourly_Settings::get_booking_end_time( $post_id );
        $duration = (int) get_post_meta( $post_id, MPHB_Hourly_Settings::META_BOOKING_DURATION, true );

        if ( $start && $end ) {
            echo esc_html( $start . ' – ' . $end );
            if ( $duration ) {
                echo ' <small>(' . esc_html( MPHB_Hourly_Price::format_duration( $duration ) ) . ')</small>';
            }
        } else {
            echo '—';
        }
    }

    public static function render_time_in_detail( $booking ): void {
        $booking_id = $booking->getId();
        $start    = MPHB_Hourly_Settings::get_booking_start_time( $booking_id );
        $end      = MPHB_Hourly_Settings::get_booking_end_time( $booking_id );
        $duration = (int) get_post_meta( $booking_id, MPHB_Hourly_Settings::META_BOOKING_DURATION, true );

        if ( ! $start || ! $end ) return;
        ?>
        <p>
            <strong><?php esc_html_e( 'Time Slot:', 'mphb-hourly' ); ?></strong>
            <?php echo esc_html( $start . ' – ' . $end ); ?>
            <?php if ( $duration ) : ?>
                (<?php echo esc_html( MPHB_Hourly_Price::format_duration( $duration ) ); ?>)
            <?php endif; ?>
        </p>
        <?php
    }
}
