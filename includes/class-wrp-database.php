<?php
defined( 'ABSPATH' ) || exit;

class WRP_Database {

    const TABLE_REFERRERS  = 'wrp_referrers';
    const TABLE_COUPONS    = 'wrp_coupons';
    const TABLE_COMMISSIONS = 'wrp_commissions';

    /* ---------------------------------------------------------------
     * Schema
     * ------------------------------------------------------------- */

    public static function create_tables(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $collate = $wpdb->get_charset_collate();
        $r  = $wpdb->prefix . self::TABLE_REFERRERS;
        $c  = $wpdb->prefix . self::TABLE_COUPONS;
        $co = $wpdb->prefix . self::TABLE_COMMISSIONS;

        $sql = "CREATE TABLE {$r} (
            id             bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id        bigint(20) unsigned NOT NULL,
            commission_rate decimal(5,2)        NOT NULL DEFAULT 0.00,
            notes          text,
            created_at     datetime            NOT NULL DEFAULT '0000-00-00 00:00:00',
            updated_at     datetime            NOT NULL DEFAULT '0000-00-00 00:00:00',
            PRIMARY KEY  (id),
            UNIQUE KEY user_id (user_id)
        ) {$collate};

        CREATE TABLE {$c} (
            id           bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            referrer_id  bigint(20) unsigned NOT NULL,
            coupon_code  varchar(100)         NOT NULL,
            created_at   datetime            NOT NULL DEFAULT '0000-00-00 00:00:00',
            PRIMARY KEY  (id),
            UNIQUE KEY coupon_code (coupon_code),
            KEY referrer_id (referrer_id)
        ) {$collate};

        CREATE TABLE {$co} (
            id                bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            referrer_id       bigint(20) unsigned NOT NULL,
            order_id          bigint(20) unsigned NOT NULL,
            coupon_code       varchar(100)         NOT NULL,
            order_total       decimal(15,4)        NOT NULL DEFAULT 0.0000,
            commission_amount decimal(15,4)        NOT NULL DEFAULT 0.0000,
            commission_rate   decimal(5,2)         NOT NULL DEFAULT 0.00,
            status            varchar(20)          NOT NULL DEFAULT 'pending',
            created_at        datetime            NOT NULL DEFAULT '0000-00-00 00:00:00',
            paid_at           datetime                     DEFAULT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY order_id (order_id),
            KEY referrer_id (referrer_id),
            KEY status (status)
        ) {$collate};";

