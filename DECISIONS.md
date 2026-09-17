# DECISIONS.md — for the owner to confirm

Per the master prompt: "when a requirement is ambiguous, pick the option
that is simplest to reverse, implement it, and list the decision here."
Nothing below blocks using the plugin; each is a default that's easy to
change later (a setting, a constant, or a small code change).

## Blocked-on-staging-access items (please confirm)

The build environment had no real Cloudways SSH credentials (the task's
`.env` block is a template, and none of the placeholder values were
filled in), so the two "verify on staging" facts in the master prompt
could not actually be checked against the live site:

1. **Form plugin behind `/wholesale-application`.** Shipped: adapters for
   Gravity Forms, WPForms, Contact Form 7, and Fluent Forms (all wired to
   fire `protech_wholesale_application_submitted`), plus a native
   fallback shortcode `[protech_wholesale_application]` with the same
   field set described in the master prompt, honeypot + nonce protected.
   **Action needed:** run `wp plugin list` on staging (or check
   wp-admin → Plugins) and set **WooCommerce → Wholesale → Settings →
   Application form source** to match. If it's a form plugin with no
   hooks (a "dumb mailer"), leave the setting on "Native form" and swap
   the shortcode into the `/wholesale-application` page content.
2. **Classic vs. Blocks cart/checkout.** Shipped: both paths are hooked
   (see `PLAN.md` hook map) so case/minimum enforcement works either way.
   No further action needed unless a future Woo version changes the
   Store API cart-extension shape.

This is **not** a retail-behavior change — nothing here alters checkout
for retail customers regardless of which branch ends up firing.

## Simplest-to-reverse defaults chosen

| Decision | Default chosen | Where to change |
|---|---|---|
| Empty wholesale price = hide vs. fallback to retail | **Hide** from wholesale product lists/order form; if a wholesale user opens the product URL directly, retail price shows with a "retail only" note | Settings → "Products with no wholesale price" |
| Wholesale users + retail coupons | **Disabled** | Settings → "Allow retail coupons for wholesale customers" |
| Wholesale tax handling | Charged exactly like retail; a documented filter (`protech_wholesale_is_tax_exempt`, default `false`) exists for a future per-customer exempt flag, but no cert upload/collection UI was built (out of scope per master prompt) | `includes/class-pricing.php` |
| Free shipping threshold exclusion | **On** by default (wholesale orders never qualify for the retail free-shipping rule) | Settings → "Exclude wholesale orders from free shipping" |
| Case size default | 10 packs/case, editable per product/variation | Product data panel |
| Global order minimum | $800 subtotal, editable globally and per customer | Settings + user profile field |
| Per-customer price override storage | Single repeater meta field on the user profile (`_protech_price_overrides`, `variation_id => price`), not a separate CPT/table — matches "keep simple" instruction in R2 | `class-approval.php` (profile fields) |
| Application → pending user role | New role `wholesale_pending` (not a meta flag on `customer`) so existing role-based plugins/reports naturally exclude pending applicants | `class-roles.php` |
| Reorder + now-out-of-stock variation | Skip that line, show an inline note, prefill everything else | `class-reorder.php` |
| Quick Order tab vs. rendering portal content inline | Portal page (`/wholesale`) **redirects** approved users to My Account → Quick Order rather than duplicating the grid inline, to avoid keeping two copies of the same markup in sync and to inherit Salient's My Account chrome for free | `class-portal.php` |
| Uninstall data handling | Kept by default; "purge on uninstall" is an opt-in setting | `uninstall.php` |

## No unavoidable retail-side changes

Retail behavior is untouched: retail pricing filters, retail coupon
validation, retail shipping rules, and retail checkout fields all early-
return unchanged for any user who isn't `wholesale_customer`/
`wholesale_pending`. If staging QA finds a spot where this isn't true,
treat it as a bug, not an intentional change — see `QA.md`.

## Salient-specific items still needing a staging look

Flagged in the master prompt as needing verification once on staging
(cannot be checked without the live theme):

- Product price HTML markup inside Salient's product loop/single templates
  (the plugin only adds a filter-based "Wholesale price" label; if Salient
  wraps price HTML in a way that swallows appended markup, the label may
  need a template-specific tweak).
- Quantity input markup/JS on Salient's single product page (Salient may
  have its own qty stepper JS that needs to respect `step`/`min` set by
  `woocommerce_quantity_input_args`).
- My Account nav styling for the new "Quick Order" tab.
- The "set all variations to $___" bulk price helper (`assets/js/admin.js`)
  intercepts WooCommerce's variations bulk-action dropdown to prompt for
  a price, since WooCommerce's own JS only does this natively for its
  built-in bulk actions. This couldn't be tested against a live
  WooCommerce admin screen; the failure mode is safe either way —
  `ProductFields::handle_bulk_edit()` refuses to change any prices when
  no value was submitted, so a JS mismatch means the button quietly does
  nothing rather than corrupting data. Confirm on staging and adjust the
  interception in `admin.js` if needed.
