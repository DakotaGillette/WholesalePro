# QA.md — Manual staging checklist

Run this on the Cloudways staging site (never production) after deploying with `bin/deploy.sh`. Check items off as you go; anything that fails should be filed as a bug, not silently worked around. Where a setting is mentioned, its default value is shown in `[]` — reset to that default before starting unless a section says otherwise.

## 1. Application → approval flow

> Field mapping already verified live (2026-09-17): 10 real submissions through the actual `/wholesale-application` Fluent Forms form (entry IDs 85–94), confirming all 13 fields map correctly in both the "Yes" and "No" hosts-events directions, and that Approve correctly switches the role. See `DECISIONS.md` for the bugs that surfaced and were fixed along the way. The steps below are still worth spot-checking (especially the emails, which weren't the focus of that pass) but the core mapping logic is no longer a guess.

- [ ] Log out (or use a private window). Visit `/wholesale-application` and submit a complete application (all fields the form asks for: name, title, phone, email, store name, business type, address, website, sales channels, TCGs carried, hosts events, estimated monthly spend, accuracy confirmation).
- [ ] Confirm a new WordPress user was created (Users list) with the role **Wholesale Applicant (Pending)**.
- [ ] Confirm the applicant's inbox received "We received your wholesale application" with the 1–3 business day language.
- [ ] Confirm the store admin's email (site's `admin_email`) received "New wholesale application: [store name]" with a link to the review screen.
- [ ] As the applicant, try logging in at `/wholesale` before approval. Confirm you land on the "Your application is under review" message (not the store at wholesale prices, and not an error).
- [ ] In wp-admin, open **WooCommerce → Wholesale**. Confirm the applicant appears in the list with a **Pending** status and visible Approve/Reject actions.
- [ ] Click **Approve**. Confirm the user's role switches to **Wholesale Customer** in the Users list.
- [ ] Confirm the applicant receives "Your Protech Sleeves wholesale account is approved!" containing a password set/reset link.
- [ ] Click that link, set a password, and log in. Confirm you are redirected straight to **the shop page**, and that wholesale pricing is visible on products there.

## 2. Rejection flow

- [ ] Submit a second test application.
- [ ] In **WooCommerce → Wholesale**, click **Reject** on this applicant and enter a reason (e.g. "Outside our current distribution area").
- [ ] Confirm the applicant receives "Update on your Protech Sleeves wholesale application" and that it includes the reason text you entered.
- [ ] Confirm the rejected user's role was **not** changed to Wholesale Customer, and that logging in as them still shows the "under review"/retail experience — i.e. no wholesale access was granted.

## 3. Pricing correctness across every surface

> **This is the section that would have caught a real, serious bug** (found 2026-09-17 while configuring the actual flagship product, "Protech Premium Matte Sleeves" — a variable product priced per-variation, not per-simple-product): `filter_price_html()`/`filter_catalog_visibility()` were checking the variable product's PARENT id for a wholesale price, which the parent never has in the normal per-variation setup — so the "Wholesale price" label never showed, and worse, the *entire product silently vanished from the wholesale shop grid*, with no error anywhere. Fixed (see `DECISIONS.md`), but every prior QA pass on this section had only ever been run against a **simple** product, never a real variable one — that's specifically why it went unnoticed. The "shop/category grid" bullet below is the one that actually catches this regression; don't skip it.

Set up: on the approved test account from Section 1, confirm a group wholesale price is set on at least one sleeve **variation of a variable product** (e.g. $5.00/pack — not a simple product, to actually exercise the parent/variation distinction above), and add one **per-customer price override** on that same account's profile for a *different* variation (a different price than the group price, e.g. $4.50/pack).

For **the group-priced variation**, logged in as the approved wholesale customer, check that the wholesale price (not retail) appears, labeled "Wholesale price", on:
- [ ] the single product page (color/variation selected),
- [ ] the shop/category grid — **confirm the parent product itself is listed at all**, not just that its price is correct once found,
- [ ] the cart,
- [ ] the mini-cart,
- [ ] the checkout page,
- [ ] the order-received (thank you) page,
- [ ] the customer's order confirmation email,
- [ ] the admin new-order notification email, and its subject line starts with **"WHOLESALE ORDER"**.

