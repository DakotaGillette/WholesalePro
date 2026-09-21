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
- [ ] **(Superseded in 1.2.0 — see §18.)** The plugin's own UI is now Protech Blue (`#42649D`) on purpose, while the theme's buttons (Add to cart, Checkout) stay black. Confirm the blue elements read as one family, their text uses the site's own font (Poppins), and corners/neutrals still match the theme (8px, `#f7f7f7`, `#e5e5e5`).

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
- [ ] ~~Confirm that account's cart/checkout minimum is now $200~~ — the dollar minimum is not enforced (see §5); confirm instead that the Tiers tab and the profile field both say so, and that checkout is NOT blocked for the Silver account at a low subtotal.
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

- [ ] **(Look updated in 1.2.0 — §18 has the visual checks.)** As an approved wholesale customer, visit any ordinary single product page. Confirm a Protech Blue bar floats at the bottom of the viewport with a tier chip and message, a track with three markers (Standard, "16 displays", "16 cases"), the live cart subtotal/Display-Case count, and a "View cart" button.
- [ ] **View the page's source (not just the rendered page) and confirm it ends with a real `</html>` tag.** This is the specific failure mode two different bugs caused during development — a plugin PHP fatal in this bar's own template/handler can truncate the entire response silently while still returning a 200, with no visible on-page error. If a future change to this bar (or Reorder, which shares the same failure mode) ever breaks it again, this is the check that will actually catch it.
- [ ] Resize the browser below ~680px wide (or use phone device emulation). Confirm the bar goes full width, flush to the bottom edge, in **two** rows (chip + message + a cart button showing the subtotal; then the track with the display/case count beside it), the marker captions and tick marks disappear, but the marker dots, message, count and cart button all remain visible and usable — this is a deliberate difference from Bambu's own mobile view (see DECISIONS.md for why).
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