        dbDelta( $sql );
    }

    /* ---------------------------------------------------------------
     * Referrer queries
     * ------------------------------------------------------------- */

    public static function get_referrer_by_user( int $user_id ): ?object {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_REFERRERS;
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE user_id = %d", $user_id ) ) ?: null;
    }

    public static function get_referrer_by_id( int $id ): ?object {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_REFERRERS;
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) ) ?: null;
    }

    public static function get_all_referrers(): array {
        global $wpdb;
        $r = $wpdb->prefix . self::TABLE_REFERRERS;
        $u = $wpdb->users;
        return (array) $wpdb->get_results(
            "SELECT r.*, u.display_name, u.user_email
             FROM {$r} r
             LEFT JOIN {$u} u ON u.ID = r.user_id
             ORDER BY r.created_at DESC"
        );
    }

    public static function insert_referrer( int $user_id, float $rate, string $notes ): int|false {
        global $wpdb;
        $now = current_time( 'mysql' );
        $res = $wpdb->insert(
            $wpdb->prefix . self::TABLE_REFERRERS,
            [
                'user_id'         => $user_id,
                'commission_rate' => $rate,
                'notes'           => $notes,
                'created_at'      => $now,
                'updated_at'      => $now,
            ],
            [ '%d', '%f', '%s', '%s', '%s' ]
        );
        return $res ? (int) $wpdb->insert_id : false;
    }

    public static function update_referrer( int $id, float $rate, string $notes ): bool {
        global $wpdb;
        $res = $wpdb->update(
            $wpdb->prefix . self::TABLE_REFERRERS,
            [
                'commission_rate' => $rate,
                'notes'           => $notes,
                'updated_at'      => current_time( 'mysql' ),
            ],
            [ 'id' => $id ],
            [ '%f', '%s', '%s' ],
            [ '%d' ]
        );
        return $res !== false;
    }

    public static function delete_referrer( int $id ): void {
        global $wpdb;
        $wpdb->delete( $wpdb->prefix . self::TABLE_COMMISSIONS, [ 'referrer_id' => $id ], [ '%d' ] );
        $wpdb->delete( $wpdb->prefix . self::TABLE_COUPONS,     [ 'referrer_id' => $id ], [ '%d' ] );
        $wpdb->delete( $wpdb->prefix . self::TABLE_REFERRERS,   [ 'id' => $id ],           [ '%d' ] );
    }

    /* ---------------------------------------------------------------
     * Coupon queries
     * ------------------------------------------------------------- */

    public static function get_coupons_for_referrer( int $referrer_id ): array {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_COUPONS;
        return (array) $wpdb->get_results(
            $wpdb->prepare( "SELECT * FROM {$table} WHERE referrer_id = %d ORDER BY created_at ASC", $referrer_id )
        );
    }

    /**
     * Returns the referrer row for a given coupon code, or null if not a referral coupon.
     */
    public static function get_referrer_by_coupon( string $coupon_code ): ?object {
        global $wpdb;
        $r = $wpdb->prefix . self::TABLE_REFERRERS;
        $c = $wpdb->prefix . self::TABLE_COUPONS;
        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT r.* FROM {$r} r
                 INNER JOIN {$c} c ON c.referrer_id = r.id
                 WHERE c.coupon_code = %s",
                strtolower( $coupon_code )
            )
        ) ?: null;
    }

    /**
     * Replaces all coupon assignments for a referrer with the provided list.
     */
    public static function set_referrer_coupons( int $referrer_id, array $coupon_codes ): void {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_COUPONS;
        $wpdb->delete( $table, [ 'referrer_id' => $referrer_id ], [ '%d' ] );

        $now = current_time( 'mysql' );
        foreach ( $coupon_codes as $code ) {
            $code = strtolower( trim( $code ) );
            if ( ! $code ) continue;
            $wpdb->insert(
                $table,
                [ 'referrer_id' => $referrer_id, 'coupon_code' => $code, 'created_at' => $now ],
                [ '%d', '%s', '%s' ]
            );
        }
    }

    /* ---------------------------------------------------------------
     * Commission queries
     * ------------------------------------------------------------- */

    public static function get_commission_by_order( int $order_id ): ?object {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_COMMISSIONS;
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE order_id = %d", $order_id ) ) ?: null;
    }

    public static function record_commission(
        int $referrer_id,
        int $order_id,
        string $coupon_code,
        float $order_total,
        float $commission_amount,
        float $rate
    ): int|false {
        global $wpdb;
        // Use INSERT IGNORE to avoid duplicates from the UNIQUE KEY on order_id
        $table = $wpdb->prefix . self::TABLE_COMMISSIONS;
        $res   = $wpdb->query(
            $wpdb->prepare(
                "INSERT IGNORE INTO {$table}
                 (referrer_id, order_id, coupon_code, order_total, commission_amount, commission_rate, status, created_at)
                 VALUES (%d, %d, %s, %f, %f, %f, 'pending', %s)",
                $referrer_id,
                $order_id,
                strtolower( $coupon_code ),
                $order_total,
                $commission_amount,
                $rate,
                current_time( 'mysql' )
            )
        );
        return $res ? (int) $wpdb->insert_id : false;
    }

    public static function get_commissions_for_referrer( int $referrer_id ): array {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_COMMISSIONS;
        return (array) $wpdb->get_results(
            $wpdb->prepare( "SELECT * FROM {$table} WHERE referrer_id = %d ORDER BY created_at DESC", $referrer_id )
        );
    }

    /**
     * Returns commissions with optional filtering. Supports 'status', 'referrer_id', 'per_page', 'page'.
     */
    public static function get_all_commissions( array $args = [] ): array {
        global $wpdb;
        $co = $wpdb->prefix . self::TABLE_COMMISSIONS;
        $r  = $wpdb->prefix . self::TABLE_REFERRERS;
        $u  = $wpdb->users;

        $where  = [];
        $values = [];

        if ( ! empty( $args['status'] ) && in_array( $args['status'], [ 'pending', 'paid' ], true ) ) {
            $where[]  = "co.status = %s";
            $values[] = $args['status'];
        }
        if ( ! empty( $args['referrer_id'] ) ) {
            $where[]  = "co.referrer_id = %d";
            $values[] = (int) $args['referrer_id'];
        }

        $where_sql = $where ? 'WHERE ' . implode( ' AND ', $where ) : '';

        $per_page = isset( $args['per_page'] ) ? max( 1, (int) $args['per_page'] ) : 50;
        $page     = isset( $args['page'] ) ? max( 1, (int) $args['page'] ) : 1;
        $offset   = ( $page - 1 ) * $per_page;

        $sql = "SELECT co.*, u.display_name
                FROM {$co} co
                LEFT JOIN {$r} ref ON ref.id = co.referrer_id
                LEFT JOIN {$u} u   ON u.ID = ref.user_id
                {$where_sql}
                ORDER BY co.created_at DESC
                LIMIT %d OFFSET %d";

        $values[] = $per_page;
        $values[] = $offset;

        return (array) $wpdb->get_results( $wpdb->prepare( $sql, ...$values ) );
    }

    public static function count_all_commissions( array $args = [] ): int {
        global $wpdb;
        $table  = $wpdb->prefix . self::TABLE_COMMISSIONS;
        $where  = [];
        $values = [];

        if ( ! empty( $args['status'] ) && in_array( $args['status'], [ 'pending', 'paid' ], true ) ) {
            $where[]  = "status = %s";
            $values[] = $args['status'];
        }
        if ( ! empty( $args['referrer_id'] ) ) {
            $where[]  = "referrer_id = %d";
            $values[] = (int) $args['referrer_id'];
        }

        $where_sql = $where ? 'WHERE ' . implode( ' AND ', $where ) : '';
        $sql       = "SELECT COUNT(*) FROM {$table} {$where_sql}";

        return (int) ( $values ? $wpdb->get_var( $wpdb->prepare( $sql, ...$values ) ) : $wpdb->get_var( $sql ) );
    }

    public static function mark_commissions_paid( array $commission_ids ): int {
        global $wpdb;
        if ( empty( $commission_ids ) ) return 0;

        $table = $wpdb->prefix . self::TABLE_COMMISSIONS;
        $ids   = implode( ',', array_map( 'intval', $commission_ids ) );
        return (int) $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$table} SET status = 'paid', paid_at = %s WHERE id IN ({$ids}) AND status = 'pending'",
                current_time( 'mysql' )
            )
        );
    }

    /**
     * Returns aggregate summary for a referrer: pending, paid, total commission amounts.
     */
    public static function get_referrer_summary( int $referrer_id ): object {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_COMMISSIONS;
        $row   = $wpdb->get_row( $wpdb->prepare(
            "SELECT
                SUM(CASE WHEN status = 'pending' THEN commission_amount ELSE 0 END) AS pending,
                SUM(CASE WHEN status = 'paid'    THEN commission_amount ELSE 0 END) AS paid,
                SUM(commission_amount) AS total
             FROM {$table}
             WHERE referrer_id = %d",
            $referrer_id
        ) );

        return (object) [
            'pending' => (float) ( $row->pending ?? 0 ),
            'paid'    => (float) ( $row->paid    ?? 0 ),
            'total'   => (float) ( $row->total   ?? 0 ),
        ];
    }
}
