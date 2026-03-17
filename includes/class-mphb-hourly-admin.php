<?php
/**
 * Interface admin : configuration du Room Type + affichage dans la liste.
 */
defined( 'ABSPATH' ) || exit;

class MPHB_Hourly_Admin {

    public static function init(): void {
        add_action( 'add_meta_boxes', [ __CLASS__, 'add_meta_box' ] );
        add_action( 'save_post',      [ __CLASS__, 'save_meta_box' ], 10, 2 );

        $booking_cpt  = MPHB()->postTypes()->booking()->getPostType();
        add_filter( "manage_{$booking_cpt}_posts_columns",       [ __CLASS__, 'add_column' ] );
        add_action( "manage_{$booking_cpt}_posts_custom_column", [ __CLASS__, 'render_column' ], 10, 2 );
    }

  

    public static function add_meta_box(): void {
        add_meta_box(
            'mphb_hourly',
            __( '⏱ Réservation à l\'heure', 'mphb-hourly' ),
            [ __CLASS__, 'render_meta_box' ],
            MPHB()->postTypes()->roomType()->getPostType(),
            'normal',
            'default'
        );
    }

    public static function render_meta_box( \WP_Post $post ): void {
        wp_nonce_field( 'mphb_hourly_save', '_mphb_hourly_nonce' );

        $rt  = $post->ID;
        $on  = MPHB_Hourly_Helper::is_hourly( $rt );
        $cur = MPHB()->settings()->currency()->getCurrencySymbol();
        ?>
        <style>
            .mphb-hourly-grid { display:grid; grid-template-columns:220px 1fr; gap:8px 16px; align-items:center; }
            .mphb-hourly-grid label { font-weight:600; }
            .mphb-hourly-dep { margin-top:12px; padding:12px; background:#f9f9f9; border:1px solid #ddd; border-radius:4px; }
            .mphb-hourly-hint { color:#777; font-size:11px; }
        </style>

        <div class="mphb-hourly-grid">
            <label><?php esc_html_e( 'Activer la réservation horaire', 'mphb-hourly' ); ?></label>
            <input type="checkbox" id="mphb_hourly_on" name="mphb_hourly_enabled" value="1" <?php checked( $on ); ?>>
        </div>

        <div class="mphb-hourly-dep" id="mphb_hourly_fields" <?php echo $on ? '' : 'style="display:none"'; ?>>
            <div class="mphb-hourly-grid">

                <label><?php printf( esc_html__( 'Prix / heure (%s)', 'mphb-hourly' ), esc_html( $cur ) ); ?></label>
                <input type="number" name="mphb_hourly_price" value="<?php echo esc_attr( MPHB_Hourly_Helper::price( $rt ) ); ?>" min="0" step="0.01" style="width:110px">

                <label><?php esc_html_e( 'Durée minimum (min)', 'mphb-hourly' ); ?></label>
                <div>
                    <input type="number" name="mphb_hourly_min" value="<?php echo esc_attr( MPHB_Hourly_Helper::min_duration( $rt ) ); ?>" min="15" step="15" style="width:90px">
                    <span class="mphb-hourly-hint"><?php esc_html_e( 'ex: 60 = 1 heure minimum', 'mphb-hourly' ); ?></span>
                </div>

                <label><?php esc_html_e( 'Durée maximum (min)', 'mphb-hourly' ); ?></label>
                <div>
                    <input type="number" name="mphb_hourly_max" value="<?php echo esc_attr( MPHB_Hourly_Helper::max_duration( $rt ) ); ?>" min="0" step="15" style="width:90px">
                    <span class="mphb-hourly-hint"><?php esc_html_e( '0 = pas de limite', 'mphb-hourly' ); ?></span>
                </div>

                <label><?php esc_html_e( 'Pas de créneau (min)', 'mphb-hourly' ); ?></label>
                <div>
                    <input type="number" name="mphb_hourly_step" value="<?php echo esc_attr( MPHB_Hourly_Helper::step( $rt ) ); ?>" min="15" step="15" style="width:90px">
                    <span class="mphb-hourly-hint"><?php esc_html_e( 'ex: 30 = créneaux toutes les 30 min', 'mphb-hourly' ); ?></span>
                </div>

                <label><?php esc_html_e( 'Heure d\'ouverture', 'mphb-hourly' ); ?></label>
                <input type="time" name="mphb_hourly_open" value="<?php echo esc_attr( MPHB_Hourly_Helper::open( $rt ) ); ?>">

                <label><?php esc_html_e( 'Heure de fermeture', 'mphb-hourly' ); ?></label>
                <input type="time" name="mphb_hourly_close" value="<?php echo esc_attr( MPHB_Hourly_Helper::close( $rt ) ); ?>">

            </div>
        </div>

        <script>
        document.getElementById('mphb_hourly_on').addEventListener('change', function(){
            document.getElementById('mphb_hourly_fields').style.display = this.checked ? '' : 'none';
        });
        </script>
        <?php
    }

    public static function save_meta_box( int $post_id, \WP_Post $post ): void {
        if ( ! isset( $_POST['_mphb_hourly_nonce'] ) ) return;
        if ( ! wp_verify_nonce( $_POST['_mphb_hourly_nonce'], 'mphb_hourly_save' ) ) return;
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
        if ( $post->post_type !== MPHB()->postTypes()->roomType()->getPostType() ) return;
        if ( ! current_user_can( 'edit_post', $post_id ) ) return;

        $enabled = isset( $_POST['mphb_hourly_enabled'] ) ? 1 : 0;
        update_post_meta( $post_id, MPHB_Hourly_Helper::RT_ENABLED, $enabled );

        if ( $enabled ) {
            update_post_meta( $post_id, MPHB_Hourly_Helper::RT_PRICE, abs( (float) ( $_POST['mphb_hourly_price'] ?? 0 ) ) );
            update_post_meta( $post_id, MPHB_Hourly_Helper::RT_MIN_DUR, max( 15, (int) ( $_POST['mphb_hourly_min'] ?? 60 ) ) );
            update_post_meta( $post_id, MPHB_Hourly_Helper::RT_MAX_DUR, max( 0, (int) ( $_POST['mphb_hourly_max'] ?? 0 ) ) );
            update_post_meta( $post_id, MPHB_Hourly_Helper::RT_STEP, max( 15, (int) ( $_POST['mphb_hourly_step'] ?? 60 ) ) );
            update_post_meta( $post_id, MPHB_Hourly_Helper::RT_OPEN, sanitize_text_field( $_POST['mphb_hourly_open'] ?? '00:00' ) );
            update_post_meta( $post_id, MPHB_Hourly_Helper::RT_CLOSE, sanitize_text_field( $_POST['mphb_hourly_close'] ?? '23:59' ) );
        }
    }

    /* ── Colonne "Créneau" dans la liste des réservations ───────────────── */

    public static function add_column( array $cols ): array {
        $new = [];
        foreach ( $cols as $k => $v ) {
            $new[ $k ] = $v;
            if ( $k === 'check_out_date' ) {
                $new['mphb_hourly_slot'] = __( 'Créneau horaire', 'mphb-hourly' );
            }
        }
        return $new;
    }

    public static function render_column( string $col, int $id ): void {
        if ( $col !== 'mphb_hourly_slot' ) return;

        $start = MPHB_Hourly_Helper::booking_start( $id );
        $end   = MPHB_Hourly_Helper::booking_end( $id );
        $dur   = MPHB_Hourly_Helper::booking_duration( $id );

        if ( $start && $end ) {
            echo esc_html( $start . ' → ' . $end );
            if ( $dur ) {
                echo ' <span style="color:#999;font-size:11px">('
                    . esc_html( MPHB_Hourly_Helper::format_duration( $dur ) ) . ')</span>';
            }
        } else {
            echo '<span style="color:#ccc">—</span>';
        }
    }
}
