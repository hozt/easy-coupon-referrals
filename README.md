# Easy Coupon Referrals

Coupon-based referral tracking for WooCommerce with commission management and a referrer dashboard.

---

## Requirements

- WordPress 6.0+
- WooCommerce 7.0+
- PHP 8.0+

---

## Setup

1. **Install and activate** the plugin — database tables and the My Account endpoint are created automatically.
2. Go to **WooCommerce → Referral Settings** and configure defaults:
   - **Default commission rate** — percentage of order total paid to referrers
   - **Default coupon discount type** — percent, fixed amount, or no discount
   - **Default coupon amount** — discount customers receive when using a referral link
   - **Minimum payout threshold** — referrers must accumulate at least this amount before commissions can be marked paid (set to 0 to disable)
3. Go to **WooCommerce → Referral Program** and click **+ Add Referrer**:
   - Select the WordPress user
   - Adjust the commission rate if it differs from the default
   - Type a coupon code and press **Enter** (or click **Add**) — the WooCommerce coupon is created automatically with your configured defaults
   - Add multiple coupon codes per referrer if needed
   - Click **Save Referrer**

---

## How Referrals Work

- Each referrer gets a shareable link: `https://yoursite.com/?refer=COUPONCODE`
- When a customer visits that link, the coupon is silently applied to their cart and a confirmation modal appears on the page
- The customer sees a notice on the cart and checkout pages confirming the discount
- When the order reaches **Processing** status, a commission is recorded automatically
- Commission = order total × referrer's commission rate

---

## Referrer Dashboard

Referrers log into the site and visit **My Account → Referral Dashboard**.

The dashboard shows:
- **Summary cards** — total earned, pending payout, and paid out amounts
- **Referral links** — all assigned coupon codes with one-click copy buttons
- **Commission history** — per-order breakdown with status and dates

> The Referral Dashboard tab only appears for users registered in the program.

Alternatively, place the `[referral_dashboard]` shortcode on any page to display the dashboard there.

---

## Managing Commissions

**WooCommerce → Referral Commissions**

- Filter commissions by status (pending / paid) or by referrer
- Mark individual commissions paid using the **Mark Paid** button on each row
- Select multiple commissions and use **Mark Selected as Paid** for bulk updates
- If a minimum payout threshold is set, commissions cannot be marked paid until the referrer's pending balance meets it

Each WooCommerce order containing a referral coupon shows a **Referral Commission** block in the order detail screen with the referrer name, coupon used, commission amount, and payment status.

---

## Notes

- Coupon codes entered in the Add Referrer form are created in WooCommerce automatically using the configured defaults. You can edit them afterward under **WooCommerce → Coupons**.
- One coupon can only be assigned to one referrer. A code already assigned to another referrer will be rejected on save.
- Commission is recorded once per order regardless of how many times the order status changes.
- A referrer can have multiple coupon codes. Only the first matching referral coupon on an order is credited.
- Deactivating the plugin does **not** delete data. Tables and options remain intact until the plugin is uninstalled.

---

## Development

Run the unit test suite (requires [Composer](https://getcomposer.org)):

```bash
composer install
composer test
```

Tests cover commission calculation, settings normalization, and payout threshold eligibility — no WordPress installation required.
