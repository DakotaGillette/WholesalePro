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

## Storefront polish, version 1.2.0 (2026-09-17, evening)

Owner review of the customer-facing UI on staging: the product page
quantity area was confusing, the sticky bar was "meh", the navbar cart
badge counted packs and overflowed, nothing in My Account said "you are a
wholesale account", and the `/wholesale` login page was narrow and
lifeless. Everything here was checked against the live theme's CSS and
markup (protechsleeves.com, Salient 18.2.1) and WooCommerce trunk before
being written — but, as with 1.1.0, **none of it has been seen in a
browser or run through PHP locally**. QA.md §18 is the list of what to
look at.

1. **Protech Blue (`#42649D`) replaces black as the plugin's accent — a
   deliberate reversal of "New site-matching color palette" above.** That
   decision matched the theme's black accent so the plugin wouldn't look
   like a differently-branded add-on. The owner's call now is the
   opposite, and better: blue is the brand color, and using it for
   everything wholesale-specific (bar, "Wholesale price" label, price
   table, account card, portal) lets a partner see at a glance which parts
   of a page are about their wholesale account. Theme buttons — Add to
   cart, Checkout — stay the theme's black; only the plugin's own UI
   changed. White on `#42649D` is 5.9:1, so it carries body copy directly;
   darker/lighter shades of the same hue (`--protech-blue-deep`,
   `--protech-blue-tint`, …) cover gradients and light surfaces. The
   neutrals and 8px corners from the earlier decision are unchanged.

2. **Fonts are inherited.** The stylesheet forced `"Open Sans"`; the site's
   body font is Poppins (`salient-dynamic-styles.css`). `--protech-font` is
   now `inherit`.

3. **Root cause of the "two quantity selectors".** The unit selector was
   always meant to hide the theme's pack stepper (`.protech-hidden-qty {
   display: none }`), but Salient's dynamic stylesheet has `.cart
   div.quantity { display: flex }` — two classes-worth of specificity
   against one — so the stepper never disappeared, and customers saw
   "Order by / Quantity" with a second, pack-denominated quantity under it.
   The hiding rule now uses the theme's own selector shape plus
   `!important`. With that fixed, the control itself was rebuilt as one
   top-to-bottom flow: two radio cards (Display / Case, each stating its
   pack count), a −/+ stepper with the unit word beside it, a live
   read-back ("2 cases · 16 displays · 160 packs"), and the Add to cart
   button restating the action ("Add 2 cases to cart"). The button text is
   only ever rewritten when the button contains plain text (true on the
   live theme) — a theme that puts markup inside it is left alone.
   Progressive enhancement is unchanged: the real pack input is still what
   submits, and still what a no-JS visitor uses.
   After an add, the control now says so ("Added 3 displays (30 packs) to
   your cart") instead of silently resetting, keeps the chosen unit, and
   remembers it in `localStorage` — a buyer who orders by the case
   shouldn't have to re-pick Case on every product.

4. **The bar's track is two segments, not one linear scale.** Defaults are
   Volume at 16 displays and Bulk at 16 cases (128 displays): linearly, the
   Volume marker sat at 12.5% and the first tier — the one most customers
   are working towards — had an eighth of the bar. Zero→Volume now spans
   0–40% and Volume→Bulk the rest (`VolumePricing::scale_percent()`,
   `VOLUME_MARKER_PERCENT`), falling back to linear if the thresholds are
   ever configured with Volume at or above Bulk. The fill is also pinned
   to the tier actually reached: the tier is decided per product (each
   line's own displays-per-case) while the track is drawn from the store
   default composition, so the two could disagree.

5. **Marker prices are store defaults — an assumption, stated.** The Volume
   and Bulk markers show a per-pack price: `Settings::get_volume_price()` /
   `get_bulk_price()` with the customer's hidden tier discount applied. A
   product with its own Volume/Bulk override, or a customer on per-product
   price overrides, can pay something different; the price table on each
   product page remains the exact figure. For this catalogue (one flagship
   line on the default ladder) the defaults are the truth. If that stops
   being so, `protech_wholesale_tier_bar_show_prices` turns the prices off
   and the markers fall back to "Better price + free shipping" / "Our best
   price". The Standard marker never shows a price — Standard is each
   product's own wholesale price, there is no single number.

6. **"Saving $X" is measured against Standard pricing, per line**
   (`get_savings_for_items()`): quantity × (Standard − price being paid),
   through the same memoized `Pricing::get_wholesale_price()`. It is empty
   at Standard and ignores lines on a per-customer override (both prices
   are the override).

7. **The celebration fires once per tier reached, never on a page load
   alone.** `global-tier-bar.js` compares the tier before and after each
   refresh and celebrates only on an increase. A tier reached through a
   full page reload (Reorder, a non-AJAX add) is caught by remembering the
   last tier seen in `sessionStorage` and comparing on the next load; the
   first page of a session never celebrates, because a returning customer's
   saved cart may already be at Volume. Confetti is eighteen CSS-animated
   spans, fanned upward so none fire into the bottom of the viewport — no
   canvas, no library. The bar stays non-dismissible (unchanged decision).

8. **The ghost preview is the one piece of bar math mirrored in JS.**
   Earlier sections insist the bar's math lives only in
   `get_tier_bar_state()`. That still holds for everything the bar
   *reports*. The preview of "where would this quantity land" has to
   respond to every stepper click, so `scalePercent()` in
   `global-tier-bar.js` mirrors `scale_percent()` using the thresholds the
   server sends in `state.scale`. It is visual only, and both sides carry a
   comment pointing at the other.

9. **Cart badge counts displays** via the `woocommerce_cart_contents_count`
   filter — confirmed in WooCommerce trunk to feed Salient's header badge
   (through `WC_Cart`'s legacy `cart_contents_count` property) and the
   Store API's `items_count` (the Blocks mini-cart). Displays rather than
   cases or line items because every wholesale line is a whole number of
   displays and it is the figure the sticky bar already shows. Nothing in
   WooCommerce core reads this count for totals, stock or validation. The
   badge also becomes a pill (the theme draws a fixed 18px/16px circle) —
   scoped to a new `protech-wholesale` body class so the retail header is
   untouched.

10. **`!important` is used where, and only where, the theme forced it.**
    Confirmed in Salient's CSS: `p { padding-bottom: 28px }`, `ul, ol {
    margin: 0 0 30px 30px }` with disc bullets, `form label { font-size:
    14px !important }`, `button[type=submit]:hover { background-color:
    #000 !important }`, `.container-wrap button[type=submit] { padding:
    16px !important; border-radius: 0 !important }`. The old bar's message
    `<p>` was silently carrying that 28px of padding, which was a real part
    of why it looked slack.

11. **No approval date on the account strip.** It would be a nice touch
    ("Partner since…"), but the plugin has never stored when an account was
    approved, and `user_registered` is a different fact. Not invented.

12. **Portal benefits come from settings**, not copy: the free-shipping and
    best-price lines read the live thresholds (`Portal::get_benefits()`,
    filterable), so the login page can't promise something the Pricing tab
    no longer delivers. No discount percentages are advertised. On narrow
    screens the form is ordered above the pitch — returning partners are
    the page's main audience.

13. **Salient's scroll-to-top button**: the plan was to lift it clear of the
    floating dock, but the live site doesn't render one (`#to-top` is
    absent from the page), so nothing was overridden. The bar does publish
    its reserved height as `--protech-bar-h` on `<html>` for whenever
    something fixed to the bottom needs to clear it.

