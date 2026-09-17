# DECISIONS.md — for the owner to confirm

Per the master prompt: "when a requirement is ambiguous, pick the option
that is simplest to reverse, implement it, and list the decision here."
Nothing below blocks using the plugin; each is a default that's easy to
change later (a setting, a constant, or a small code change).

## Post-launch additions (2026-09-17)

Three features added after the initial build, based on direct feedback:

1. **Default case size** is now a real setting (WooCommerce → Wholesale →
   Settings) instead of a hardcoded constant. Per-product/variation case
   size fields now show it as a placeholder rather than pre-filling the
   value, so saving a product without touching that field doesn't bake
   today's default into it — it stays in sync if the global default
   changes later. See `class-settings.php` / `class-product-fields.php`.

2. **"Wholesale only" products**: a per-product checkbox (General pricing
   section, next to the wholesale price field) that hides a product from
   the retail shop, search, and category pages entirely for anyone who
   isn't an approved wholesale customer. A direct link to the product
   page still resolves (title/images/description render normally) but
   shows an "available to approved wholesale accounts only" message with
   an apply link in place of the price, and the product can't be added to
   cart. **Decision**: chose the polite-message behavior over a hard
   redirect/404, matching the existing "retail only" note pattern used
   elsewhere in the plugin — reversible by changing
   `Pricing::filter_price_html()`/`restrict_wholesale_only_purchase()` if
   a harder block is wanted later. A "Products" tab under WooCommerce →
   Wholesale lists every wholesale-configured product for reference
   (still edited on each product's own screen, not duplicated there).

3. **Wholesale tiers** (Bronze/Silver/Gold/Platinum) — an internal-only
   classification never shown to the customer, assigned per-customer from
   their profile (WooCommerce → Wholesale doesn't list customers by tier
   yet; the Applicants → Approved tab remains the customer list). Tiers
   sit *between* the global default and the existing per-customer
   override layer: precedence is now per-customer override > tier >
   global default, for both the minimum order subtotal and a percentage
   discount off each product's group wholesale price. Silver/Gold/
   Platinum each get an optional minimum-order override and discount
   percentage under WooCommerce → Wholesale → Tiers. See `class-tiers.php`.

   **Follow-up (same day)**: the global minimum order subtotal and
   default case size — originally on the Settings tab — moved to live in
   the Tiers tab instead, as directly-editable fields on the Bronze row
   (still the same two options under the hood, `Settings::OPT_MIN_ORDER`
   / `OPT_DEFAULT_CASE_SIZE` — Bronze row edits them in place rather than
   duplicating them into separate tier-specific storage), so every
   tier-related setting — including the base one everyone starts on —
   lives on one screen.

4. **Exact application form ID**: alongside "which plugin renders
   `/wholesale-application`," there's now a companion "Application form
   ID" setting (`Settings::OPT_APPLICATION_FORM_ID`, default `'4'` —
   Fluent Forms' confirmed real form ID on this site). Without it, *any*
   form built with the selected plugin — a contact form, a newsletter
   signup, anything else on the site using the same form plugin — would
   have every submission funneled into `create_pending_applicant()`.
   Left empty, the setting falls back to the old "any form from this
   plugin" behavior. Verified live against the real Fluent Forms form
   after adding this — a fresh submission still correctly created a
   pending applicant with form ID `4` configured.

   The Gravity Forms/WPForms lookups (`$form['id']` / `$form_data['id']`)
   couldn't be verified live since neither plugin is installed on this
   site — flagged the same way the GF composite-field caveat already is.

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

   **The adapter was then run against the live form for real** (ten
   submissions through the actual AJAX endpoint the front-end JS uses,
   with the resulting pending applicants inspected in wp-admin, then
   deleted). That surfaced four more bugs no amount of reading the field
   labels off the rendered page would have caught, since they were about
   the JSON schema Fluent Forms hands the hook, not the labels themselves:
   - `$form->fields` doesn't exist on Fluent Forms 6.2.14's `Form` model —
     the field schema is under `$form->form_fields`. This alone made the
     adapter silently process zero fields.
   - The site's form uses "Two Column Container" layout elements (Title +
     Phone in one row, Email + Business Name in another). Fluent Forms
     nests the real fields inside `columns[].fields[]` on those, not flat
     in the top-level list — `flatten_fluent_fields()` now recurses into
     containers to find them.
   - The Terms & Conditions element has no `settings.label` at all; its
     consent text lives in `settings.tnc_html`. Without special-casing
     `element === 'terms_and_condition'`, `accuracy_confirmation` could
     never match.
   - The bare synonym `'address'` matched **"Email Address"** before ever
     reaching "Physical Store Address" (email comes first in the field
     order), silently overwriting the applicant's address with their own
     email. Narrowed to `'store address'` / `'physical store'` /
     `'business address'` / `'mailing address'`.

   Also confirmed the earlier concern about the guessed synonym list was
   justified: the live labels ("Position / Title", "Business Phone
   Number", a paragraph-length hosts-events question) would have matched
   almost nothing under the original exact-match logic. The `wpFluent()`
   existence guard from the initial build was also removed — that
   function doesn't exist in this Fluent Forms version, so the guard was
   silently killing every submission before the (still-broken-at-the-time)
   `$form->fields` bug even mattered.

   Net result: all 13 fields (name, title, phone, email, store name,
   business type, address, website, sales channels, TCGs carried,
   hosts-events, estimated spend, accuracy confirmation) now map
   correctly, in both the "Yes" and "No" hosts-events directions, verified
   by inspecting the created pending applicant's user meta directly. The
   full Approve flow (role change to `wholesale_customer`, showing up
   under the Approved tab) was verified too.

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

## Display/Case quantity-tier pricing + shipping pivot (2026-09-17)

Replaces the earlier "cases" ordering model with a single-product,
Display-or-Case unit selector plus a visible three-tier quantity ladder,
per the owner's real pricing sheet:

- **$5.50/pack** (Standard) under 16 displays combined in the cart,
  **$5.00/pack** (Volume) at 16+ displays, **$4.50/pack** (Bulk) at 16+
  cases (128+ displays at the default 8 displays/case). One product, not
  two — the customer picks Display or Case per row on the Quick Order
  form (`templates/order-form.php`); both convert to packs client-side
  before anything hits the server, so stock/cart/shipping logic all still
  works in packs like before. See `class-volume-pricing.php`.
- **Tier thresholds are combined across the whole cart** (all colors/
  products together), not per line item — confirmed with the owner as
  the expected behavior, since the tiers are meant to reward total order
  size, not per-SKU volume.
- **Stacks with the existing hidden Bronze/Silver/Gold/Platinum customer
  tiers** rather than replacing them: the quantity ladder now sets the
  *base* price (previously always the product's flat wholesale price),
  a customer's hidden tier discount percentage still applies on top of
  whichever quantity tier is active, and a per-customer price override
  still wins over both, unconditionally. See
  `Pricing::get_wholesale_price()`'s precedence order.
- **Progress bar UI** (`.protech-tier-bar` in `wholesale.css`/
  `order-form.js`) modeled on Bambu Lab's filament bulk-discount page, per
  the owner's reference — a filled track with two milestone markers
  (Volume/free-shipping unlock, Bulk/best-price), live-updated as
  quantities change, no page reload.
- **Shipping**: flat $19.95 below the 16-display Volume threshold, free
  at or above it — the same threshold that sets the Volume price tier,
  not a separate number. Implemented as a real `WC_Shipping_Method`
  (`class-wholesale-shipping-method.php`, id `protech_wholesale_shipping`)
  rather than a fee/discount hack, so it behaves like any other shipping
  option at checkout (shows in the rate list, radio-selectable, works
  with WooCommerce Blocks' Store API checkout). `is_available()` returns
  `false` for anyone who isn't an approved wholesale customer, so retail
  checkout is unaffected by its mere existence. Added to the **"United
  States (US)" zone** (zone 1) on staging via the REST API — **not** the
  worldwide catch-all zone (zone 0) — since that's the only zone with a
  real address to test against. If the store ships internationally,
  add the same method to zone 0 (or any other zone) from WooCommerce →
  Settings → Shipping.
- To avoid a wholesale customer ever seeing **zero** shipping options in
  a zone where the wholesale method hasn't been added yet,
  `CaseRules::hide_retail_shipping_for_wholesale()` only strips the
  retail Flat rate/Free shipping methods for wholesale customers when
  `protech_wholesale_shipping` is actually present in that zone's rates —
  otherwise the retail methods are left alone as a fallback.

**Bug found and fixed during live verification**: the first
implementation tried to recalculate cart-wide tier pricing by hooking
`woocommerce_before_calculate_totals` and calling
`$cart_item['data']->set_price()`. This silently lost to
`Pricing::filter_price()` (hooked unconditionally to
`woocommerce_product_get_price`), which WooCommerce and the Store API
both re-invoke on demand throughout a request — checkout, cart totals,
the Store API's own item serialization — each call re-running
`filter_price()` with no tier context and overwriting whatever
`set_price()` had just done. Fixed by making the price filters
themselves cart-aware (`Pricing::get_current_tier()` checks `WC()->cart`
directly inside the filter) and removing the separate
`before_calculate_totals` pass entirely — the same pattern already used
for the per-role variation price cache (`bust_variation_price_cache_per_role()`).
Verified live via the Store API cart endpoint at all three tiers (100 /
170 / 1300 packs → $5.50 / $5.00 / $4.50) and both shipping states ($19.95
below 16 displays, free at/above).

**Flagged for the owner**: the pre-pivot dollar-based "Minimum order"
field on each customer tier (Bronze/Silver/Gold/Platinum, WooCommerce →
Wholesale → Tiers) is now vestigial. It used to hard-block checkout below
that subtotal; that block was removed earlier this session in favor of
reusing the number as the free-shipping cutoff — but the pivot then
introduced the 16-display Volume threshold as a distinct, concrete number
from the owner's actual pricing sheet, which superseded that plan. The
per-tier "Minimum order" fields still save and display correctly, but
nothing in cart, checkout, or shipping logic reads them anymore. Left
in place rather than removed, since deleting a tier's stored value is
harder to reverse than leaving an inert field — but worth either wiring
back in as a genuine order-size floor or removing from the Tiers UI,
whichever the owner prefers.

## Sticky, site-wide tier bar (2026-09-17)

Follow-up to the Display/Case pivot above: the Quick Order form's tier
progress bar only ever appeared on that one page, but wholesale
customers can browse and buy from ordinary single product pages too
(they already get wholesale pricing/labels there). Added a second,
sticky version of the same bar — fixed to the bottom of the viewport,
shown to wholesale customers on every front-end page except the Quick
Order page itself (which keeps its own non-sticky version) — modeled on
the same Bambu Lab reference the owner gave for the original bar.

- **Reflects the real cart, not form previews.** The Quick Order page's
  bar shows what tier you'd reach if you added the quantities currently
  typed into the form; this one shows the ACTUAL cart, since there's no
  form to preview from on an ordinary product page. Both read from the
  same `VolumePricing::get_tier_bar_state()` math for the actual-cart
  case, but the Quick Order page keeps its own independent JS-side
  calculation for the form-preview case — they're solving different
  problems, not duplicated code for the same one.
- **Updates without a page reload** after any add-to-cart — classic
  single-product/shop-loop AJAX add-to-cart (jQuery's `added_to_cart`
  event) and the WooCommerce Blocks cart data store (`wp.data.subscribe`
  on `wc/store/cart`) both trigger a refetch from a small AJAX endpoint
  that just calls the same server-side state method — the tier math
  itself is never duplicated in JS for this bar (unlike the Quick Order
  page's bar, which has to compute locally since it's previewing unsaved
  form input the server doesn't know about yet).
- **Dismissible per browser session** (a "×" that hides it via
  `sessionStorage`, not a cookie) so it doesn't become permanently
  annoying — but a real cart-quantity change automatically un-dismisses
  it, since that's exactly the moment a customer benefits from seeing it
  again.
- Loading `wholesale.css` was previously gated to only the Quick
  Order/portal/account pages (`Plugin::enqueue_frontend_assets()`) — that
  gate has now been widened so a wholesale customer gets it on every
  page, since the "Wholesale price" price-html label (which was already
  rendering site-wide, ungated) and this new bar both need it everywhere,
  not just those three page types. Retail visitors are unaffected: the
  widened condition only applies once `Roles::is_wholesale_customer()` is
  already true.

**Two real bugs found and fixed during live verification** (both
via the truncated-page symptom described below, not by inspection):

1. **A namespace bug that silently truncated every page it touched.**
   `templates/global-tier-bar.php` called `MyAccount::quick_order_url()`
   directly. Template files loaded via `wc_get_template()` are NOT
   inside the plugin's `ProtechWholesale` namespace (only the `includes/`
   classes are), so that call resolved to a nonexistent global-namespace
   `MyAccount` class and threw an uncaught fatal *mid-render*, terminating
   the entire request right after printing `<a href="` — silently cutting
   off the rest of the page (all later scripts, `</body></html>`, etc.)
   while still returning a `200`. Every other template in this plugin
   already avoids this by only ever printing pre-computed scalar/array
   values passed in via `wc_get_template()`'s `$args` — fixed the same
   way here: `GlobalTierBar::render()` now computes the URL and passes it
   in as `$quick_order_url` instead of the template calling the class
   itself.
2. **`is_wc_endpoint_url()` doesn't know about this plugin's own
   endpoint.** Used it to detect "we're on the Quick Order page" (to
   suppress this bar there); it only recognizes endpoints registered
   through WooCommerce's own account-endpoint registry, and
   `MyAccount::register_endpoint()` registers `quick-order` straight
   through WordPress's `add_rewrite_endpoint()` instead — so the check
   always returned `false`, and the sticky bar wrongly doubled up with
   the Quick Order page's own bar. Fixed by checking
   `$wp_query->query_vars['quick-order']` directly instead.

Both were only caught by noticing a live page's HTML was truncated
(missing `</html>`) during manual Store-API-driven verification, not by
any error visibly surfacing — worth remembering if a future template
addition goes quiet in the same way: check whether the response is a
well-formed, complete document before assuming "no visible error" means
"it worked."

## Quick Order removal, shop-page visibility fix, and bar redesign (2026-09-17)

Four follow-up changes from direct feedback after using the plugin on staging:

1. **Removed the Quick Order page/tab entirely.** With wholesale pricing
   already visible on every ordinary shop/product page (plus the sticky
   global tier bar for progress toward better pricing), the dedicated
   bulk-entry page was redundant and the owner asked for it to go. This
   touched more than the page itself:
   - `class-order-form.php`, `templates/order-form.php`, and
     `assets/js/order-form.js` were deleted outright.
   - `MyAccount` no longer registers a `quick-order` rewrite endpoint or
     nav item — it now only owns the post-login redirect and the pending-
     application notice on the account dashboard.
   - **Post-login redirect for wholesale customers, the portal page's
     redirect for already-approved customers, and the login form's own
     redirect** all now send the customer to the **shop page**
     (`wc_get_page_permalink('shop')`) instead of Quick Order — simplest
     replacement, since wholesale pricing is already live there.
   - **Reorder was rebuilt to act directly** instead of prefilling a form
     that no longer exists: clicking "Reorder" (My Account → Orders list,
     an order's detail page, or a new shortcut on the account dashboard
     for the most recent order) now adds every still-available line from
     that order straight to the current cart, in its original pack
     quantity, and sends the customer to `/cart/` to review pricing
     (recalculated at whatever quantity tier the reorder lands in) and
     check out — no intermediate review page. Implemented as an
     `admin-post.php` handler (`class-reorder.php`) rather than a
     shortcode/endpoint, since there's no longer a page for it to live on.
   - The pre-pivot per-tier dollar "Minimum order" fields (flagged as
     vestigial in the section above) remain exactly as vestigial as
     before — unaffected by this change.

   **Bug found while wiring up the new Reorder handler**: `admin-post.php`
   requests run under WordPress's `is_admin()` (anything under
   `/wp-admin/` counts, including this dispatcher), and WooCommerce only
   initializes `WC()->cart`/`WC()->session` for requests it considers
   "frontend." Calling `WC()->cart->add_to_cart()` from an
   `admin_post_*` hook without accounting for this is a fatal
   (`add_to_cart()` on `null`) that — like the templating bug above —
   silently truncates the response with no visible error, this time with
   the added twist that curl still reported "200 OK" with a **1-byte**
   body and no `Location` header, easy to mistake for "the redirect just
   didn't happen" rather than "this crashed." Fixed with WooCommerce's own
   `wc_load_cart()` helper (built for exactly this: forcing session/cart
   init from a non-frontend context) at the top of the handler, before
   any cart access.

   **Also surfaced during that same verification** (not a bug, but worth
   documenting): WooCommerce 11.1 clamps a below-minimum `add_to_cart()`
   quantity UP to the product's current minimum order quantity rather
   than rejecting it — a minimum this plugin itself sets per product via
   `CaseRules::set_quantity_step()`. A real past order can never actually
   be below its own product's minimum at the moment it was placed (our
   own case-multiple validation already guarantees that on every
   legitimate add-to-cart), so this only matters if a product's case size
   /minimum is *raised* after the order was placed — reordering it later
   would then silently add more than the customer clicked "Reorder"
   expecting. `Reorder::add_order_items_to_cart()` now compares the
   quantity actually placed in the cart against what the original order
   had and surfaces a notice if WooCommerce adjusted it upward, rather
   than letting the customer discover a bigger cart at checkout.

2. **Fixed wholesale-only products disappearing from the shop for
   wholesale customers too.** Real cause: WooCommerce's own native
   "Catalog visibility" product setting (Shop and search / Shop only /
   Search only / Hidden) is a blunt, role-blind exclusion applied at the
   database query level, before this plugin's own
   `Pricing::filter_catalog_visibility()` (which IS role-aware) ever gets
   a chance to run. The one real "wholesale only" product on staging
   ("Vendor Starter Kit", ID 1656) had that native setting on **Hidden**
   — presumably someone's intuitive-but-wrong attempt to hide it from
   retail, not realizing the plugin's own "Wholesale only" checkbox
   already does that per-role. A native "Hidden" setting excludes a
   product from every query regardless of role, which is exactly what
   was reported. Fixed two ways: corrected that product's native
   visibility back to "Shop and search results" directly on staging, and
   `ProductFields::save_simple_fields()` now forces a product's native
   catalog visibility back to "visible" whenever "Wholesale only" is
   checked, so this can't recur for a future product — the checkbox is
   now the only visibility control that matters for a wholesale-only
   product; WooCommerce's own native dropdown is irrelevant to it going
   forward, one way (it can still be used normally on any product that
   isn't wholesale-only).

3. **New site-matching color palette.** The plugin's own UI (the sticky
   tier bar, the `/wholesale` login page, the "Wholesale price" label)
   previously used an arbitrary green accent chosen before staging access
   existed. Pulled the store's actual palette from its live Salient
   customizer output
   (`wp-content/uploads/salient/salient-dynamic-styles.css`): the site's
   "Accent Color" is pure black (`#000000`), buttons are solid black with
   8px corners, backgrounds are `#ffffff`/`#f7f7f7`, dividers are
   `#e5e5e5`. `wholesale.css`'s `:root` variables now match this exactly
   (`--protech-accent: #000000`, `--protech-radius: 8px`, etc.) — every
   plugin UI element inherits it automatically since none of them use a
   hardcoded color outside those variables. Kept the semantic red/green
   tints for error/success notices as-is (universal UX convention, not a
   brand color).

4. **Redesigned the sticky tier bar to actually match Bambu Lab's
   reference design**, not just its general "bar across the bottom"
   concept. Studied Bambu's actual bulk-discount bar
   (us.store.bambulab.com/pages/promotions/filament-bulk-sale) directly:
   it's a dark message+live-totals strip, a light track panel with
   circular threshold-marker dots (label + reward text above/below each,
   on desktop only), and a set of action buttons — collapsing, below
   their own "xl" breakpoint, to just the message and a plain slim track
   with the marker *labels and reward text hidden* (the dots alone stay
   visible; nothing else). Rebuilt `templates/global-tier-bar.php` and
   the corresponding CSS to match that same structure — a black header
   strip (message + live cart subtotal + item count), a light grey track
   panel with the same marker-dot styling, and a "View cart" CTA — using
   this site's own black/white palette (item 3 above) instead of Bambu's
   brand colors, since a colored fill/header would reintroduce the exact
   clash item 3 just fixed. **Mobile deviates from Bambu on purpose**:
   Bambu can afford to show *only* the message on mobile because their
   bar lives on a single product page that already has its own in-page
   Add to Cart buttons; this bar is the only floating navigation aid
   across the *entire* site, so the "View cart" action and live
   stats/subtotal stay visible on narrow screens too (stacked into three
   rows instead of one), while only the marker labels/reward text — the
   part that genuinely doesn't fit — get hidden, matching Bambu's own
   reasoning for hiding them, not just copying the outcome.

## Real flagship product configured for wholesale, and a serious bug it uncovered (2026-09-17)

Set up actual wholesale pricing on the site's real flagship product —
"Protech Premium Matte Sleeves" (ID 891), a variable product with 14
color variations — rather than another disposable test product, so the
owner can see and click through the finished feature on the real catalog
item it was built for. Set `_protech_wholesale_price = 5.50` (the store
default Standard price) on all 14 variations via the WooCommerce REST
API's variation batch-update endpoint (a genuine partial update — unlike
the raw admin-ajax form replication considered earlier in this project
and rejected as too risky, this can't accidentally clear an unrelated
field like stock management). Left case size, and the Volume/Bulk price
overrides, unset on every variation so they all inherit the store-wide
defaults from WooCommerce → Wholesale → Pricing — the plugin's own
intended "typical" setup. Verified live with a disposable test account
(since deleted) that stock quantities, `manage_stock`, and retail prices
on all 14 variations were completely untouched by the update.

