<?php
/**
 * Admin meta box: hourly options on the Room Type edit screen.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class MPHB_Hourly_Meta {

    public static function init(): void {
        add_action( 'add_meta_boxes',  [ __CLASS__, 'add_meta_box' ] );
        add_action( 'save_post',       [ __CLASS__, 'save_meta_box' ] );
    }

    public static function add_meta_box(): void {
        $room_type_post_type = MPHB()->postTypes()->roomType()->getPostType();

        add_meta_box(
            'mphb_hourly_options',
            __( 'Hourly Booking Options', 'mphb-hourly' ),
            [ __CLASS__, 'render_meta_box' ],
            $room_type_post_type,
            'normal',
            'default'
        );
    }

    public static function render_meta_box( \WP_Post $post ): void {
        wp_nonce_field( 'mphb_hourly_save', 'mphb_hourly_nonce' );

        $enabled      = MPHB_Hourly_Settings::is_hourly_room_type( $post->ID );
        $price        = MPHB_Hourly_Settings::get_hourly_price( $post->ID );
        $min_dur      = MPHB_Hourly_Settings::get_min_duration( $post->ID );
        $max_dur      = MPHB_Hourly_Settings::get_max_duration( $post->ID );
        $slot_step    = MPHB_Hourly_Settings::get_slot_step( $post->ID );
        $open_time    = MPHB_Hourly_Settings::get_open_time( $post->ID );
        $close_time   = MPHB_Hourly_Settings::get_close_time( $post->ID );
        $currency     = MPHB()->settings()->currency()->getCurrencySymbol();

        ?>
        <style>
            .mphb-hourly-table { width: 100%; border-collapse: collapse; }
            .mphb-hourly-table th { text-align: left; padding: 8px 0 4px; font-weight: 600; width: 220px; }
            .mphb-hourly-table td { padding: 4px 0 8px; }
            .mphb-hourly-table .description { color: #666; font-size: 12px; }
            .mphb-hourly-dependent { margin-top: 10px; }
        </style>

        <table class="mphb-hourly-table">
            <tr>
                <th><?php esc_html_e( 'Enable Hourly Booking', 'mphb-hourly' ); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="mphb_hourly_enabled" id="mphb_hourly_enabled" value="1" <?php checked( $enabled ); ?>>
                        <?php esc_html_e( 'Allow reservations by the hour for this accommodation type', 'mphb-hourly' ); ?>
                    </label>
                </td>
            </tr>
        </table>

        <div id="mphb_hourly_dependent" class="mphb-hourly-dependent" <?php echo $enabled ? '' : 'style="display:none"'; ?>>
            <table class="mphb-hourly-table">
                <tr>
                    <th><?php printf( esc_html__( 'Price per Hour (%s)', 'mphb-hourly' ), esc_html( $currency ) ); ?></th>
                    <td>
                        <input type="number" name="mphb_hourly_price" value="<?php echo esc_attr( $price ); ?>" min="0" step="0.01" style="width:120px">
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Minimum Duration (minutes)', 'mphb-hourly' ); ?></th>
                    <td>
                        <input type="number" name="mphb_hourly_min_duration" value="<?php echo esc_attr( $min_dur ); ?>" min="15" step="15" style="width:100px">
                        <span class="description"><?php esc_html_e( 'e.g. 60 = at least 1 hour', 'mphb-hourly' ); ?></span>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Maximum Duration (minutes)', 'mphb-hourly' ); ?></th>
                    <td>
                        <input type="number" name="mphb_hourly_max_duration" value="<?php echo esc_attr( $max_dur ); ?>" min="0" step="15" style="width:100px">
                        <span class="description"><?php esc_html_e( '0 = no limit', 'mphb-hourly' ); ?></span>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Time Slot Step (minutes)', 'mphb-hourly' ); ?></th>
                    <td>
                        <input type="number" name="mphb_hourly_slot_step" value="<?php echo esc_attr( $slot_step ); ?>" min="15" step="15" style="width:100px">
                        <span class="description"><?php esc_html_e( 'Granularity of selectable times (e.g. 30 or 60)', 'mphb-hourly' ); ?></span>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Opening Time', 'mphb-hourly' ); ?></th>
                    <td>
                        <input type="time" name="mphb_hourly_open_time" value="<?php echo esc_attr( $open_time ); ?>">
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Closing Time', 'mphb-hourly' ); ?></th>
                    <td>
                        <input type="time" name="mphb_hourly_close_time" value="<?php echo esc_attr( $close_time ); ?>">
                    </td>
                </tr>
            </table>
        </div>

        <script>
        document.getElementById('mphb_hourly_enabled').addEventListener('change', function(){
            document.getElementById('mphb_hourly_dependent').style.display = this.checked ? '' : 'none';
        });
        </script>
        <?php
    }

    public static function save_meta_box( int $post_id ): void {
        if ( ! isset( $_POST['mphb_hourly_nonce'] ) ) return;
        if ( ! wp_verify_nonce( $_POST['mphb_hourly_nonce'], 'mphb_hourly_save' ) ) return;
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
        if ( ! current_user_can( 'edit_post', $post_id ) ) return;

        $room_type_post_type = MPHB()->postTypes()->roomType()->getPostType();
        if ( get_post_type( $post_id ) !== $room_type_post_type ) return;

        $enabled = isset( $_POST['mphb_hourly_enabled'] ) ? 1 : 0;
        update_post_meta( $post_id, MPHB_Hourly_Settings::META_ENABLED, $enabled );

        if ( $enabled ) {
            update_post_meta( $post_id, MPHB_Hourly_Settings::META_PRICE_PER_H,
                abs( floatval( $_POST['mphb_hourly_price'] ?? 0 ) ) );

            update_post_meta( $post_id, MPHB_Hourly_Settings::META_MIN_DURATION,
                max( 15, intval( $_POST['mphb_hourly_min_duration'] ?? 60 ) ) );

            update_post_meta( $post_id, MPHB_Hourly_Settings::META_MAX_DURATION,
                max( 0, intval( $_POST['mphb_hourly_max_duration'] ?? 0 ) ) );

            update_post_meta( $post_id, MPHB_Hourly_Settings::META_SLOT_STEP,
                max( 15, intval( $_POST['mphb_hourly_slot_step'] ?? 60 ) ) );

            $open  = sanitize_text_field( $_POST['mphb_hourly_open_time']  ?? '00:00' );
            $close = sanitize_text_field( $_POST['mphb_hourly_close_time'] ?? '23:59' );
            update_post_meta( $post_id, MPHB_Hourly_Settings::META_OPEN_TIME,  $open );
            update_post_meta( $post_id, MPHB_Hourly_Settings::META_CLOSE_TIME, $close );
        }
    }
}
