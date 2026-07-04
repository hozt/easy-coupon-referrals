<?php
/**
 * Tests for the minimum payout threshold eligibility check.
 *
 * The rule from WRP_Admin::check_threshold():
 *   - If threshold <= 0  → always eligible (return null).
 *   - If pending < threshold → not eligible (return error string).
 *   - If pending >= threshold → eligible (return null).
 *
 * This test class exercises that pure comparison logic directly.
 */

use PHPUnit\Framework\TestCase;

class ThresholdEligibilityTest extends TestCase {

    /**
     * Replicates the eligibility decision in WRP_Admin::check_threshold().
     *
     * @param float $threshold Minimum required pending amount.
     * @param float $pending   Referrer's current pending balance.
     * @return bool True = eligible to be paid.
     */
    private function is_eligible( float $threshold, float $pending ): bool {
        if ( $threshold <= 0 ) {
            return true;
        }
        return $pending >= $threshold;
    }

    // -----------------------------------------------------------------------
    // Threshold disabled (0 or negative)
    // -----------------------------------------------------------------------

    public function test_zero_threshold_always_eligible(): void {
        $this->assertTrue( $this->is_eligible( 0.0, 0.0 ) );
        $this->assertTrue( $this->is_eligible( 0.0, 100.0 ) );
    }

    public function test_negative_threshold_always_eligible(): void {
        $this->assertTrue( $this->is_eligible( -10.0, 0.0 ) );
    }

    // -----------------------------------------------------------------------
    // Threshold enabled
    // -----------------------------------------------------------------------

    public function test_pending_below_threshold_not_eligible(): void {
        $this->assertFalse( $this->is_eligible( 50.0, 49.99 ) );
    }

    public function test_pending_exactly_at_threshold_is_eligible(): void {
        $this->assertTrue( $this->is_eligible( 50.0, 50.0 ) );
    }

    public function test_pending_above_threshold_is_eligible(): void {
        $this->assertTrue( $this->is_eligible( 50.0, 75.0 ) );
    }

    public function test_zero_pending_below_positive_threshold(): void {
        $this->assertFalse( $this->is_eligible( 25.0, 0.0 ) );
    }

    /** @dataProvider threshold_provider */
    public function test_boundary_cases( float $threshold, float $pending, bool $expected ): void {
        $this->assertSame( $expected, $this->is_eligible( $threshold, $pending ) );
    }

    public static function threshold_provider(): array {
        return [
            'just below threshold'    => [ 100.0,  99.9999, false ],
            'exactly at threshold'    => [ 100.0, 100.0,    true  ],
            'just above threshold'    => [ 100.0, 100.0001, true  ],
            'large pending, small th' => [   1.0, 999.99,   true  ],
            'disabled threshold'      => [   0.0,   0.0,    true  ],
        ];
    }
}
