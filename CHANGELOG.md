# Changelog

All notable changes to the Protech Wholesale plugin. Dates are the day the
change landed on staging.

## 1.5.0 — 2026-09-18

### Added
- **Messaging & automations**, under WooCommerce → Wholesale → Messaging.
  Email and SMS to wholesale customers through Brevo (email falls back to
  the site's WooCommerce mailer if Brevo isn't connected):
  - **Automations**: reorder reminder, win-back (repeating), first-order
    nudge, and order-status (e.g. "shipped") rules — each with a
    tier filter, email/SMS content with merge tags, and a "Preview
    recipients" dry run. A day-based rule only fires inside a 7-day
    window from its trigger day, so enabling one never reaches back
    into old history, and never repeats for the same order.
  - **Compose**: one-off email/SMS to a chosen audience (all, a tier, no
    order in N days, never ordered, prefers texts but hasn't opted in,
    or specific customers ticked on the Customers tab), plus "send test
    to me."
  - **Log**: every message ever queued, its status, and why it was
    skipped when it was.
  - **Compliance**: the SMS opt-in wording in use, message types
    configured, a consent-records CSV export, and suggested
    privacy-policy/terms text — the proof-of-opt-in package for Brevo's
    toll-free-number verification.
  - **Settings**: Brevo connection (own key, or the Brevo plugin's own
    key), sender, brand, quiet hours, frequency cap, unsubscribe footer.
  - Self-service SMS/email preferences under My Account → Notifications;
    an admin can also record consent on a customer's profile (a note is
    required). The wholesale application form gained two SMS consent
    checkboxes (order updates, marketing/reorder reminders).
  - Delivery runs entirely on Action Scheduler — nothing is ever sent
    from a checkout or admin request. First custom database table in
    this plugin (`{prefix}protech_wholesale_messages`), created via
    `DB_VERSION` 3.
- **MSRP crossed out next to the wholesale price** everywhere a price
  shows to a wholesale customer (product page, shop grid, the per-colour
  price swap): "MSRP ~~$9.99~~ $5.00 Save 50%", using WooCommerce's own
  del/ins sale markup so the theme's price styles still apply. The MSRP
  is the store's regular price; a product whose MSRP isn't above the
  wholesale price shows the plain price. `Pricing::get_msrp_range()`.
- **"After a wholesale login, go to"** (Settings → Catalog): where an
  approved wholesale customer lands after logging in on `/wholesale` or
  My Account. Empty = the shop page, as before. Off-site URLs fall back
  to the shop. `Settings::login_landing_url()`.
- **"How wholesale quantities work" legend** above the Display/Case
  control on every wholesale product page: pack → display → case tiles
  drawn from the product's own composition, then "1 case = 8 displays.
  Mix and match your displays however you'd like. Free shipping from 16
  displays (2 cases), any combination of colours." Every number comes
  from the settings. `templates/quantity-legend.php`.

