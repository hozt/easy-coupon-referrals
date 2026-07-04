# Easy Coupon Referrals

Coupon-based referral tracking for WooCommerce with commission management and a referrer dashboard.

---

## Setup

1. **Activate** the plugin — DB tables and permalink endpoint are created automatically.
2. Go to **WooCommerce → Referral Settings** and set your defaults:
   - Default commission rate (% of order total paid to referrers)
   - Default coupon discount type and amount (what the customer receives)
3. Go to **WooCommerce → Referral Program → + Add Referrer**:
   - Select the WordPress user
   - Adjust commission rate if different from the default
   - Type a coupon code and press **Enter** (or click **Add**) — the WooCommerce coupon is created automatically
   - Save

---

## How Referrals Work

- Each referrer gets a shareable link: `https://yoursite.com/?refer=COUPONCODE`
- When a customer visits that link, the coupon is applied to their cart automatically
- When the order reaches **Processing** status, a commission is recorded
- Commission = order total × referrer's commission rate

---

## Referrer Dashboard

Referrers log into the site and go to **My Account → Referral Dashboard**.  
They can see their referral links, commission history, and payout status.

> The tab only appears for users who are registered in the program.

---

## Managing Commissions

- **WooCommerce → Referral Commissions** — filter by status or referrer, mark individual or bulk commissions as paid
- Each WooCommerce order that has a referral shows the referrer name, coupon, commission amount, and paid/pending status in the order detail screen

---

## Notes

- Coupons typed in the Add Referrer form are created in WooCommerce automatically with the configured defaults. You can edit them afterward under **WooCommerce → Coupons**.
- One coupon can only be assigned to one referrer. A coupon assigned to another referrer will be rejected on save.
- Commission is recorded once per order. If an order status changes multiple times, no duplicate commission is created.
- Deactivating the plugin does **not** delete data. Tables and options persist.
