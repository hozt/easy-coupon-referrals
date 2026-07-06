<?php
defined( 'ABSPATH' ) || exit;

class WRP_Admin {

    public function __construct() {
        add_action( 'admin_menu',            [ $this, 'register_menu' ] );
        add_action( 'admin_init',            [ $this, 'handle_settings_save' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        add_action( 'wp_ajax_wrp_save_referrer',   [ $this, 'ajax_save_referrer' ] );
        add_action( 'wp_ajax_wrp_delete_referrer', [ $this, 'ajax_delete_referrer' ] );
        add_action( 'wp_ajax_wrp_get_referrer',    [ $this, 'ajax_get_referrer' ] );
        add_action( 'wp_ajax_wrp_search_users',    [ $this, 'ajax_search_users' ] );
        add_action( 'wp_ajax_wrp_search_coupons',  [ $this, 'ajax_search_coupons' ] );
        add_action( 'wp_ajax_wrp_mark_paid',       [ $this, 'ajax_mark_paid' ] );
        add_action( 'wp_ajax_wrp_bulk_mark_paid',  [ $this, 'ajax_bulk_mark_paid' ] );
    }

    /* ---------------------------------------------------------------
     * Settings helper
     * ------------------------------------------------------------- */

    public static function get_settings(): array {
        $saved = (array) get_option( 'wrp_settings', [] );
        return array_merge( [
            'default_commission_rate' => 10.0,
            'default_coupon_type'     => 'percent',
            'default_coupon_amount'   => 0.0,
            'min_payout_threshold'    => 0.0,
        ], $saved );
    }

    public function handle_settings_save(): void {
        if ( empty( $_POST['wrp_save_settings'] ) ) {
            return;
        }
        check_admin_referer( 'wrp_settings_save' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }

        $coupon_type = sanitize_text_field( wp_unslash( $_POST['default_coupon_type'] ?? 'percent' ) );
        if ( ! in_array( $coupon_type, [ 'percent', 'fixed_cart', 'none' ], true ) ) {
            $coupon_type = 'percent';
        }

        update_option( 'wrp_settings', [
            'default_commission_rate' => min( 100.0, max( 0.0, (float) ( $_POST['default_commission_rate'] ?? 10 ) ) ),
            'default_coupon_type'     => $coupon_type,
            'default_coupon_amount'   => max( 0.0, (float) ( $_POST['default_coupon_amount'] ?? 0 ) ),
            'min_payout_threshold'    => max( 0.0, (float) ( $_POST['min_payout_threshold'] ?? 0 ) ),
        ] );

        wp_safe_redirect( add_query_arg( [ 'page' => 'wrp-settings', 'saved' => '1' ], admin_url( 'admin.php' ) ) );
        exit;
    }

    public function register_menu(): void {
        add_submenu_page(
            'woocommerce',
            'Referral Program',
            'Referral Program',
            'manage_woocommerce',
            'wrp-referrers',
            [ $this, 'render_referrers_page' ]
        );
        add_submenu_page(
            'woocommerce',
            'Referral Commissions',
            'Referral Commissions',
            'manage_woocommerce',
            'wrp-commissions',
            [ $this, 'render_commissions_page' ]
        );
        add_submenu_page(
            'woocommerce',
            'Referral Settings',
            'Referral Settings',
            'manage_woocommerce',
            'wrp-settings',
            [ $this, 'render_settings_page' ]
        );
    }

    public function enqueue_assets( string $hook ): void {
        $is_wrp = false !== strpos( $hook, 'wrp-referrers' )
               || false !== strpos( $hook, 'wrp-commissions' )
               || false !== strpos( $hook, 'wrp-settings' );
        if ( ! $is_wrp ) {
            return;
        }
        wp_enqueue_style(
            'wrp-admin',
            WRP_PLUGIN_URL . 'assets/admin.css',
            [],
            WRP_VERSION
        );
        $settings = self::get_settings();
        if ( false === strpos( $hook, 'wrp-settings' ) ) {
            // JS only needed on referrers/commissions pages
            wp_enqueue_script(
                'wrp-admin',
                WRP_PLUGIN_URL . 'assets/admin.js',
                [ 'jquery', 'jquery-ui-dialog' ],
                WRP_VERSION,
                true
            );
            wp_enqueue_style( 'wp-jquery-ui-dialog' );
            wp_localize_script( 'wrp-admin', 'WRP', [
                'ajax_url'                => admin_url( 'admin-ajax.php' ),
                'nonce'                   => wp_create_nonce( 'wrp_nonce' ),
                'currency_symbol'         => get_woocommerce_currency_symbol(),
                'default_commission_rate' => (string) $settings['default_commission_rate'],
                'default_coupon_type'     => $settings['default_coupon_type'],
                'default_coupon_amount'   => (string) $settings['default_coupon_amount'],
            ] );
        }
    }

    /* ---------------------------------------------------------------
     * Referrers page
     * ------------------------------------------------------------- */

    public function render_referrers_page(): void {
        $referrers = WRP_Database::get_all_referrers();
        ?>
        <div class="wrap wrp-wrap">
            <h1 class="wp-heading-inline">Referral Program</h1>
            <button type="button" class="page-title-action wrp-btn-add">+ Add Referrer</button>
            <hr class="wp-header-end">

            <p class="wrp-subtitle">Assign WooCommerce coupon codes to users as referral codes and track commissions earned.</p>

            <?php if ( empty( $referrers ) ) : ?>
                <div class="wrp-empty-state">
                    <span class="dashicons dashicons-groups"></span>
                    <p>No referrers yet. Click <strong>+ Add Referrer</strong> to get started.</p>
                </div>
            <?php else : ?>
            <table class="widefat wrp-table" id="wrp-referrers-table">
                <thead>
                    <tr>
                        <th>User</th>
                        <th>Email</th>
                        <th>Commission Rate</th>
                        <th>Referral Codes</th>
                        <th>Notes</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $referrers as $referrer ) : ?>
                        <?php $this->render_referrer_row( $referrer ); ?>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>

            <div id="wrp-empty-placeholder" <?php echo ! empty( $referrers ) ? 'style="display:none"' : ''; ?>>
                <div class="wrp-empty-state">
                    <span class="dashicons dashicons-groups"></span>
                    <p>No referrers yet. Click <strong>+ Add Referrer</strong> to get started.</p>
                </div>
            </div>
        </div>

        <!-- Add/Edit Referrer Modal -->
        <div id="wrp-modal" title="Referrer" style="display:none;">
            <form id="wrp-referrer-form">
                <input type="hidden" id="wrp-referrer-id" value="">

                <table class="form-table wrp-form-table">
                    <tr>
                        <th><label for="wrp-user-search">User <span class="required">*</span></label></th>
                        <td>
                            <input type="text" id="wrp-user-search" class="regular-text" placeholder="Type name or email…" autocomplete="off">
                            <input type="hidden" id="wrp-user-id">
                            <div id="wrp-user-suggestions" class="wrp-suggestions"></div>
                            <p class="description">The WordPress user who will be the referrer.</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="wrp-commission-rate">Commission Rate <span class="required">*</span></label></th>
                        <td>
                            <input type="number" id="wrp-commission-rate" class="small-text" min="0" max="100" step="0.01" placeholder="10">
                            <span>%</span>
                            <p class="description">Percentage of the order total paid to this referrer.</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label>Coupon Codes</label></th>
                        <td>
                            <div id="wrp-coupon-tags" class="wrp-tag-input-wrap"></div>
                            <div style="display:flex;gap:6px;margin-top:6px;">
                                <input type="text" id="wrp-coupon-search" class="regular-text" placeholder="Type code and press Enter, or search existing…" autocomplete="off">
                                <button type="button" class="button wrp-btn-add-coupon">Add</button>
                            </div>
                            <div id="wrp-coupon-suggestions" class="wrp-suggestions"></div>
                            <input type="hidden" id="wrp-coupon-codes-json" value="[]">
                            <p class="description">Type a new or existing coupon code and press <strong>Enter</strong> (or click Add). New codes will be created automatically in WooCommerce.</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label>New Coupon Defaults</label></th>
                        <td>
                            <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                                <select id="wrp-coupon-discount-type">
                                    <option value="percent">% Percent off</option>
                                    <option value="fixed_cart">$ Fixed cart discount</option>
                                    <option value="none">No discount (tracking only)</option>
                                </select>
                                <span id="wrp-coupon-amount-wrap" style="display:flex;align-items:center;gap:4px;">
                                    <span class="wrp-currency-symbol"><?php echo esc_html( get_woocommerce_currency_symbol() ); ?></span>
                                    <input type="number" id="wrp-coupon-amount" class="small-text" min="0" step="0.01" placeholder="0" value="0">
                                </span>
                            </div>
                            <p class="description">Applied to <em>newly created</em> coupons only. Existing WooCommerce coupons are not modified.</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="wrp-notes">Notes</label></th>
                        <td>
                            <textarea id="wrp-notes" rows="2" class="large-text" maxlength="500" placeholder="Optional internal notes…"></textarea>
                        </td>
                    </tr>
                </table>
                <p class="wrp-form-error" id="wrp-form-error"></p>
            </form>
        </div>
        <?php
    }

    public function render_referrer_row( object $referrer, bool $echo = true ): string {
        $coupons = WRP_Database::get_coupons_for_referrer( (int) $referrer->id );

        ob_start();
        ?>
        <tr class="wrp-referrer-row" data-id="<?php echo esc_attr( $referrer->id ); ?>">
            <td><strong><?php echo esc_html( $referrer->display_name ?? '(No name)' ); ?></strong></td>
            <td><?php echo esc_html( $referrer->user_email ?? '' ); ?></td>
            <td><?php echo esc_html( $referrer->commission_rate ); ?>%</td>
            <td class="wrp-codes-cell">
                <?php if ( empty( $coupons ) ) : ?>
                    <span class="wrp-muted">None</span>
                <?php else : ?>
                    <?php foreach ( $coupons as $c ) :
                        $share_url = add_query_arg( 'refer', rawurlencode( $c->coupon_code ), home_url( '/' ) );
                    ?>
                        <span class="wrp-code-wrap">
                            <code class="wrp-code"><?php echo esc_html( strtoupper( $c->coupon_code ) ); ?></code>
                            <button type="button" class="button button-small wrp-btn-copy-link"
                                    data-url="<?php echo esc_attr( $share_url ); ?>"
                                    title="Copy shareable referral link">&#128279;</button>
                        </span>
                    <?php endforeach; ?>
                <?php endif; ?>
            </td>
            <td class="wrp-muted"><?php echo esc_html( $referrer->notes ?? '' ); ?></td>
            <td class="wrp-actions">
                <button class="button button-small wrp-btn-edit" data-id="<?php echo esc_attr( $referrer->id ); ?>">Edit</button>
                <button class="button button-small wrp-btn-delete" data-id="<?php echo esc_attr( $referrer->id ); ?>">Delete</button>
            </td>
        </tr>
        <?php
        $html = ob_get_clean();
        if ( $echo ) echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        return $html;
    }

    /* ---------------------------------------------------------------
     * Commissions page
     * ------------------------------------------------------------- */

    public function render_commissions_page(): void {
        $status      = isset( $_GET['wrp_status'] ) ? sanitize_text_field( wp_unslash( $_GET['wrp_status'] ) ) : '';
        $referrer_id = isset( $_GET['wrp_referrer'] ) ? absint( $_GET['wrp_referrer'] ) : 0;
        $page_num    = isset( $_GET['wrp_page'] ) ? max( 1, absint( $_GET['wrp_page'] ) ) : 1;
        $per_page    = 30;

        $args  = array_filter( [
            'status'      => in_array( $status, [ 'pending', 'paid' ], true ) ? $status : '',
            'referrer_id' => $referrer_id,
            'per_page'    => $per_page,
            'page'        => $page_num,
        ] );
        $commissions = WRP_Database::get_all_commissions( $args );
        $total       = WRP_Database::count_all_commissions( $args );
        $referrers   = WRP_Database::get_all_referrers();
        $total_pages = (int) ceil( $total / $per_page );
        ?>
        <div class="wrap wrp-wrap">
            <h1 class="wp-heading-inline">Referral Commissions</h1>
            <hr class="wp-header-end">

            <!-- Filters -->
            <form method="get" class="wrp-filters">
                <input type="hidden" name="page" value="wrp-commissions">
                <select name="wrp_status">
                    <option value="">All Statuses</option>
                    <option value="pending" <?php selected( $status, 'pending' ); ?>>Pending</option>
                    <option value="paid"    <?php selected( $status, 'paid' ); ?>>Paid</option>
                </select>
                <select name="wrp_referrer">
                    <option value="">All Referrers</option>
                    <?php foreach ( $referrers as $ref ) : ?>
                        <option value="<?php echo esc_attr( $ref->id ); ?>" <?php selected( $referrer_id, $ref->id ); ?>>
                            <?php echo esc_html( $ref->display_name ?: $ref->user_email ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php submit_button( 'Filter', 'secondary', '', false ); ?>
            </form>

            <!-- Minimum threshold notice -->
            <?php
            $threshold = (float) self::get_settings()['min_payout_threshold'];
            if ( $threshold > 0 ) :
                $eligible = [];
                foreach ( WRP_Database::get_all_referrers() as $ref ) {
                    $summary = WRP_Database::get_referrer_summary( (int) $ref->id );
                    if ( $summary->pending >= $threshold ) {
                        $eligible[] = esc_html( $ref->display_name ?: $ref->user_email )
                                    . ' (' . wp_kses_post( wc_price( $summary->pending ) ) . ' pending)';
                    }
                }
            ?>
            <div class="notice notice-info inline" style="margin:12px 0;">
                <p>
                    <strong>Minimum payout threshold: <?php echo wp_kses_post( wc_price( $threshold ) ); ?></strong>
                    <?php if ( $eligible ) : ?>
                        &mdash; Eligible to pay: <?php echo implode( ', ', $eligible ); ?>
                    <?php else : ?>
                        &mdash; No referrers have reached the threshold yet.
                    <?php endif; ?>
                </p>
            </div>
            <?php endif; ?>

            <!-- Bulk action -->
            <div class="wrp-bulk-bar">
                <button type="button" class="button wrp-btn-bulk-paid">Mark Selected as Paid</button>
                <span class="wrp-bulk-notice"></span>
            </div>

            <?php if ( empty( $commissions ) ) : ?>
                <div class="wrp-empty-state">
                    <span class="dashicons dashicons-chart-bar"></span>
                    <p>No commissions found.</p>
                </div>
            <?php else : ?>
            <table class="widefat wrp-table" id="wrp-commissions-table">
                <thead>
                    <tr>
                        <th><input type="checkbox" id="wrp-check-all" title="Select all"></th>
                        <th>Date</th>
                        <th>Order</th>
                        <th>Referrer</th>
                        <th>Coupon</th>
                        <th>Order Total</th>
                        <th>Commission</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $commissions as $commission ) : ?>
                        <?php $this->render_commission_row( $commission ); ?>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="5"><strong>Page totals:</strong></td>
                        <td><strong><?php echo wp_kses_post( wc_price( array_sum( array_column( $commissions, 'order_total' ) ) ) ); ?></strong></td>
                        <td><strong><?php echo wp_kses_post( wc_price( array_sum( array_column( $commissions, 'commission_amount' ) ) ) ); ?></strong></td>
                        <td colspan="2"></td>
                    </tr>
                </tfoot>
            </table>

            <!-- Pagination -->
            <?php if ( $total_pages > 1 ) : ?>
            <div class="wrp-pagination">
                <?php
                $base_url = add_query_arg( [
                    'page'         => 'wrp-commissions',
                    'wrp_status'   => $status,
                    'wrp_referrer' => $referrer_id,
                ], admin_url( 'admin.php' ) );

                echo paginate_links( [
                    'base'      => $base_url . '&wrp_page=%#%',
                    'format'    => '',
                    'current'   => $page_num,
                    'total'     => $total_pages,
                    'prev_text' => '&laquo;',
                    'next_text' => '&raquo;',
                ] );
                ?>
                <span class="wrp-pagination-info"><?php echo esc_html( $total ); ?> total commission<?php echo 1 !== $total ? 's' : ''; ?></span>
            </div>
            <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php
    }

    /* ---------------------------------------------------------------
     * Settings page
     * ------------------------------------------------------------- */

    public function render_settings_page(): void {
        $settings = self::get_settings();
        $saved    = isset( $_GET['saved'] ) && '1' === $_GET['saved'];
        $currency = get_woocommerce_currency_symbol();
        ?>
        <div class="wrap wrp-wrap">
            <h1>Referral Program Settings</h1>
            <p class="wrp-subtitle">These values are used as defaults when adding new referrers and auto-creating coupon codes.</p>

            <?php if ( $saved ) : ?>
                <div class="notice notice-success is-dismissible"><p>Settings saved.</p></div>
            <?php endif; ?>

            <form method="post">
                <?php wp_nonce_field( 'wrp_settings_save' ); ?>
                <input type="hidden" name="wrp_save_settings" value="1">

                <table class="form-table wrp-form-table" style="max-width:600px;">
                    <tr>
                        <th><label for="default_commission_rate">Default Commission Rate</label></th>
                        <td>
                            <input type="number" name="default_commission_rate" id="default_commission_rate"
                                   class="small-text" min="0" max="100" step="0.01"
                                   value="<?php echo esc_attr( $settings['default_commission_rate'] ); ?>"> %
                            <p class="description">Pre-filled when adding a new referrer. Each referrer can still be set individually.</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="default_coupon_type">Default Coupon Discount Type</label></th>
                        <td>
                            <select name="default_coupon_type" id="default_coupon_type">
                                <option value="percent"    <?php selected( $settings['default_coupon_type'], 'percent' ); ?>>% Percent off</option>
                                <option value="fixed_cart" <?php selected( $settings['default_coupon_type'], 'fixed_cart' ); ?>><?php echo esc_html( $currency ); ?> Fixed cart discount</option>
                                <option value="none"       <?php selected( $settings['default_coupon_type'], 'none' ); ?>>No discount (tracking only)</option>
                            </select>
                            <p class="description">Discount type applied to <em>newly auto-created</em> WooCommerce coupons.</p>
                        </td>
                    </tr>
                    <tr id="wrp-settings-amount-row">
                        <th><label for="default_coupon_amount">Default Coupon Amount</label></th>
                        <td>
                            <span id="wrp-settings-currency-label"><?php echo esc_html( $settings['default_coupon_type'] === 'percent' ? '%' : $currency ); ?></span>
                            <input type="number" name="default_coupon_amount" id="default_coupon_amount"
                                   class="small-text" min="0" step="0.01"
                                   value="<?php echo esc_attr( $settings['default_coupon_amount'] ); ?>">
                            <p class="description">Discount amount for auto-created coupons. Customers using the referral link receive this discount.</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="min_payout_threshold">Minimum Payout Threshold</label></th>
                        <td>
                            <?php echo esc_html( $currency ); ?>
                            <input type="number" name="min_payout_threshold" id="min_payout_threshold"
                                   class="small-text" min="0" step="0.01"
                                   value="<?php echo esc_attr( $settings['min_payout_threshold'] ); ?>">
                            <p class="description">Referrers must accumulate at least this much in pending commissions before any can be marked paid. Set to <strong>0</strong> to disable.</p>
                        </td>
                    </tr>
                </table>

                <?php submit_button( 'Save Settings' ); ?>
            </form>
        </div>

        <script>
        jQuery( function( $ ) {
            var currency = '<?php echo esc_js( $currency ); ?>';
            function updateSettingsAmountRow() {
                var type = $( '#default_coupon_type' ).val();
                $( '#wrp-settings-amount-row' ).toggle( type !== 'none' );
                $( '#wrp-settings-currency-label' ).text( type === 'percent' ? '%' : currency );
            }
            $( '#default_coupon_type' ).on( 'change', updateSettingsAmountRow );
            updateSettingsAmountRow();
        } );
        </script>
        <?php
    }

    public function render_commission_row( object $commission, bool $echo = true ): string {
        $order_edit_url = get_edit_post_link( $commission->order_id );
        if ( ! $order_edit_url ) {
            // HPOS-compatible URL
            $order_edit_url = admin_url( 'admin.php?page=wc-orders&action=edit&id=' . $commission->order_id );
        }

        $is_paid     = 'paid' === $commission->status;
        $status_html = $is_paid
            ? '<span class="wrp-badge wrp-badge-paid">Paid</span>'
            : '<span class="wrp-badge wrp-badge-pending">Pending</span>';

        $paid_note = '';
        if ( $is_paid && $commission->paid_at ) {
            $paid_note = '<br><span class="wrp-muted" style="font-size:11px;">' . esc_html( date_i18n( get_option( 'date_format' ), strtotime( $commission->paid_at ) ) ) . '</span>';
        }

        ob_start();
        ?>
        <tr class="wrp-commission-row" data-id="<?php echo esc_attr( $commission->id ); ?>">
            <td>
                <?php if ( ! $is_paid ) : ?>
                    <input type="checkbox" class="wrp-commission-check" value="<?php echo esc_attr( $commission->id ); ?>">
                <?php endif; ?>
            </td>
            <td><?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $commission->created_at ) ) ); ?></td>
            <td><a href="<?php echo esc_url( $order_edit_url ); ?>">#<?php echo esc_html( $commission->order_id ); ?></a></td>
            <td><?php echo esc_html( $commission->display_name ?? '(Unknown)' ); ?></td>
            <td><code class="wrp-code"><?php echo esc_html( strtoupper( $commission->coupon_code ) ); ?></code></td>
            <td><?php echo wp_kses_post( wc_price( $commission->order_total ) ); ?></td>
            <td>
                <?php echo wp_kses_post( wc_price( $commission->commission_amount ) ); ?>
                <span class="wrp-muted" style="font-size:11px;">(<?php echo esc_html( $commission->commission_rate ); ?>%)</span>
            </td>
            <td><?php echo wp_kses_post( $status_html . $paid_note ); ?></td>
            <td class="wrp-actions">
                <?php if ( ! $is_paid ) : ?>
                    <button class="button button-small wrp-btn-mark-paid"
                            data-id="<?php echo esc_attr( $commission->id ); ?>">Mark Paid</button>
                <?php else : ?>
                    <span class="wrp-muted">—</span>
                <?php endif; ?>
            </td>
        </tr>
        <?php
        $html = ob_get_clean();
        if ( $echo ) echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        return $html;
    }

    /* ---------------------------------------------------------------
     * AJAX handlers
     * ------------------------------------------------------------- */

    private function verify(): void {
        check_ajax_referer( 'wrp_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( 'Insufficient permissions.' );
        }
    }

    public function ajax_save_referrer(): void {
        $this->verify();

        $referrer_id          = isset( $_POST['referrer_id'] ) && '' !== $_POST['referrer_id'] ? absint( $_POST['referrer_id'] ) : null;
        $user_id              = absint( $_POST['user_id'] ?? 0 );
        $commission_rate      = min( 100.0, max( 0.0, (float) ( $_POST['commission_rate'] ?? 0 ) ) );
        $notes                = substr( sanitize_textarea_field( wp_unslash( $_POST['notes'] ?? '' ) ), 0, 500 );
        $coupon_codes         = json_decode( wp_unslash( $_POST['coupon_codes_json'] ?? '[]' ), true );
        $coupon_codes         = is_array( $coupon_codes ) ? array_map( 'sanitize_text_field', $coupon_codes ) : [];
        $coupon_discount_type = sanitize_text_field( wp_unslash( $_POST['coupon_discount_type'] ?? 'percent' ) );
        $coupon_amount        = max( 0.0, (float) ( $_POST['coupon_amount'] ?? 0 ) );

        if ( ! in_array( $coupon_discount_type, [ 'percent', 'fixed_cart', 'none' ], true ) ) {
            $coupon_discount_type = 'percent';
        }

        // Validate user
        if ( ! $user_id || ! get_userdata( $user_id ) ) {
            wp_send_json_error( 'Please select a valid WordPress user.' );
        }

        // Ensure each coupon exists in WooCommerce — create it if not
        foreach ( $coupon_codes as $code ) {
            $code   = strtolower( trim( $code ) );
            $coupon = new WC_Coupon( $code );
            if ( ! $coupon->get_id() ) {
                $new_coupon = new WC_Coupon();
                $new_coupon->set_code( $code );
                if ( 'none' === $coupon_discount_type ) {
                    $new_coupon->set_discount_type( 'percent' );
                    $new_coupon->set_amount( 0 );
                } else {
                    $new_coupon->set_discount_type( $coupon_discount_type );
                    $new_coupon->set_amount( $coupon_amount );
                }
                $new_coupon->set_individual_use( false );
                $new_coupon->save();
                if ( ! $new_coupon->get_id() ) {
                    wp_send_json_error( 'Failed to create coupon "' . esc_html( strtoupper( $code ) ) . '".' );
                }
            }
        }

        // Check coupon conflicts (coupon assigned to a different referrer)
        foreach ( $coupon_codes as $code ) {
            $existing_referrer = WRP_Database::get_referrer_by_coupon( $code );
            if ( $existing_referrer && (int) $existing_referrer->id !== $referrer_id ) {
                wp_send_json_error( 'Coupon "' . esc_html( strtoupper( $code ) ) . '" is already assigned to another referrer.' );
            }
        }

        if ( $referrer_id ) {
            // Update existing
            $referrer = WRP_Database::get_referrer_by_id( $referrer_id );
            if ( ! $referrer ) {
                wp_send_json_error( 'Referrer not found.' );
            }

            // If user_id changed, check it's not already taken by another referrer
            if ( (int) $referrer->user_id !== $user_id ) {
                $existing = WRP_Database::get_referrer_by_user( $user_id );
                if ( $existing && (int) $existing->id !== $referrer_id ) {
                    wp_send_json_error( 'This user is already registered as a referrer.' );
                }
            }

            WRP_Database::update_referrer( $referrer_id, $commission_rate, $notes );
            WRP_Database::set_referrer_coupons( $referrer_id, $coupon_codes );
            $is_new = false;
        } else {
            // Insert new — check user not already a referrer
            if ( WRP_Database::get_referrer_by_user( $user_id ) ) {
                wp_send_json_error( 'This user is already registered as a referrer.' );
            }
            $referrer_id = WRP_Database::insert_referrer( $user_id, $commission_rate, $notes );
            if ( ! $referrer_id ) {
                wp_send_json_error( 'Failed to save referrer. Please try again.' );
            }
            WRP_Database::set_referrer_coupons( $referrer_id, $coupon_codes );
            $is_new = true;
        }

        // Re-fetch for display_name / email JOIN
        $all_referrers = WRP_Database::get_all_referrers();
        $saved = null;
        foreach ( $all_referrers as $ref ) {
            if ( (int) $ref->id === (int) $referrer_id ) {
                $saved = $ref;
                break;
            }
        }

        if ( ! $saved ) {
            wp_send_json_error( 'Saved but could not retrieve updated row.' );
        }

        $html = $this->render_referrer_row( $saved, false );
        wp_send_json_success( [ 'html' => $html, 'is_new' => $is_new ] );
    }

    public function ajax_delete_referrer(): void {
        $this->verify();
        $id = absint( $_POST['referrer_id'] ?? 0 );
        if ( ! $id ) {
            wp_send_json_error( 'Invalid referrer.' );
        }
        WRP_Database::delete_referrer( $id );
        wp_send_json_success();
    }

    public function ajax_get_referrer(): void {
        $this->verify();
        $id = absint( $_POST['referrer_id'] ?? 0 );

        $all = WRP_Database::get_all_referrers();
        $referrer = null;
        foreach ( $all as $ref ) {
            if ( (int) $ref->id === $id ) {
                $referrer = $ref;
                break;
            }
        }

        if ( ! $referrer ) {
            wp_send_json_error( 'Referrer not found.' );
        }

        $coupons       = WRP_Database::get_coupons_for_referrer( $id );
        $coupon_codes  = array_column( $coupons, 'coupon_code' );
        $user          = get_userdata( (int) $referrer->user_id );
        $user_label    = $user ? $user->display_name . ' (' . $user->user_email . ')' : '';

        wp_send_json_success( [
            'id'              => $referrer->id,
            'user_id'         => $referrer->user_id,
            'user_label'      => $user_label,
            'commission_rate' => $referrer->commission_rate,
            'notes'           => $referrer->notes,
            'coupon_codes'    => $coupon_codes,
        ] );
    }

    public function ajax_search_users(): void {
        $this->verify();
        $search = sanitize_text_field( wp_unslash( $_POST['search'] ?? '' ) );

        $users = get_users( [
            'search'         => '*' . $search . '*',
            'search_columns' => [ 'user_login', 'user_email', 'display_name' ],
            'number'         => 10,
        ] );

        $results = [];
        foreach ( $users as $user ) {
            $results[] = [
                'id'    => $user->ID,
                'label' => $user->display_name . ' (' . $user->user_email . ')',
            ];
        }
        wp_send_json_success( $results );
    }

    public function ajax_search_coupons(): void {
        $this->verify();
        $search = sanitize_text_field( wp_unslash( $_POST['search'] ?? '' ) );

        $posts = get_posts( [
            'post_type'      => 'shop_coupon',
            'posts_per_page' => 10,
            's'              => $search,
            'post_status'    => 'publish',
        ] );

        $results = [];
        foreach ( $posts as $post ) {
            $results[] = [
                'code'  => $post->post_title,
                'label' => strtoupper( $post->post_title ),
            ];
        }
        wp_send_json_success( $results );
    }

    public function ajax_mark_paid(): void {
        $this->verify();
        $id = absint( $_POST['commission_id'] ?? 0 );
        if ( ! $id ) {
            wp_send_json_error( 'Invalid commission.' );
        }

        // Threshold check
        $commission = WRP_Database::get_commission_by_order( 0 ); // placeholder — fetch by ID below
        global $wpdb;
        $commission = $wpdb->get_row( $wpdb->prepare(
            'SELECT * FROM ' . $wpdb->prefix . WRP_Database::TABLE_COMMISSIONS . ' WHERE id = %d',
            $id
        ) );
        if ( $commission ) {
            $error = $this->check_threshold( (int) $commission->referrer_id );
            if ( $error ) {
                wp_send_json_error( wp_strip_all_tags( $error ) );
            }
        }

        $affected = WRP_Database::mark_commissions_paid( [ $id ] );
        if ( ! $affected ) {
            wp_send_json_error( 'Commission not found or already paid.' );
        }

        $this->sync_order_meta_for_commission_ids( [ $id ] );

        // Re-fetch and re-render the row
        $commissions = WRP_Database::get_all_commissions( [ 'per_page' => 1000 ] );
        $commission  = null;
        foreach ( $commissions as $c ) {
            if ( (int) $c->id === $id ) {
                $commission = $c;
                break;
            }
        }

        if ( ! $commission ) {
            wp_send_json_success( [ 'html' => '' ] );
        }

        $html = $this->render_commission_row( $commission, false );
        wp_send_json_success( [ 'html' => $html ] );
    }

    public function ajax_bulk_mark_paid(): void {
        $this->verify();
        $ids = array_filter( array_map( 'absint', (array) ( $_POST['commission_ids'] ?? [] ) ) );

        if ( empty( $ids ) ) {
            wp_send_json_error( 'No commissions selected.' );
        }

        // Threshold check — group selected IDs by referrer and validate each
        global $wpdb;
        $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        $rows         = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, referrer_id FROM {$wpdb->prefix}" . WRP_Database::TABLE_COMMISSIONS . " WHERE id IN ({$placeholders})",
                $ids
            )
        );
        $by_referrer  = [];
        foreach ( $rows as $row ) {
            $by_referrer[ (int) $row->referrer_id ][] = (int) $row->id;
        }
        $blocked = [];
        foreach ( array_keys( $by_referrer ) as $referrer_id ) {
            $error = $this->check_threshold( $referrer_id );
            if ( $error ) {
                $user     = get_userdata( (int) WRP_Database::get_referrer_by_id( $referrer_id )->user_id );
                $blocked[] = ( $user ? $user->display_name : 'Referrer #' . $referrer_id ) . ': ' . wp_strip_all_tags( $error );
            }
        }
        if ( $blocked ) {
            wp_send_json_error( implode( ' | ', $blocked ) );
        }

        $affected = WRP_Database::mark_commissions_paid( $ids );
        $this->sync_order_meta_for_commission_ids( $ids );

        wp_send_json_success( [ 'count' => $affected ] );
    }

    /**
     * Returns an error string if the referrer hasn't met the minimum payout threshold, otherwise null.
     */
    private function check_threshold( int $referrer_id ): ?string {
        $threshold = (float) self::get_settings()['min_payout_threshold'];
        if ( $threshold <= 0 ) {
            return null;
        }
        $summary = WRP_Database::get_referrer_summary( $referrer_id );
        if ( $summary->pending < $threshold ) {
            return sprintf(
                'This referrer has %s pending, but the minimum payout threshold is %s.',
                wc_price( $summary->pending ),
                wc_price( $threshold )
            );
        }
        return null;
    }

    /**
     * Sets _wrp_referral_paid = 'yes' on orders for the given commission IDs.
     */
    private function sync_order_meta_for_commission_ids( array $ids ): void {
        global $wpdb;
        if ( empty( $ids ) ) return;

        $table        = $wpdb->prefix . WRP_Database::TABLE_COMMISSIONS;
        $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        $order_ids    = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT order_id FROM {$table} WHERE id IN ({$placeholders})",
                array_map( 'intval', $ids )
            )
        );

        foreach ( $order_ids as $order_id ) {
            $order = wc_get_order( (int) $order_id );
            if ( $order ) {
                $order->update_meta_data( '_wrp_referral_paid', 'yes' );
                $order->save_meta_data();
            }
        }
    }
}