14. **First look on staging (owner screenshot, same evening): a white band
    under the black footer.** The bar reserved its room with
    `padding-bottom` on `<body>` — inherited from the original full-width
    bar, where it was invisible because the bar covered it edge to edge.
    Under a *floating* dock the padding shows, and it shows the body's
    background (white) beneath a black footer. Padding the footer instead
    isn't possible in any clean way on this site: the theme's own
    `#footer-outer` is **empty**, and the visible footer is a Salient
    "global section" (`.nectar-global-section.
    nectar_hook_global_section_parallax_footer`) — a page-builder row
    whose black comes from an absolutely-positioned `.row-bg` layer, not
    from any wrapper. So the reserved room is now its own element
    (`.protech-tier-bar-spacer`, last in `<body>`) whose color is
    **sampled from the live page**: `document.elementsFromPoint()` just
    above the spacer, first fully opaque background in the stack (skipping
    the bar itself, see-through overlays and hidden layers). It samples
    early — from the viewport's bottom edge once the page end is within
    300px — so the color is in place before the spacer scrolls into view,
    and re-samples while the page end is near, since this footer has a
    parallax effect that settles as it arrives. Works for any page or
    theme without naming a footer element; `protech_wholesale_tier_bar_
    spacer_color` overrides it if the guess is ever wrong. A background
    *image* or gradient can't be matched this way — the nearest solid
    color under it is used.