For **the per-customer-override variation**, repeat the same checks and confirm the override price is what's shown everywhere (not the group price) — on the product page, shop grid, cart, mini-cart, checkout, order-received page, and both emails.

- [ ] Log out and view the same two variations as a guest (or as a plain retail account): confirm retail pricing shows, with no "Wholesale price" label and no sign a wholesale price exists.

## 4. Products/variations with no wholesale price

- [ ] Pick a variation with no group wholesale price and no per-customer override for the test account. With the default "Products with no wholesale price" setting (**hide**), confirm it does **not** appear anywhere in the wholesale customer's shop/category view.
- [ ] Switch **Settings → Products with no wholesale price** to **"Show retail price with a 'retail only' note"**. Reload that variation's product page as the wholesale customer and confirm the retail price now shows along with a "(retail only — not available at wholesale)" note. Switch the setting back to "hide" when done.

## 5. Case-multiple enforcement (dollar-based order minimum is now retired)

> The old **$800 minimum-order checkout block** described in earlier versions of this checklist was removed in the 2026-09-17 Display/Case pivot — see `DECISIONS.md`. A low-subtotal cart should now check out normally; there is no dollar floor anymore, only the quantity-tier pricing and shipping in Section 14.

- [ ] As the wholesale customer, try adding a quantity that is **not** a multiple of the packs-per-display size (default **10** packs) to the cart — e.g. 15 packs of one color. Confirm it is blocked with a message asking for a multiple of that size.
- [ ] Add a valid multiple (e.g. 20 packs = 2 displays) and confirm the cart line shows a "Displays" annotation alongside the pack quantity.
- [ ] Add a single display's worth of one product (well below any tier threshold) and confirm checkout is **not** blocked by a minimum-order notice anywhere (cart page, checkout page, or Store API cart errors).

## 6. Reorder

> Rebuilt 2026-09-17 when the Quick Order page was removed (see `DECISIONS.md`) — Reorder now adds items straight to the cart via an `admin-post.php` handler instead of prefilling a form on that page. Verified end-to-end live on staging with a disposable test order/account (since deleted): the link correctly added the order's line to the cart in its original pack quantity and redirected to `/cart/`. One real bug was found and fixed along the way — `WC()->cart` isn't initialized by WooCommerce for `admin-post.php` requests by default (they run under `is_admin()`), which fatals silently (truncated response, no error) without `wc_load_cart()`; worth remembering if this handler is ever changed.

- [ ] From **My Account → Orders**, find a past wholesale order and click its **Reorder** action. Confirm it adds that order's items straight to your cart (in their original pack quantities) and takes you to `/cart/` — no intermediate review page.
- [ ] From an individual order's detail page (My Account → Orders → view an order), confirm the **Reorder** button there does the same thing.
- [ ] On the **My Account dashboard**, with an account that has at least one past order, confirm a "Your last order (#..., date) is ready to reorder" prompt appears with its own "Reorder last order" button, and that it works the same way.
- [ ] Set a variation from a past order to "Out of stock" (or reduce its stock to 0) via the product edit screen, then reorder that order. Confirm a visible notice says that item was skipped (not silently dropped), and that any other still-available lines were still added.
- [ ] Raise a product's "Packs per display" (its case size / minimum order quantity) above the quantity in an old order for it, then reorder that order. Confirm a notice explains the quantity was increased to meet the new minimum, rather than silently checking out a bigger order than expected.
- [ ] Try a Reorder link with an altered/expired nonce or a mismatched order ID (edit the URL). Confirm it fails safely (an error page or a redirect), not a blank/broken response.

## 7. `/wholesale` portal page — all four states

