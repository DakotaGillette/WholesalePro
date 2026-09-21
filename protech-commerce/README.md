# Protech Commerce

> Formerly "Protech Wholesale" (folder `protech-wholesale`, versions up to 1.6.3). Renamed in 2.0.0 because it now covers messaging for every customer, not only wholesale. Settings, roles, tables and hooks kept their `protech_wholesale_*` names, so nothing was migrated.

A WordPress/WooCommerce plugin for [Protech Sleeves](https://protechsleeves.com) that turns approved retailers into a self-service wholesale channel: they apply, get approved, log in, and see wholesale pricing automatically on every ordinary shop/product page — no separate order-taking page and no manual work on the store owner's side. A price table on each product and a sticky bar across the site show live progress toward better quantity-tier pricing and free shipping, and "Reorder" on any past order adds it straight back to the cart.

## Requirements

- WordPress 6.0+
- WooCommerce, active (the plugin will not run without it)
- PHP 8.1+
- Salient theme (ThemeNectar) is assumed but not required — the plugin works through standard WooCommerce hooks and degrades gracefully on any theme

## Install

1. Upload the `protech-wholesale` folder to `wp-content/plugins/` (or upload the zip through Plugins → Add New → Upload Plugin).
2. Activate **Protech Commerce** from the Plugins screen.
3. Activation automatically:
   - creates the `wholesale_pending` and `wholesale_customer` roles,
   - saves default settings (see below),
   - creates a `/wholesale` page containing the `[protech_wholesale_portal]` shortcode, if one doesn't already exist.
4. Go to **WooCommerce → Wholesale → Settings** and confirm **Application form source** / **Application form ID** match whatever powers `/wholesale-application` on your site (Fluent Forms, form #4, on the live site).
5. Add **Protech Wholesale Shipping** to each shipping zone under WooCommerce → Settings → Shipping (it is invisible to retail customers).
6. On each product you sell at wholesale, open the **Wholesale** product data tab and set a **Wholesale price** (per variation, for a variable product — use the Variations tab's "Set wholesale prices" bulk action to set every color at once). Products with no wholesale price are simply not offered at wholesale.

## Admin screens

Everything lives under **WooCommerce → Wholesale**. The menu item shows a count bubble while applications are waiting, the Plugins list row links straight to Applicants and Settings, and a notice at the top of the screen lists anything not set up yet (shipping method not in a zone, no priced product, no portal page, unknown form ID) until it is. The Help pull-down on the screen explains each tab.

| Tab | What's there |
|---|---|
| Applicants | Pending / Approved / Rejected applications with Approve and Reject (with an optional reason) actions, search, pagination. |
| Customers | Every wholesale customer: store, tier (change it here and Save), override count, orders, last order, lifetime spend, and whether they have had the welcome email (Send / Resend on the row). Add existing customers to wholesale, or send the welcome email to ticked rows. |
| Products | Every product priced or flagged for wholesale: price, Standard and Volume overrides, packs per display, flags. The same summary is a "Wholesale" column on Products → All Products. |
| Pricing & Shipping | Quantity pricing (the Base/Standard/Volume ladder), display and case defaults, and wholesale shipping: the flat rate, which zones have the method, and the retail free-shipping exclusion. |
| Tiers | Bronze/Silver/Gold/Platinum discount percentages (internal, never shown to customers). |
| Messaging | Automated and one-off emails/texts to wholesale customers through Brevo — Automations (rules), Compose (manual sends), Log, Compliance (SMS opt-in proof for Brevo), Settings. See "Messaging & automations" below. |
| Settings | Catalog (empty-price behaviour, where a wholesale login lands, coupons), Applications (form plugin, form ID, notification email), Updates (installed and latest release, check now), Uninstall. |

Per-customer price overrides, the wholesale flag and the customer's submitted application are on their **user profile** (Users → Edit); the tier is there too.

Pricing a product happens on the product's own **Wholesale** tab. A variable product has "Apply to all variations" fields there that write one price to every color on Update; one color can still be priced differently on the Variations tab.

## Settings reference

| Setting | Option key | Default | What it does |
|---|---|---|---|
| Products with no wholesale price | `protech_wholesale_empty_price_behavior` | `hide` | What a wholesale customer sees for a product with no wholesale price. `hide` removes it from the wholesale shop/search/category views entirely (at the query level, so pagination and counts stay right). `fallback` shows the retail price with a "(retail only)" note. |
| Allow retail coupons for wholesale customers | `protech_wholesale_allow_retail_coupons` | `no` | When off, any coupon applied by a wholesale account is rejected. |
| Retail free shipping (on Pricing & Shipping) | `protech_wholesale_exclude_free_shipping` | `yes` | Keeps the retail free-shipping rule from applying to wholesale customers in zones where the wholesale method hasn't been added. |
| Send new-application emails to | `protech_wholesale_notification_email` | empty (site admin email) | Where the "new wholesale application" email goes. |
| Application form source / form ID | `protech_wholesale_application_source` / `protech_wholesale_application_form_id` | `fluent_forms` / `4` | Which plugin and which exact form produce wholesale applications. Adapters exist for Gravity Forms, WPForms, Contact Form 7, and Fluent Forms, plus a native fallback form (`[protech_wholesale_application]`). |
| On uninstall | `protech_wholesale_purge_on_uninstall` | `no` | If checked, deleting the plugin removes its settings, roles, the auto-created `/wholesale` page (only if unmodified), and wholesale user meta. Product pricing meta and order flags are never removed. See `uninstall.php`. |
| Automations enabled (Messaging → Settings) | `protech_wholesale_msg_enabled` | `no` | Master switch for the daily automation job and order-status messages. Compose (manual sends) works either way. |
| Brevo API key (Messaging → Settings) | `protech_wholesale_msg_brevo_api_key` | empty (uses the Brevo plugin's own key) | Only needed if this plugin should use a different Brevo account/key than the Brevo WordPress plugin already connected to the site. |
| SMS sender / brand (Messaging → Settings) | `protech_wholesale_msg_sms_sender` / `protech_wholesale_msg_brand` | empty / site name | The Brevo-registered sender (a toll-free number in the US/Canada — alphanumeric senders aren't supported there) and the brand prefix put on every text. |
| Quiet hours (Messaging → Settings) | `protech_wholesale_msg_quiet_start` / `_quiet_end` | `20` / `10` | No marketing texts are sent in this window (site time). |
| Frequency cap (Messaging → Settings) | `protech_wholesale_msg_frequency_cap_days` | `7` | Fewest days between two automated marketing messages to the same customer; manual sends and order-update messages are exempt. |

## How pricing precedence works

For any product or variation, a wholesale customer's per-pack price is resolved in this order:

1. **Per-customer override** — set on their user profile with the product picker. Always wins, regardless of cart quantity.
2. **The quantity tier the cart has reached** (an un-named base tier, then Standard, then Volume) — see "Quantity-tier pricing and shipping" below. The base tier is the product's own "Wholesale price"; Standard/Volume are store defaults, overridable per product/variation.
3. **The customer's tier discount** (Silver/Gold/Platinum percentage, Bronze = none) — applied on top of #2.
4. **Not available at wholesale** — no "Wholesale price" set at all. Depending on the empty-price setting the product is hidden from wholesale views or shown at retail with a note.

Retail customers and guests never see a wholesale price under any circumstance — pricing filters only apply to logged-in users with the `wholesale_customer` role.

## Quantity-tier pricing and shipping

Wholesale sells sleeves by two units, both built on the "pack" WooCommerce tracks stock in: a **Display** (10 packs by default — "Packs per display", per product; a variation inherits its parent's) and a **Case** (8 Displays by default — "Displays per case"). Customers pick Display or Case and a quantity on the product page; it always submits a real pack count.

- **Ladder** (WooCommerce → Wholesale → Pricing & Shipping): $5.50/pack under 16 combined Displays (the base tier: the product's own price, deliberately un-named on the storefront so nobody aims for it), $5.00/pack at 16+ Displays (Standard), $4.50/pack at 16+ Cases (Volume). The slugs and meta keys keep their original `standard`/`volume`/`bulk` names; see `VolumePricing::get_tier_labels()`. Thresholds are **combined across the whole cart** (every product and color together). Each product page shows the customer their own three prices in a small table, with the cart's current tier marked, and the main price shows the retail MSRP crossed out next to the wholesale price with a "Save N%" chip.
- **Shipping**: a flat $19.95 below the Standard threshold, free at or above it — a real WooCommerce shipping method (`Wholesale Shipping`), only shown to wholesale customers whose cart holds wholesale-eligible items, and only once it's been added to a zone. When present it hides the zone's retail Flat rate / Free shipping methods (list filterable via `protech_wholesale_retail_shipping_methods`).
- Wholesale quantities must be multiples of the packs-per-display: enforced at add-to-cart (classic and Store API), on the Blocks cart's own quantity controls, and again at checkout.
- Cart/checkout lines carry a "Displays: N" annotation; it is saved on the order line item.
- There is **no dollar-based order minimum**; the fields from an earlier design were removed in 1.3.0.
- Display and case rules apply only to products that carry a wholesale price. A product without one (a sample pack sold at a plain price from a link, say) is bought on ordinary retail terms by everyone, one at a time, and counts toward nothing.

## The sticky tier bar

A Protech Blue dock floating at the bottom of the viewport (full width on phones) on every front-end page except checkout, for logged-in wholesale customers only: a tier chip and message ("Add 3 more displays to unlock free shipping and Standard pricing"), a track with a marker per tier, the live cart subtotal, Display/Case count and — once a tier is reached — what that tier is saving the order. It updates without a page reload after any add-to-cart anywhere on the site, previews where the quantity being dialled in on a product page would land, celebrates a newly reached tier once, and is deliberately not dismissible. All motion is disabled under `prefers-reduced-motion`. See `class-global-tier-bar.php`.

- The track is two segments (Standard marker at 40%), not one linear scale — see `VolumePricing::scale_percent()`.
- The per-pack prices on the Standard/Volume markers are the **store defaults** from the Pricing tab with the customer's tier discount applied. If most products carry their own Standard/Volume overrides, remove them with `add_filter( 'protech_wholesale_tier_bar_show_prices', '__return_false' );`.
- Every new state is re-broadcast as a `protech:tier-state` DOM event (`event.detail` is the state) for theme code that wants to react to it.
- The dock matches the width of the site header: a header that is a floating card (as on this site) is matched edge for edge, a full-width header on the content inside it. Point it at a different element with the `protech_wholesale_tier_bar_align_selector` filter (a CSS selector; return `''` for a centred 1180px dock).
- The room it reserves at the end of each page takes the color of whatever the page ends with, so it blends into the footer. If that guess is ever wrong, set it with the `protech_wholesale_tier_bar_spacer_color` filter (any CSS color).

## Other customer-facing pieces

- **Product page order control**: Display / Case cards, a −/+ stepper and a live read-back ("3 displays · 30 packs") replace the theme's pack stepper; the Add to cart button restates what it will add. Above the price table, a "How wholesale quantities work" legend (pack → display → case, drawn from the product's own composition, and "1 case = 8 displays. Mix and match your displays however you'd like"), from `templates/quantity-legend.php`. See `CaseRules::render_unit_selector()` and `assets/js/unit-selector.js`.
- **Add one display of every color**: right under the wholesale price table on a variable product's page, an outlined button adds one display of each color that has a wholesale price and is in stock, in one click, at whatever tier the cart then reaches. It is built from the product's live colors on every page load, so a color added later is included with nothing to set up, and an out-of-stock color gets a small red-marked "Out of stock: <name>" line, a crossed-out swatch and a button that says "each color in stock". It reuses the starter-kit engine with the product itself as the source (`StarterKit::is_every_color_source()`), so no kit product is needed. Template: `templates/add-every-color.php`.
- **Short description below Add to cart**: for a wholesale customer the product's short description (size, count, finish) prints below the add-to-cart button instead of above the pricing, so the page leads with how wholesale works and the buying controls. Everyone else keeps WooCommerce's order. `Plugin::move_short_description_below_add_to_cart()`.
- **Header cart badge** counts displays, not packs, for wholesale customers (it also drives the Blocks mini-cart count). Switch back with `add_filter( 'protech_wholesale_cart_count_in_displays', '__return_false' );`.
- **My Account**: a "Wholesale Partner" strip with the business name on every account page (amber "Application under review" for pending applicants), and a dashboard card with the cart's tier, progress and savings.
- **`/wholesale`**: a two-panel login page whose benefit list is built from the live thresholds (`protech_wholesale_portal_benefits` filter), and a status card for pending / rejected / retail-only visitors. An approved customer who logs in there (or on My Account) lands on the page set under Settings → "After a wholesale login, go to" (the flagship product on staging), or the shop when that is empty.
- All of it is styled in Protech Blue (`--protech-blue` in `assets/css/wholesale.css`); the theme's own buttons keep the theme's color.
- **Header banner** ("FREE SHIPPING WITH $30+ ORDERS"): Salient's own "Text To Display In Header" field already runs `do_shortcode()`, so wrapping its text in `[protech_header_notice]...[/protech_header_notice]` shows that text unchanged to everyone except a logged-in wholesale customer, who sees a wholesale line instead — by default "FREE SHIPPING ON WHOLESALE ORDERS OF 2+ CASES", generated from the live Standard threshold (converted to cases, rounded up) so it can't go stale. Pass `wholesale="…"` for fixed text, or use the `protech_wholesale_header_notice` filter. No theme edit. See `class-header-notice.php`.

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

## Welcome email for upgraded accounts

Customers who already had an ordinary account and are moved to wholesale never went through the application, so they never got the "approved" email. **WooCommerce → Wholesale → Customers** has a "Welcome to Protech Wholesale" email for them: how to log in (the wholesale page, the email they already use, a password reset link), the pack, display and case picture from the product page, and what each quantity level unlocks. Every number comes from the store settings.

- **Send it**: "Add existing customers to wholesale" has a "Send them the welcome email" box (ticked by default), or tick rows and press "Send welcome email", or use Send / Resend on a single row. The Welcome email column shows the date it went out.
- **Preview it**: the "Welcome email" box above the table sends a preview to any address, filled in with your own name and email.
- It goes out like every other message: through Brevo when connected, the WooCommerce mailer otherwise, and it appears in the message log. It never resets or creates a password.
- Override the layout by copying `templates/welcome-email.php` into your theme's `woocommerce/` folder, or change the text with the `protech_wholesale_welcome_email_body` and `protech_wholesale_welcome_email_subject` filters.

## Starter kits

A starter kit is a product page that adds **one display of every color of another product** to the cart in one click, instead of being sold itself. What lands in the cart is the real color variations, one line each, so stock is tracked per color, Reorder works on the order, and the price is whatever the pricing engine charges for that many displays: a 16-display kit reaches the Volume threshold, so it costs 160 packs at the Volume price with free shipping.

Set one up on any simple product's **Wholesale** tab:

1. Tick **Starter kit**.
2. **Kit is built from**: search for the variable product whose colors make up the kit (the flagship sleeves).
3. **Displays in a kit**: leave empty to use the Standard threshold (16). If there are fewer colors than this, the difference is made up with extra displays of the colors in the next field.
4. **Make up the difference with**: the filler colors, in order, e.g. Black then White. With 14 colors that gives one of each plus a second Black and a second White; once there are 16 colors the fillers are no longer used. Nothing to edit when a color is added.
5. Usually also tick **Wholesale only**. The kit's own prices and pack sizes are ignored, so they can stay empty.

A color that is out of stock is left out (the page says so) and the fillers cover the shortfall. The kit's page shows what it contains for that customer today, its total at the tier the cart will reach once it is added, a "how many kits" stepper, and one button. The header price and shop-grid price for the kit are the same live total.

## A plain, unlisted product for new vendors

The Vendor Starter Kit is sold from a link on the one-sheet to vendors who may not have a wholesale account yet, one line at a fixed price. Set it up as an ordinary simple product: Regular price 800; Catalog visibility **Hidden** (WooCommerce's own setting, under Publish, so it is out of the shop and search but the link works); on the Wholesale tab leave **Wholesale only** unticked, **Wholesale price** empty, **Starter kit** unticked and **Packs per display** empty. A guest sees a normal $800 product and can buy it; a logged-in wholesale customer sees the same and can buy one, with no display rule and no effect on their tier.

## Updating

New versions are published as GitHub releases (`DakotaGillette/WholesalePro`, public). The release job in `.github/workflows/ci.yml` runs on every push to `main` that passes the tests: if the version in the plugin header has no tag yet, it builds `protech-wholesale.zip`, takes that version's section of `CHANGELOG.md` as the notes and creates release `v<version>`. WordPress on each site checks that release twice a day (the plugin's `Update URI` header routes the check to `includes/class-updater.php`) and offers it in the Plugins list like any other update, with View details and one-click Update. "Check for updates" under the plugin's row, or "Check now" on Settings → Updates, asks GitHub immediately.

To ship a release: bump `Version:` in `protech-commerce.php`, add the section to `CHANGELOG.md`, merge to `main`.

## "Wholesale only" products

Check **Wholesale only** on the product's **Wholesale** tab (product level — applies to all of a variable product's colors). Retail visitors then never see it in the shop, search, categories, sitemaps, or the public Store API product listing; a direct link shows an "available to approved wholesale accounts only" message with an apply link in place of the price, and it can't be added to cart. Leave WooCommerce's own "Catalog visibility" on its default: checking Wholesale only forces it back to visible on save, because the native "Hidden" setting hides a product from wholesale customers too.

## Messaging & automations

**WooCommerce → Wholesale → Messaging** sends email and SMS to wholesale customers, through Brevo (email falls back to the site's own WooCommerce mailer if Brevo isn't connected; SMS has no fallback). Nothing is ever sent from an admin or checkout request — everything queues through the message log and delivers via Action Scheduler.

- **Automations**: four rule types — *Reorder reminder* (X days after a customer's last order, if they haven't ordered since), *Win-back* (no order in N days, repeats up to a limit), *First-order nudge* (X days after approval with no order yet), and *Order status* (an order reaching a chosen status, e.g. "completed" → a shipping email/text, with a delay so tracking numbers catch up). A day-based rule only fires inside a 7-day window starting on its trigger day — turning one on never reaches back into years of history, and a missed daily run is absorbed rather than double-sent. A rule never sends the same message twice for the same order/approval (`Automations::anchor_for()` + the message log's unique key). **Preview recipients** runs the same logic as a dry run before you commit to enabling a rule.
- **Compose**: a one-off email or text to a chosen audience (all customers, a tier, no order in N days, never ordered, said they'd like texts but haven't opted in, or specific customers ticked on the Customers tab). "Send preview" delivers immediately to any address (or phone number) you type, or to you if you leave it blank, so you can check it before sending to customers. The same box is on every automation rule.
- **Log**: every message ever queued, its status (queued/sending/sent/failed/skipped) and, for a skip, why (no consent, unsubscribed, frequency cap, …).
- **Compliance**: see the next section.
- **Merge tags**: `{first_name}`, `{store_name}`, `{last_order_number}`, `{last_order_total}`, `{last_order_url}`, `{days_since_last_order}`, `{shop_url}`, `{account_url}`, `{unsubscribe_url}`, `{brand}`, and, for the order-status trigger only, `{order_number}`, `{order_status}`, `{tracking_number}`, `{tracking_url}`, `{tracking_block}` (reads the "Advanced Shipment Tracking" plugin when it's active; empty otherwise).
- **Cron matters.** The daily automation job and delayed order-status/SMS deliveries run on Action Scheduler, which is driven by WP-Cron — and WP-Cron only fires on an actual page hit. A cached storefront (Breeze, on this site) can mean overnight cron doesn't run until the first uncached visit. If automations seem to run late, add a real server cron hitting `wp-cron.php` (or `wp action-scheduler run`) every few minutes; check WooCommerce → Status → Scheduled Actions either way.
- Brevo's own WooCommerce plugin can run its own marketing automations — decide whether wholesale contacts should be in both, or just this plugin's.

## SMS compliance (Brevo toll-free number verification)

Texting from a US/Canada number through Brevo requires registering a toll-free number, which Brevo verifies against: the site working, a privacy policy/terms that cover SMS, a description of what's sent, and **proof customers opted in**. Marketing texts (offers, reorder reminders) require an explicit opt-in; order-update texts are a separate, narrower consent. Consent is captured — with the exact wording shown, a timestamp, IP, and source — on the wholesale application form (add the two checkboxes with the wording from Messaging → Compliance to your form if it doesn't have them yet), self-service under My Account → Notifications, or by an admin on the customer's profile (a note is required). Brevo's own STOP handling on the toll-free number is honored before every marketing text.

**Before submitting to Brevo:** open Messaging → Compliance. It shows the exact opt-in wording and where it appears, a sample of every message type configured, a **Download consent records (CSV)** button (Brevo's proof of opt-in), and ready-to-paste privacy-policy/terms text (the "mobile information will not be shared…" line, frequency/rates/STOP/HELP). The setup notice on the Applicants tab flags it if Brevo isn't connected or the privacy policy doesn't seem to mention SMS.

## Development

```sh
cd protech-wholesale
composer install                   # phpunit, WPCS, PHPStan (dev only; never deployed)
composer lint                      # phpcs (advisory for now)
composer analyse                   # phpstan
cd .. && npx wp-env start          # WordPress + WooCommerce + this plugin (Docker)
npx wp-env run tests-cli --env-cwd=wp-content/plugins/protech-commerce vendor/bin/phpunit
```

GitHub Actions (`.github/workflows/ci.yml`) runs the same on every push. `bin/deploy.sh` rsyncs the plugin to Cloudways staging (excluding tests and tooling) and purges the object and Breeze page caches; see `.env.example`.

## Known limitations

- Gravity Forms and WPForms adapters are untested against real installs (neither plugin is on this site); Fluent Forms is verified live.
- No tax-exemption or resale-certificate handling: wholesale orders are taxed like retail.
- The variations bulk actions follow WooCommerce's documented custom-bulk-action contract but should be exercised once on staging after each WooCommerce update.
- Salient-specific visuals (price label inside Salient's price markup, the login/portal page typography) are worth a look after theme updates.
- Content for automations and Compose is written in this admin screen only — there's no Brevo-designed-template picker in this build.
- Quiet hours and the daily automation hour are site time, not each recipient's own time zone.
- Sending an SMS from any rule or Compose will fail until a Brevo-registered sender (a US/Canada toll-free number) is set on Messaging → Settings; the failure reads clearly in the Log, but it's expected until then.

None of the above affects retail customers or retail checkout in any way. See `PLAN.md` for the file layout and hook map, `DECISIONS.md` for every judgement call, `QA.md` for the staging checklist, and `CHANGELOG.md` for what changed when.
