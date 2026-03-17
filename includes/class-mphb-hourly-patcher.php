<?php

defined( 'ABSPATH' ) || exit;

class MPHB_Hourly_Patcher {

    const OPTION_PATCH1_HASH = 'mphb_hourly_patch1_hash';
    const OPTION_PATCH2_HASH = 'mphb_hourly_patch2_hash';

    
    const PATCH1_MARKER = '/* mphb_hourly_patch1 */';
    const PATCH2_MARKER = '/* mphb_hourly_patch2 */';

    public static function init(): void {
       
        add_action( 'plugins_loaded', [ __CLASS__, 'apply_all' ], 0 );
    }

    /* -----------------------------------------------------------------------
     * Point d'entrée principal
     * -------------------------------------------------------------------- */

    public static function apply_all(): void {
        $p1 = self::ensure_patch1();
        $p2 = self::ensure_patch2();

       
        if ( $p1 !== true || $p2 !== true ) {
            add_action( 'admin_notices', function () use ( $p1, $p2 ) {
                self::render_patch_failure_notice( $p1, $p2 );
            } );
        }
    }

    /* -----------------------------------------------------------------------
     * PATCH 1 — room-persistence.php
     * -------------------------------------------------------------------- */

    public static function ensure_patch1(): bool|string {
        $file = self::mphb_path( 'includes/persistences/room-persistence.php' );
        if ( ! file_exists( $file ) ) {
            return 'Fichier introuvable : includes/persistences/room-persistence.php';
        }

        $content = file_get_contents( $file );
        if ( $content === false ) {
            return 'Impossible de lire : includes/persistences/room-persistence.php';
        }


        if ( strpos( $content, self::PATCH1_MARKER ) !== false ) {
            return true;
        }

        // Chercher le point d'injection exact
        // Ligne cible : $roomIds = array_map( 'absint', $roomIds );
        // suivie de : return $roomIds;
        $search = "\$roomIds = array_map( 'absint', \$roomIds );";

        if ( strpos( $content, $search ) === false ) {
           
            $search = "\$roomIds = array_map('absint', \$roomIds);";
            if ( strpos( $content, $search ) === false ) {
                return "Point d'injection introuvable dans room-persistence.php (array_map absint). La structure de MPHB a peut-être changé.";
            }
        }

        $injection = "\n\t\t" . self::PATCH1_MARKER . "\n"
                   . "\t\t\$roomIds = apply_filters( 'mphb_found_locked_rooms', \$roomIds, \$atts );\n";

        $new_content = str_replace( $search, $search . $injection, $content );

        return self::write_file( $file, $content, $new_content, 'patch1' );
    }

    /* -----------------------------------------------------------------------
     * PATCH 2 — step-booking.php
     * -------------------------------------------------------------------- */

    public static function ensure_patch2(): bool|string {
        $file = self::mphb_path( 'includes/shortcodes/checkout-shortcode/step-booking.php' );
        if ( ! file_exists( $file ) ) {
            return 'Fichier introuvable : step-booking.php';
        }

        $content = file_get_contents( $file );
        if ( $content === false ) {
            return 'Impossible de lire : step-booking.php';
        }

        // Déjà patché ?
        if ( strpos( $content, self::PATCH2_MARKER ) !== false ) {
            return true;
        }

       
        $search_rate_null = "\$rateId = isset( \$roomDetails['rate_id'] ) ? \\MPHB\\Utils\\ValidateUtils::validateInt( \$roomDetails['rate_id'] ) : null;";

        if ( strpos( $content, $search_rate_null ) === false ) {
            return "Point d'injection A introuvable dans step-booking.php (\$rateId validateInt). La structure de MPHB a peut-être changé.";
        }

        $inject_after_rate = "\n\t\t\t" . self::PATCH2_MARKER . "\n"
            . "\t\t\t\$isHourlyRoom = (bool) get_post_meta( \$roomTypeId, '_mphb_hourly_enabled', true );\n";

      
        $search_if_rate_null  = "if ( ! \$rateId ) {";
        $replace_if_rate_null = "if ( ! \$rateId && ! \$isHourlyRoom ) {";

       
        $search_in_array  = "if ( ! in_array( \$rateId, \$allowedRatesIds ) ) {";
        $replace_in_array = "if ( ! \$isHourlyRoom && ! in_array( \$rateId, \$allowedRatesIds ) ) {";

       
        $search_rules = "if ( mphb_availability_facade()->isBookingRulesViolated(\n\t\t\t\t\t\$roomType->getOriginalId(),\n\t\t\t\t\t\$this->checkInDate,\n\t\t\t\t\t\$this->checkOutDate,\n\t\t\t\t\t\$isIgnoreBookingRules\n\t\t\t\t)\n\t\t\t) {";
        $replace_rules = "if ( ! \$isHourlyRoom && mphb_availability_facade()->isBookingRulesViolated(\n\t\t\t\t\t\$roomType->getOriginalId(),\n\t\t\t\t\t\$this->checkInDate,\n\t\t\t\t\t\$this->checkOutDate,\n\t\t\t\t\t\$isIgnoreBookingRules\n\t\t\t\t)\n\t\t\t) {";

       
        $missing = [];
        if ( strpos( $content, $search_if_rate_null ) === false )  $missing[] = 'if(!$rateId)';
        if ( strpos( $content, $search_in_array ) === false )       $missing[] = 'in_array(rateId)';
        if ( strpos( $content, $search_rules ) === false )          $missing[] = 'isBookingRulesViolated';

        if ( ! empty( $missing ) ) {
            
            $search_rules_alt = "if ( mphb_availability_facade()->isBookingRulesViolated(";
            if ( strpos( $content, $search_rules_alt ) !== false ) {
                
                $new_content = preg_replace(
                    '/if \( mphb_availability_facade\(\)->isBookingRulesViolated\(\s*\$roomType->getOriginalId\(\),\s*\$this->checkInDate,\s*\$this->checkOutDate,\s*\$isIgnoreBookingRules\s*\)\s*\) \{/',
                    "if ( ! \$isHourlyRoom && mphb_availability_facade()->isBookingRulesViolated(\n\t\t\t\t\t\$roomType->getOriginalId(),\n\t\t\t\t\t\$this->checkInDate,\n\t\t\t\t\t\$this->checkOutDate,\n\t\t\t\t\t\$isIgnoreBookingRules\n\t\t\t\t)\n\t\t\t) {",
                    $content,
                    1 
                );
                if ( $new_content !== null ) {
                    $content = $new_content;
                    
                    $missing = array_diff( $missing, [ 'isBookingRulesViolated' ] );
                }
            }

            if ( ! empty( $missing ) ) {
                return "Points d'injection introuvables dans step-booking.php : " . implode( ', ', $missing ) . ". La structure de MPHB a peut-être changé.";
            }
        }

        
        $new_content = $content;

        
        $new_content = str_replace(
            $search_rate_null,
            $search_rate_null . $inject_after_rate,
            $new_content
        );

        
        $new_content = str_replace( $search_if_rate_null, $replace_if_rate_null, $new_content );

       
        $new_content = str_replace( $search_in_array, $replace_in_array, $new_content );

        
        if ( strpos( $new_content, $search_rules ) !== false ) {
            $new_content = str_replace( $search_rules, $replace_rules, $new_content );
        }

        return self::write_file( $file, $content, $new_content, 'patch2' );
    }