- [ ] **Logged out:** visit `/wholesale`. Confirm a login form (email/username + password + "Remember me" + "Lost your password?") appears along with a "Not a partner yet? Apply here" button linking to `/wholesale-application`. Submit valid wholesale-customer credentials through this form and confirm it logs you in and redirects to the shop page. Submit invalid credentials and confirm a clear error shows on the page itself (not a redirect to wp-login.php).
- [ ] **Pending:** log in as a still-pending applicant and visit `/wholesale`. Confirm the "Your application is under review" message and a contact link appear.
- [ ] **Approved:** log in as an approved wholesale customer and visit `/wholesale`. Confirm you're redirected straight to the shop page (you should never see the portal's login/pitch body).
- [ ] **Retail-only:** log in as an ordinary (non-wholesale) customer and visit `/wholesale`. Confirm a short "This page is for approved wholesale accounts" message with an "Apply here" link appears — no login form, no wholesale pricing.

## 8. Retail customers are unaffected

- [ ] As a plain retail customer (or guest), confirm product pricing, the shop grid, cart, and checkout all look and behave exactly as before this plugin was installed.
- [ ] Confirm the retail free-shipping-over-$30 threshold still applies normally to a retail order.
- [ ] Confirm retail coupons still apply normally to a retail order.
- [ ] Confirm no wholesale-related UI appears anywhere for a retail account: no "Wholesale price" label on any product, no wholesale-only notices, no sticky tier bar at the bottom of the screen.

## 9. Coupon behavior per setting

- [ ] With **Settings → Allow retail coupons for wholesale customers** unchecked (default), try applying a valid retail coupon to a wholesale cart. Confirm it's rejected with "This coupon is not valid for wholesale accounts."
- [ ] Check that setting on, and confirm the same coupon now applies successfully to a wholesale cart.
- [ ] Uncheck it again afterward to restore the default.

## 10. HPOS compatibility

- [ ] Confirm the store's current order-storage mode (**WooCommerce → Settings → Advanced → Features**, "High-performance order storage").
- [ ] Place one wholesale order and one retail order. In **WooCommerce → Orders**, confirm the **Wholesale** column shows a "Wholesale" badge on the wholesale order and a dash (—) on the retail order.
- [ ] Use the **wholesale filter dropdown** above the orders list to filter to "Wholesale only" and confirm only the wholesale order(s) appear; filter to "Retail only" and confirm only the retail order(s) appear.
- [ ] If possible, toggle HPOS on/off (on a copy of staging, not live) and repeat the column + filter checks in the other mode to confirm both the legacy (posts-based) and HPOS orders screens show the same information.

## 11. Salient-theme visual spot-checks

- [ ] Product price HTML on a single sleeve product page: confirm the "Wholesale price" label renders visibly next to the price and isn't clipped, hidden, or stripped by Salient's price markup.
- [ ] Quantity stepper on a single product page as a wholesale customer: confirm the +/- stepper (if Salient adds one) respects the case-size step/minimum rather than allowing single-pack increments.
- [ ] `/wholesale` portal page in each of its four states: confirm the login form, pending message, and retail-only message inherit Salient's page/typography styling rather than looking like unstyled fallback HTML.
- [ ] Confirm the portal login button, the sticky tier bar's black chrome, and any other plugin UI element visually match the site's real black/white/8px-corner button style (pulled from the live Salient customizer palette 2026-09-17 — see `DECISIONS.md`) rather than looking like a mismatched, differently-branded add-on.

## 12. "Wholesale only" products

> Added 2026-09-17. **Verified live on staging** (same day, follow-up pass): the one real "wholesale only" product on the site ("Vendor Starter Kit") was found to have WooCommerce's own native "Catalog visibility" set to "Hidden" — which excludes a product from every query for *everyone*, wholesale customers included, regardless of this plugin's own per-role filter. Fixed live (native visibility corrected back to "Shop and search results") and fixed going forward (`ProductFields::save_simple_fields()` now forces that native setting back to "visible" whenever "Wholesale only" is checked, on every save). See `DECISIONS.md` for the full story. The steps below are worth re-running after any WooCommerce core update touching catalog visibility, since that's the exact mechanism this relies on staying out of the way.