15. **The dock is the same width as the site header (owner request).** On
    this site the header is a floating card: Salient sizes `#header-outer`
    itself as `calc(100% - var(--container-padding) * 2)`, capped at
    `var(--container-width) - 2 x padding` (1700px here). The dock matches
    that card edge for edge. `global-tier-bar.js` measures it, so it
    follows the theme at every breakpoint: a header narrower than the
    viewport is matched on its outer edges; a full-bleed header is matched
    on the content box of the `.container` inside it; phones keep the
    edge-to-edge bar; no header found leaves the stylesheet's width alone.
    The stylesheet's own width uses the same two theme variables (falling
    back to a centred 1180px on any other theme), so the width is already
    right before the script runs. Filter:
    `protech_wholesale_tier_bar_align_selector` (`''` turns it off).
    Because the dock can now be 1700px wide, the message column stops
    growing at 380px and the track takes the rest; the track's minimum is
    440px (the narrowest at which its three captions don't collide), and
    the two-row layout starts at 1160px instead of 980px.

16. **Two wrong first attempts at 14 and 15, and what fixed the process.**
    The first deploy of item 15 measured the header's *inner* container,
    and took its selector only from the localized settings, silently doing
    nothing when that key was absent — the owner's second screenshot showed
    the dock unchanged. The first deploy of item 14 sampled the footer
    color on the last scroll event, but this footer is a parallax section
    that is still gliding into place after scrolling stops, so it sampled
    the light strip above it. Neither was findable by reading code. Both
    were found in minutes once the page was actually rendered: Chrome is
    installed on this machine, and a small Node script can drive it
    headless over the DevTools protocol (no puppeteer needed) against the
    real staging page with the bar's markup, CSS and JS injected — scroll,
    measure, screenshot. The sampler now keeps re-sampling for ~2s after
    the last scroll event; the default selector lives in the script as
    well as in PHP. Verified in that harness at 2045, 1440, 1200, 1100, 800
    and 390px: dock edges equal the header's, no overflow, no colliding
    captions, spacer `rgb(0, 0, 0)` under the black footer. **Lesson for
    this project: render it before shipping it.** "No browser available"
    (said throughout this file) was never true on this machine.

Known gaps, not addressed in this pass:

- The main product price above the price table is rendered server-side, so
  after an AJAX add that crosses a tier it stays at the old figure until
  the next page load. The price table beside it (which now updates live)
  and the bar are both correct in the meantime.
- Cart and order lines still read "Displays: 8" without the case
  equivalent.

## Starter kits, and a 404 they uncovered (2026-09-17, late evening)

The owner has a "Vendor Starter Kit" product (#1656 on staging): one
display of every color of the flagship sleeves — today all 14 plus a
second Black and a second White, and "all 16" once two more colors land.
It was set up as a simple product with a wholesale price of 800 and
"packs per display" 160, which the plugin would have read as $800 *per
pack* in multiples of 160. Asked to give it "the functionality" so it
"just adds one of everything".

1. **The kit is a landing page, not a thing that gets bought.** Its page
   adds the source product's REAL variations to the cart, one display
   each. Everything then follows from machinery that already exists:
   stock is per color, Reorder works on the order, the order's lines are
   the colors, and the price is what the pricing engine says — 16
   displays *is* the Volume threshold, so 160 packs at $5.00 with free
   shipping is exactly the $800 the owner had typed on the product. No
   number on the kit product is ever charged: `woocommerce_is_purchasable`
   is false for a kit, and its price HTML (title and shop grid) is a live
   quote. The alternative — a single $800 line for the kit — would have
   needed its own stock, its own tier handling and its own reorder path,
   and would have double-counted against the tier bar.

2. **Composition is a rule with fillers, not a stored list.** One display
   of every variation this customer can buy at wholesale and that is in
   stock; if that is short of the kit's target (default: the Volume
   threshold), extra displays of the configured filler colors, in order,
   round-robin. 14 colors + Black + White = 16 today; add two colors and
   it is one of each with nothing to edit, which is the owner's stated
   plan. An out-of-stock color is left out (said so on the page) and the
   fillers cover for it, so the kit still reaches Volume. Target and
   fillers are per-kit settings on the Wholesale tab (`StarterKit::META_*`),
   using WooCommerce's own product-search selects.

3. **The quote is computed, per request, for the cart as it stands.**
   Adding 8 kits (128 displays = 16 cases) reaches Bulk, so the same kit
   is $720 each at that quantity; adding a kit to a cart already at
   Volume prices differently again. `get_quote()` prices the kit at the
   tier the cart *would* reach with it added, using the same
   `Pricing::get_wholesale_price()` the cart will use. The page re-quotes
   from the server on every quantity change (debounced) and after every
   cart change; nothing is priced in JavaScript.

4. **`WC_Cart::add_to_cart()`'s own error notices are lifted into the
   kit's report** and removed from the session, so "we have 20 in stock"
   appears once beside the button rather than on whatever page loads next.

5. **The 404.** `/product/starterkit/` was a 404 for the admin session,
   which is how this started. `CatalogQuery::filter_product_query()`
   (1.1.0) applied its wholesale-only exclusion to *every* product query,
   including the single-product main query — so any wholesale-only
   product's own page was a 404 for anyone who isn't a wholesale
   customer, staff included, instead of the "approved wholesale accounts
   only" page `Pricing::filter_price_html()` exists for, and a wholesale
   customer opening a product with no wholesale price got a 404 rather
   than the retail-only note. Singular queries are now exempt; a test
   covers both the single page and a listing of the same product.

6. **Still not rendered as a wholesale customer.** The kit panel was
   checked in the headless harness by injecting its markup into a guest
   product page (layout only); the live add and quote round-trips run
   for the first time when the owner tests. The owner sets the kit up
   themselves (README, "Starter kits"): the product's settings are theirs
   to change, and editing a product through wp-admin from here would have
   meant re-posting its whole edit form.

7. **Reversed for this product the same evening.** The Vendor Starter Kit
   is linked from the owner's one-sheet as an unlisted special that gets a
   *new* vendor — one who may not have a wholesale account yet — buying
   quickly: one line, $800, no login. The kit behaviour (sixteen color
   lines, wholesale customers only) was the wrong shape for that. The
   feature stays in the plugin for a future product; this product goes
   back to a plain simple product. What actually stood in the way of a
   plain sale to a *wholesale* customer was not the kit code at all but
   the display rules — see the 1.3.0 section, item 6.

## Admin audit, updates from GitHub, version 1.3.0 (2026-09-18)

The owner asked for a deep audit of the admin side and two specific
things: updates that show up in the Plugins list when a release is pushed
to `main`, and a settings link on the plugin's row. Three decisions were
theirs, taken up front: make the GitHub repo public (so no token is needed
for updates), remove the unenforced dollar minimum order outright, and
keep the Tiers tab as its own tab while dropping the "(Tier 1/2/3)"
suffixes from Standard/Volume/Bulk.

1. **Updates ride WordPress core's own mechanism, not a library.** The
   header's `Update URI: https://github.com/DakotaGillette/WholesalePro`
   makes `wp_update_plugins()` call `update_plugins_github.com` for this
   plugin instead of asking wordpress.org (confirmed against
   wp-includes/update.php: core requires `version`, fills `new_version`,
   `id` and `plugin`, and files the answer under `response` when newer).
   `Updater::check()` answers from the repository's latest release: tag =
   version, the `protech-wholesale.zip` asset = package. `plugins_api`
   supplies the View details modal from the release notes. Nothing else
   is needed for the Plugins-list row, one-click update or auto-updates.
   The lookup is cached twelve hours, a failure one hour, so an outage at
   GitHub never means one API call per admin page load. No
   `upgrader_source_selection` rename: CI builds the zip with
   `protech-wholesale/` at its root, exactly what wp-admin uploads had.

2. **Releases are cut by CI, from the version header, only when the tag
   is new.** A `release` job in ci.yml (`needs: test`, `main` only) reads
   `Version:` from the plugin file, stops if `v<version>` is already
   tagged, builds the zip with the same exclusions as `bin/deploy.sh`,
   takes the matching `CHANGELOG.md` section as notes and runs `gh
   release create`. So a push to `main` that changes docs or CI without
   bumping the version publishes nothing, and a bump can never be
   published twice. Making the repo public was the owner's call over a
   read-only token stored on the site; nothing secret is committed
   (`.env` is ignored, credentials never were).

3. **`main` did not exist.** The repository's only branch (and default)
   was `claude/new-session-ummf3i`, the branch every session had worked
   on. `main` was created from it and made the default as part of this
   work, since "pushed to main" was the owner's own phrasing of the
   trigger.

4. **What the audit found, and the shape of the fixes.** Nothing said an
   application was waiting (a `awaiting-mod` bubble on the menu item now
   does, from a five-minute-cached count that approve/reject/apply
   invalidate). Nothing checked the silent-failure setup steps (the
   shipping method in a zone, a priced product, the portal page, a real
   form ID): `SetupChecks` does, as one notice on the landing tab that
   disappears when everything is in place. Shipping settings were split
   between two tabs (now all on Pricing & Shipping, which also names the
   zones that have the method). The Products tab's empty-state text
   pointed at a panel that no longer existed. The Customers tab loaded
   every order of every listed customer to count and sum them; it now
   uses WooCommerce's cached per-customer count and spend.

5. **Pricing a variable product no longer needs the Variations tab.**
   Three "Apply to all variations" fields on the parent's Wholesale tab
   write to every child on Update and then come back empty, with a line
   saying what the children currently hold ("Currently $5.50 on 14 of 14
   variations."). They are deliberately not stored on the parent: the
   variations remain the single source of truth, and the fields are a
   verb, not a value. The Variations-tab bulk actions stay for pricing a
   subset.

6. **Display and case rules now apply only to products sold at
   wholesale.** `CaseRules::sold_by_the_display()` gates every rule on
   `Pricing::is_available_at_wholesale()` for the current user. Before,
   a wholesale customer could not buy *any* product in a quantity that
   wasn't a multiple of its packs-per-display — including a product with
   no wholesale price, which the plugin otherwise treats as "not sold at
   wholesale". That is what made the Vendor Starter Kit unbuyable for a
   logged-in wholesale customer: a plain $800 product with no wholesale
   price, sold in ones. Such a product is now bought on ordinary retail
   terms by everyone, counts toward no tier (it never did) and counts as
   one item on the cart badge. A wholesale-priced product is exactly as
   strict as before; tests cover both.

7. **Minimum order removed, not enforced.** The owner chose removal over
   turning the fields into a real checkout floor. Gone: the Tiers-tab
   column and Bronze input, per-tier `min_order` storage (an old saved
   value is ignored), `Settings::OPT_MIN_ORDER`, the profile override
   field and `Approval::META_MIN_ORDER`, `CaseRules::get_minimum_order()`,
   the template helper. `uninstall.php` still deletes the old option and
   user meta on purge. This closes the last item under "Simplest-to-
   reverse defaults chosen" that was still waiting on a decision.

8. **Tier naming.** "Standard (Tier 1)" and friends are now plain
   Standard/Volume/Bulk everywhere in admin; "tier" is reserved for the
   Bronze-to-Platinum customer levels, which keep their own tab.

9. **Seen running, at last.** Admin screens cannot be rendered in the
   headless harness (they need a login), so after deploy each tab was
   fetched with the saved admin session and checked for a 200, no
   critical error and the expected new markers (all present). The
   release job published v1.3.0 with the zip attached on the first push
   to `main` that passed; staging, already on 1.3.0 by zip upload, answers
   "Check for updates" with "1.3.0 is the latest release" through the
   real GitHub call. The one-click "Update now" itself will first run on
   the next version, since 1.2.0 on staging predated the updater.

10. **The test suite had never been executed either, and it showed.** The
    first CI run: 50 errors and 2 failures, none in the code under test.
    `Protech_Test_Factory` called `WP_UnitTestCase::factory()`, which is
    protected (it now owns a `WP_UnitTest_Factory`). The approval-email
    test looked for `&id=` where `esc_url()` writes `&#038;id=`. The email
    "wrapped in the WooCommerce template" test failed because the mailer
    singleton was first created *inside* an earlier test, and the WP test
    framework's hook restore at that test's end removed the header/footer
    actions its constructor had registered while the instance lived on —
    suite-only, diagnosed by a temporary `fwrite(STDERR)` in CI, fixed by
    re-adding the two actions in that test's `set_up()`. A shipping test
    built packages without the `destination` that
    `WC_Shipping_Method::is_available()` reads. Then: 106 tests, 335
    assertions, green. The gate is real from here on.

## Header banner, per-audience (2026-09-18, version 1.4.0)

The owner's ask: the navbar has custom "Text To Display In Header" copy
("FREE SHIPPING WITH $30+ ORDERS", a Salient customizer field) and wanted
a logged-in wholesale customer to see the wholesale free-shipping line
instead, everyone else unchanged.

1. **No theme edit, because none was needed.** That field is not a plain
   string echoed verbatim — Salient runs it through `do_shortcode()`
   (confirmed against ThemeNectar's own support documentation, not
   guessed: a documented pattern is embedding a widget-area shortcode in
   that same field). That makes a shortcode the right tool, not a JS DOM
   swap or a theme child-template override — no flash of the wrong text,
   no touching Salient files, works whether the site is cached or not.

2. **The owner wraps their own text; the plugin never stores or
   generates the retail copy.** `[protech_header_notice]FREE SHIPPING
   WITH $30+ ORDERS[/protech_header_notice]` — a non-wholesale visitor
   gets exactly what's enclosed, unchanged (run through `do_shortcode()`
   itself, so a nested shortcode still works as it would have without
   this wrapper). This is a one-time manual edit to that one customizer
   field; nothing here can safely script a change to Salient's customizer
   state from outside a browser, and it's a thirty-second edit.

3. **The wholesale line is generated, not typed**, from
   `Settings::get_volume_threshold_displays()` — the exact number the
   sticky bar and the portal page already use — so it can't drift out of
   sync with the real ladder if that setting ever changes. A `wholesale`
   attribute or the `protech_wholesale_header_notice` filter overrides it
   with fixed text if the owner ever wants different wording.

4. **Deliberately static, not cart-aware.** The header text is baked into
   the page HTML on render, not updated by AJAX — matching what it always
   was, and appropriate for a persistent banner rather than a live cart
   summary (that's the sticky tier bar's job). It reflects the customer's
   status (logged in as wholesale or not), not their current cart.

## Messaging & automations, version 1.5.0 (2026-09-18)

The owner's ask: send emails and texts to wholesale customers, manually
and on rules (e.g. "X days after an order, remind them to reorder"), run
from the wholesale area, through Brevo (already connected on staging as
"mailin" 3.3.5, active). The owner separately flagged that Brevo's SMS
terms require a working site, a privacy policy/terms that cover SMS, a
description of the message types, and **proof of opt-in** — checked
against staging: form #4 has no consent checkbox, and neither the
privacy policy nor the terms page mentions SMS. This build produces that
proof rather than assuming it exists.

1. **A custom database table — the first one in this plugin.** Every
   other feature stores state in options or user meta; the message log
   (`{prefix}protech_wholesale_messages`) needs a real `UNIQUE KEY` so
   two evaluations of the same rule/customer/day can never queue the
   same message twice, and the Log/Compliance views need to query
   across every customer by rule, status and date — a serialized
   per-user meta array can't do either. Created via `dbDelta()`, in
   `Activator::activate()` and again in `Plugin::maybe_upgrade()`
   (`DB_VERSION` 3, since a GitHub-release update never re-runs
   activation).

2. **A day-based rule fires once per "anchor," inside a 7-day window
   from its trigger day, decided fresh every day rather than scheduled
   once.** No per-customer cron job is created; instead one daily
   Action Scheduler action scans every wholesale customer against every
   enabled rule (`Automations::anchor_for()`). The anchor is always tied
   to a real event — an order id, an approval date, or an order id plus
   a repeat index for win-back — so it can't drift, and the 7-day
   catch-up window is what makes it safe to enable a rule on a store
   with years of order history: it never reaches back past a week of
   the trigger day, and it absorbs a missed daily run (e.g. cron not
   firing overnight on a cached storefront) without ever double-sending,
   since the anchor — and therefore the message log's dedup key — is
   identical every day inside the window.
   **Follow-up (2026-09-19, agent review):** `run_daily()` originally
   called `Automations::snapshot()` (a real `wc_get_orders()` query)
   inside `candidates()`'s per-user loop — once per rule per user, so
   evaluating 3 rules against 200 customers ran the same "last order"
   query 3 times per customer. Snapshots are now built once per page of
   customers and passed into `candidates()`, restoring the "one query
   per customer per run" the design intended.

3. **Two separate SMS consents, not one.** Carriers require an explicit
   opt-in specifically for marketing texts; an "your order shipped" text
   is a service message a customer reasonably expects once they've
   given a phone number for that purpose. `SmsConsent` tracks
   `sms_marketing` and `sms_transactional` independently — a customer can
   opt into order updates without agreeing to promotional texts. Email
   stays opt-out (CAN-SPAM), so a marketing email's gate only checks for
   an explicit "no."

4. **Marketing SMS fails closed when Brevo's blacklist status can't be
   verified; transactional SMS fails open.** Every send re-checks Brevo
   for a STOP-triggered blacklist immediately before sending. If Brevo
   is unreachable (or not connected), a marketing send is refused
   (`reason: consent_unverified`) rather than risk texting someone who
   opted out through Brevo directly; a transactional send — already
   gated on its own separate consent — still goes out, since refusing a
   service message the customer explicitly asked for is the worse
   failure mode. `Test_Sms_Consent` covers both directions explicitly,
   including a version of this test that initially asserted the wrong
   thing (`assertTrue` where the fail-closed behavior requires
   `assertFalse`) and was caught before being trusted.

5. **Brevo API for email, not just SMS — with a WooCommerce-mailer
   fallback.** The owner's account already has 10,000 email credits;
   using the same channel for both gives one delivery log, one set of
   opens/click stats in Brevo, and one place (`MessageTransport`) that
   decides sender/footer/branding. If Brevo is ever disconnected, email
   still sends through the site's own mailer (provider recorded as
   `wc_mailer` in the Log) rather than failing outright — SMS has no
   such fallback; Brevo is the only SMS channel.