    /* -----------------------------------------------------------------------
     * Écriture sécurisée avec backup
     * -------------------------------------------------------------------- */

    private static function write_file( string $file, string $original, string $patched, string $name ): bool|string {
        
        if ( $original === $patched ) {
            return "Aucune modification générée pour {$name}. Vérifier la logique de patch.";
        }

       
        if ( ! is_writable( $file ) ) {
            return "Fichier non accessible en écriture : {$file}. Appliquer le patch manuellement (voir /patches/).";
        }

       
        $backup = $file . '.mphb-hourly.bak';
        if ( ! file_exists( $backup ) ) {
            copy( $file, $backup );
        }

        
        $result = file_put_contents( $file, $patched );
        if ( $result === false ) {
            return "Échec de l'écriture dans {$file}.";
        }

       
        $written = file_get_contents( $file );
        $marker  = $name === 'patch1' ? self::PATCH1_MARKER : self::PATCH2_MARKER;
        if ( strpos( $written, $marker ) === false ) {
          
            copy( $backup, $file );
            return "Vérification d'intégrité échouée pour {$name}. Fichier original restauré.";
        }

        
        if ( function_exists( 'opcache_invalidate' ) ) {
            opcache_invalidate( $file, true );
        }

        return true;
    }

    /* -----------------------------------------------------------------------
     * Vérification de l'état actuel (sans modifier)
     * -------------------------------------------------------------------- */

    public static function is_patch1_applied(): bool {
        $file = self::mphb_path( 'includes/persistences/room-persistence.php' );
        if ( ! file_exists( $file ) ) return false;
        return strpos( file_get_contents( $file ), self::PATCH1_MARKER ) !== false;
    }

    public static function is_patch2_applied(): bool {
        $file = self::mphb_path( 'includes/shortcodes/checkout-shortcode/step-booking.php' );
        if ( ! file_exists( $file ) ) return false;
        return strpos( file_get_contents( $file ), self::PATCH2_MARKER ) !== false;
    }

    /* -----------------------------------------------------------------------
     * Restauration des backups (utilitaire admin)
     * -------------------------------------------------------------------- */

    public static function restore_backups(): array {
        $results = [];
        $files   = [
            'includes/persistences/room-persistence.php',
            'includes/shortcodes/checkout-shortcode/step-booking.php',
        ];

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
     * Notice admin en cas d'échec
     * -------------------------------------------------------------------- */

    private static function render_patch_failure_notice( $p1, $p2 ): void {
        $failures = [];
        if ( $p1 !== true ) $failures['Patch 1 (room-persistence.php)'] = $p1;
        if ( $p2 !== true ) $failures['Patch 2 (step-booking.php)']     = $p2;

        if ( empty( $failures ) ) return;

        echo '<div class="notice notice-error"><p>';
        echo '<strong>MPHB Hourly Booking — Patches requis non appliqués automatiquement :</strong><br>';

        foreach ( $failures as $label => $reason ) {
            echo '<br><strong>' . esc_html( $label ) . '</strong> : ' . esc_html( $reason );
        }

        echo '<br><br>';
        printf(
            esc_html__( 'Appliquer les patches manuellement en suivant les instructions dans le dossier %s du plugin.', 'mphb-hourly' ),
            '<code>/wp-content/plugins/mphb-hourly-booking/patches/</code>'
        );
        echo '</p></div>';
    }

    /* -----------------------------------------------------------------------
     * Utilitaire
     * -------------------------------------------------------------------- */

    private static function mphb_path( string $relative ): string {
        return WP_PLUGIN_DIR . '/motopress-hotel-booking/' . $relative;
    }
}
