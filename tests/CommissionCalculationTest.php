<?php
/**
 * Tests for WRP_Tracker::calculate_commission().
 */

use PHPUnit\Framework\TestCase;

class CommissionCalculationTest extends TestCase {

    /** @dataProvider commission_provider */
    public function test_basic_calculation( float $total, float $rate, float $expected ): void {
        $this->assertSame( $expected, WRP_Tracker::calculate_commission( $total, $rate ) );
    }

    public static function commission_provider(): array {
        return [
            'ten percent of 100'        => [ 100.00,   10.0,  10.0    ],
            'ten percent of 99.99'      => [  99.99,   10.0,   9.999  ],
            'fifteen percent of 200'    => [ 200.00,   15.0,  30.0    ],
            '100 percent of 50'         => [  50.00,  100.0,  50.0    ],
            'zero rate'                 => [ 250.00,    0.0,   0.0    ],
            'zero total'                => [   0.00,   10.0,   0.0    ],
            'fractional rate'           => [ 100.00,    7.5,   7.5    ],
            'large order'               => [ 999.99,   20.0, 199.998  ],
            'rounds to 4 decimal places' => [ 100.00,  3.333, 3.333  ],
        ];
    }

    public function test_rounding_to_four_decimal_places(): void {
        // 100 * (1/3) = 33.333... should round to 4 decimal places.
        $result = WRP_Tracker::calculate_commission( 100.0, 1 / 3 );
        $this->assertSame( round( 100.0 * ( ( 1 / 3 ) / 100 ), 4 ), $result );
    }

    public function test_returns_float(): void {
        $result = WRP_Tracker::calculate_commission( 50.0, 10.0 );
        $this->assertIsFloat( $result );
    }

    public function test_commission_never_exceeds_order_total(): void {
        // Rate capped at 100 % in the admin, but the math should still hold.
        $total  = 75.0;
        $result = WRP_Tracker::calculate_commission( $total, 100.0 );
        $this->assertLessThanOrEqual( $total, $result );
    }
}
