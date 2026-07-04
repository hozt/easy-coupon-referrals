<?php
defined( 'ABSPATH' ) || exit;

class WRP_Tracker {

    /** Coupon code captured from cookie before headers are sent. */
    private string $modal_code = '';

    public function __construct() {
        add_action( 'woocommerce_order_status_processing', [ $this, 'on_order_processing' ], 10, 2 );
        add_action( 'woocommerce_admin_order_data_after_order_details', [ $this, 'render_referral_meta_box' ] );
        add_action( 'woocommerce_before_cart',            [ $this, 'maybe_show_referral_notice' ] );
        add_action( 'woocommerce_before_checkout_form',   [ $this, 'maybe_show_referral_notice' ] );
        // Capture cookie early (headers still open), clear it, render modal in footer.
        add_action( 'template_redirect', [ $this, 'capture_referral_cookie' ], 20 );
        add_action( 'wp_footer',         [ $this, 'maybe_render_referral_modal' ] );
    }

    /**
     * Reads the short-lived cookie set after a ?refer= redirect, stores the
     * code in a property, and clears the cookie while headers are still open.
     */
    public function capture_referral_cookie(): void {
        if ( empty( $_COOKIE['wrp_referral_notice'] ) ) {
            return;
        }
        $code = sanitize_text_field( wp_unslash( $_COOKIE['wrp_referral_notice'] ) );
        // Clear immediately so it only shows once.
        setcookie( 'wrp_referral_notice', '', time() - 3600, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true );

        if ( WRP_Database::get_referrer_by_coupon( $code ) ) {
            $this->modal_code = $code;
        }
    }

    /**
     * Outputs a floating modal in wp_footer confirming the referral was applied.
     */
    public function maybe_render_referral_modal(): void {
        if ( ! $this->modal_code ) {
            return;
        }

        $wc_coupon = new WC_Coupon( $this->modal_code );
        $amount    = (float) $wc_coupon->get_amount();
        $type      = $wc_coupon->get_discount_type();

        if ( $amount > 0 ) {
            $savings  = 'percent' === $type
                ? esc_html( $amount ) . '% off'
                : wp_kses_post( wc_price( $amount ) ) . ' off';
            $headline = 'Referral discount applied!';
            $body     = 'You\'re saving <strong>' . $savings . '</strong> on your order. Add products to your cart to use it.';
        } else {
            $headline = 'Referral link activated!';
            $body     = 'You\'re shopping through a referral link. Enjoy your visit!';
        }
        ?>
        <div id="wrp-modal-overlay" role="dialog" aria-modal="true" aria-label="Referral notice">
            <div id="wrp-modal-card">
                <button id="wrp-modal-close" aria-label="Dismiss">&times;</button>
                <div id="wrp-modal-icon">🎉</div>
                <h2 id="wrp-modal-headline"><?php echo esc_html( $headline ); ?></h2>
                <p id="wrp-modal-body"><?php echo wp_kses_post( $body ); ?></p>
                <a id="wrp-modal-cta" href="<?php echo esc_url( wc_get_page_permalink( 'shop' ) ); ?>">Start Shopping</a>
            </div>
        </div>
        <style>
        #wrp-modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:99999;display:flex;align-items:center;justify-content:center;animation:wrpFadeIn .25s ease;}
        #wrp-modal-card{background:#fff;border-radius:12px;padding:36px 32px;max-width:420px;width:90%;text-align:center;box-shadow:0 20px 60px rgba(0,0,0,.25);position:relative;}
        #wrp-modal-close{position:absolute;top:12px;right:16px;background:none;border:none;font-size:22px;cursor:pointer;color:#888;line-height:1;}
        #wrp-modal-close:hover{color:#333;}
        #wrp-modal-icon{font-size:48px;line-height:1;margin-bottom:12px;}
        #wrp-modal-headline{margin:0 0 10px;font-size:22px;font-weight:700;color:#1d2327;}
        #wrp-modal-body{margin:0 0 24px;font-size:15px;color:#555;line-height:1.6;}
        #wrp-modal-cta{display:inline-block;background:#00a32a;color:#fff;padding:12px 28px;border-radius:6px;font-size:15px;font-weight:600;text-decoration:none;transition:background .15s;}
        #wrp-modal-cta:hover{background:#008a22;}
        @keyframes wrpFadeIn{from{opacity:0;}to{opacity:1;}}
        </style>
        <script>
        (function(){
            var overlay = document.getElementById('wrp-modal-overlay');
            function close(){ overlay.style.display = 'none'; }
            document.getElementById('wrp-modal-close').addEventListener('click', close);
            overlay.addEventListener('click', function(e){ if(e.target === overlay) close(); });
            document.addEventListener('keydown', function(e){ if(e.key === 'Escape') close(); });
        })();
        </script>
        <?php
    }

