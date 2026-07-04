<?php
defined( 'ABSPATH' ) || exit;

class WRP_Dashboard {

    const ENDPOINT = 'referral-dashboard';

    public function __construct() {
        // My Account endpoint
        add_action( 'init',                                   [ $this, 'add_endpoint' ] );
        add_filter( 'woocommerce_account_menu_items',         [ $this, 'add_menu_item' ] );
        add_action( 'woocommerce_account_' . self::ENDPOINT . '_endpoint', [ $this, 'render_endpoint' ] );

        // Shortcode (kept for backwards-compat / standalone page use)
        add_shortcode( 'referral_dashboard', [ $this, 'render_shortcode' ] );

        add_action( 'wp_enqueue_scripts', [ $this, 'maybe_enqueue_assets' ] );
    }

    /* ---------------------------------------------------------------
     * My Account endpoint registration
     * ------------------------------------------------------------- */

    public function add_endpoint(): void {
        add_rewrite_endpoint( self::ENDPOINT, EP_ROOT | EP_PAGES );
    }

    /**
     * Only show the menu item to users who are registered referrers.
     */
    public function add_menu_item( array $items ): array {
        if ( ! is_user_logged_in() ) {
            return $items;
        }
        if ( ! WRP_Database::get_referrer_by_user( get_current_user_id() ) ) {
            return $items;
        }

        // Insert before "logout" so it appears near the bottom but not last
        $logout = array_splice( $items, array_search( 'customer-logout', array_keys( $items ), true ) );
        $items[ self::ENDPOINT ] = 'Referral Dashboard';
        return array_merge( $items, $logout );
    }

    public function render_endpoint(): void {
        $this->enqueue_assets();
        echo wp_kses_post( $this->build_dashboard_html() );
    }

    /* ---------------------------------------------------------------
     * Shortcode (standalone page)
     * ------------------------------------------------------------- */

    public function render_shortcode( array $atts ): string {
        if ( ! is_user_logged_in() ) {
            ob_start();
            echo '<div class="wrp-dashboard wrp-login-gate">';
            echo '<p>Please log in to view your referral dashboard.</p>';
            echo wp_login_form( [ 'echo' => false, 'redirect' => get_permalink() ] );
            echo '</div>';
            return ob_get_clean();
        }

        if ( ! WRP_Database::get_referrer_by_user( get_current_user_id() ) ) {
            return '<div class="wrp-dashboard"><p class="wrp-not-registered">You are not currently registered in the referral program. Please contact us to get started.</p></div>';
        }

        return $this->build_dashboard_html();
    }

    /* ---------------------------------------------------------------
     * Shared dashboard HTML
     * ------------------------------------------------------------- */

