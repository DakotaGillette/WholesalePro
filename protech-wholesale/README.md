# Protech Wholesale

A WordPress/WooCommerce plugin for [Protech Sleeves](https://protechsleeves.com) that turns approved retailers into a self-service wholesale channel: they apply, get approved, log in, and see wholesale pricing automatically on every ordinary shop/product page — no separate order-taking page, tab, or manual work on the store owner's side. A sticky bar follows wholesale customers around the site showing live progress toward better quantity-tier pricing and free shipping, and "Reorder" on any past order adds it straight back to the cart.

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
4. Go to **WooCommerce → Wholesale → Settings** and review the defaults, then set **Application form source** to match whatever actually powers `/wholesale-application` on your site (see "Known limitations").
5. Set a **Wholesale price** (and optionally non-default **Packs per display** / **Displays per case** / per-tier price overrides) on each product/variation you want available at wholesale. Products with no wholesale price are simply not offered at wholesale (see "Pricing precedence" below).

## Settings reference

All settings live under **WooCommerce → Wholesale → Settings**.

| Setting | Option key | Default | What it does |
|---|---|---|---|
| Products with no wholesale price | `protech_wholesale_empty_price_behavior` | `hide` | What a wholesale customer sees for a product/variation with no group or per-customer wholesale price. `hide` removes it from wholesale shop/catalog views entirely. `fallback` shows the normal retail price with a "(retail only — not available at wholesale)" note if the wholesale user opens that product's page directly. |
| Allow retail coupons for wholesale customers | `protech_wholesale_allow_retail_coupons` | `no` (unchecked) | When off, any coupon applied by a wholesale account is rejected with "This coupon is not valid for wholesale accounts." When on, wholesale customers can use retail coupons like anyone else. |
| Exclude wholesale orders from free shipping | `protech_wholesale_exclude_free_shipping` | `yes` (checked) | Keeps the retail free-shipping-over-$30 rule from applying to wholesale customers, so they always pay real shipping rates. |
| Application form source + exact form ID | `protech_wholesale_application_source` / `protech_wholesale_application_form_id` | `native` / empty | Which system produces new wholesale applications: the plugin's own native form, or an adapter for Gravity Forms, WPForms, Contact Form 7, or Fluent Forms — and, once you've picked one of those, the exact form ID to listen to (so a *different* form built with the same plugin, like a newsletter signup, doesn't also get funneled into the applicant queue). Leaving the ID empty falls back to "any form from that plugin." See "Known limitations." |
| On uninstall | `protech_wholesale_purge_on_uninstall` | `no` (unchecked) | If left unchecked, deleting the plugin keeps all wholesale data (roles, prices, applications, overrides) untouched. If checked, deleting the plugin removes plugin settings, the wholesale roles, the auto-created `/wholesale` page (only if unmodified), and wholesale-related user meta. See `uninstall.php` for exactly what is and is not removed. |

The global minimum-order subtotal (now vestigial — see "Case size and shipping" below), the Standard/Volume/Bulk price ladder, case composition defaults, and the wholesale shipping flat rate all live under **WooCommerce → Wholesale → Tiers** and **→ Pricing** instead of Settings — see those tabs directly, or `DECISIONS.md`.

## How pricing precedence works

For any product or variation, a wholesale customer's price is resolved in this order:

1. **Per-customer override** — a price set specifically for that one customer, on their user profile. Always wins if present, regardless of cart quantity.
2. **The Standard/Volume/Bulk quantity tier the cart has reached** — see "Quantity-tier pricing and shipping" below. Volume/Bulk can be overridden per product; Standard is always that product's own "Wholesale price".
3. **The customer's tier discount** (Bronze/Silver/Gold/Platinum, set on their user profile, never shown to the customer) — a percentage off whatever #2 resolved to. Bronze has no discount, so it's a no-op for most accounts.
4. **Not available at wholesale** — if the product has no "Wholesale price" set at all, it simply isn't sold at wholesale, regardless of tiers. Depending on the "Products with no wholesale price" setting, it is either hidden from wholesale views (default) or shown at the normal retail price with a note.

Retail customers and guests never see a wholesale price under any circumstance — pricing filters only apply to logged-in users with the `wholesale_customer` role.

## How to approve a customer

**Through the application flow:**

1. A prospective retailer submits the form at `/wholesale-application` (whichever form is configured under Settings → Application form source).
2. A WordPress user is created automatically with the `wholesale_pending` role, and all submitted fields (store name, business type, address, etc.) are saved as user meta. The applicant gets a "we received your application, 1–3 business days" email, and the store admin gets a notification email with a link to review it.
3. The admin opens **WooCommerce → Wholesale** (the "Applicants" tab) and sees the applicant with a Pending status, along with Approve/Reject actions.
4. Clicking **Approve** switches the user's role to `wholesale_customer` and emails them a password set/reset link. Clicking **Reject** lets the admin add an optional reason, which is included in a polite decline email; the user's account is left as-is (still `wholesale_pending`, no wholesale access).
5. The approved retailer follows the link in their email, sets a password, and logs in. They are redirected straight to **the shop**, where wholesale prices are now visible on every product.

**Manually flagging an existing customer:**

An admin can skip the application queue entirely by opening any user's profile (**Users → [pick a user] → Edit**) and checking **"Flag this customer as an approved wholesale customer"** under the "Protech Wholesale" section. Saving the profile switches their role to `wholesale_customer` immediately. Unchecking it switches them back to `customer`.

## How to add a per-customer price

On the same user-profile screen (Users → edit a customer), under **Protech Wholesale → Per-customer price overrides**, click **"+ Add price override"** and enter:

- **Product/variation ID** — the numeric ID of the specific product or variation. For a simple product this is the product ID; for one color of the sleeves this is the *variation* ID, not the parent product's ID. You can find either by opening that product/variation for editing in wp-admin and looking at the URL's `post=` parameter (for a variation, expand it in the Variations tab — WooCommerce shows the variation ID next to its SKU) or by hovering the row in Products list.
- **Price per pack** — the price this one customer pays, in the same "per pack" unit as the group wholesale price.

This override applies only to that one customer and only for that one product/variation — it beats the group wholesale price for them, but doesn't change what any other wholesale customer pays. Leaving the repeater empty means the customer simply pays the group price (or sees the item as unavailable, if no group price is set either).

## Quantity-tier pricing and shipping

Wholesale sells sleeves by two units, both built on top of the same "pack" WooCommerce already tracks stock in: a **Display** (10 packs of one color by default — "Packs per display" per product/variation) and a **Case** (8 Displays by default — "Displays per case"). Customers still order in packs the normal WooCommerce way (quantity field, Add to Cart); Display/Case are pricing-tier units, not a separate ordering unit.

- **Standard/Volume/Bulk price ladder** (WooCommerce → Wholesale → Pricing): $5.50/pack under 16 combined Displays in the cart (Standard, the product's own "Wholesale price"), $5.00/pack at 16+ Displays (Volume), $4.50/pack at 16+ Cases (Bulk) — all editable store-wide, with an optional per-product override for the Volume/Bulk price. Thresholds are **combined across the whole cart** (every wholesale-eligible product/color together), not per line item, and shown live on the sticky tier bar that follows a wholesale customer around the site (see below).
- **Shipping**: a flat $19.95 below the 16-Display threshold, free at or above it — the same real WooCommerce shipping method (`Wholesale Shipping`) shown at checkout, available only to approved wholesale customers, and only once it's been added to a shipping zone (WooCommerce → Settings → Shipping → pick a zone → Add shipping method).
- Any wholesale add-to-cart quantity that isn't an exact multiple of a product's Packs-per-display is rejected with a message asking for a multiple of that size.
- Cart/checkout still shows a "Displays: 3" annotation on each wholesale line, alongside the real pack quantity WooCommerce tracks for stock.
- There is **no dollar-based order minimum** to check out — an older $800-subtotal checkout block was removed in favor of this quantity-tier system. (A vestigial per-tier "Minimum order" field still exists on WooCommerce → Wholesale → Tiers from before that change; it no longer does anything — see `DECISIONS.md`.)

Retail customers are entirely unaffected by any of the above — these rules only ever apply to users with the `wholesale_customer` role.

## The sticky tier bar

A bar fixed to the bottom of the viewport, shown to logged-in wholesale customers on every front-end page (product pages, the shop grid, the cart — not just one dedicated screen), reflecting their actual current cart: a message ("Add 3 more displays to unlock better pricing + free shipping"), the live cart subtotal and Display/Case count, and a track with markers for the Volume and Bulk thresholds. It updates without a page reload after any Add to Cart action anywhere on the site, dismisses per browsing session (a real cart change brings it back), and is never shown to retail customers or guests. See `class-global-tier-bar.php`.

## Reorder

"Reorder" appears on **My Account → Orders**, on each order's detail page, and as a shortcut on the account dashboard for the customer's most recent order. Clicking it adds every still-available line from that order straight to the current cart (skipping — with a visible note — any line that's out of stock, no longer sold, or no longer available at wholesale) and sends the customer to `/cart/` to review pricing and check out. There's no intermediate review/prefill page.

## "Wholesale only" products

Checking **"Wholesale only"** in a product's pricing panel hides it from the retail shop, search, and category pages entirely — a retail visitor who opens its direct URL sees an "available to approved wholesale accounts only" message instead of a price, with no way to add it to cart. A wholesale customer sees it normally everywhere, including the shop grid.

This is the **only** visibility control that should ever be used for a wholesale-only product — leave WooCommerce's own native "Catalog visibility" dropdown (further down the same panel) on its default "Shop and search results". Setting that native dropdown to "Hidden" excludes the product from every query for *everyone*, wholesale customers included, and this plugin can't override that (see `DECISIONS.md` for the real instance of this that shipped hidden from wholesale accounts until caught and fixed). Checking "Wholesale only" now automatically corrects that native setting back to visible on save, so this shouldn't be able to recur — but it's worth knowing why, if a wholesale-only product ever seems to vanish for wholesale accounts too.

## Known limitations

Built without live access to the Protech Sleeves staging site, so a few items are shipped as best-effort, easy-to-adjust defaults rather than confirmed facts. See `DECISIONS.md` for full detail; in short:

- **Which form plugin renders `/wholesale-application` was never confirmed against the live site.** The plugin ships adapters for Gravity Forms, WPForms, Contact Form 7, and Fluent Forms, plus a native fallback form (`[protech_wholesale_application]`), gated by the "Application form source" setting so only one is ever active. Confirm which plugin (if any) is actually installed and set the matching option; if `/wholesale-application` turns out to be a "dumb mailer" form with no integration hooks, leave the setting on "Native form" and drop the native shortcode into that page instead.
- ~~Classic vs. WooCommerce Blocks cart/checkout was never confirmed either.~~ **Confirmed on staging**: both the Cart and Checkout pages are WooCommerce Blocks, so the Store API hooks (`woocommerce_store_api_cart_errors`) are the ones that actually matter; the classic hooks stay in place as a harmless no-op. See `DECISIONS.md`.
- **No tax-exemption or resale-certificate collection UI.** Wholesale orders are taxed exactly like retail. A filter (`protech_wholesale_is_tax_exempt`, defaulting to `false`) exists in `includes/class-pricing.php` for a future per-customer exemption, but nothing calls it yet and there is no certificate upload flow.
- **The "set all variations to $___" bulk price helper's JS hasn't been checked against a live WooCommerce admin screen.** It intercepts WooCommerce's Variations bulk-action dropdown to prompt for a price. If a future WooCommerce version changes how that dropdown forwards its typed value, the failure mode is safe — the button does nothing rather than corrupting prices — but it should be exercised once on staging.
- **A few Salient-theme-specific visual spots need a look:** the product price HTML markup inside Salient's product loop/single templates (the plugin only appends a "Wholesale price" label via a filter — if Salient's markup swallows appended text, it may need a template-specific tweak), and Salient's own quantity-stepper JS on the single product page (it needs to respect the `step`/`min` values the plugin sets).

None of the above affects retail customers or retail checkout in any way.

## File layout

See `PLAN.md` at the repository root for the full file layout and hook map (which filter/action does what, organized by requirement).
