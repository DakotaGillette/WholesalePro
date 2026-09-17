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

Everything lives under **WooCommerce → Wholesale**:

| Tab | What's there |
|---|---|
| Applicants | Pending / Approved / Rejected applications with Approve and Reject (with an optional reason) actions, search, pagination. |
| Customers | Every wholesale customer: store, tier, override count, orders, last order, lifetime spend. |
| Products | Every product with wholesale pricing or flagged wholesale-only, for reference. |
| Pricing & Shipping | The Standard/Volume/Bulk ladder (thresholds and store-default prices), packs-per-display and displays-per-case defaults, and the wholesale shipping flat rate. |
| Tiers | Bronze/Silver/Gold/Platinum discount percentages (internal, never shown to customers). |
| Settings | Empty-price behaviour, retail coupons, free-shipping exclusion, application form source/ID, uninstall purge. |

Per-customer settings (wholesale flag, tier, per-customer price overrides) and the customer's submitted application are on their **user profile** (Users → Edit).

## Settings reference

| Setting | Option key | Default | What it does |
|---|---|---|---|
| Products with no wholesale price | `protech_wholesale_empty_price_behavior` | `hide` | What a wholesale customer sees for a product with no wholesale price. `hide` removes it from the wholesale shop/search/category views entirely (at the query level, so pagination and counts stay right). `fallback` shows the retail price with a "(retail only)" note. |
| Allow retail coupons for wholesale customers | `protech_wholesale_allow_retail_coupons` | `no` | When off, any coupon applied by a wholesale account is rejected. |
| Exclude wholesale orders from free shipping | `protech_wholesale_exclude_free_shipping` | `yes` | Keeps the retail free-shipping rule from applying to wholesale customers in zones where the wholesale method hasn't been added. |
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
- There is **no dollar-based order minimum**. The "Minimum order" fields on the Tiers tab and user profile are from an earlier design and are not enforced anywhere; they're labelled as such pending a decision to remove or re-enable them.

## The sticky tier bar

A bar fixed to the bottom of the viewport on every front-end page except checkout, for logged-in wholesale customers only: a message ("Add 3 more displays to unlock better pricing + free shipping"), the live cart subtotal and Display/Case count, and a track with markers for the Volume and Bulk thresholds. It updates without a page reload after any add-to-cart anywhere on the site, and is deliberately not dismissible. See `class-global-tier-bar.php`.

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
