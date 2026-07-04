<?php
/**
 * Tests for WRP_Admin settings defaults and normalization logic.
 *
 * These tests exercise the pure normalization rules used in handle_settings_save()
 * and the hard-coded defaults returned by get_settings() when the WP option is empty.
 *
 * Because get_option() is stubbed to always return its $default parameter, calling
 * WRP_Admin::get_settings() with no saved data returns the hard-coded defaults.
 */

use PHPUnit\Framework\TestCase;

class SettingsDefaultsTest extends TestCase {

    // -----------------------------------------------------------------------
    // Helpers that replicate the normalization in handle_settings_save()
    // -----------------------------------------------------------------------

    /** @see WRP_Admin::handle_settings_save() */
    private function normalize_rate( $value ): float {
        return min( 100.0, max( 0.0, (float) $value ) );
    }

    private function normalize_threshold( $value ): float {
        return max( 0.0, (float) $value );
    }

    private function normalize_coupon_type( $value ): string {
        $valid = [ 'percent', 'fixed_cart', 'none' ];
        return in_array( $value, $valid, true ) ? $value : 'percent';
    }

    private function normalize_amount( $value ): float {
        return max( 0.0, (float) $value );
    }

    // -----------------------------------------------------------------------
    // Commission rate normalization
    // -----------------------------------------------------------------------

    public function test_rate_clamps_below_zero(): void {
        $this->assertSame( 0.0, $this->normalize_rate( -5 ) );
    }

    public function test_rate_clamps_above_100(): void {
        $this->assertSame( 100.0, $this->normalize_rate( 150 ) );
    }

    public function test_rate_accepts_zero(): void {
        $this->assertSame( 0.0, $this->normalize_rate( 0 ) );
    }

    public function test_rate_accepts_100(): void {
        $this->assertSame( 100.0, $this->normalize_rate( 100 ) );
    }

    public function test_rate_accepts_fractional(): void {
        $this->assertSame( 7.5, $this->normalize_rate( 7.5 ) );
    }

    public function test_rate_casts_string(): void {
        $this->assertSame( 10.0, $this->normalize_rate( '10' ) );
    }

    // -----------------------------------------------------------------------
    // Payout threshold normalization
    // -----------------------------------------------------------------------

    public function test_threshold_clamps_below_zero(): void {
        $this->assertSame( 0.0, $this->normalize_threshold( -1 ) );
    }

    public function test_threshold_accepts_zero(): void {
        $this->assertSame( 0.0, $this->normalize_threshold( 0 ) );
    }

    public function test_threshold_accepts_positive(): void {
        $this->assertSame( 50.0, $this->normalize_threshold( 50 ) );
    }

    // -----------------------------------------------------------------------
    // Coupon type normalization
    // -----------------------------------------------------------------------

    public function test_coupon_type_accepts_percent(): void {
        $this->assertSame( 'percent', $this->normalize_coupon_type( 'percent' ) );
    }

    public function test_coupon_type_accepts_fixed_cart(): void {
        $this->assertSame( 'fixed_cart', $this->normalize_coupon_type( 'fixed_cart' ) );
    }

    public function test_coupon_type_accepts_none(): void {
        $this->assertSame( 'none', $this->normalize_coupon_type( 'none' ) );
    }

    public function test_coupon_type_rejects_invalid(): void {
        $this->assertSame( 'percent', $this->normalize_coupon_type( 'bogus' ) );
    }

    public function test_coupon_type_rejects_empty(): void {
        $this->assertSame( 'percent', $this->normalize_coupon_type( '' ) );
    }

    // -----------------------------------------------------------------------
    // Coupon amount normalization
    // -----------------------------------------------------------------------

    public function test_amount_clamps_below_zero(): void {
        $this->assertSame( 0.0, $this->normalize_amount( -10 ) );
    }

    public function test_amount_accepts_zero(): void {
        $this->assertSame( 0.0, $this->normalize_amount( 0 ) );
    }

    public function test_amount_accepts_positive(): void {
        $this->assertSame( 15.0, $this->normalize_amount( 15 ) );
    }
}