    private function build_dashboard_html(): string {
        $user_id  = get_current_user_id();
        $referrer = WRP_Database::get_referrer_by_user( $user_id );

        if ( ! $referrer ) {
            return '<p class="wrp-not-registered">You are not currently registered in the referral program.</p>';
        }

        $summary     = WRP_Database::get_referrer_summary( (int) $referrer->id );
        $coupons     = WRP_Database::get_coupons_for_referrer( (int) $referrer->id );
        $commissions = WRP_Database::get_commissions_for_referrer( (int) $referrer->id );
        $user        = wp_get_current_user();

        ob_start();
        ?>
        <div class="wrp-dashboard">
            <p class="wrp-dashboard-welcome">Your commission rate is <strong><?php echo esc_html( $referrer->commission_rate ); ?>%</strong> of each referred order total.</p>

            <!-- Summary Cards -->
            <div class="wrp-summary-cards">
                <div class="wrp-card">
                    <div class="wrp-card-label">Total Earned</div>
                    <div class="wrp-card-value"><?php echo wp_kses_post( wc_price( $summary->total ) ); ?></div>
                </div>
                <div class="wrp-card wrp-card-pending">
                    <div class="wrp-card-label">Pending Payout</div>
                    <div class="wrp-card-value"><?php echo wp_kses_post( wc_price( $summary->pending ) ); ?></div>
                </div>
                <div class="wrp-card wrp-card-paid">
                    <div class="wrp-card-label">Paid Out</div>
                    <div class="wrp-card-value"><?php echo wp_kses_post( wc_price( $summary->paid ) ); ?></div>
                </div>
            </div>

            <!-- Referral Links -->
            <h3 class="wrp-section-title">Your Referral Links</h3>
            <?php if ( empty( $coupons ) ) : ?>
                <p class="wrp-muted">No referral codes assigned yet. Contact us to get your referral link.</p>
            <?php else : ?>
            <table class="wrp-table">
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>Shareable Link</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $coupons as $coupon ) :
                        $link = home_url( '/?refer=' . rawurlencode( $coupon->coupon_code ) );
                    ?>
                    <tr>
                        <td><code class="wrp-code"><?php echo esc_html( strtoupper( $coupon->coupon_code ) ); ?></code></td>
                        <td><span class="wrp-link-text"><?php echo esc_html( $link ); ?></span></td>
                        <td>
                            <button type="button" class="wrp-copy-btn" data-url="<?php echo esc_attr( $link ); ?>">Copy Link</button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>

            <!-- Commission History -->
            <h3 class="wrp-section-title">Commission History</h3>
            <?php if ( empty( $commissions ) ) : ?>
                <p class="wrp-muted">No commissions yet. Share your referral link to start earning!</p>
            <?php else : ?>
            <table class="wrp-table wrp-history-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Order</th>
                        <th>Order Total</th>
                        <th>Your Commission</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $commissions as $c ) :
                        $is_paid = 'paid' === $c->status;
                    ?>
                    <tr class="<?php echo $is_paid ? 'wrp-row-paid' : 'wrp-row-pending'; ?>">
                        <td><?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $c->created_at ) ) ); ?></td>
                        <td>#<?php echo esc_html( $c->order_id ); ?></td>
                        <td><?php echo wp_kses_post( wc_price( $c->order_total ) ); ?></td>
                        <td><?php echo wp_kses_post( wc_price( $c->commission_amount ) ); ?></td>
                        <td>
                            <?php if ( $is_paid ) : ?>
                                <span class="wrp-status-paid">Paid<?php echo $c->paid_at ? ' ' . esc_html( date_i18n( get_option( 'date_format' ), strtotime( $c->paid_at ) ) ) : ''; ?></span>
                            <?php else : ?>
                                <span class="wrp-status-pending">Pending</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /* ---------------------------------------------------------------
     * Assets
     * ------------------------------------------------------------- */

    public function enqueue_assets(): void {
        wp_enqueue_style(
            'wrp-dashboard',
            WRP_PLUGIN_URL . 'assets/dashboard.css',
            [],
            WRP_VERSION
        );
        wp_enqueue_script(
            'wrp-dashboard',
            WRP_PLUGIN_URL . 'assets/dashboard.js',
            [ 'jquery' ],
            WRP_VERSION,
            true
        );
    }

    public function maybe_enqueue_assets(): void {
        // My Account endpoint
        if ( is_wc_endpoint_url( self::ENDPOINT ) ) {
            $this->enqueue_assets();
            return;
        }

        // Standalone shortcode page
        if ( is_singular() ) {
            $post = get_post();
            if ( $post && has_shortcode( $post->post_content, 'referral_dashboard' ) ) {
                $this->enqueue_assets();
            }
        }
    }

    /* ---------------------------------------------------------------
     * Flush rewrite rules on activation (called from WRP_Plugin::activate)
     * ------------------------------------------------------------- */

    public static function flush_rewrite_rules(): void {
        add_rewrite_endpoint( self::ENDPOINT, EP_ROOT | EP_PAGES );
        flush_rewrite_rules();
    }
}
