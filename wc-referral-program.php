<?php
/**
 * Plugin Name: WC Referral Program
 * Plugin URI:  https://hozt.com/woocommerce
 * Description: Referral tracking with coupon-based attribution and commission management.
 * Version:     1.0.0
 * Author:      Jeffrey Haug
 * Text Domain: wc-referral-program
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * WC requires at least: 7.0
 */

defined( 'ABSPATH' ) || exit;

define( 'WRP_VERSION',     '1.0.0' );
define( 'WRP_PLUGIN_FILE', __FILE__ );
define( 'WRP_PLUGIN_DIR',  plugin_dir_path( __FILE__ ) );
define( 'WRP_PLUGIN_URL',  plugin_dir_url( __FILE__ ) );

// Activation hook must be registered at file-include time, not inside a callback.
register_activation_hook( __FILE__, array( 'WRP_Plugin', 'activate' ) );

// Boot
add_action( 'plugins_loaded', array( 'WRP_Plugin', 'init' ) );

class WRP_Plugin {

    private static $instance = null;

    public static function init() {
        if ( ! class_exists( 'WooCommerce' ) ) {
            add_action( 'admin_notices', array( __CLASS__, 'wc_missing_notice' ) );
            return;
        }
        if ( null === self::$instance ) {
            self::$instance = new self();
        }

        // Ensure tables exist (handles cases where activation hook was missed).
        if ( get_option( 'wrp_db_version' ) !== WRP_VERSION ) {
            require_once WRP_PLUGIN_DIR . 'includes/class-wrp-database.php';
            WRP_Database::create_tables();
            update_option( 'wrp_db_version', WRP_VERSION );
        }

        return self::$instance;
    }

    private function __construct() {
        require_once WRP_PLUGIN_DIR . 'includes/class-wrp-database.php';
        require_once WRP_PLUGIN_DIR . 'includes/class-wrp-admin.php';
        require_once WRP_PLUGIN_DIR . 'includes/class-wrp-tracker.php';
        require_once WRP_PLUGIN_DIR . 'includes/class-wrp-dashboard.php';

        new WRP_Admin();
        new WRP_Tracker();
        new WRP_Dashboard();

        add_action( 'template_redirect', array( $this, 'handle_refer_link' ) );
    }

    public static function activate() {
        require_once plugin_dir_path( __FILE__ ) . 'includes/class-wrp-database.php';
        require_once plugin_dir_path( __FILE__ ) . 'includes/class-wrp-dashboard.php';
        WRP_Database::create_tables();
        update_option( 'wrp_db_version', WRP_VERSION );
        WRP_Dashboard::flush_rewrite_rules();
    }

    /**
     * Apply a referral coupon when ?refer=CODE is present in the URL.
     * Stays on the current page (strips the param from the URL) and sets a
     * short-lived cookie so the tracker can show a modal on the next render.
     */
    public function handle_refer_link(): void {
        if ( empty( $_GET['refer'] ) ) {
            return;
        }

        $code = sanitize_text_field( wp_unslash( $_GET['refer'] ) );

        if ( WC()->cart && ! WC()->cart->has_discount( $code ) ) {
            WC()->cart->apply_coupon( $code );
        }

        // Cookie survives the redirect and tells the tracker to show the modal.
        setcookie( 'wrp_referral_notice', $code, time() + 120, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true );

        // Redirect to the same page without ?refer= in the URL.
        $clean = remove_query_arg( 'refer', home_url( add_query_arg( [] ) ) );
        wp_safe_redirect( $clean );
        exit;
    }

    public static function wc_missing_notice() {
        echo '<div class="notice notice-error"><p><strong>WC Referral Program</strong> requires WooCommerce to be installed and active.</p></div>';
    }
}