6. **A per-user random unsubscribe token, not a signed link.** A
   salt-rotation-proof HMAC was considered; a stored per-user token
   (`Unsubscribe::META_TOKEN`) is simpler, survives a salt rotation the
   same way, and can be individually invalidated if one is ever leaked —
   an HMAC of a fixed salt cannot be revoked without rotating the salt
   for everyone.

7. **`{last_order_url}` points at the My Account order page, not a
   Reorder link.** `Reorder::get_reorder_url()` is a per-user nonce URL
   minted at request time; a merge tag rendered inside an Action
   Scheduler background job has no request to mint one from, and a
   stale/reused nonce would just 403. The order page already carries
   WooCommerce's own Reorder button.

8. **Quiet hours and the daily run are site time, not each recipient's.**
   Simpler, and every wholesale customer on this store today is a
   business, not a consumer receiving a text at an odd personal hour —
   revisit if the customer base becomes geographically spread with real
   evening-quiet expectations per time zone.

9. **Compose has no live AJAX recipient count.** The plugin's only two
   existing AJAX endpoints (the tier bar, starter kits) are both
   customer-facing; every *admin* screen in this plugin is plain
   POST-and-reload. "Preview recipients" reuses that pattern — a submit
   button that reloads the page with the count and skip-reason
   breakdown — rather than introducing a new admin AJAX contract for one
   screen. Simpler and consistent; revisit if the audience count becomes
   something an admin wants to watch update as they type.

