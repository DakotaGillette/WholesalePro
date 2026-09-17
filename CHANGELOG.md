# Changelog

All notable changes to the Protech Wholesale plugin. Dates are the day the
change landed on staging.

## 1.1.0 — 2026-09-17

### Security
- A public application-form submission carrying an existing account's email
  could replace that account's roles (an administrator's email demoted the
  administrator to a read-only pending applicant). The two wholesale roles
  are now only ever added to or removed from an account, and accounts with
  staff capabilities are never changed automatically — their application is
  recorded for manual review instead.

### Fixed
- Orders placed through the (Blocks) checkout were never flagged as
  wholesale, so the Wholesale column/filter and the "WHOLESALE ORDER" email
  subject never fired on this site.
- The "Set wholesale prices" variations bulk action did nothing.
- "Wholesale only" and "Displays per case" were unreachable on variable
  products (the fields rendered inside a simple-products-only panel).
- Rejected applicants stayed in the pending role: still counted as pending,
  and shown "your application is under review" forever.
- A variable product's cached price range ignored the cart's quantity tier
  and per-customer overrides.
- Wholesale-only products leaked (name and retail price) through the public
  Store API product listing, and template-level hiding left pagination holes.
- The Blocks cart's quantity controls ignored the display size.
- Reorder showed "invalid link" to a customer whose session had expired.
- The My Account login form did not redirect wholesale customers to the shop.
- The HPOS orders filter dropdown rendered twice.

### Added
- "Wholesale" product data tab; Volume/Bulk override bulk actions.
- Wholesale price table (Standard/Volume/Bulk) on the product page.
- Customers tab; application details on the user profile; product search
  picker for per-customer overrides; search and pagination on Applicants;
  Rejected view with a reason prompt; confirmation before Approve.
- "Your wholesale account" panel on the My Account dashboard.
- Branded emails via the WooCommerce mailer; approval link goes to the
  My Account password form; the admin notice includes the full application.
- Tier bar: hidden on checkout, screen-reader announcements, safe-area
  padding, reduced-motion support.
- Test suite (PHPUnit inside wp-env), PHPCS/PHPStan configs, GitHub Actions.

### Changed
- Price filters memoize the cart tier and role checks per request.
- Wholesale shipping is not offered for a cart with nothing wholesale-eligible;
  the retail methods it hides are filterable.
- Packs per display falls back variation → parent → store default.
- `pre_get_posts` catalog filtering replaces template-level hiding; a one-off
  upgrade backfills the parent-level has-wholesale-price flag.

## 1.0.0 — 2026-09-17

Initial build: roles and approval flow, wholesale pricing engine, Display/Case
quantity-tier ladder, wholesale shipping method, sticky tier bar, unit
selector, reorder, portal page, admin reporting.
