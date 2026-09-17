# DECISIONS.md — for the owner to confirm

Per the master prompt: "when a requirement is ambiguous, pick the option
that is simplest to reverse, implement it, and list the decision here."
Nothing below blocks using the plugin; each is a default that's easy to
change later (a setting, a constant, or a small code change).

## Verified against staging (2026-09-17)

Once staging access was available, both previously-open items were
confirmed directly against `wordpress-1394472-6677194.cloudwaysapps.com`:

1. **Form plugin behind `/wholesale-application`**: **Fluent Forms**
   (active plugin `fluentform/fluentform.php`), rendering form #4 on that
   page. `Settings::get_defaults()` now defaults
   `OPT_APPLICATION_SOURCE` to `ApplicationForm::SOURCE_FLUENT_FORMS`
   instead of "native" to match.

   The live form's actual field labels are full sentences, not the short
   guesses the adapter originally shipped with — e.g. "Business Phone
   Number", "Position / Title", "Is your business a 'play store' Local
   game store (LGS) that hosts TCG events, tournaments, or play space?".
   `ApplicationForm::map_labeled_values()` was rewritten from exact-label
   matching to substring matching, with synonym lists updated against the
   real copy, because several fields (phone, sales channels, TCGs
   carried, hosts-events, estimated spend) would otherwise never have
   matched. Two more real-data bugs were fixed at the same time:
   - The live form's Name and Address questions are Fluent Forms
     composite fields, which submit as PHP arrays. Casting straight to
     string would have saved the literal text `"Array"` into the
     applicant's user meta — `flatten_value()` now joins composite
     sub-values into a readable string first (applied to all four
     adapters, not just Fluent Forms, since Gravity Forms/WPForms have
     the same composite-field shape).
   - The live "hosts events" question is a radio button with full-
     sentence options ("Yes – We are a brick-and-mortar LGS...", "No – We
     are primarily online-only..."), not a plain checkbox. The original
     `hosts_events` boolean coercion only recognized an exact `'no'`
     value; it now checks for a *leading* "no" so the real "No – ..."
     option is correctly read as declining.

   Gravity Forms' entry model stores composite-field (Name/Address)
   sub-inputs under dotted keys (`"3.1"`, `"3.2"`, ...) rather than a
   nested array under the parent field ID — `handle_gravity_forms()`
   wasn't corrected for this, since Gravity Forms isn't installed on this
   site and there was nothing live to verify it against. Revisit if a
   site using that adapter ever comes up.

2. **Classic vs. Blocks cart/checkout**: **both the Cart (page 838) and
   Checkout (page 839) pages are WooCommerce Blocks**, not the classic
   shortcodes, on WooCommerce 11.1.0. The Store API hooks in
   `class-case-rules.php` (`woocommerce_store_api_cart_errors`) are
   therefore load-bearing, not just a defensive fallback — confirmed
   those are still the correct hook names for this WooCommerce version.
   The classic-cart hooks stay in place as a harmless no-op in case the
   store ever reverts a page to the shortcode.

3. **HPOS**: confirmed **enabled** ("High-performance order storage")
   under WooCommerce → Settings → Advanced → Features — the
   `manage_woocommerce_page_wc-orders_*` hooks in `class-orders-admin.php`
   are the ones that matter on this site; the legacy `shop_order`
   post-type hooks are the no-op fallback here.

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