10. **The dollar figure "one message per rule per anchor" is enforced by
    the database, not by application logic.** `MessageLog::enqueue()` is
    an `INSERT IGNORE` keyed on `(rule_id, user_id, anchor, channel)`;
    `MessageLog::claim()` is a conditional `UPDATE ... WHERE
    status = 'queued'`. Two Action Scheduler workers racing to evaluate
    the same rule, or a worker retried after a crash mid-delivery, can
    each only succeed once — the two locks were designed together so
    that "evaluated twice" and "delivered twice" are both impossible by
    construction, not by convention.

**Not yet human-verified** (same caveat as every prior version): this
machine has no PHP, so nothing here has run outside PHPUnit in GitHub
Actions. SMS cannot be verified at all until the owner registers a
toll-free number with Brevo — the Compliance view's CSV/wording/
suggested privacy text is what that registration needs. See QA.md §21.

## MSRP strikethrough, login landing, tier names, quantity legend (2026-09-21, in 1.5.0)

Four owner asks in one message, after the header banner shipped:

1. **The main price shows the MSRP crossed out next to the wholesale
   price.** Done inside `Pricing::filter_price_html()`, the one place
   every wholesale price already passed through, by wrapping the price
   in WooCommerce's own `wc_format_sale_price()` markup (del/ins plus
   the screen-reader "Original price was / Current price is" text) with
   a "Save N%" chip after (an "MSRP" tag in front was tried and dropped, the strikethrough says it). Reusing the sale
   markup rather than inventing a strikethrough means Salient's own
   `.price del/ins` rules apply, and the accessibility text comes free.
   `filter_sale_price()` still blanks WooCommerce's real sale price for
   wholesale customers, so this is the only strikethrough they see; the
   two never stack. The MSRP is `get_msrp()` (the raw `_regular_price`
   meta, since `get_regular_price()` is already rewritten to the
   wholesale price on the same request), ranged across a variable
   product's priced variations. A product whose MSRP isn't above the
   wholesale price shows the plain price rather than a "Save 0%".

2. **Log in on `/wholesale`, land on the product page.** A setting, not
   a hard-coded product: "After a wholesale login, go to" (a URL; empty
   = the shop, exactly the old behaviour). All four places that sent an
   approved customer to the shop (the portal's own login form, the
   approved-customer bounce off the portal page, the "Continue" link,
   and the WordPress/WooCommerce `login_redirect` filters) read it, so
   My Account logins land in the same place as portal logins. Off-site
   URLs fall back to the shop via `wp_validate_redirect()`. On staging
   it is set to the Premium Matte Sleeves page. A product picker was
   considered and rejected: the WooCommerce enhanced select needs the
   screen registered as a WooCommerce screen for its scripts, and a URL
   also lets the owner pick a category or landing page later.

