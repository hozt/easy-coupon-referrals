<?php
/**
 * PHPUnit bootstrap for Easy Coupon Referrals unit tests.
 *
 * Covers pure-logic static/instance methods only. WordPress and WooCommerce
 * functions are stubbed below so no WP installation is required.
 */

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

// ---------------------------------------------------------------------------
// WordPress constant stubs
// ---------------------------------------------------------------------------

if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', '/tmp/wp/' );
}

// ---------------------------------------------------------------------------
// WordPress function stubs (only those called by the classes under test)
// ---------------------------------------------------------------------------

if ( ! function_exists( 'get_option' ) ) {
    function get_option( $option, $default = false ) {
        return $default;
    }
}

if ( ! function_exists( 'update_option' ) ) {
    function update_option( $option, $value, $autoload = null ) {
        return true;
    }
}

if ( ! function_exists( 'add_action' ) ) {
    function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {}
}

if ( ! function_exists( 'add_filter' ) ) {
    function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {}
}

// ---------------------------------------------------------------------------
// Classes under test
// ---------------------------------------------------------------------------

require_once dirname( __DIR__ ) . '/includes/class-wrp-tracker.php';
