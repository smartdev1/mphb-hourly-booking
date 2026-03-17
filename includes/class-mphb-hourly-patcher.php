<?php
defined( 'ABSPATH' ) || exit;

class MPHB_Hourly_Patcher {

    const PATCH1_MARKER = '/* mphb_hourly_patch1 */';
    const PATCH2_MARKER = '/* mphb_hourly_patch2 */';
    const PATCH3_MARKER = '/* mphb_hourly_patch3 */';

    /* -----------------------------------------------------------------------
     * Entry point - called directly from plugin bootstrap (no action hook)
     * -------------------------------------------------------------------- */

    public static function apply_all() {
        $p1 = self::ensure_patch1();
        $p2 = self::ensure_patch2();
        $p3 = self::ensure_patch3();

        if ( $p1 !== true || $p2 !== true || $p3 !== true ) {
            $p1r = $p1; $p2r = $p2; $p3r = $p3;
            add_action( 'admin_notices', function () use ( $p1r, $p2r, $p3r ) {
                self::render_patch_failure_notice( $p1r, $p2r, $p3r );
            } );
        }
    }

    /* -----------------------------------------------------------------------
     * PATCH 1 - room-persistence.php
     * Adds: apply_filters( 'mphb_found_locked_rooms', $roomIds, $atts )
     * -------------------------------------------------------------------- */

    public static function ensure_patch1() {
        $file = self::mphb_path( 'includes/persistences/room-persistence.php' );
        if ( ! file_exists( $file ) ) return 'File not found: room-persistence.php';

        $content = file_get_contents( $file );
        if ( $content === false ) return 'Cannot read: room-persistence.php';
        if ( strpos( $content, self::PATCH1_MARKER ) !== false ) return true;

        $search = '$roomIds = array_map( \'absint\', $roomIds );';
        if ( strpos( $content, $search ) === false ) {
            $search = '$roomIds = array_map(\'absint\', $roomIds);';
            if ( strpos( $content, $search ) === false ) {
                return 'Injection point not found in room-persistence.php';
            }
        }

        $injection = "\n\t\t" . self::PATCH1_MARKER . "\n"
                   . "\t\t\$roomIds = apply_filters( 'mphb_found_locked_rooms', \$roomIds, \$atts );\n";

        $patched = str_replace( $search, $search . $injection, $content );
        return self::write_file( $file, $content, $patched, 'patch1' );
    }

    /* -----------------------------------------------------------------------
     * PATCH 2 - step-booking.php
     * Bypasses rate/rules validation for hourly room types
     * -------------------------------------------------------------------- */

    public static function ensure_patch2() {
        $file = self::mphb_path( 'includes/shortcodes/checkout-shortcode/step-booking.php' );
        if ( ! file_exists( $file ) ) return 'File not found: step-booking.php';

        $content = file_get_contents( $file );
        if ( $content === false ) return 'Cannot read: step-booking.php';
        if ( strpos( $content, self::PATCH2_MARKER ) !== false ) return true;

        $search_rate_null = '$rateId = isset( $roomDetails[\'rate_id\'] ) ? \MPHB\Utils\ValidateUtils::validateInt( $roomDetails[\'rate_id\'] ) : null;';
        if ( strpos( $content, $search_rate_null ) === false ) {
            return 'Injection point A not found in step-booking.php ($rateId validateInt)';
        }

        $inject_after_rate = "\n\t\t\t" . self::PATCH2_MARKER . "\n"
            . "\t\t\t\$isHourlyRoom = (bool) get_post_meta( \$roomTypeId, '_mphb_hourly_enabled', true );\n";

        $search_if_rate_null  = 'if ( ! $rateId ) {';
        $replace_if_rate_null = 'if ( ! $rateId && ! $isHourlyRoom ) {';

        $search_in_array  = 'if ( ! in_array( $rateId, $allowedRatesIds ) ) {';
        $replace_in_array = 'if ( ! $isHourlyRoom && ! in_array( $rateId, $allowedRatesIds ) ) {';

        $search_rules = "if ( mphb_availability_facade()->isBookingRulesViolated(\n\t\t\t\t\t\$roomType->getOriginalId(),\n\t\t\t\t\t\$this->checkInDate,\n\t\t\t\t\t\$this->checkOutDate,\n\t\t\t\t\t\$isIgnoreBookingRules\n\t\t\t\t)\n\t\t\t) {";
        $replace_rules = "if ( ! \$isHourlyRoom && mphb_availability_facade()->isBookingRulesViolated(\n\t\t\t\t\t\$roomType->getOriginalId(),\n\t\t\t\t\t\$this->checkInDate,\n\t\t\t\t\t\$this->checkOutDate,\n\t\t\t\t\t\$isIgnoreBookingRules\n\t\t\t\t)\n\t\t\t) {";

        $missing = array();
        if ( strpos( $content, $search_if_rate_null ) === false ) $missing[] = 'if(!$rateId)';
        if ( strpos( $content, $search_in_array ) === false )     $missing[] = 'in_array(rateId)';
        if ( strpos( $content, $search_rules ) === false )        $missing[] = 'isBookingRulesViolated';

        if ( ! empty( $missing ) ) {
            return 'Injection points not found in step-booking.php: ' . implode( ', ', $missing );
        }

        $patched = str_replace(
            $search_rate_null,
            $search_rate_null . $inject_after_rate,
            $content
        );
        $patched = str_replace( $search_if_rate_null, $replace_if_rate_null, $patched );
        $patched = str_replace( $search_in_array,     $replace_in_array,     $patched );
        $patched = str_replace( $search_rules,        $replace_rules,        $patched );

        return self::write_file( $file, $content, $patched, 'patch2' );
    }

