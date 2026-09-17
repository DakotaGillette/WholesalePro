# Protech Wholesale

A WordPress/WooCommerce plugin for [Protech Sleeves](https://protechsleeves.com) that turns approved retailers into a self-service wholesale channel: they apply, get approved, log in, see wholesale pricing automatically, and place a reorder from a single "Quick Order" page in under a minute — without any manual order-taking on the store owner's side.

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
5. Set a **Wholesale price** (and optionally a non-default **Case size**) on each product/variation you want available at wholesale. Products with no wholesale price are simply not offered at wholesale (see "Pricing precedence" below).

## Settings reference

All settings live under **WooCommerce → Wholesale → Settings**.

| Setting | Option key | Default | What it does |
|---|---|---|---|
| Minimum wholesale order subtotal | `protech_wholesale_min_order` | `800` | The subtotal (in your store currency) a wholesale cart must reach before checkout is allowed. Overridable per customer on their user profile. |
| Products with no wholesale price | `protech_wholesale_empty_price_behavior` | `hide` | What a wholesale customer sees for a product/variation with no group or per-customer wholesale price. `hide` removes it from the Quick Order form and wholesale shop/catalog views entirely. `fallback` shows the normal retail price with a "(retail only — not available at wholesale)" note if the wholesale user opens that product's page directly. |
| Allow retail coupons for wholesale customers | `protech_wholesale_allow_retail_coupons` | `no` (unchecked) | When off, any coupon applied by a wholesale account is rejected with "This coupon is not valid for wholesale accounts." When on, wholesale customers can use retail coupons like anyone else. |
| Exclude wholesale orders from free shipping | `protech_wholesale_exclude_free_shipping` | `yes` (checked) | Keeps the retail free-shipping-over-$30 rule from applying to wholesale customers, so they always pay real shipping rates. |
| Application form source | `protech_wholesale_application_source` | `native` | Which system produces new wholesale applications: the plugin's own native form, or an adapter for Gravity Forms, WPForms, Contact Form 7, or Fluent Forms. Only one source is ever active. See "Known limitations." |
| On uninstall | `protech_wholesale_purge_on_uninstall` | `no` (unchecked) | If left unchecked, deleting the plugin keeps all wholesale data (roles, prices, applications, overrides) untouched. If checked, deleting the plugin removes plugin settings, the wholesale roles, the auto-created `/wholesale` page (only if unmodified), and wholesale-related user meta. See `uninstall.php` for exactly what is and is not removed. |

## How pricing precedence works

For any product or variation, a wholesale customer's price is resolved in this order:

1. **Per-customer override** — a price set specifically for that one customer, on their user profile. Always wins if present.
2. **Group price** — the "Wholesale price" set on the product or variation itself, in the WooCommerce product data panel. Applies to every wholesale customer who doesn't have an override for that item.
3. **Not available at wholesale** — if neither of the above is set, that product/variation simply isn't sold at wholesale. Depending on the "Products with no wholesale price" setting, it is either hidden from wholesale views (default) or shown at the normal retail price with a note.

Retail customers and guests never see a wholesale price under any circumstance — pricing filters only apply to logged-in users with the `wholesale_customer` role.

## How to approve a customer

**Through the application flow:**

1. A prospective retailer submits the form at `/wholesale-application` (whichever form is configured under Settings → Application form source).
2. A WordPress user is created automatically with the `wholesale_pending` role, and all submitted fields (store name, business type, address, etc.) are saved as user meta. The applicant gets a "we received your application, 1–3 business days" email, and the store admin gets a notification email with a link to review it.
3. The admin opens **WooCommerce → Wholesale** (the "Applicants" tab) and sees the applicant with a Pending status, along with Approve/Reject actions.
4. Clicking **Approve** switches the user's role to `wholesale_customer` and emails them a password set/reset link. Clicking **Reject** lets the admin add an optional reason, which is included in a polite decline email; the user's account is left as-is (still `wholesale_pending`, no wholesale access).
5. The approved retailer follows the link in their email, sets a password, and logs in. They are redirected straight to **My Account → Quick Order**, where wholesale prices are now visible.

**Manually flagging an existing customer:**

An admin can skip the application queue entirely by opening any user's profile (**Users → [pick a user] → Edit**) and checking **"Flag this customer as an approved wholesale customer"** under the "Protech Wholesale" section. Saving the profile switches their role to `wholesale_customer` immediately. Unchecking it switches them back to `customer`.

## How to add a per-customer price

On the same user-profile screen (Users → edit a customer), under **Protech Wholesale → Per-customer price overrides**, click **"+ Add price override"** and enter:

- **Product/variation ID** — the numeric ID of the specific product or variation. For a simple product this is the product ID; for one color of the sleeves this is the *variation* ID, not the parent product's ID. You can find either by opening that product/variation for editing in wp-admin and looking at the URL's `post=` parameter (for a variation, expand it in the Variations tab — WooCommerce shows the variation ID next to its SKU) or by hovering the row in Products list.
- **Price per pack** — the price this one customer pays, in the same "per pack" unit as the group wholesale price.

This override applies only to that one customer and only for that one product/variation — it beats the group wholesale price for them, but doesn't change what any other wholesale customer pays. Leaving the repeater empty means the customer simply pays the group price (or sees the item as unavailable, if no group price is set either).

## Case size and minimum order

Wholesale sells sleeves by the **display case**, not the individual pack. By default, one case = **10 packs** of a single color; this is configurable per product/variation via the "Case size (packs)" field in the product data panel.

- On the **Quick Order** form, quantities are entered in **cases** (labeled "Cases") — entering `3` for a color orders 3 cases (30 packs) of that color.
- In the **cart and checkout**, WooCommerce still tracks the real pack quantity (so stock levels stay accurate), but each wholesale line item shows a "Cases" annotation (e.g. "Cases: 3") alongside the pack quantity.
- Any wholesale add-to-cart quantity that isn't an exact multiple of the case size is rejected with a message asking for a multiple of that case size.
- Wholesale carts must reach a **minimum order subtotal** — **$800 by default** — before checkout is allowed. A cart below the minimum shows "Add [amount] more to reach your [minimum] wholesale order minimum" and blocks checkout, both in the classic cart/checkout flow and via the WooCommerce Blocks Store API.
- The $800 minimum can be overridden for an individual customer via the **"Minimum order override"** field on their user profile (e.g. a newer, smaller account approved at $400). Leaving that field blank uses the global setting.

Retail customers are entirely unaffected by case-size or minimum-order rules — these only ever apply to users with the `wholesale_customer` role.

## Known limitations

Built without live access to the Protech Sleeves staging site, so a few items are shipped as best-effort, easy-to-adjust defaults rather than confirmed facts. See `DECISIONS.md` for full detail; in short:

- **Which form plugin renders `/wholesale-application` was never confirmed against the live site.** The plugin ships adapters for Gravity Forms, WPForms, Contact Form 7, and Fluent Forms, plus a native fallback form (`[protech_wholesale_application]`), gated by the "Application form source" setting so only one is ever active. Confirm which plugin (if any) is actually installed and set the matching option; if `/wholesale-application` turns out to be a "dumb mailer" form with no integration hooks, leave the setting on "Native form" and drop the native shortcode into that page instead.
- **Classic vs. WooCommerce Blocks cart/checkout was never confirmed either.** Both are hooked defensively (classic `woocommerce_check_cart_items`/`woocommerce_checkout_process` and the Store API's `woocommerce_store_api_cart_errors`), so case-size and minimum-order enforcement should work under either, but only one path has actually been exercised against a real cart/checkout screen.
- **No tax-exemption or resale-certificate collection UI.** Wholesale orders are taxed exactly like retail. A filter (`protech_wholesale_is_tax_exempt`, defaulting to `false`) exists in `includes/class-pricing.php` for a future per-customer exemption, but nothing calls it yet and there is no certificate upload flow.
- **The "set all variations to $___" bulk price helper's JS hasn't been checked against a live WooCommerce admin screen.** It intercepts WooCommerce's Variations bulk-action dropdown to prompt for a price. If a future WooCommerce version changes how that dropdown forwards its typed value, the failure mode is safe — the button does nothing rather than corrupting prices — but it should be exercised once on staging.
- **A few Salient-theme-specific visual spots need a look:** the product price HTML markup inside Salient's product loop/single templates (the plugin only appends a "Wholesale price" label via a filter — if Salient's markup swallows appended text, it may need a template-specific tweak), Salient's own quantity-stepper JS on the single product page (it needs to respect the `step`/`min` values the plugin sets), and the "Quick Order" My Account nav tab's styling.

None of the above affects retail customers or retail checkout in any way.

## File layout

See `PLAN.md` at the repository root for the full file layout and hook map (which filter/action does what, organized by requirement).