3. **Tier names: un-named, then Standard, then Volume.** The owner's
   reasoning: nobody should be *aiming* for the first tier, so it gets
   no name to aim for. Customer-facing surfaces describe it by its range
   ("Under 16 displays"), the ladder's first column now merges name and
   range, the sticky bar's first marker is labelled with the range, the
   chip reads just "Wholesale" while a cart is there, and the My Account
   card's tier list does the same. Admin needs *some* word for the row,
   so it is "Base" there (Pricing tab, bulk prompts, product-field
   descriptions). Suggested alternatives if "Base" grates: "Entry",
   "Opening", "Starter" (avoided: "Starter" collides with starter kits).
   **Slugs and meta keys stay `standard`/`volume`/`bulk`** and so do the
   `TIER_*` constants: renaming them would mean migrating
   `_protech_volume_price`/`_protech_bulk_price` on every variation and
   the four option names, for no customer-visible gain. The mapping is
   documented once, on `VolumePricing::get_tier_labels()`, and the
   customer/admin split lives in `get_tier_short_label()` (returns `''`
   for the base tier) versus `get_tier_labels()`. The starter-kit quote
   substitutes "Wholesale" when the short label is empty.

4. **A legend for buyers who don't know what a display or a case is.**
   `templates/quantity-legend.php`, rendered by
   `CaseRules::render_unit_selector()` directly above the Display/Case
   control (the place the words are first needed), for wholesale
   customers on priced products only. Three tiles, pack, display (a
   grid of pack dots), case (a grid of differently-colored display
   swatches, because a case is any mix of colors), with "×10" / "×8"
   between them, then the owner's line verbatim-ish: "8 displays = 1 case.
   Mix and match your displays however you'd like." (the owner asked for
   this order after seeing the first version), then free shipping on its
   own muted line. US spelling ("color") on every storefront string.
   Every number is the product's own composition and the live threshold,
   so it can't go stale. It is not on the cart page: the Blocks cart has
   no server hook for it, and the sticky bar already restates the totals
   there. The art caps at 12 dots/swatches so an unusual composition
   doesn't draw a wall of squares.

Verified in the headless-Chrome harness (product page at 1440 and 390,
shop grid at 1440) against the real Salient markup; PHPUnit covers the
price HTML (simple, variable, retail untouched), the landing URL (empty,
on-site, off-site, and the login_redirect filter), the ladder labels and
the legend's copy. Not yet human-verified on staging.

## Welcome email and previews to any address (2026-09-21, 1.6.0)

Three asks: US spelling, a welcome email for accounts being upgraded to
wholesale, and a preview-before-send with a typed address on every message.

1. **"colour" to "color"** across the repo, including docs, the CHANGELOG's
   history and two test method names. No option, meta key or class used the
   British spelling, so nothing else moved.

2. **The welcome email is its own thing, not the "approved" email.** That
   email (Emails::send_approved) tells an applicant to set a password with a
   reset key. The accounts being upgraded already have a password and use
   it, and generating a reset key for each would be noise at best and a
   lockout risk at worst, so the welcome email says "the password you
   already use" and links WooCommerce's ordinary reset page. "Add existing
   customers to wholesale" now calls approve_user() without the approved
   email and sends the welcome email instead when the box is ticked (it is
   ticked by default, since upgrading someone without telling them is the
   unusual case). Sending goes through MessageTransport like every other
   message, so Brevo is used when connected and the WooCommerce mailer
   otherwise, it is written to the message log (kind "welcome"), and it is
   categorised transactional: no marketing footer, no marketing opt-out
   check. A customer is stamped (_protech_wholesale_welcome_sent_at) only on
   a real send, never on a preview, which is what the Customers table's
   Welcome email column shows. The legend is rebuilt from tables and inline
   styles (the CSS version cannot work in an email client); it caps its
   drawn dots and swatches the same way. Where it lives: the Customers tab,
   because that is where an admin already is when they upgrade someone. It
   is not editable text in an admin screen; the copy is a template plus two
   filters, which is enough until the owner asks to change words often.

3. **"Send a preview", not "Send test to me".** The old button could only
   reach the admin's own account. Compose, each automation rule and the
   welcome email now have a box with an email field (and a phone field for
   texts); blank means the admin's own address. Merge tags still fill in from
   the admin's account, because a preview to an arbitrary address has no
   customer to be "about", and the automation form says order details show
   as blank. The automation form previews the unsaved rule as typed, using
   the same validation the rule would get (a missing name does not block a
   preview). "Both" previews each channel and reports each. Messaging that
   is not built from these screens (the applicant received / approved /
   rejected emails) has no preview: they are fixed text with no editing
   screen to preview from.

**Legend position (same day):** the owner asked for the quantity legend
above the Wholesale pricing table, so it is no longer rendered by
CaseRules::render_unit_selector() inside the add-to-cart form. It has its
own hook, CaseRules::render_quantity_legend(), on
woocommerce_single_product_summary at 24 (the price table is 25), with the
same wholesale-priced-product condition, and it drops its 520px cap to match
the table's width.

**"Add one display of every color" button (same day):** the starter-kit
engine already computes "one display of every color that is priced and in
stock" from a variable product's live variations, and its one-of-each mode
was built precisely so a new color needs no edit. Rather than a second
implementation, StarterKit::get_composition() treats a variable product that
is not itself a kit as its own source in one-of-each mode
(is_every_color_source()), and the existing quote and add endpoints accept
that product id, so the button is a small template, a small script and no new
server endpoint. It sits under the variable product's own add-to-cart form
(woocommerce_after_add_to_cart_form) as an outlined button so the black Add
to cart stays the one primary action, and it is hidden when fewer than two
colors are available. It adds exactly one of each, with no stepper, because
that is what was asked; pressing it twice adds a second set, and the cart's
quantity rules and tier pricing apply as for any other line.

**Every-color block: position and out-of-stock (1.6.1):** moved from under
the add-to-cart form to woocommerce_single_product_summary at 26, directly
under the price table (25), because it reads as part of choosing what to
order and the owner wanted it there. The out-of-stock note was one muted line
of small text; it is now an amber notice with an icon that names the color,
says it will not be added and how many will be, plus a crossed-out swatch and
wording that stops claiming "every" color ("each of the 13 colors in stock",
"Add one display of each color in stock") when one is missing. The
composition gained out_colors (name and swatch of each out-of-stock color) to
draw that; the existing unavailable list is unchanged.

