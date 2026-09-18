# Protech Wholesale

A WordPress/WooCommerce plugin for [Protech Sleeves](https://protechsleeves.com) that turns approved retailers into a self-service wholesale channel: they apply, get approved, log in, and see wholesale pricing automatically on every ordinary shop/product page — no separate order-taking page and no manual work on the store owner's side. A price table on each product and a sticky bar across the site show live progress toward better quantity-tier pricing and free shipping, and "Reorder" on any past order adds it straight back to the cart.

## Requirements

- WordPress 6.0+
- WooCommerce, active (the plugin will not run without it)
- PHP 8.1+
- Salient theme (ThemeNectar) is assumed but not required — the plugin works through standard WooCommerce hooks and degrades gracefully on any theme

## Install

1. Upload the `protech-wholesale` folder to `wp-content/plugins/` (or upload the zip through Plugins → Add New → Upload Plugin).
2. Activate **Protech Wholesale** from the Plugins screen.
3. Activation automatically:
   - creates the `wholesale_pending` and `wholesale_customer` roles,
   - saves default settings (see below),
   - creates a `/wholesale` page containing the `[protech_wholesale_portal]` shortcode, if one doesn't already exist.
4. Go to **WooCommerce → Wholesale → Settings** and confirm **Application form source** / **Application form ID** match whatever powers `/wholesale-application` on your site (Fluent Forms, form #4, on the live site).
5. Add **Protech Wholesale Shipping** to each shipping zone under WooCommerce → Settings → Shipping (it is invisible to retail customers).
6. On each product you sell at wholesale, open the **Wholesale** product data tab and set a **Wholesale price** (per variation, for a variable product — use the Variations tab's "Set wholesale prices" bulk action to set every colour at once). Products with no wholesale price are simply not offered at wholesale.

## Admin screens

Everything lives under **WooCommerce → Wholesale**. The menu item shows a count bubble while applications are waiting, the Plugins list row links straight to Applicants and Settings, and a notice at the top of the screen lists anything not set up yet (shipping method not in a zone, no priced product, no portal page, unknown form ID) until it is. The Help pull-down on the screen explains each tab.

| Tab | What's there |
|---|---|
| Applicants | Pending / Approved / Rejected applications with Approve and Reject (with an optional reason) actions, search, pagination. |
| Customers | Every wholesale customer: store, tier (change it here and Save), override count, orders, last order, lifetime spend. |
| Products | Every product priced or flagged for wholesale: price, Volume and Bulk overrides, packs per display, flags. The same summary is a "Wholesale" column on Products → All Products. |
| Pricing & Shipping | Quantity pricing (the Standard/Volume/Bulk ladder), display and case defaults, and wholesale shipping: the flat rate, which zones have the method, and the retail free-shipping exclusion. |
| Tiers | Bronze/Silver/Gold/Platinum discount percentages (internal, never shown to customers). |
| Settings | Catalog (empty-price behaviour, coupons), Applications (form plugin, form ID, notification email), Updates (installed and latest release, check now), Uninstall. |

Per-customer price overrides, the wholesale flag and the customer's submitted application are on their **user profile** (Users → Edit); the tier is there too.

Pricing a product happens on the product's own **Wholesale** tab. A variable product has "Apply to all variations" fields there that write one price to every colour on Update; one colour can still be priced differently on the Variations tab.

## Settings reference

| Setting | Option key | Default | What it does |
|---|---|---|---|
| Products with no wholesale price | `protech_wholesale_empty_price_behavior` | `hide` | What a wholesale customer sees for a product with no wholesale price. `hide` removes it from the wholesale shop/search/category views entirely (at the query level, so pagination and counts stay right). `fallback` shows the retail price with a "(retail only)" note. |
| Allow retail coupons for wholesale customers | `protech_wholesale_allow_retail_coupons` | `no` | When off, any coupon applied by a wholesale account is rejected. |
| Retail free shipping (on Pricing & Shipping) | `protech_wholesale_exclude_free_shipping` | `yes` | Keeps the retail free-shipping rule from applying to wholesale customers in zones where the wholesale method hasn't been added. |
| Send new-application emails to | `protech_wholesale_notification_email` | empty (site admin email) | Where the "new wholesale application" email goes. |
| Application form source / form ID | `protech_wholesale_application_source` / `protech_wholesale_application_form_id` | `fluent_forms` / `4` | Which plugin and which exact form produce wholesale applications. Adapters exist for Gravity Forms, WPForms, Contact Form 7, and Fluent Forms, plus a native fallback form (`[protech_wholesale_application]`). |
| On uninstall | `protech_wholesale_purge_on_uninstall` | `no` | If checked, deleting the plugin removes its settings, roles, the auto-created `/wholesale` page (only if unmodified), and wholesale user meta. Product pricing meta and order flags are never removed. See `uninstall.php`. |

## How pricing precedence works

For any product or variation, a wholesale customer's per-pack price is resolved in this order:

1. **Per-customer override** — set on their user profile with the product picker. Always wins, regardless of cart quantity.
2. **The Standard/Volume/Bulk quantity tier the cart has reached** — see "Quantity-tier pricing and shipping" below. Standard is the product's own "Wholesale price"; Volume/Bulk are store defaults, overridable per product/variation.
3. **The customer's tier discount** (Silver/Gold/Platinum percentage, Bronze = none) — applied on top of #2.
4. **Not available at wholesale** — no "Wholesale price" set at all. Depending on the empty-price setting the product is hidden from wholesale views or shown at retail with a note.

Retail customers and guests never see a wholesale price under any circumstance — pricing filters only apply to logged-in users with the `wholesale_customer` role.

## Quantity-tier pricing and shipping

Wholesale sells sleeves by two units, both built on the "pack" WooCommerce tracks stock in: a **Display** (10 packs by default — "Packs per display", per product; a variation inherits its parent's) and a **Case** (8 Displays by default — "Displays per case"). Customers pick Display or Case and a quantity on the product page; it always submits a real pack count.

- **Ladder** (WooCommerce → Wholesale → Pricing & Shipping): $5.50/pack under 16 combined Displays (Standard, the product's own price), $5.00/pack at 16+ Displays (Volume), $4.50/pack at 16+ Cases (Bulk). Thresholds are **combined across the whole cart** (every product and colour together). Each product page shows the customer their own three prices in a small table, with the cart's current tier marked.
- **Shipping**: a flat $19.95 below the Volume threshold, free at or above it — a real WooCommerce shipping method (`Wholesale Shipping`), only shown to wholesale customers whose cart holds wholesale-eligible items, and only once it's been added to a zone. When present it hides the zone's retail Flat rate / Free shipping methods (list filterable via `protech_wholesale_retail_shipping_methods`).
- Wholesale quantities must be multiples of the packs-per-display: enforced at add-to-cart (classic and Store API), on the Blocks cart's own quantity controls, and again at checkout.
- Cart/checkout lines carry a "Displays: N" annotation; it is saved on the order line item.
- There is **no dollar-based order minimum**; the fields from an earlier design were removed in 1.3.0.
- Display and case rules apply only to products that carry a wholesale price. A product without one (a sample pack sold at a plain price from a link, say) is bought on ordinary retail terms by everyone, one at a time, and counts toward nothing.

## The sticky tier bar

A Protech Blue dock floating at the bottom of the viewport (full width on phones) on every front-end page except checkout, for logged-in wholesale customers only: a tier chip and message ("Add 3 more displays to unlock Volume pricing and free shipping"), a track with Standard / Volume / Bulk markers, the live cart subtotal, Display/Case count and — once a tier is reached — what that tier is saving the order. It updates without a page reload after any add-to-cart anywhere on the site, previews where the quantity being dialled in on a product page would land, celebrates a newly reached tier once, and is deliberately not dismissible. All motion is disabled under `prefers-reduced-motion`. See `class-global-tier-bar.php`.

- The track is two segments (Volume marker at 40%), not one linear scale — see `VolumePricing::scale_percent()`.
- The per-pack prices on the Volume/Bulk markers are the **store defaults** from the Pricing tab with the customer's tier discount applied. If most products carry their own Volume/Bulk overrides, remove them with `add_filter( 'protech_wholesale_tier_bar_show_prices', '__return_false' );`.
- Every new state is re-broadcast as a `protech:tier-state` DOM event (`event.detail` is the state) for theme code that wants to react to it.
- The dock matches the width of the site header: a header that is a floating card (as on this site) is matched edge for edge, a full-width header on the content inside it. Point it at a different element with the `protech_wholesale_tier_bar_align_selector` filter (a CSS selector; return `''` for a centred 1180px dock).
- The room it reserves at the end of each page takes the colour of whatever the page ends with, so it blends into the footer. If that guess is ever wrong, set it with the `protech_wholesale_tier_bar_spacer_color` filter (any CSS colour).

## Other customer-facing pieces

- **Product page order control**: Display / Case cards, a −/+ stepper and a live read-back ("3 displays · 30 packs") replace the theme's pack stepper; the Add to cart button restates what it will add. See `CaseRules::render_unit_selector()` and `assets/js/unit-selector.js`.
- **Header cart badge** counts displays, not packs, for wholesale customers (it also drives the Blocks mini-cart count). Switch back with `add_filter( 'protech_wholesale_cart_count_in_displays', '__return_false' );`.
- **My Account**: a "Wholesale Partner" strip with the business name on every account page (amber "Application under review" for pending applicants), and a dashboard card with the cart's tier, progress and savings.
- **`/wholesale`**: a two-panel login page whose benefit list is built from the live thresholds (`protech_wholesale_portal_benefits` filter), and a status card for pending / rejected / retail-only visitors.
- All of it is styled in Protech Blue (`--protech-blue` in `assets/css/wholesale.css`); the theme's own buttons keep the theme's colour.
- **Header banner** ("FREE SHIPPING WITH $30+ ORDERS"): Salient's own "Text To Display In Header" field already runs `do_shortcode()`, so wrapping its text in `[protech_header_notice]...[/protech_header_notice]` shows that text unchanged to everyone except a logged-in wholesale customer, who sees a wholesale line instead — by default "FREE SHIPPING ON WHOLESALE ORDERS OF 16+ DISPLAYS", generated from the live Volume threshold so it can't go stale. Pass `wholesale="…"` for fixed text, or use the `protech_wholesale_header_notice` filter. No theme edit. See `class-header-notice.php`.

## How to approve a customer

1. A retailer submits `/wholesale-application`. A WordPress user is created with the `wholesale_pending` role and every answer is saved as user meta. The applicant gets a "we received your application" email; the store admin gets an email with the full application and a link to the profile.
   - If the email address already belongs to an ordinary shopper account, the pending role is **added** to it (its existing roles are kept). If it belongs to a staff account (administrator, shop manager, editor…), the account's roles are **never** changed: the application is recorded and flagged for manual review instead.
   - An already-approved wholesale customer re-applying is ignored.
2. In **WooCommerce → Wholesale → Applicants** (Pending), click **Approve** (confirmation prompt) or **Reject** (prompts for an optional reason, included in the email).
3. **Approve** adds the `wholesale_customer` role, removes `wholesale_pending`, and emails a link to set a password on the My Account page (valid 24 hours; "Lost your password?" issues a new one). **Reject** removes the pending role (the account becomes an ordinary customer), records the reason, moves them to the Rejected view, and emails them. A rejected applicant can be approved later from the Rejected view, and can re-apply.
4. On login the customer lands on the shop with wholesale pricing visible.

**Manually flagging an existing customer:** on any user's profile, check **"Flag this customer as an approved wholesale customer"**. This adds the role without touching the account's other roles; if the account has a pending application, the approval email is sent too. Unchecking removes the wholesale role (and adds `customer` if nothing else remains).

## How to add a per-customer price

On the user's profile under **Protech Wholesale → Per-customer price overrides**, click **"+ Add price override"**, pick the product or variation with the search box, and enter the **price per pack**. It beats the group price and every quantity tier for that one customer and product only.

## Starter kits

A starter kit is a product page that adds **one display of every colour of another product** to the cart in one click, instead of being sold itself. What lands in the cart is the real colour variations, one line each, so stock is tracked per colour, Reorder works on the order, and the price is whatever the pricing engine charges for that many displays: a 16-display kit reaches the Volume threshold, so it costs 160 packs at the Volume price with free shipping.

Set one up on any simple product's **Wholesale** tab:

1. Tick **Starter kit**.
2. **Kit is built from**: search for the variable product whose colours make up the kit (the flagship sleeves).
3. **Displays in a kit**: leave empty to use the Volume threshold (16). If there are fewer colours than this, the difference is made up with extra displays of the colours in the next field.
4. **Make up the difference with**: the filler colours, in order, e.g. Black then White. With 14 colours that gives one of each plus a second Black and a second White; once there are 16 colours the fillers are no longer used. Nothing to edit when a colour is added.
5. Usually also tick **Wholesale only**. The kit's own prices and pack sizes are ignored, so they can stay empty.

A colour that is out of stock is left out (the page says so) and the fillers cover the shortfall. The kit's page shows what it contains for that customer today, its total at the tier the cart will reach once it is added, a "how many kits" stepper, and one button. The header price and shop-grid price for the kit are the same live total.

## A plain, unlisted product for new vendors

The Vendor Starter Kit is sold from a link on the one-sheet to vendors who may not have a wholesale account yet, one line at a fixed price. Set it up as an ordinary simple product: Regular price 800; Catalog visibility **Hidden** (WooCommerce's own setting, under Publish, so it is out of the shop and search but the link works); on the Wholesale tab leave **Wholesale only** unticked, **Wholesale price** empty, **Starter kit** unticked and **Packs per display** empty. A guest sees a normal $800 product and can buy it; a logged-in wholesale customer sees the same and can buy one, with no display rule and no effect on their tier.

## Updating

New versions are published as GitHub releases (`DakotaGillette/WholesalePro`, public). The release job in `.github/workflows/ci.yml` runs on every push to `main` that passes the tests: if the version in the plugin header has no tag yet, it builds `protech-wholesale.zip`, takes that version's section of `CHANGELOG.md` as the notes and creates release `v<version>`. WordPress on each site checks that release twice a day (the plugin's `Update URI` header routes the check to `includes/class-updater.php`) and offers it in the Plugins list like any other update, with View details and one-click Update. "Check for updates" under the plugin's row, or "Check now" on Settings → Updates, asks GitHub immediately.

To ship a release: bump `Version:` in `protech-wholesale.php`, add the section to `CHANGELOG.md`, merge to `main`.

## "Wholesale only" products

Check **Wholesale only** on the product's **Wholesale** tab (product level — applies to all of a variable product's colours). Retail visitors then never see it in the shop, search, categories, sitemaps, or the public Store API product listing; a direct link shows an "available to approved wholesale accounts only" message with an apply link in place of the price, and it can't be added to cart. Leave WooCommerce's own "Catalog visibility" on its default: checking Wholesale only forces it back to visible on save, because the native "Hidden" setting hides a product from wholesale customers too.

## Development

```sh
cd protech-wholesale
composer install                   # phpunit, WPCS, PHPStan (dev only; never deployed)
composer lint                      # phpcs (advisory for now)
composer analyse                   # phpstan
cd .. && npx wp-env start          # WordPress + WooCommerce + this plugin (Docker)
npx wp-env run tests-cli --env-cwd=wp-content/plugins/protech-wholesale vendor/bin/phpunit
```

GitHub Actions (`.github/workflows/ci.yml`) runs the same on every push. `bin/deploy.sh` rsyncs the plugin to Cloudways staging (excluding tests and tooling) and purges the object and Breeze page caches; see `.env.example`.

## Known limitations

- Gravity Forms and WPForms adapters are untested against real installs (neither plugin is on this site); Fluent Forms is verified live.
- No tax-exemption or resale-certificate handling: wholesale orders are taxed like retail.
- The variations bulk actions follow WooCommerce's documented custom-bulk-action contract but should be exercised once on staging after each WooCommerce update.
- Salient-specific visuals (price label inside Salient's price markup, the login/portal page typography) are worth a look after theme updates.

None of the above affects retail customers or retail checkout in any way. See `PLAN.md` for the file layout and hook map, `DECISIONS.md` for every judgement call, `QA.md` for the staging checklist, and `CHANGELOG.md` for what changed when.