    /* -----------------------------------------------------------------------
     * PATCH 3 - checkout-view.php
     * Adds null-guard after findById() so $roomType === null never crashes
     *
     * Target code (around line 960):
     *   $roomType = MPHB()->getRoomTypeRepository()->findById( $roomTypeId );
     *   ?>
     *   <div class="mphb-room-details" ...>
     *       <input ... value="<?php echo esc_attr( $roomType->getOriginalId() ); ?>"
     *
     * We insert: if ( ! $roomType ) { continue; }
     * -------------------------------------------------------------------- */

    public static function ensure_patch3() {
        $file = self::mphb_path( 'includes/views/shortcodes/checkout-view.php' );
        if ( ! file_exists( $file ) ) return 'File not found: checkout-view.php';

        $content = file_get_contents( $file );
        if ( $content === false ) return 'Cannot read: checkout-view.php';
        if ( strpos( $content, self::PATCH3_MARKER ) !== false ) return true;

        // The exact line we target - present in both MPHB 5.x variants
        $search = '$roomType   = MPHB()->getRoomTypeRepository()->findById( $roomTypeId );';
        if ( strpos( $content, $search ) === false ) {
            // Try without extra spaces
            $search = '$roomType = MPHB()->getRoomTypeRepository()->findById( $roomTypeId );';
            if ( strpos( $content, $search ) === false ) {
                return 'Injection point not found in checkout-view.php (findById roomType)';
            }
        }

        $guard = "\n\t\t\t\t" . self::PATCH3_MARKER . "\n"
               . "\t\t\t\tif ( ! \$roomType ) { continue; }\n";

        $patched = str_replace( $search, $search . $guard, $content );

        // Verify we actually changed something
        if ( $patched === $content ) {
            return 'str_replace had no effect in checkout-view.php';
        }

        return self::write_file( $file, $content, $patched, 'patch3' );
    }

    /* -----------------------------------------------------------------------
     * Status checks (read-only)
     * -------------------------------------------------------------------- */

    public static function is_patch1_applied() {
        $file = self::mphb_path( 'includes/persistences/room-persistence.php' );
        return file_exists( $file ) && strpos( file_get_contents( $file ), self::PATCH1_MARKER ) !== false;
    }

    public static function is_patch2_applied() {
        $file = self::mphb_path( 'includes/shortcodes/checkout-shortcode/step-booking.php' );
        return file_exists( $file ) && strpos( file_get_contents( $file ), self::PATCH2_MARKER ) !== false;
    }

    public static function is_patch3_applied() {
        $file = self::mphb_path( 'includes/views/shortcodes/checkout-view.php' );
        return file_exists( $file ) && strpos( file_get_contents( $file ), self::PATCH3_MARKER ) !== false;
    }

    /* -----------------------------------------------------------------------
     * Restore backups
     * -------------------------------------------------------------------- */

    public static function restore_backups() {
        $results = array();
        $files   = array(
            'includes/persistences/room-persistence.php',
            'includes/shortcodes/checkout-shortcode/step-booking.php',
            'includes/views/shortcodes/checkout-view.php',
        );

        foreach ( $files as $rel ) {
            $file   = self::mphb_path( $rel );
            $backup = $file . '.mphb-hourly.bak';
            if ( file_exists( $backup ) && is_writable( $file ) ) {
                copy( $backup, $file );
                if ( function_exists( 'opcache_invalidate' ) ) {
                    opcache_invalidate( $file, true );
                }
                $results[ $rel ] = 'restored';
            } else {
                $results[ $rel ] = 'skipped';
            }
        }

        return $results;
    }

    /* -----------------------------------------------------------------------
     * File write helper
     * -------------------------------------------------------------------- */

    private static function write_file( $file, $original, $patched, $name ) {
        if ( $patched === $original ) {
            return 'No change produced for ' . $name . ' - check logic.';
        }

        if ( ! is_writable( $file ) ) {
            return 'File not writable: ' . $file;
        }

        $backup = $file . '.mphb-hourly.bak';
        if ( ! file_exists( $backup ) ) {
            copy( $file, $backup );
        }

        $result = file_put_contents( $file, $patched );
        if ( $result === false ) {
            return 'Write failed: ' . $file;
        }

        $written = file_get_contents( $file );
        $marker  = constant( 'self::' . strtoupper( $name ) . '_MARKER' );
        if ( strpos( $written, $marker ) === false ) {
            copy( $backup, $file );
            return 'Integrity check failed for ' . $name . '. Original restored.';
        }

        if ( function_exists( 'opcache_invalidate' ) ) {
            opcache_invalidate( $file, true );
        }

        return true;
    }

    /* -----------------------------------------------------------------------
     * Admin notice on patch failure
     * -------------------------------------------------------------------- */

    private static function render_patch_failure_notice( $p1, $p2, $p3 ) {
        $failures = array();
        if ( $p1 !== true ) $failures['Patch 1 (room-persistence.php)']   = $p1;
        if ( $p2 !== true ) $failures['Patch 2 (step-booking.php)']       = $p2;
        if ( $p3 !== true ) $failures['Patch 3 (checkout-view.php)']      = $p3;
        if ( empty( $failures ) ) return;

        echo '<div class="notice notice-error"><p>';
        echo '<strong>MPHB Hourly Booking - Required patches not applied:</strong><br>';
        foreach ( $failures as $label => $reason ) {
            echo '<br><strong>' . esc_html( $label ) . '</strong>: ' . esc_html( $reason );
        }
        echo '</p></div>';
    }

    /* -----------------------------------------------------------------------
     * Helper
     * -------------------------------------------------------------------- */

    private static function mphb_path( $relative ) {
        return WP_PLUGIN_DIR . '/motopress-hotel-booking/' . $relative;
    }
}