    /**
     * Fires when an order transitions to "processing" status.
     * Records a commission if the order contains a referral coupon.
     */
    public function on_order_processing( int $order_id, WC_Order $order ): void {
        // Idempotency: bail if commission already recorded for this order
        if ( WRP_Database::get_commission_by_order( $order_id ) ) {
            return;
        }

        $coupon_codes = $order->get_coupon_codes();
        if ( empty( $coupon_codes ) ) {
            return;
        }

        foreach ( $coupon_codes as $code ) {
            $referrer = WRP_Database::get_referrer_by_coupon( $code );
            if ( ! $referrer ) {
                continue;
            }

            $order_total       = (float) $order->get_total();
            $commission_rate   = (float) $referrer->commission_rate;
            $commission_amount = round( $order_total * ( $commission_rate / 100 ), 4 );

            $inserted = WRP_Database::record_commission(
                (int) $referrer->id,
                $order_id,
                $code,
                $order_total,
                $commission_amount,
                $commission_rate
            );

            if ( $inserted ) {
                $order->update_meta_data( '_wrp_referral_paid', 'no' );
                $order->save_meta_data();

                if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
                    error_log( sprintf(
                        '[WRP] Commission recorded: order #%d, referrer #%d, coupon %s, amount $%.2f',
                        $order_id,
                        $referrer->id,
                        $code,
                        $commission_amount
                    ) );
                }
            }

            // Only record for the first matching referral coupon on the order
            break;
        }
    }

    /**
     * Shows a confirmation notice on cart and checkout when a referral coupon is active.
     */
    public function maybe_show_referral_notice(): void {
        if ( ! WC()->cart ) {
            return;
        }

        foreach ( WC()->cart->get_applied_coupons() as $code ) {
            if ( ! WRP_Database::get_referrer_by_coupon( $code ) ) {
                continue;
            }

            $wc_coupon = new WC_Coupon( $code );
            $amount    = (float) $wc_coupon->get_amount();
            $type      = $wc_coupon->get_discount_type();

            if ( $amount > 0 ) {
                $savings = 'percent' === $type
                    ? esc_html( $amount ) . '% off your order'
                    : wp_kses_post( wc_price( $amount ) ) . ' off your order';
                $message = '&#127881; You\'re shopping with a referral link &mdash; you\'re saving <strong>' . $savings . '</strong>!';
            } else {
                $message = '&#127881; You\'re shopping with a referral link!';
            }

            echo '<div class="wrp-referral-notice">' . wp_kses_post( $message ) . '</div>';
            echo '<style>.wrp-referral-notice{background:#edfaef;border:1px solid #00a32a;border-left:4px solid #00a32a;border-radius:4px;padding:12px 16px;margin-bottom:20px;color:#1d4a27;font-size:15px;}</style>';

            break; // only show once even if multiple referral coupons somehow applied
        }
    }

    /**
     * Displays referral commission info inside the WP admin order edit screen.
     */
    public function render_referral_meta_box( WC_Order $order ): void {
        $commission = WRP_Database::get_commission_by_order( $order->get_id() );
        if ( ! $commission ) {
            return;
        }

        $referrer = WRP_Database::get_referrer_by_id( (int) $commission->referrer_id );
        $user     = $referrer ? get_userdata( (int) $referrer->user_id ) : null;
        $name     = $user ? $user->display_name : '(Unknown)';

        $status_label = 'paid' === $commission->status
            ? '<span style="color:#00a32a;font-weight:600;">Paid</span>'
              . ( $commission->paid_at ? ' <span style="color:#646970;font-size:11px;">on ' . esc_html( date_i18n( get_option( 'date_format' ), strtotime( $commission->paid_at ) ) ) . '</span>' : '' )
            : '<span style="color:#dba617;font-weight:600;">Pending</span>';
        ?>
        <div class="address" style="margin-top:16px;border-top:1px solid #e5e5e5;padding-top:12px;">
            <p><strong>Referral Commission</strong></p>
            <p>
                <span>Referrer:</span> <?php echo esc_html( $name ); ?><br>
                <span>Coupon:</span> <code><?php echo esc_html( strtoupper( $commission->coupon_code ) ); ?></code><br>
                <span>Commission:</span> <?php echo wp_kses_post( wc_price( $commission->commission_amount ) ); ?>
                    (<?php echo esc_html( $commission->commission_rate ); ?>% of <?php echo wp_kses_post( wc_price( $commission->order_total ) ); ?>)<br>
                <span>Status:</span> <?php echo wp_kses_post( $status_label ); ?>
            </p>
        </div>
        <?php
    }
}
