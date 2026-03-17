<?php
/**
 * Plugin Name: MPHB Hourly Booking
 * Description: Étend MotoPress Hotel Booking avec des réservations à l'heure.
 * Version:     1.2.0
 * Requires Plugins: motopress-hotel-booking
 * Text Domain: mphb-hourly
 */

defined( 'ABSPATH' ) || exit;

define( 'MPHB_HOURLY_VERSION', '1.1.0' );
define( 'MPHB_HOURLY_PATH',    plugin_dir_path( __FILE__ ) );
define( 'MPHB_HOURLY_URL',     plugin_dir_url( __FILE__ ) );

add_action( 'plugins_loaded', function () {

    if ( ! function_exists( 'MPHB' ) ) {
        add_action( 'admin_notices', function () {
            echo '<div class="notice notice-error"><p>'
                . esc_html__( 'MPHB Hourly Booking nécessite MotoPress Hotel Booking.', 'mphb-hourly' )
                . '</p></div>';
        } );
        return;
    }

    require_once MPHB_HOURLY_PATH . 'includes/class-mphb-hourly-patcher.php';
    MPHB_Hourly_Patcher::apply_all();

    require_once MPHB_HOURLY_PATH . 'includes/class-mphb-hourly-helper.php';

    require_once MPHB_HOURLY_PATH . 'includes/class-mphb-hourly-availability.php';

    require_once MPHB_HOURLY_PATH . 'includes/class-mphb-hourly-checkout.php';

    require_once MPHB_HOURLY_PATH . 'includes/class-mphb-hourly-repository.php';

    require_once MPHB_HOURLY_PATH . 'includes/class-mphb-hourly-price.php';

    require_once MPHB_HOURLY_PATH . 'includes/class-mphb-hourly-lock.php';

    require_once MPHB_HOURLY_PATH . 'includes/class-mphb-hourly-emails.php';

    require_once MPHB_HOURLY_PATH . 'includes/class-mphb-hourly-confirmation.php';

    require_once MPHB_HOURLY_PATH . 'includes/class-mphb-hourly-admin.php';

    require_once MPHB_HOURLY_PATH . 'includes/class-mphb-hourly-search.php';

    require_once MPHB_HOURLY_PATH . 'includes/class-mphb-hourly-scripts.php';

    // Initialisation
    MPHB_Hourly_Availability::init();
    MPHB_Hourly_Checkout::init();
    MPHB_Hourly_Repository::init();
    MPHB_Hourly_Price::init();
    MPHB_Hourly_Lock::init();
    MPHB_Hourly_Emails::init();
    MPHB_Hourly_Confirmation::init();
    MPHB_Hourly_Admin::init();
    MPHB_Hourly_Search::init();
    MPHB_Hourly_Scripts::init();


} );