- [ ] On a **test product** (not a real catalog item), check "Wholesale only" (General pricing section) and set a wholesale price. Save. Confirm WooCommerce's own "Catalog visibility" setting on that same product reads "Shop and search results" after saving, even if you had set it to something else first.
- [ ] As a guest/retail account: confirm the product does **not** appear on the shop page, in search, or in its category.
- [ ] As that same guest/retail account, visit the product's direct URL. Confirm the title/images/description still render, but in place of the price and Add to Cart button you see "Available to approved wholesale accounts only." with a working "Apply for a wholesale account" link — and that there's no way to add it to cart.
- [ ] As the approved wholesale customer: confirm the product appears normally on the **shop page and its own product page**, with its wholesale price.
- [ ] Open **WooCommerce → Wholesale → Products** and confirm this test product is listed with "Yes" under Wholesale only.
- [ ] Uncheck "Wholesale only" on the test product and confirm it reappears in the shop/search for retail visitors.

## 13. Wholesale tiers

> Also added 2026-09-17, same caveat as above — verified by code review and confirming the Tiers tab and profile tier dropdown render without errors, not yet tested end-to-end against a live order.

- [ ] Open **WooCommerce → Wholesale → Tiers**. Set Silver's minimum order override to a distinct test value (e.g. $200) and its discount to 10%.
- [ ] On a test wholesale account with **no** per-customer minimum-order or price override set, assign the **Silver** tier from their user profile.
- [ ] Confirm that account's cart/checkout minimum is now $200 (Silver's override), not the global default — and that a *different* wholesale account left on **Bronze** still enforces the global default.
- [ ] Confirm a product with a group wholesale price (e.g. $5.00) shows as $4.50 for the Silver-tier account (10% off), while showing the plain $5.00 for a Bronze-tier account.
- [ ] On the Silver account, add a **per-customer price override** for that same product (e.g. $3.00). Confirm the override wins — the account now sees $3.00, not the tier-discounted $4.50.
- [ ] Confirm the tier is never visible anywhere the customer can see it (shop, My Account, emails, the portal page).

## 14. Display/Case quantity-tier pricing + shipping (Standard/Volume/Bulk)

> Added 2026-09-17. Unlike sections 12–13, this **was** verified end-to-end live against staging via the Store API (`/wp-json/wc/store/v1/cart`) using a disposable test product and test wholesale account (both since deleted): confirmed a cart's line price switches correctly at exactly 100/170/1300 packs → $5.50/$5.00/$4.50 (the store's default Standard/Volume/Bulk prices), and that shipping switches correctly between the $19.95 flat rate and free shipping at the same 16-display threshold. The `protech_wholesale_shipping` method was added to the site's **"United States (US)"** shipping zone during that verification — confirm it's still there (WooCommerce → Settings → Shipping → United States (US)) before testing, and add it to any other zone the store ships from if one gets created later. What's below is the browser-based version of that same check, plus the pieces that couldn't be verified via the Store API alone (admin UI, per-product overrides, checkout completion).