**This immediately surfaced a serious, high-impact bug that no prior
testing had caught**, because every prior wholesale-pricing test in this
project used a **simple** product (a disposable test product, or a
simple product like "Vendor Starter Kit"), never a real **variable**
product priced the standard way (per-variation, via this plugin's own
variation edit-screen fields) — which is exactly how a real sleeve
catalog works, and exactly the scenario this whole plugin exists for:

- `Pricing::is_available_at_wholesale( $product_id, $user_id )` checks
  whether *that exact ID* has its own wholesale price. Cart/order line
  pricing always calls it with a concrete ID (a simple product's own ID,
  or a specific variation's ID) — correct there. But
  `woocommerce_get_price_html` and `woocommerce_product_is_visible` —
  the hooks behind the "Wholesale price" label and the shop/category
  visibility filter — hand a **variable product's PARENT ID** to those
  filters for its range/loop display, not any specific variation. Since
  the parent itself never has its own wholesale price (only its
  variations do, in the normal per-variation setup), both filters saw
  "not available at wholesale" for the parent and acted accordingly.
- Net effect on the real product: the "Wholesale price" label never
  appeared next to its price, and — far worse — **the entire product
  disappeared from the shop grid for wholesale customers**, because the
  default "Products with no wholesale price" setting is `hide`. A
  wholesale customer could still reach it by direct URL (where the price
  itself displayed correctly, since `filter_price()`/
  `filter_variation_prices_array_entry()` already used the correct
  variation-level IDs — only the label and visibility filters had the
  bug), but had no way to *find* it by browsing the shop, which defeats
  the entire feature.
- Fixed by adding
  `Pricing::is_available_at_wholesale_including_variations()` — true if
  the given ID itself has a wholesale price, OR, for a variable product,
  if *any* of its variations do — and using that (instead of the plain
  ID-only check) specifically in `filter_price_html()` and
  `filter_catalog_visibility()`, the two places that can receive a
  variable product's parent ID. Every other call site
  (`get_wholesale_price()`, `VolumePricing::get_totals_for_items()`,
  `Reorder::add_order_items_to_cart()`) always resolves a concrete
  line-item ID first and correctly keeps using the strict, ID-exact
  check — broadening those too would have been wrong (a cart line for
  one out-of-stock color shouldn't "borrow" wholesale eligibility from a
  sibling color that happens to have one set).
- Verified live after the fix: the product reappeared on the wholesale
  shop grid, the "Wholesale price" label appeared next to its ($5.50)
  price, a retail guest viewing the same product still saw the normal
  $9.99 retail price with no wholesale UI at all, and adding 200 packs
  correctly triggered Volume pricing ($5.00/pack) with the sticky tier
  bar reflecting it live.

## Display/Case unit selector on the product page, and a stepper bug (2026-09-17)

Feedback after testing the real flagship product live: no way to order
by Display or Case (only a raw pack-count quantity field), and the
on-page quantity stepper let you click up to invalid quantities like 11
before being rejected only at Add to Cart. Two separate things:

1. **Real bug**: `CaseRules::set_quantity_step()` (hooked to
   `woocommerce_quantity_input_args`) was setting `$args['input_step']`
   — a key WooCommerce's own quantity-input template never reads. The
   real key is `step`. This meant the +/- stepper and keyboard arrows
   always moved by 1 regardless of case size, even though `min_value`
   (the correct key) was already working — so the field *looked* like it
   would accept 11, even though server-side validation
   (`validate_add_to_cart()`, a completely separate, independent check)
   always correctly rejected it. No data integrity issue ever existed;
   this was purely a front-end affordance bug. Fixed by using the
   correct `step` key.
2. **Missing feature**: removing the Quick Order page (see above) removed
   its Display/Case dropdown with nothing replacing it. Rather than
   bringing back a separate page or restructuring the catalog into
   Color×Unit variations (both discussed and declined — see the
   AskUserQuestion answer), added a small "Order by: Display / Case +
   Quantity" control directly on the existing single product page,
   wholesale-customers-only:
   - `CaseRules::render_unit_selector()`, hooked to
     `woocommerce_before_add_to_cart_quantity` (fires in both `simple.php`
     and `variable.php` add-to-cart templates), renders the control and
     leaves WooCommerce's own real pack-quantity field in place
     untouched — it's still the one thing actually submitted with the
     form.
   - `assets/js/unit-selector.js` hides that real field (via a
     `protech-native-qty` marker class `set_quantity_step()` now also
     adds) and keeps it in sync as the friendly unit/quantity fields
     change: `packs = friendlyQty × (unit === 'case' ? caseSize ×
     displaysPerCase : caseSize)`.
   - For a **variable** product, a color switch can in principle carry a
     different case size per variation (even though none currently do on
     this catalog). `CaseRules::add_unit_data_to_variation()` (hooked to
     `woocommerce_available_variation`) adds the resolved case size/
     displays-per-case onto the same per-variation JSON payload
     WooCommerce's own variation-swap JS already uses, and
     `unit-selector.js` listens for that same script's `found_variation`
     jQuery event to re-sync when it fires — no extra AJAX round trip.
   - Progressive enhancement: if JS fails to load, the real quantity
     field is simply never hidden, so the customer still has a working,
     correctly-stepped (post-fix) pack-quantity input — degraded, but
     still correct, never silently wrong.

   **Bug found while wiring this up**: `render_unit_selector()` was
   first written to receive `$product` as a method parameter, the same
   way `filter_price_html( $price_html, $product )` and other WooCommerce
   *filter* callbacks do. But `woocommerce_before_add_to_cart_quantity`
   is an *action* that WooCommerce's templates call as
   `do_action( 'woocommerce_before_add_to_cart_quantity' )` — no
   arguments at all — so nothing was ever actually passed to the method.
   Unlike the two truncating fatals found earlier in this project, this
   one failed silently and safely (no product, but also no argument-count
   type error, since the parameter had no type-hint) — it just quietly
   never rendered anything, with everything else on the page working
   normally, which made it easy to miss. Fixed the same way
   `ProductFields::render_simple_fields()` already handles this exact
   kind of hook: read `global $product;` inside the method instead of
   expecting it as a parameter.

## Sticky bar scrolling away with the page instead of staying fixed (2026-09-17)

Reported with a screenshot: the bar appeared, but sitting right under the
site's footer at the bottom of the page — scrolling with the content
instead of staying pinned to the bottom of the viewport, i.e. behaving
like a normal in-flow element despite `position: fixed`.

Root cause: Salient wraps the entire page (`#ajax-content-wrap` /
`.ocm-effect-wrap`) for its AJAX page-transition/scroll effects, and that
kind of wrapper almost always carries a CSS `transform` (needed for the
transition animation itself, or just to promote it to its own composited
layer). A `position: fixed` element positions itself relative to the
nearest ancestor with a `transform` (or `filter`/`perspective`/`contain`)
set, instead of the viewport, when one exists — exactly this symptom.
Confirmed the bar's server-rendered HTML sits outside both of those
wrapper divs already (so it isn't a template-nesting mistake), which
narrows it to a transform somewhere in that ancestor chain rather than
markup placement.

Fixed the robust way regardless of exactly which ancestor turns out to
carry the transform (now, or after a future Salient update changes it):
`assets/js/global-tier-bar.js` now re-parents the bar to be a direct
child of `<body>` via `document.body.appendChild( bar )` on load, before
anything else runs. This can't be verified from here — this environment
has no browser to render the page in — so this is the standard, correct
fix for this well-known class of theme conflict, applied and deployed,
but **still needs a visual re-check** to confirm it actually resolves
what the screenshot showed.

## Browser cache was likely masking fixes all session — real cause of "nothing changed" (2026-09-17)

After deploying the `document.body.appendChild()` fix for the sticky bar
(previous section), the owner reported it was "still stuck under the
footer, like nothing has changed." The fix itself may still be correct —
but investigating turned up a much bigger, silent problem: **every JS/CSS
file this plugin enqueues has used the exact same cache-busting version
string, `PROTECH_WHOLESALE_VERSION = '1.0.0'`, unchanged for the entire
session**, across every one of today's redeploys. `wp_enqueue_script()`/
`wp_enqueue_style()` append that version as `?ver=...` on the file URL —
with a constant that never changes, that URL is byte-identical after
every deploy, so any browser (or the site's caching plugin) that had
already fetched an older copy of `global-tier-bar.js`,
`wholesale.css`, `unit-selector.js`, or `admin.js` had no reason to ever
fetch it again. A real, correctly-deployed fix could sit on the server
while a testing browser kept running whatever version it first loaded —
which is exactly "like nothing has changed."

This wasn't unique to the sticky-bar fix — it's been true of every asset
change made today, so it's plausible (though unconfirmed) that earlier
apparent successes in this session were partly luck-of-cache-timing
rather than confirmed fresh loads on the testing side, even though every
server-side verification in this session (via curl, which never carries
browser cache) was genuinely checking the real deployed file each time.

Fixed properly rather than just bumping the number once:
`Plugin::asset_version()` now derives each file's cache-busting version
from its own `filemtime()` instead of the shared constant, so every
future deploy automatically produces a new URL with zero extra steps to
remember. Confirmed live: the enqueued URLs now read
`?ver=<unix-timestamp>` instead of `?ver=1.0.0`.

**If a fix still doesn't appear to take effect after this**, a hard
refresh (Ctrl+Shift+R / Cmd+Shift+R, or clearing the browser cache once)
rules out any last remaining stale copy from before this fix shipped —
after that, any file should always be fetched fresh on every deploy going
forward.

## Bar made non-dismissible, and a real live-update gap fixed (2026-09-17)

Two follow-ups once the sticky bar was actually visible and pinned
correctly:

1. **Removed the "×" dismiss control entirely** — the bar is now always
   visible once rendered, with no way for a wholesale customer to lose
   track of their progress toward better pricing/free shipping. Removed
   the button from the template, the dismiss/sessionStorage logic from
   `global-tier-bar.js`, and the now-unused CSS.

2. **The bar wasn't updating without a full page reload on the single
   product page.** Root cause: WooCommerce's single product page submits
   Add to Cart as an ordinary form POST (full page reload) by default —
   unlike the shop loop's own Add to Cart buttons, which are AJAX out of
   the box. Neither of the bar's two live-update paths
   (`added_to_cart`/`wc_fragments_refreshed` jQuery events, or the
   WooCommerce Blocks `wp.data` cart store) ever had anything to react to
   on that page, since nothing there was ever emitting them.

   Fixed by AJAXifying the product-page form's submission in
   `unit-selector.js` — but not via WooCommerce's classic
   `?wc-ajax=add_to_cart` endpoint, which was the first thing tried and
   confirmed (live, via a direct request) to silently fail for this exact
   product: that endpoint only ever reads `product_id`/`quantity` from
   the request, with no concept of `variation_id` at all, so it has no
   way to add a specific color of a variable product — it isn't a bug
   anywhere in this plugin, just a real limitation of that specific
   legacy endpoint (which is why WooCommerce's own shop-loop "Add to
   Cart" buttons quietly become "Select options" *links* instead, for any
   variable product — they can't use that endpoint either). Switched to
   the modern, fully variation-aware **WooCommerce Store API**
   (`/wp-json/wc/store/v1/cart/add-item`) instead — the same endpoint
   this whole project has used for live verification throughout, now
   reused as the actual product-facing mechanism. On success, it triggers
   WooCommerce's own `wc_fragment_refresh` event (the standard "something
   changed, please refresh the mini-cart" signal cart-fragments.js
   already listens for) rather than faking that plugin's own event
   payload shape — its own follow-up `wc_fragments_refreshed` event is
   already exactly what the bar was listening for, so this needed no
   changes to `global-tier-bar.js` at all, just something to actually
   fire that event chain on this one page.

   Verified live end-to-end: a direct Store API add-item call (mirroring
   exactly what the browser JS now sends) correctly added the right
   variation at the right wholesale price, and the bar's own AJAX
   refresh endpoint immediately reflected the new cart state
   (`4 displays`, `$220.00`, `12 more displays to unlock better
   pricing`) — every link in the chain from "click Add to Cart" through
   to "bar shows the new numbers" checks out; the one thing this
   environment still can't confirm is the visual result of a real click
   in a real browser.

## Simplest-to-reverse defaults chosen

| Decision | Default chosen | Where to change |
|---|---|---|
| Empty wholesale price = hide vs. fallback to retail | **Hide** from wholesale product lists/order form; if a wholesale user opens the product URL directly, retail price shows with a "retail only" note | Settings → "Products with no wholesale price" |
| Wholesale users + retail coupons | **Disabled** | Settings → "Allow retail coupons for wholesale customers" |
| Wholesale tax handling | Charged exactly like retail; a documented filter (`protech_wholesale_is_tax_exempt`, default `false`) exists for a future per-customer exempt flag, but no cert upload/collection UI was built (out of scope per master prompt) | `includes/class-pricing.php` |
| Free shipping threshold exclusion | **On** by default (wholesale orders never qualify for the retail free-shipping rule) | Settings → "Exclude wholesale orders from free shipping" |
| Packs per display default | 10, editable per product/variation | Product data panel |
| Displays per case default | 8, editable per product/variation | Product data panel |
| Global order minimum (dollar-based, per customer tier) | $800 subtotal, editable globally and per customer — **now vestigial**, see "Display/Case quantity-tier pricing + shipping pivot" above | Tiers tab (Bronze row) + user profile field |
| Per-customer price override storage | Single repeater meta field on the user profile (`_protech_price_overrides`, `variation_id => price`), not a separate CPT/table — matches "keep simple" instruction in R2 | `class-approval.php` (profile fields) |
| Application → pending user role | New role `wholesale_pending`, ADDED to the account (never replacing its roles); staff accounts are never changed automatically — see the 1.1.0 section | `class-roles.php`, `class-application-form.php` |
| Reorder + now-out-of-stock variation | Skip that line, show a notice, add everything else straight to the cart | `class-reorder.php` |
| Where an approved customer lands | Portal page (`/wholesale`), post-login, and Reorder all send an approved wholesale customer to the **shop page** — Quick Order (which this used to redirect to) was removed; see "Quick Order removal..." above | `class-portal.php`, `class-my-account.php` |
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
- ~~My Account nav styling for the new "Quick Order" tab.~~ (That tab was removed; nothing to check.)
- The "set all variations to $___" bulk price helper (`assets/js/admin.js`)
  intercepts WooCommerce's variations bulk-action dropdown to prompt for
  a price, since WooCommerce's own JS only does this natively for its
  built-in bulk actions. This couldn't be tested against a live
  WooCommerce admin screen; the failure mode is safe either way —
  `ProductFields::handle_bulk_edit()` refuses to change any prices when
  no value was submitted, so a JS mismatch means the button quietly does
  nothing rather than corrupting data. Confirm on staging and adjust the
  interception in `admin.js` if needed. **Update (1.1.0):** it was indeed
  quietly doing nothing — see item 5 in the section below.

## Audit and remediation, version 1.1.0 (2026-09-17, afternoon)

A full read-through of the plugin, with its WooCommerce-behaviour
assumptions checked against WooCommerce trunk source and the Salient 18.1
theme source, followed by six phases of fixes. The audit itself (every
finding tagged confirmed / likely / verify) is the plan document this work
was approved from; what follows is the decisions it produced.

1. **Roles are only ever added or removed, never replaced.** Every role
   change used `WP_User::set_role()`, which replaces all of an account's
   roles — so a public application-form submission carrying an
   administrator's email demoted that administrator to a read-only
   pending applicant, and un-flagging a shop manager made them a plain
   customer. `Roles::grant()`/`revoke()` now add/remove just the two
   wholesale roles. **Policy for staff accounts** (anything with
   `edit_posts`, `manage_woocommerce`, or `edit_users`; filterable via
   `protech_wholesale_is_privileged_account`): a public submission never
   changes their roles at all — the application is recorded with status
   `pending`, the admin email says so, and it can be approved by hand from
   the profile.
2. **Application views are status-driven, not role-driven.** Rejecting
   removes the pending role (the account becomes an ordinary `customer`
   if it has nothing else) and keeps the `rejected` status plus the
   reason; Pending/Rejected views query that status, Approved stays
   role-based so manually flagged customers appear. A rejected applicant
   can be re-approved or can re-apply. The portal shows a rejected-state
   message instead of "under review".
3. **Blocks checkout never fired `woocommerce_checkout_create_order`**
   (verified in WooCommerce's `StoreApi/Utilities/OrderController.php`
   and `Routes/V1/Checkout.php`), so no order on this site had ever been
   flagged wholesale. `woocommerce_store_api_checkout_update_order_meta`
   is hooked as well; the email prefix falls back to the customer's role
   for older unflagged orders. Historical orders are NOT backfilled — a
   WP-CLI one-off could do that if reporting on them matters.
4. **Product fields moved to a "Wholesale" product data tab.** They were
   hooked to `woocommerce_product_options_pricing`, which WooCommerce
   renders inside its `show_if_simple show_if_external` group (verified
   in `html-product-data-general.php`), so "Wholesale only" and
   "Displays per case" were unreachable on the variable flagship
   product. Packs per display is product-level too now (a variation
   inherits it, then the store default), matching displays-per-case.
5. **The variations bulk action was wired to a contract WooCommerce
   doesn't have.** `meta-boxes-product-variation.js` hands a custom bulk
   action its data via `triggerHandler('<action>_ajax_data')`; the old
   JS wrote a data attribute nobody read (and matched the wrong class,
   so the prompt never even appeared). Rewritten; Volume/Bulk override
   bulk actions added since the plumbing now exists.
6. **Catalog hiding moved to the query.** `woocommerce_product_is_visible`
   only hides at template level (pagination holes, wrong counts) and
   never runs for the Store API, so wholesale-only products' names and
   retail prices were readable by anyone at `/wp-json/wc/store/v1/products`.
   `CatalogQuery` adds `pre_get_posts` meta queries: wholesale-only
   excluded for non-wholesale visitors (also on plain site search and
   sitemaps), and — under "hide" — products with no wholesale price
   excluded for wholesale customers via a parent-level
   `_protech_has_wholesale_price` flag kept in sync on every save (a
   variable product's prices live on its variations, so a query can't
   see them directly). `Plugin::maybe_upgrade()` backfills that flag once
   (`DB_VERSION` 2) on the first `init` after deploy; until it has run,
   the "hide" rule would hide everything, which is why it doesn't wait
   for an admin visit. Authenticated REST for staff (`edit_products`) is
   exempt. The old template-level filter stays as a fallback.
7. **Memoization.** Every price filter evaluated the cart tier (walking
   the whole cart) before even checking the role, hundreds of times per
   shop page, for retail users too. Role first; tier, role checks, and
   per-variation availability are memoized per request and flushed by
   cart-change, role-change, and `_protech_*` meta hooks. Correctness
   over speed: the flush list is deliberately broad.
8. **Variation price cache hash** includes the cart tier, customer tier,
   and an override hash. Without that, a variable product's cached
   min/max range computed at Standard kept showing $5.50 after the cart
   crossed into Volume.
9. **Blocks cart quantities.** `woocommerce_quantity_input_args` is
   classic-only; `woocommerce_store_api_product_quantity_multiple_of` /
   `_minimum` (verified in `StoreApi/Utilities/QuantityLimits.php`) make
   the Blocks stepper move by display size and reject non-multiples.
   Confirmed at the same time that the Store API DOES still apply the
   legacy `woocommerce_add_to_cart_validation` filter, so the unit
   selector's add-item path was already validated.
10. **Tier bar refresh no longer depends on cart-fragments.js**, which
    WooCommerce stopped loading on product pages by default in 7.8; the
    unit selector dispatches a `protech:cart-changed` DOM event the bar
    listens to. If Salient's own "AJAX add to cart" theme option is on,
    its click handler takes over the button (it prevents the form's
    submit event) and fires `added_to_cart`, which also works. Either
    path is fine; which one runs on staging depends on that option.
11. **Price ladder table on the product page** (`TierLadder`): the
    customer's actual three prices for this product, current tier
    marked. Two marker dots weren't enough to explain the incentive.
12. **Emails through the WooCommerce mailer** (branded header/footer,
    the store's From address). The approval link goes to the My Account
    lost-password endpoint with the reset key — exactly what
    WooCommerce's own reset email does — instead of bare `wp-login.php`,
    and the email says the link lasts 24 hours. The admin notice
    includes the whole application, a Reply-To of the applicant, and a
    direct profile link built with `admin_url()` (not
    `get_edit_user_link()`, which returns '' during the applicant's
    logged-out request).
13. **The dollar minimum order is still not enforced** (Bronze row on
    the Tiers tab, per-tier fields, the profile override,
    `CaseRules::get_minimum_order()`). It is now labelled as such in both
    places instead of looking live. Still the owner's call: delete it,
    or re-enable it as a real checkout floor via
    `woocommerce_store_api_cart_errors` + the classic hooks. Left in
    place because deleting stored values is the harder thing to reverse.
14. **Corrections to earlier notes in this file.** The Reorder section
    says `WC_Cart::add_to_cart()` clamps a below-minimum quantity up to
    the product minimum; the WooCommerce source has no such clamp (the
    guard code is harmless and stays). `WC_Cart::add_to_cart()` DOES fill
    a variation's attributes in from the variation itself when none are
    passed, so Reorder works for variations (checked, not assumed).
15. **Nothing in 1.1.0 was executed locally.** This machine has neither
    PHP nor Docker, so the test suite, PHPCS, and PHPStan run for the
    first time in GitHub Actions (`.github/workflows/ci.yml`); lint and
    analysis are advisory there until their backlog is cleared, the
    tests are the gate. Every PHP/JS file passed a bracket-balance scan
    and each change was reviewed against the WooCommerce source it
    touches, but the first CI run may still surface something — treat a
    red run as the next thing to fix, not as noise.