**Short description below Add to cart (1.6.2):** the owner asked for the
size/count/finish block under the Add to cart button, for wholesale customers
only. It is WooCommerce's woocommerce_template_single_excerpt on
woocommerce_single_product_summary, so the move is a remove at whatever
priority it currently has and an add at 35 (add to cart is 30, category and
brand 40), done on the wp action for a wholesale customer on a product page.
Reading the current priority rather than assuming 20 means a theme that
already moved it cannot end up with it printed twice. Nothing changes for
guests or retail customers, so the standard product page and its SEO
structure are untouched.

**Out-of-stock line, second pass (1.6.3):** the owner found the yellow icon
hard to see on the block's light blue and the sentence after the color name
redundant, since the line below already says "each of the 13 colors in stock"
and the button says "each color in stock". The icon is now a soft red circle
with a red mark (the danger color) and the line is just "Out of stock: <name>".

## Rename to Protech Commerce (2026-09-21, 2.0.0)

The owner wants messaging, and its new visual email composer, to serve every
customer, not just wholesale, so the plugin's name should say so. Decisions:

1. **Slug and folder change, prefixes do not.** Folder, main file and header
   become `protech-commerce`; every option, meta key, hook, the message table,
   the `ProtechWholesale` namespace, the `PROTECH_WHOLESALE_*` constants and
   the `protech-wholesale` text domain stay. The owner never sees them, and
   changing them would touch every file and migrate every stored value for no
   gain. Read `protech_wholesale_*` in the code as "the original name", not a
   mistake.
2. **A slug change is a different plugin to WordPress**, so it cannot go
   through the updater: install, activate, then delete the old one. The one
   hazard is uninstall.php, which wipes all data when "Purge data on uninstall"
   is ticked, so that box must be unticked before deleting the old copy.
   Verified unticked on staging before the swap.
3. **The old plugin is not offered the new zip.** An installed 1.x updater
   looks for `protech-wholesale.zip` on the latest release, finds only
   `protech-commerce.zip`, records "no zip attached" and offers nothing. Had
   both names been attached, its one-click update would have unpacked a
   folder whose main file it does not know, breaking the site.
4. **The GitHub repository keeps its name** (`DakotaGillette/WholesalePro`):
   the updater and the `Update URI` header point at the repository, not the
   plugin slug, and renaming a repository breaks clone URLs for nothing.
5. **How the move was done on Windows.** `git mv` of the whole folder was
   refused (an editor holds handles inside it), so the 105 tracked files were
   moved one at a time, which git records as pure renames and keeps history.

## Messaging becomes its own menu (2026-09-21, 2.1.0)

Messaging is going to serve retail customers as well as wholesale, and a
visual composer is coming, so it should not sit under a Wholesale tab.

1. **One page slug per view, not one page plus a `view` argument.** Each
   view (Automations, Compose, Log, Compliance, Settings) is its own sub-menu
   page (`protech-messaging`, `protech-messaging-compose`, ...), so WordPress
   highlights the right sidebar item and the browser title is right. The
   landing view owns the top-level slug. All of them share one render callback
   and pick the view from the page slug.
2. **`MessagingTab::url()` is the single URL builder**, and it already was:
   the Customers tab, the setup checks and every admin-post handler redirect
   through it, so the move was a change in that one method.