- [ ] Open **WooCommerce → Wholesale → Pricing**. Confirm the Standard/Volume/Bulk store defaults show $5.50 / $5.00 / $4.50 per pack, a 16-display Volume threshold, a 16-case Bulk threshold, and a $19.95 shipping flat rate (or whatever the owner has since changed them to) — and that all five are editable and save correctly.
- [ ] On one product/variation's edit screen, set a **Volume price override** different from the store default (e.g. $4.75). Add enough of that product to a cart to cross the Volume threshold and confirm that specific product uses its override while every other wholesale product still uses the store default Volume price.
- [ ] Combine two different colors/products in the same cart such that neither alone reaches 16 displays but their **combined** total does. Confirm both lines still switch to the Volume price — thresholds are combined-cart, not per line item.
- [ ] Add a **per-customer price override** (Approval's profile field) for one product, then push the cart into the Volume tier. Confirm the override price still wins over the tier price for that one product, while every other product in the cart still uses its tier price.
- [ ] Assign a discounted customer tier (e.g. Silver, 10% off) to the test account. Push the cart into the Volume tier and confirm the displayed price is the Volume price *with* the 10% tier discount stacked on top (not just the plain Volume price) — e.g. $5.00 × 0.9 = $4.50, distinct from Bulk's flat $4.50 default (a coincidence at default values worth double-checking with a non-default discount percentage to be sure it's actually stacking).
- [ ] With the cart below the 16-display threshold, go to checkout and confirm **Wholesale Shipping** shows a $19.95 flat rate as the only shipping option (retail's Flat rate/Free shipping methods should not also appear).
- [ ] Cross the 16-display threshold and confirm the same **Wholesale Shipping** method now shows **$0.00 (free)**, still as the only option.
- [ ] Complete one full checkout in each state (below and at/above the threshold) and confirm the order total, shipping line, and per-item price on the order confirmation page/email all match what the cart showed.
- [ ] As a retail (non-wholesale) customer/guest, confirm none of the above is visible: no tier bar, no Wholesale Shipping method at checkout (only the normal retail Flat rate/Free shipping options), and product prices unaffected by cart quantity.

## 15. Sticky global tier bar (every page, in the store's own colors)

> Added 2026-09-17, redesigned the same day to match Bambu Lab's bulk-discount bar (message+totals strip / track panel with threshold markers / CTA) and this site's own black/white palette instead of an arbitrary color — see `DECISIONS.md`, "Quick Order removal, shop-page visibility fix, and bar redesign," items 3–4. Verified end-to-end live: the bar renders correctly on an ordinary single product page, the shop grid, and the (WooCommerce Blocks) cart page, with its message/fill/stats/subtotal matching a real cart's contents. Two real bugs surfaced and were fixed during that work, both worth re-checking specifically since neither produced a visible on-page error — see the note in that DECISIONS.md section, and the "ends with `</html>`" check below.

- [ ] As an approved wholesale customer, visit any ordinary single product page. Confirm a bar appears fixed to the bottom of the viewport: a black strip with a message and live cart subtotal/Display-Case count, a light grey track panel with two threshold-marker dots (labeled "16 displays"/"Free shipping" and "16 cases"/"Best price" on wider screens), and a "View cart" button — all in black/white/grey, not a color that doesn't appear anywhere else on the site.
- [ ] **View the page's source (not just the rendered page) and confirm it ends with a real `</html>` tag.** This is the specific failure mode two different bugs caused during development — a plugin PHP fatal in this bar's own template/handler can truncate the entire response silently while still returning a 200, with no visible on-page error. If a future change to this bar (or Reorder, which shares the same failure mode) ever breaks it again, this is the check that will actually catch it.
- [ ] Resize the browser below ~680px wide (or use phone device emulation). Confirm the bar stacks into three rows (message, track, "View cart"), the marker labels and reward text ("Free shipping"/"Best price") disappear, but the marker dots, message, live subtotal/count, and "View cart" button all remain visible and usable — this is a deliberate difference from Bambu's own mobile view (see DECISIONS.md for why).
- [ ] Add a wholesale product to your cart from that product page's normal "Add to Cart" button (with the Display/Case unit selector — section 16). Confirm the page does **not** reload, a brief loading state shows on the button, and the bar updates in place to reflect the new cart subtotal, Display/Case count, and message — this specifically exercises `unit-selector.js`'s Store-API-based AJAX add (see `DECISIONS.md`, "Bar made non-dismissible, and a real live-update gap fixed," for why the more obvious approach didn't work for a variable product).
- [ ] Cross the Volume threshold this way and confirm the message, fill percentage, active-tier marker styling, and subtotal/count all update correctly.
- [ ] Also confirm the site's normal mini-cart (if the theme has one) updates too after that same add — it should, since the fix reuses WooCommerce's own `wc_fragment_refresh` mechanism rather than something bar-specific.
- [ ] Confirm there is **no dismiss/close control** on the bar — it's deliberately not dismissible (removed 2026-09-17, see `DECISIONS.md`) so a wholesale customer can't lose track of their pricing progress.
- [ ] Visit the shop/category grid and the cart page as the wholesale customer; confirm the sticky bar appears on both with the same live-updating behavior.
- [ ] Click the bar's "View cart" button and confirm it goes to `/cart/`.
- [ ] As a retail (non-wholesale) customer/guest, confirm the sticky bar never appears anywhere on the site.

- [ ] **Scroll partway down a long page (not all the way to the bottom) and confirm the bar stays pinned to the bottom of the *viewport*** — not just visible once you happen to scroll to the bottom of the *page*. This is the specific bug reported 2026-09-17 (screenshot showed the bar sitting under the site footer, scrolling away with the page instead of staying fixed) — caused by Salient's AJAX page-transition wrapper (`#ajax-content-wrap`/`.ocm-effect-wrap`) applying a CSS transform, which makes a `position: fixed` descendant fixed relative to that wrapper instead of the viewport. Fixed by re-parenting the bar onto `<body>` directly via JS (`global-tier-bar.js`) — **not yet visually re-confirmed**, since this environment has no browser to check it in; this is the first real check that should re-run after any deploy touching this bar.

> If the bar still isn't visible at all after that check (a different symptom than the one above): first confirm the account testing with actually has the **Wholesale Customer** role (an admin account isn't wholesale unless separately flagged — see README, "Manually flagging an existing customer"). If it does, check browser dev tools for a `#protech-global-tier-bar` element at the end of `<body>` — present-but-invisible points to a CSS conflict or an ad blocker's cosmetic filters (a fixed bottom bar with "bar" in its class name is a fairly common generic ad-blocker target); missing entirely points back to the role/account question.

## 16. Display/Case unit selector (single product page)

> Added 2026-09-17, after "no way to differentiate Display/Case" feedback on the real flagship product. A small "Order by: Display / Case" + quantity control now sits above the real (JS-hidden) pack-quantity field on the single product page, wholesale-customers-only. Two real bugs were found and fixed getting here — see `DECISIONS.md`, "Display/Case unit selector on the product page, and a stepper bug": the on-page quantity stepper was incrementing by 1 instead of jumping by the case size (a WooCommerce arg-key typo, `input_step` vs `step` — validation itself was never affected), and the selector didn't render at all on first attempt (a hook-signature mismatch, fixed the same way `ProductFields::render_simple_fields()` already handles it).

- [ ] As an approved wholesale customer, open the real flagship product's page. Confirm an "Order by" dropdown (Display / Case) and a "Quantity" number field appear above the Add to Cart button, and that the real WooCommerce quantity stepper is no longer visible.
- [ ] With "Display" selected and quantity `1`, click the +/- on the (now hidden) native field is moot — instead confirm Add to Cart adds **10 packs** (or whatever the product's actual packs-per-display is). Set quantity to `3` Displays and confirm it adds 30 packs.
- [ ] Switch to "Case" and set quantity `1`. Confirm it adds **80 packs** (packs-per-display × displays-per-case), not 10.
- [ ] On the variable flagship product, switch color (variation). Confirm the "Order by" dropdown's option labels (e.g. "Display (10 packs)") stay correct for the new color, and that adding to cart still adds the right pack count — this exercises `add_unit_data_to_variation()` / the `found_variation` re-sync, even though every color on this catalog currently shares the same case size.
- [ ] Disable JavaScript (or block the plugin's scripts) and reload the product page. Confirm the "Order by" selector is still visible but inert, and the real WooCommerce quantity field is visible and usable underneath it, still correctly stepped in multiples of the case size (min/step from `CaseRules::set_quantity_step()`, unaffected by JS) — a degraded but still-correct fallback, never a silently wrong one.
- [ ] As a retail (non-wholesale) customer/guest, confirm the "Order by" selector never appears, and the quantity field behaves with no restriction (step 1, min 1).
- [ ] Click Add to Cart with JS enabled. Confirm the button shows a brief loading state, the page does **not** reload, and the "Order by"/Quantity fields reset back to "Display" / `1` afterward (ready for the next add).
- [ ] Force a validation failure — e.g. edit the DOM (or a script blocker) to submit a quantity that isn't a multiple of the case size — and confirm an inline error message appears near the selector (in place of the usual "X packs total" hint) rather than the page silently doing nothing or crashing.