- [ ] As an approved wholesale customer, open the real flagship product's page. Confirm an "Order by" pair of cards (Display / Case) and a "How many" −/+ stepper appear above the Add to Cart button, and that **the theme's own quantity stepper is NOT visible anywhere on the page** — it never actually hid before 1.2.0 (see `DECISIONS.md`, "Storefront polish", item 3), which is the bug this check exists for.
- [ ] With "Display" selected and quantity `1`, click the +/- on the (now hidden) native field is moot — instead confirm Add to Cart adds **10 packs** (or whatever the product's actual packs-per-display is). Set quantity to `3` Displays and confirm it adds 30 packs.
- [ ] Switch to "Case" and set quantity `1`. Confirm it adds **80 packs** (packs-per-display × displays-per-case), not 10.
- [ ] On the variable flagship product, switch color (variation). Confirm the "Order by" cards' captions (e.g. "10 packs", "8 displays · 80 packs") stay correct for the new color, and that adding to cart still adds the right pack count — this exercises `add_unit_data_to_variation()` / the `found_variation` re-sync, even though every color on this catalog currently shares the same case size.
- [ ] Disable JavaScript (or block the plugin's scripts) and reload the product page. Confirm the "Order by" selector is still visible but inert, and the real WooCommerce quantity field is visible and usable underneath it, still correctly stepped in multiples of the case size (min/step from `CaseRules::set_quantity_step()`, unaffected by JS) — a degraded but still-correct fallback, never a silently wrong one.
- [ ] As a retail (non-wholesale) customer/guest, confirm the "Order by" selector never appears, and the quantity field behaves with no restriction (step 1, min 1).
- [ ] Click Add to Cart with JS enabled. Confirm the button shows a brief loading state, the page does **not** reload, a green "Added … to your cart" line appears under the stepper for a few seconds, and the quantity resets to `1` while the chosen unit (Display or Case) **stays** as it was.
- [ ] Force a validation failure — e.g. edit the DOM (or a script blocker) to submit a quantity that isn't a multiple of the case size — and confirm an inline error message appears near the selector (in place of the usual "3 displays · 30 packs" read-back) rather than the page silently doing nothing or crashing.

## 17. Version 1.1.0 audit fixes

> Added 2026-09-17 after the code audit. None of this was run locally (no PHP/Docker on the build machine); CI is the first execution. These are the staging checks for each fix, in the order they matter.

**Security / roles**
- [ ] Submit `/wholesale-application` with an **administrator's** email address. Confirm the administrator keeps every role and capability (Users list still says Administrator; wp-admin still works for them), an application record appears under Applicants → Pending with the "existing staff account" note, and the admin email says the roles were left unchanged.
- [ ] Submit it with an existing **retail customer's** email. Confirm the account now has BOTH `customer` and `wholesale_pending` (Users → edit → role list, or `wp user get <id> --field=roles`).
- [ ] Approve a pending applicant. Confirm they gain `wholesale_customer`, lose `wholesale_pending`, and keep any other role they had.
- [ ] Un-flag a wholesale customer from their profile. Confirm only the wholesale role is removed (a shop manager stays a shop manager; a plain account becomes `customer`).

**Applications**
- [ ] Reject an applicant from the Pending view: a prompt asks for an optional reason; after submitting, they appear under **Rejected** with the reason, are gone from Pending, the count on each view tab is right, and the rejection email contains the reason.
- [ ] Log in as that rejected user and visit `/wholesale`. Confirm the "update on your application" message, not "under review".
- [ ] Approve from the Rejected view. Confirm it works and the reason is cleared on their profile.
- [ ] Open the applicant's profile. Confirm the "Wholesale application" section lists all 13 answers, the status, and the submission time.
- [ ] Search on the Applicants tab (by email); confirm pagination controls appear once there are more than 20 in a view.
- [ ] Open the admin notification email for a new application. Confirm it has the store's branded header/footer, the full answers, a working "Review" link to the profile, and Reply-To set to the applicant.
- [ ] Open the approval email. Confirm the "Set your password" button opens the **My Account** password form (not wp-login.php) and works.

**Orders (Blocks checkout)**
- [ ] Place a wholesale order through checkout. In WooCommerce → Orders confirm the **Wholesale** badge on it, that the "Wholesale only" filter finds it, that the admin new-order email subject starts with "WHOLESALE ORDER", and that each line item shows "Displays: N" meta.
- [ ] Confirm the wholesale filter dropdown appears once (top of the list only).

**Products**
- [ ] Edit the flagship variable product. Confirm a **Wholesale** tab exists with "Packs per display", "Displays per case", and "Wholesale only", plus a note pointing to the Variations tab for prices. Confirm a simple product's Wholesale tab also shows the price fields.
- [ ] Variations tab → bulk action "Set wholesale prices": a prompt appears (once), and after entering a value every variation's wholesale price is updated. Repeat for "Set Volume price overrides".
- [ ] Set "Packs per display" on the parent only; confirm a variation's product page steps by that size.

**Catalog**
- [ ] As a guest, load `/wp-json/wc/store/v1/products?per_page=100` and confirm no wholesale-only product is listed. Confirm the same via the shop grid, site search, and `/wp-sitemap-posts-product-1.xml`.
- [ ] As a wholesale customer with "hide" set, confirm the shop grid shows the flagship product and no unpriced products, and that "Showing X–Y of Z results" counts are right (no short pages).
- [ ] Check WooCommerce → Status → Logs (source `protech-wholesale`) for the "Upgraded plugin data to version 2" line after the first page load post-deploy.

**Pricing / cart**
- [ ] With an empty cart, open the flagship product as a wholesale customer. Confirm a "Wholesale pricing" table shows Standard/Volume/Bulk with prices and thresholds, "Your cart" on Standard.
- [ ] Add 16+ displays, reload the product page and the shop grid: the table marks Volume, and the grid/range price is the Volume price immediately (no stale $5.50).
- [ ] On the Blocks cart page, use the +/- on a wholesale line: confirm it moves by the display size and a typed non-multiple is rejected.
- [ ] Confirm the sticky bar does NOT appear on the checkout page, does appear elsewhere, and updates after a product-page add with the mini-cart closed.
- [ ] My Account dashboard: confirm the "Your wholesale account" panel shows the cart tier and thresholds.
- [ ] Log in via the My Account form (not the portal); confirm a wholesale customer lands on the shop.

**Shipping**
- [ ] Wholesale customer with only a non-wholesale (retail-priced) item in the cart: the zone's retail methods show, not Wholesale Shipping.

**Tooling**
- [ ] Confirm GitHub Actions is green on the latest commit (PHPUnit inside wp-env). Lint/analysis steps are advisory.
- [ ] Run `bin/deploy.sh` and confirm `tests/`, `vendor/`, and `composer.json` are absent from the plugin directory on staging.

## 18. Version 1.2.0 storefront polish

> Added 2026-09-17 (evening). A visual pass over everything a wholesale customer sees — see `DECISIONS.md`, "Storefront polish, version 1.2.0". **None of this has been seen in a browser yet**: it was written against the live theme's CSS and markup, and the JavaScript passed a syntax check, but this checklist is the first real look. Hard-refresh once after deploying. Test at 1440px, ~800px and 375px unless a check says otherwise.

**Product page: one quantity control**
- [ ] Exactly one quantity control is visible — the theme's own −/+ pack stepper is gone (this was the reported "two quantity selectors" confusion).
- [ ] The Display and Case cards each state their pack count; the selected card is blue with a check. Tab to them and switch with the arrow keys; the focus ring is visible.
- [ ] −/+ changes the number, never below 1. Typing a number works; clearing the field and tabbing away restores `1`.
- [ ] The line under the stepper reads e.g. "3 displays · 30 packs", and in Case mode "2 cases · 16 displays · 160 packs". The word beside the stepper follows the unit and the plural.
- [ ] The Add to cart button reads "Add 3 displays to cart" / "Add 1 case to cart" and the cart receives exactly that many packs.
- [ ] After adding: a green "Added 3 displays (30 packs) to your cart." line shows for a few seconds, the quantity returns to 1, the unit stays put.
- [ ] Choose Case, open a different product: Case is preselected (remembered per browser).
- [ ] On the variable flagship product, change color: card captions, read-back and button text stay correct.

**Sticky bar**
- [ ] Desktop: a rounded Protech Blue dock floats ~14px above the bottom edge. **Its left and right edges line up exactly with the floating header card.** Resize the window: it follows. (If the header can't be found it falls back to a centred 1180px.)
- [ ] **Scroll to the very bottom of several pages (home, shop, a product, My Account, the cart).** Page content can be scrolled fully clear of the dock, and the space under the footer is the **footer's own color** — no white (or any other) band. This was the first bug found on staging: see `DECISIONS.md`, "Storefront polish", item 14. Also check a page whose last section is light: the space should be light there.
- [ ] It slides in once on the first page of a browser session and does not re-animate on later pages.
- [ ] Left to right: a "WHOLESALE · STANDARD" chip with the message (quantity in bold), the track, subtotal with "N displays (N cases)" under it, a white "View cart" pill.
- [ ] Track: three markers — Standard at the start, "16 displays" at 40% with "$5.00/pack + free shipping", "16 cases" at the end with "$4.50/pack, our best price" — plus faint tick marks between them. Reached markers are white with a blue check. (Prices are the store defaults from the Pricing tab with the account's tier discount; if the catalogue ever makes that misleading, `protech_wholesale_tier_bar_show_prices` removes them.)
- [ ] With an empty cart the message reads "Add 16 displays to unlock Volume pricing and free shipping."; with 15 displays, "Add **1** more display…" (singular).
- [ ] **Preview:** on a product page, raise the quantity and watch a paler striped segment extend ahead of the fill. When the dialled-in quantity would cross a tier, that marker pulses.
- [ ] **Celebration:** from under 16 displays, add enough to cross into Volume. Confirm, once: the Volume marker pops with a ring, a sheen sweeps the dock, confetti bursts upward from the marker, the chip changes to VOLUME, a gold "Saving $X" appears, and the message changes. Navigate to another page: no replay.
- [ ] Repeat into Bulk (16 cases): chip turns white-on-blue "BULK", track is full, "You've unlocked our best price and free shipping!".
- [ ] Cross a tier through a full page reload instead (use **Reorder** on a large past order): the celebration still fires once after the page loads.
- [ ] Remove items to drop a tier: the bar updates quietly, no celebration.
- [ ] The cart button gives a small bump on every cart change; changed numbers tick into place.
- [ ] Below ~1160px wide (try 1100px and 800px): two rows; the cart pill shows the subtotal instead of "View cart"; marker captions still visible and never overlapping each other.
- [ ] 375px: flush to the bottom, rounded top corners, dots only (no captions or ticks), message clamps to two lines, nothing overlaps. On an iPhone the home-indicator area is clear.
- [ ] With the OS "reduce motion" setting on: no slide-in, shine, pulse or confetti; tier changes still show through color and text.
- [ ] Still absent on checkout; still absent for retail customers and guests.

**Product page price table**
- [ ] The active row has a blue left rule and tint with a "Your cart" badge; Volume and Bulk rows show a green "Save N%" chip that matches the prices.
- [ ] After an AJAX add that crosses a tier, the highlight moves to the new row without a reload. (Known gap: the big price above the table only updates on the next page load.)

**Header cart badge**
- [ ] As a wholesale customer with 3 displays in the cart the navbar badge reads **3**, not 30 — desktop header and the mobile header. The Blocks mini-cart count agrees.
- [ ] With 120+ displays the badge is a pill with the number fully inside it and legible.
- [ ] As a retail customer the badge still counts items and looks exactly as before.

**My Account**
- [ ] Approved: a strip at the top of **every** account page (dashboard, orders, addresses, account details) shows a shield icon, the business name (billing company, else the name from the application), and a blue "WHOLESALE PARTNER" badge. It spans the full width above the navigation and doesn't break the two-column layout.
- [ ] Pending applicant: the same strip in amber with "APPLICATION UNDER REVIEW"; the existing notice still shows beneath it.
- [ ] Retail customer: no strip.
- [ ] Dashboard card: blue header with the tier badge, progress rail with a tick at the Volume point, stat tiles (displays, cases, subtotal, plus green "Tier savings" once at Volume), a three-line tier checklist with blue checks on unlocked tiers, and two buttons (blue "Shop at wholesale pricing", text-style "View cart").

**`/wholesale`**
- [ ] Logged out, desktop: a wide two-panel card roughly the width of the `/my-account` login — blue pitch panel on the left (four benefits whose numbers match the Pricing tab), form on the right. Compare both pages side by side.
- [ ] Inputs are tall with a blue focus ring; the eye button shows/hides the password; "Remember me" and "Lost your password?" share a row; the Log in button is blue and stays blue on hover (the theme forces black on submit buttons — this is the override to watch).
- [ ] A password manager offers to fill the form.
- [ ] Wrong password: the error appears in a red box with an icon and **no literal HTML tags**, and the username field keeps what was typed.
- [ ] Under ~860px the form sits above the pitch panel; at 375px nothing overflows.
- [ ] Pending: centred card with a clock icon and a three-step tracker (received ✓ → under review, pulsing → approved). Rejected and retail-only: the same card with their own icon and button.
- [ ] Headings and paragraphs on all four states have sane spacing (the theme adds 28px under every paragraph; the plugin resets it).

**Starter kit feature (not used on the Vendor Starter Kit — see §19 for that product)**

> The owner decided (2026-09-17, late) that the Vendor Starter Kit stays a plain unlisted $800 product for new vendors. The kit feature stays in the plugin for a future product. To exercise it, use a **disposable** simple product and delete it afterwards.

- [ ] Set up a test kit: on a disposable simple product's Wholesale tab tick **Starter kit**, choose *Protech Premium Matte Sleeves* under "Kit is built from", leave "Displays in a kit" empty (= 16), pick Black then White under "Make up the difference with", tick Wholesale only, Update.
- [ ] As an admin (not wholesale), open that product's page: it loads (no 404) and shows the "approved wholesale accounts only" notice with no price and no button.
- [ ] As the wholesale customer: the page shows every color with a swatch, "×2" on Black and White, "16 displays · 160 packs", a kit total of **$800.00** with "Volume pricing ($5.00/pack) with free shipping.", a how-many stepper, and "Add starter kit to cart". The price beside the title is the same $800.00. No Display/Case control and no price table on this page.
- [ ] The sticky bar's preview segment reaches the "16 displays" marker and that marker pulses while the page is open with an empty cart.
- [ ] Set 2 kits: the button reads "Add 2 starter kits to cart" and the total re-quotes (32 displays, still Volume pricing). Set 8: 128 displays is 16 cases, so the quote switches to Bulk pricing ($4.50/pack).
- [ ] Add 1 kit with an empty cart. Confirm: a green "Starter kit added: 16 displays are in your cart." line, the bar celebrates Volume, the cart holds one line per color (Black and White at 20 packs, the rest at 10), no Vendor Starter Kit line, subtotal $800.00, free shipping at checkout.
- [ ] Mark one color out of stock and reload the kit page: it is listed as left out, and the fillers cover the shortfall so the kit is still 16 displays.
- [ ] Reorder the resulting order from My Account: all 16 displays come back into the cart.
- [ ] Disable JavaScript and click the button: the kit is added and you land on the cart with the same success notice.

## 19. Version 1.3.0 admin audit, updates, and the Vendor Starter Kit

> Added 2026-09-18. Written against the WordPress and WooCommerce source but not run locally; the admin screens were fetched with the saved admin session after deploy (every tab 200, no critical error) and nothing more. This is the first real look.

**Vendor Starter Kit (product #1656), back to a plain product**
- [ ] On its edit screen: Regular price 800; Catalog visibility (Publish box) **Hidden**; Wholesale tab: Wholesale only **unticked**, Wholesale price **empty**, Starter kit **unticked**, Packs per display empty. Update.
- [ ] Logged out, open `/product/starterkit/` from the one-sheet link: an ordinary $800 product with a quantity box stepping by 1 and Add to cart. Add it: the cart holds one "Vendor Starter Kit" line at $800. It does not appear in the shop or search.
- [ ] Logged in as a wholesale customer, open the same link: $800, quantity 1 accepted (no "multiples of 10 packs" error), no Display/Case control, no price table, no "Displays: N" on the cart line, and the sticky bar's display count and tier do not move when it is in the cart.
- [ ] The header cart badge counts it as 1.

**Wholesale screen**
- [ ] With a pending application, the WooCommerce → Wholesale menu item shows a red count bubble; approve or reject it and the bubble updates (within five minutes at most; immediately after an approve/reject).
- [ ] Under the "Wholesale" title: links to the wholesale login page, the application form and the log. All three open the right thing.
- [ ] Temporarily remove Protech Wholesale Shipping from its zone: the Applicants tab shows a yellow "not fully set up" notice naming that; add it back and the notice is gone. Set the application form ID to 999: the notice names the missing form; restore it.
- [ ] The Help pull-down (top right) has "Wholesale tabs" and "Pricing a product".
- [ ] Tiers tab: two columns only (Tier, Discount), no minimum order anywhere; save a Silver discount and confirm a Silver customer's prices reflect it.
- [ ] Customers tab: loads quickly; change one customer's tier in the dropdown and Save tiers; their profile shows the new tier.
- [ ] Pricing & Shipping: three groups; the ladder rows read Standard / Volume / Bulk with no "(Tier N)"; the shipping group names the zone(s) that have the method; the "Never give wholesale orders the retail free-shipping rule" box saves and reloads correctly.
- [ ] Settings: Catalog, Applications (with the notification email), Updates, Uninstall. Set a notification email, submit a test application, confirm it arrives there.
- [ ] Products tab: Volume/Bulk override columns and a Flags column; the empty-state text no longer mentions a "General pricing section".
- [ ] Products → All Products: a "Wholesale" column after Price showing the price (or range), with "Wholesale only" / "Starter kit" badges where set.
- [ ] Flagship product → Wholesale tab: three "Apply to all variations" fields with a "Currently $5.50 on 14 of 14 variations" line. Enter 5.55 in the first, Update: every variation shows 5.55 on the Variations tab and the line updates. Put it back to 5.50.
- [ ] User profile: the "Minimum order override" field is gone; tier and price overrides still save.

**Plugins list and updates**
- [ ] Plugins → Protech Wholesale row: "Applicants | Settings" before Deactivate; "Changelog | Check for updates" under the description. Changelog opens a modal with the release notes; Check for updates returns to the list with a notice.
- [ ] With staging on an older version than the latest GitHub release: the row shows "There is a new version of Protech Wholesale available" with View details; Update now installs it, the plugin stays active, and the version on the row changes.
- [ ] Settings → Updates shows installed version, latest release with date, last checked, and Check now.

## 20. Header banner shortcode (1.4.0)

> Requires a one-time manual step: in Salient's Theme Options (or Customizer) → Header → "Text To Display In Header", wrap the existing text: `[protech_header_notice]FREE SHIPPING WITH $30+ ORDERS[/protech_header_notice]`. Not done automatically — see README, "Other customer-facing pieces".

- [ ] Logged out: the header still reads exactly "FREE SHIPPING WITH $30+ ORDERS", same as before.
- [ ] Logged in as a retail (non-wholesale) customer: same, unchanged.
- [ ] Logged in as an approved wholesale customer: the header instead reads "FREE SHIPPING ON WHOLESALE ORDERS OF 16+ DISPLAYS" (or the current Volume threshold from Pricing & Shipping).
- [ ] Change the Volume threshold on Pricing & Shipping to a different number, reload as a wholesale customer: the header number matches.
- [ ] Confirm the styling (color, alignment, size) matches the rest of that header text — it inherits the theme's own CSS since no markup is added, only the text.

## 21. Messaging & automations (1.5.0)

> Requires, before SMS can work at all: a Brevo toll-free number registered and verified (see the Compliance view's CSV/wording/suggested privacy text — that's the submission package). Email works with no extra setup beyond Brevo already being connected (it is, on staging).

**After the update lands** (never by zip — let staging discover the release, so the migration path itself is tested):
- [ ] WooCommerce → Status → Scheduled Actions has no error; the plugin's table exists (`wp db query "SHOW TABLES LIKE '%protech_wholesale_messages%'"` or check via phpMyAdmin) even though the site was never reactivated.
- [ ] `_protech_wholesale_approved_at` is set on the one existing wholesale customer (djg10212) after the update — confirms the backfill ran.

**Settings**
- [ ] Messaging → Settings → "Test Brevo connection" shows "Connected as Allen Tran" (or whoever the account is).
- [ ] Turn on "Automations enabled", save, confirm `protech_wholesale_daily_automations` appears under Scheduled Actions (group `protech-wholesale`) with a future run time.

**Compose (manual send)**
- [ ] Customers tab: tick the one wholesale customer, "Send message to selected" lands on Compose with them pre-selected.
- [ ] Write a subject/body with `{first_name}` and `{shop_url}`, "Send preview" (leave the email blank) — arrives at your own address with the branded header/footer and the tags filled in.
- [ ] "Preview recipients" shows the right count before sending for real.
- [ ] "Send" queues it; the Log (filtered by the campaign id in the URL) shows it move from queued → sent within a minute or two of a real page load (cron permitting — see the cron note in the README if it sits at "queued").

**Automations**
- [ ] Add the "Reorder reminder" preset, leave disabled, click Preview — shows a sensible recipient count and skip reasons (most real customers will show "window" until an order is 30 days old).
- [ ] Enable it, "Run automations now", check the Log a minute later.
- [ ] Add the "Order status" preset for "completed", place (or advance) a real test order to Completed, confirm an email/SMS shows up in the Log tied to that order within the configured delay.

**Compliance / SMS opt-in**
- [ ] Messaging → Compliance shows both wording variants, and flags that the privacy policy doesn't mention SMS yet (until the owner adds it).
- [ ] Download consent records CSV opens in a spreadsheet with sensible columns.
- [ ] My Account → Notifications (logged in as the wholesale customer) saves a phone number and both SMS checkboxes; reloading the page shows them still checked.
- [ ] An admin profile's "Messaging" section refuses to save a consent change with no note, and records one correctly with a note.
- [ ] Click a real `{unsubscribe_url}` link from a test email while logged out: lands on `/wholesale` with the "you're unsubscribed" notice, and My Account → Notifications (once logged in) shows the email checkbox now unchecked.

**SMS itself** (only once a toll-free number is registered with Brevo)
- [ ] "Send preview" with a phone number typed in reaches that real phone with the brand prefix and, for a marketing message, "Reply STOP to opt out."
- [ ] Reply STOP on a real phone, then try sending another marketing text to that number — the Log shows it skipped as unsubscribed, not sent.

## 22. MSRP price, login landing, tier names, quantity legend (1.5.0)

Log in as the wholesale customer (djg10212) for everything below.

**MSRP price**
- [ ] Product page for Protech Premium Matte Sleeves: the price line reads "~~$9.99~~ $5.50 Save 45% Wholesale price" (the crossed-out amount is the same grey as in the price table below) (or $5.00 / Save 50% once the cart is at 16+ displays). Picking a color keeps the strikethrough.
- [ ] Shop grid: every wholesale-priced product shows the same crossed-out MSRP; the Vendor Starter Kit (no wholesale price) shows its plain $800.
- [ ] Logged out or as a retail customer: no strikethrough anywhere, plain $9.99.

**Login landing**
- [ ] WooCommerce → Wholesale → Settings → "After a wholesale login, go to" shows the Premium Matte Sleeves URL. Save with it empty, log out, log in on `/wholesale`: lands on the shop. Put the URL back, log in again: lands on the product page. Same from My Account's login form.
- [ ] Visiting `/wholesale` while already logged in bounces to the same page.

**Tier names**
- [ ] Product page price table: first row "Under 16 displays" with no name, then "Standard, 16+ displays, Free shipping", then "Volume, 16+ cases (128 displays), Best price". "Your cart" sits on the right row.
- [ ] Sticky bar with an empty cart: chip reads just "WHOLESALE", message "Add 16 displays to unlock free shipping and Standard pricing.", first marker "Under 16 displays / Wholesale price". At 16 displays the chip reads "WHOLESALE · STANDARD"; at 16 cases "WHOLESALE · VOLUME".
- [ ] My Account dashboard card: badge "Wholesale pricing" / "Standard pricing" / "Volume pricing"; the tier list starts with "Under 16 displays".
- [ ] Admin: Pricing & Shipping rows read Base / Standard / Volume; a variation's fields read "Standard price override" and "Volume price override"; the Products tab columns "Standard override" / "Volume override". Existing override values are unchanged.

**Legend**
- [ ] Above the "Wholesale pricing" table on the product page (full width, not next to the order control): "How wholesale quantities work" with the three tiles (1 pack, ×10, 1 display = 10 packs, ×8, 1 case = 8 displays (80 packs), all in US spelling (color)) and the line "1 case = 8 displays. Mix and match your displays however you'd like. Free shipping from 16 displays (2 cases), any combination of colors."
- [ ] On a phone the three tiles stack and nothing overflows.
- [ ] Not shown logged out, and not on the Vendor Starter Kit page.

## 23. Welcome email and previews (1.6.0)

**Welcome email**
- [ ] WooCommerce → Wholesale → Customers → "Welcome email" (collapsed box): type your own address, "Send preview". It arrives with the branded header, "Hi <your first name>", your address in step 2, the working login and reset links, the three-tile picture (colors) and "8 displays = 1 case."
- [ ] Look at it on a phone and in Gmail: the tiles line up and nothing is cut off.
- [ ] The table has a Welcome email column ("Not sent" plus Send). Send to yourself as a test wholesale customer: the column now reads "Sent <today>" with Resend, and the Messaging → Log shows a "welcome" row.
- [ ] Tick two customers and press "Send welcome email": the notice says how many were sent.
- [ ] "Add existing customers to wholesale": the box is ticked by default; adding a plain customer with it ticked sends them the welcome email (not the "set your password" one) and adds no reset key.

**Add one display of every color**
- [ ] On the Premium Matte Sleeves page, directly under the Wholesale pricing table (above the color picker): "Want every color?" with a swatch per color, the line "One display of each of the N colors: N displays, N0 packs", a price, and the outlined button.
- [ ] Press it with an empty cart: a green "Added N displays to your cart, one of every color." line, the cart badge, the sticky bar and the mini-cart update, and the cart holds one line per color at one display each.
- [ ] Mark one color out of stock and reload: an amber box says "Out of stock: <color>. It will not be added. The other N colors are added.", its swatch is crossed out, the line says "colors in stock", the button reads "Add one display of each color in stock", and the count drops by one. Add a color (with a wholesale price) and reload: the count goes up with nothing else changed.
- [ ] Not shown to a guest or a retail customer, and not on a product with only one color.

**Previews**
- [ ] Compose: fill in an email, type a friend's address in "Send a preview", press it. They receive it; your own inbox does not.
- [ ] Compose with the channel set to Both and a phone number typed: two green notices (email and text).
- [ ] An automation rule (reorder reminder): "Send a preview" works before saving, and the rule is not saved by it.