3. **Old URLs redirect, and nothing else changes.** An `admin_init` hook maps
   `page=protech-wholesale&tab=messaging&view=X` to the new page, carrying
   every other query argument (a Compose link's `ids`, a Log filter), so
   bookmarks, emailed links and the previous release's own links keep working.
4. **The Wholesale screen loses the Messaging tab** and gains a link beside
   its Log link. Admin assets (`admin.js`, `admin.css`, the enhanced select)
   now load on the new pages too, gated on the `protech-messaging` page ids.
5. **The page shell and the existing views are untouched.** The 1200-line
   `class-messaging-tab.php` still owns them; splitting it is a separate
   cleanup and not part of this move.

## Groundwork for the composer, and the marketing footer (2026-09-21, 2.1.1)

A release with almost no visible change, so the next ones can be built on it.

1. **`MergeTags::fill()` is the token half of `render()`**, extracted so the
   composer can substitute tags without `render()`'s `wp_kses_post()`. That
   filter runs every inline style through `safecss_filter_attr()`, which deletes
   properties outside a whitelist (the `mso-` ones Outlook needs). `render()`
   now delegates to it, so escaping is one implementation, and the existing
   merge tag tests are the specification for both. A new test pins the guard: a
   declaration kses removes survives `fill()`.
2. **`MessageTransport::footer_html_for()` is the only place that decides
   whether a message carries the footer**, and `dispatch()` is the only place
   that hands finished HTML to Brevo or the site mailer. `send_email()` is the
   same function it was, built from those two, so nothing about existing sends
   changed. The composer's path will call the same two, which is what makes it
   impossible to send marketing without the unsubscribe link and address by
   forgetting to ask.
3. **The footer gained the postal address it always claimed to have**, and its
   sentence now depends on who is reading. The address is read from
   WooCommerce's store settings, not a plugin setting, so there is one place to
   keep it. If it is empty the email still goes (an unsent email is a worse
   outcome than a missing line), and the Wholesale setup notice flags it while
   messaging is on.
4. **`EmailBlocks` starts with the two drawing helpers** the welcome email
   used inline (`swatch_grid`, `step_number`); the welcome template now calls
   them. The composer's blocks are added to the same class next release.
5. **Merge-tag insertion is delegated** to the document, so a field or chip
   added after page load works. The caret behaviour is unchanged.

## Templates on the send path, and the review screen (2026-09-21, 2.4.0)

1. **One sender decides.** `MessageTransport::send_content_email()` resolves a rule's or
   campaign's email: the template when there is one, otherwise the typed subject,
   heading and body exactly as before. Real sends, test sends and the review screen
   all go through it (the review through a twin, `content_email_preview()`, that
   shares the same two document builders), so they cannot show different things.
2. **A typed subject beats the template's.** It is the one field left on the form
   that makes sense with a template (a campaign's own subject line). Heading and body
   are ignored when a template is chosen, and the Design row says so.
3. **A deleted template is not an empty email.** With a typed body it falls back to
   it; without one the message fails with reason `template_missing`, which the Log
   shows. Saving a rule or campaign that points at a template that does not exist is
   refused.
4. **Order-only tags stay out of templates**, so a template on an order-status rule
   cannot use `{order_number}`. Templates were built without an order context; adding
   one is a later change if it is asked for.
5. **The review is a page, not a dialog.** "Review and send" stores the input and
   redirects back to Compose, which shows the review when a stash exists. It carries
   the whole input forward as hidden fields, so Send, Back to edit and a preview sent
   from the review all work from the same form. The form's default `action` leads
   only to the review or back to the form, never to a send: the send is its own button.
   The old pop-up confirmation and its localized string were removed with it.
6. **The review applies the same consent gate a send does**, per customer and
   channel, but is a snapshot: each recipient is checked again when their message
   goes out.

## Buttons carry their own action (2026-09-21, 2.3.1)

A submit button with `formaction="admin-post.php?action=x"` inside a form that has
a hidden `action` field does not run `x`: admin-post.php reads `$_REQUEST`, where
the POST value beats the query string, so the button runs the form's own action.
Compose's Send preview and Preview recipients, and the automation form's Preview
recipients, were built that way in 1.5.0 and 1.6.0; it was found while building the
editor, and confirmed against staging with a harmless action pair. The fix is
`name="action" value="..."` on the button (the submitter comes after the hidden
input, and PHP keeps the last duplicate). The Customers tab already did this.
`test_no_button_relies_on_a_query_string_action` scans the includes.

## The template editor (2026-09-21, 2.3.0)

1. **The form is the state.** Every setting is a real input named by position
   (`blocks[2][attrs][text]`); the page posts the whole template and the server
   validates it. There is no JSON in a hidden field and no client-side model. The
   script only moves cards and renumbers them, rebuilding each name from the input's
   `data-name-suffix`, which is what makes blocks inside columns safe.
2. **The preview is the same form posted into an iframe** (`formtarget`), rendered by
   `EmailRenderer`, so it cannot drift from what sends. Without JavaScript it is a
   Refresh button.
3. **The submit buttons carry the action.** The form has a hidden `action` (save),
   but PHP lets a POST value beat the query string, so a `formaction` alone would
   make Refresh and Send preview save. Each button has `name="action"` with its own
   value; the submitter comes later in the form than the hidden input, so it wins.
   A test pins it.
4. **Columns print all three lists**, the third hidden when there are two, so
   switching the count needs no reload and no lost blocks. Blocks cannot be dragged
   out of their column (the same-list rule) and a column menu never offers Columns.
5. **Copy, not clone-and-hope.** A copied card gets its typed values written back to
   attributes first, its id blanked (the server mints a new one) and its product
   picker returned to a plain select for WooCommerce to enhance again.
6. **A template in use cannot be deleted**, and the list says where it is used
   (a lifecycle slot, a rule or a campaign).

## The template library and renderer (2026-09-21, 2.2.0)

The middle of the email composer: what a template is, how it becomes an email,
and six starters. No editor yet and nothing sends through it yet, on purpose,
so the hard part is tested on its own.

1. **Templates live in one non-autoloaded option, not a post type.**
   `wp_insert_post()` passes content through `wp_filter_post_kses` for any user
   without `unfiltered_html`, which would mangle stored block data, the exact
   failure the renderer exists to avoid. An option also matches
   `Automations::OPTION` and `Campaigns::OPTION`, makes uninstall one string, and
   costs nothing per request. Capped at 200.
2. **The renderer never filters what it builds.** Blocks are nested tables with
   inline styles; merge tags are substituted per field with `MergeTags::fill()`,
   each value escaped as it goes in; text an admin typed is sanitized once on
   save (a small allowlist with no `style` attribute, so nothing needs
   filtering again). A test pins it: the document keeps `mso-hide:all`, and
   `wp_kses_post()` of the same document is different. Composed templates also
   skip `WC_Email::style_inline()`, which would inline WooCommerce's stylesheet
   onto our elements and undo the point of owning the design.
   One known consequence: when Brevo is not connected the fallback path sends
   through `WC_Emails::send()`, which runs `style_inline` itself. Our own inline
   styles win over its rules, so the result is right, but it is not identical to
   the Brevo path.
3. **The footer is passed in, not built by the renderer.** The caller supplies
   `MessageTransport::footer_html_for()`, so a template cannot be rendered for
   marketing without the unsubscribe link and postal address by omission. A
   template's own "show store address" option applies only when no marketing
   footer is supplied, so the address is never printed twice.
3a. **A group that was not submitted keeps its value.** `validate()` treats
   missing `style`, `header` or `footer` as "leave alone" and a submitted group
   as complete (an unticked checkbox is absent from a form post, so absent
   inside a submitted group means off). Without that, saving from a form that
   omitted a group would silently reset it.
4. **Columns nest one level, enforced when saving.** Columns inside columns are
   dropped and reported. It bounds the renderer and the editor, and deeper
   layouts are where table-based email breaks.
5. **Wholesale-only blocks vanish for a retail reader** instead of leaving an
   empty frame, and show in a preview so the admin can see them. Product
   grids price per recipient (`Pricing::get_wholesale_price()` for a wholesale
   reader, the shop price otherwise), skip wholesale-only products for retail,
   and print a real dollar sign (the `&#36;` bug of 1.6.2 has a test).
6. **The welcome email's picture and pricing lines moved into two shared
   templates** (`email-quantity-diagram.php`, `email-pricing-ladder.php`) by
   moving the exact lines rather than retyping them, so the welcome email and
   the blocks share one implementation and the welcome email is unchanged.
7. **Seeding is once, and additive by key.** Starters are created on the
   `DB_VERSION` 4 step only while the library is empty, so a deleted starter
   never returns, and a later release adds new ones by naming their keys. They
   are never bound to a lifecycle email, so a release cannot change what a
   customer receives. The two application starters (approved, rejected) wait
   for the `{set_password_url}` tag, which must not be part of every recipient's
   context: generating a reset key invalidates the previous one.
8. **Two new tags**, `{login_url}` and `{lost_password_url}`. The login URL is
   cached for the request because a campaign now asks for it once per recipient.