### Changed
- **Tier names.** The first wholesale tier no longer has a name on the
  storefront: it is shown by its range ("Under 16 displays") in the
  price table, the sticky bar's marker and the My Account card, and the
  bar's chip reads just "Wholesale" while a cart is in it. What was
  called Volume is now **Standard** and what was called Bulk is now
  **Volume**; the message reads "Add N more displays to unlock free
  shipping and Standard pricing." In admin the three rows are Base /
  Standard / Volume, and the product-field labels follow ("Standard
  price override", "Volume price override"). Slugs, option names and
  meta keys are unchanged (`standard`/`volume`/`bulk`), so nothing
  migrates and every existing override keeps working.

## 1.4.0 — 2026-09-18

### Added
- `[protech_header_notice]` shortcode for Salient's "Text To Display In
  Header" customizer field: wrap the existing retail text in it and a
  logged-in wholesale customer sees a wholesale line instead ("FREE
  SHIPPING ON WHOLESALE ORDERS OF 16+ DISPLAYS" by default, built from the
  live Volume threshold), with a `wholesale` attribute and a
  `protech_wholesale_header_notice` filter for custom text. No theme edit.

## 1.3.1 — 2026-09-18

### Fixed
- On a variable product's page the theme's own quantity stepper was back
  beside the Display/Case control, with no read-back line and a plain "Add
  to cart" button, and the quantity no longer stepped by the display. The
  1.3.0 "sold at wholesale" check looked at the variable product's parent,
  which never carries a price itself; it now looks through to the colours.
  Regression test added. (Sample packs without any wholesale price are still
  exempt, as intended.)

## 1.3.0 — 2026-09-18

Admin-side audit, and updates from GitHub.

### Added
- **Updates.** New versions are published as GitHub releases by CI on every
  push to `main` (tag from the plugin header, `protech-wholesale.zip` attached,
  notes from this file). The plugin's `Update URI` routes WordPress's update
  check to that release, so it shows in the Plugins list with View details
  and one-click Update. "Check for updates" under the plugin row, and an
  Updates block on Settings.
- Plugins list row links: Applicants, Settings, Changelog, Check for updates.
- A count bubble on the WooCommerce → Wholesale menu item while applications
  are waiting; quick links (login page, application form, log) under the
  title; a Help pull-down explaining the tabs.
- A setup notice on the Wholesale screen while something is missing: the
  shipping method not in any zone, no priced product, no portal page, or an
  application form ID Fluent Forms does not have. Each with a link to fix it.
- "Apply to all variations" price fields on a variable product's Wholesale
  tab, with a line saying what the variations currently hold.
- A "Wholesale" column on Products → All Products.
- Tier changes from the Customers tab.
- "Send new-application emails to" setting.

### Changed
- Pricing & Shipping is three titled groups (quantity pricing, displays and
  cases, wholesale shipping) with one Save button; the retail free-shipping
  exclusion moved here from Settings, next to the rest of shipping; the tab
  says which zones have the wholesale method.
- Settings is grouped: Catalog, Applications, Updates, Uninstall.
- "Standard (Tier 1)" / "Volume (Tier 2)" / "Bulk (Tier 3)" are now just
  Standard, Volume, Bulk. "Tier" means only the Bronze to Platinum customer
  levels.
- **Display and case rules apply only to products that carry a wholesale
  price.** A product without one is bought on retail terms by everyone,
  quantity 1 allowed, and does not count toward tiers or the cart badge.
  This is what lets the Vendor Starter Kit be a plain, unlisted $800 product
  sold from a link to new vendors.
- Customers tab reads order count and spend from WooCommerce's cached
  per-customer figures instead of loading every order of every row.

### Removed
- The unenforced dollar "Minimum order" fields (Tiers tab, per-tier
  overrides, user profile) and the code behind them. Old saved values are
  ignored and still deleted on purge.

## 1.2.0 — 2026-09-17

Storefront polish: everything a wholesale customer sees.

### Changed
- **Protech Blue (`#42649D`) is now the colour of all wholesale UI**: the
  sticky bar, the "Wholesale price" label, the price table, the account
  card and the `/wholesale` page. Theme buttons (Add to cart, Checkout) stay
  black. Plugin text now inherits the site font (Poppins) instead of forcing
  Open Sans.
- **Product page quantity is one control.** Display/Case are two radio
  cards, with a −/+ stepper, a live "3 displays · 30 packs" read-back, and an
  Add to cart button that says what it will add ("Add 3 displays to cart").
  A successful add is confirmed in place, and the chosen unit is remembered.
- **Sticky tier bar rebuilt** as a floating blue dock (full width on
  phones, two rows instead of three): tier chip, a Standard marker and
  per-pack prices on the Volume/Bulk markers, whole-case tick marks, a
  "Saving $X" figure once a tier is reached, and a preview segment showing
  where the quantity being dialled in on a product page would land.
  Reaching a tier is celebrated once (marker pop, sheen, confetti), including
  when it happened through a full page reload. All motion is off under
  `prefers-reduced-motion`.
- The bar's track is now two segments, with the Volume marker at 40%
  rather than 12.5% — the first tier is no longer crammed into the left edge.
- **Header cart badge counts displays** for wholesale customers (it also
  drives the Blocks mini-cart count), and grows into a pill instead of
  overflowing at three digits. Retail is unchanged. Filter:
  `protech_wholesale_cart_count_in_displays`.
- **My Account** shows a "Wholesale Partner" strip with the business name on
  every account page (an "Application under review" one for pending
  applicants), and the dashboard panel is now a card with a progress rail,
  cart stats, tier savings and a checklist of unlocked tiers.
- **`/wholesale` login page** is a full-width two-panel card: a blue pitch
  panel whose benefits come from the store's real thresholds, beside a
  larger form with a show/hide password button. Pending, rejected and
  retail-only share one status card; pending gets a three-step tracker.
- The product page price table highlights the active row in blue, shows a
  "Save N%" chip per tier, and moves its highlight live when the cart
  crosses a tier.

### Fixed
- The theme's own pack-quantity stepper was never actually hidden on the
  product page (Salient's `.cart div.quantity { display: flex }` outranked
  the plugin's hiding rule), so customers saw two quantity controls.
- Bar messages read "Add 12 more display worth…"; they now use real
  singular/plural wording and name the tier being unlocked.
- The login form had no `autocomplete` attributes, lost the username after a
  failed attempt, and printed the login error's HTML tags as literal text.
- The price table's "Your cart" row stayed stale after an AJAX add to cart.
- The bar's fill could drift from the real tier for products with a
  non-default case composition; it is now pinned to the tier reached.
- (1.1.0 regression) A wholesale-only product's own page returned a 404 to
  anyone who isn't a wholesale customer, staff included, because the
  query-level catalog filter also ran on the single-product query. It now
  leaves single-product pages alone, so the "approved accounts only" notice
  shows as documented.
- A white band showed under the footer at the very end of every page: the
  room reserved for the floating bar was `<body>` padding, which paints the
  body's background. It is now a spacer that takes the colour of whatever
  the page ends with (found on staging, first look).
- The sticky bar is now the same width as the site header instead of a
  fixed 1180px: it matches the floating header card edge for edge (measured
  in the script, with a stylesheet fallback built on the theme's own
  container variables). The two-row layout starts at 1160px so the track's
  captions never collide. Verified by rendering the real staging page in
  headless Chrome at six widths.

### Added
- **Starter kits.** A simple product can be marked as a kit on its Wholesale
  tab: its page adds one display of every colour of a chosen variable
  product to the cart, topped up to a target (default: the Volume threshold)
  with chosen filler colours. Real variations go in the cart, so stock,
  pricing and Reorder all just work; a 16-display kit costs 160 packs at the
  Volume price with free shipping. The page lists the kit's contents, quotes
  it live for any number of kits, and adds it in place (the sticky bar
  previews the jump to Volume). Set up: README, "Starter kits".
- Filters: `protech_wholesale_tier_bar_show_prices`,
  `protech_wholesale_cart_count_in_displays`,
  `protech_wholesale_portal_benefits`,
  `protech_wholesale_tier_bar_align_selector`,
  `protech_wholesale_tier_bar_spacer_color`.
- DOM events: `protech:tier-state` (every new bar state) and
  `protech:qty-preview` (the quantity dialled into the product page control).
- Template `account-wholesale-header.php`; script `assets/js/portal.js`.

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
