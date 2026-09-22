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
- [ ] "Review and send" opens the review screen with the right count (see section 29) before anything is sent.
- [ ] "Send ... now" on the review screen queues it; the Log (filtered by the campaign id in the URL) shows it move from queued → sent within a minute or two of a real page load (cron permitting — see the cron note in the README if it sits at "queued").

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

## 44. Automations v2: flows (3.6.0)

- [ ] Messaging → Automatic lists the four standard flows (imported from the old rules),
  each switched off, with the same names as before.
- [ ] The Emails screen's own "Automatic" section no longer shows a rule table, only a line
  linking to the Automatic screen; the link works.
- [ ] New flow: trigger "Their wholesale account is approved", one step "Send an email" with
  a subject and body, save it, and switch it on.
- [ ] Approve a real (or test) applicant through the normal Applicants queue. Confirm the
  flow's row on the Automatic list shows one entered, and the message appears in the Log.
- [ ] Edit that same flow to change a subscriber's role by hand from Users → your test
  account instead of through the approval queue. Confirm that also starts the flow.
- [ ] New flow: trigger "An order reaches a status" (completed), steps "Wait" (a few
  minutes) then "Send an email". Move a test order to that status. Confirm nothing sends
  immediately, the flow's row shows one waiting, and the email arrives once the wait elapses.
- [ ] New flow: trigger "Only when added manually" is not offered a way to fire itself from
  the UI yet (expected); confirm the trigger dropdown still lists it without error.
- [ ] New flow: one step "Branch on a condition" (is a wholesale customer), each branch a
  different "Send an email". Confirm the step-slot fields for the branch you did not choose
  are hidden without a page reload, and typing into a slot's type dropdown swaps its fields.
- [ ] Turn a flow with an active or waiting run off. Confirm the flow's counts do not
  continue to change and no further message from it appears in the Log.
- [ ] Duplicate a flow; confirm the copy is switched off, keeps the same steps, and edits
  independently of the original.

## 43. Signup forms and double opt-in (3.5.0)

- [ ] Messaging → Forms → New form: give it a name, consent wording and a success message,
  save it, and note its shortcode.
- [ ] Embed that shortcode on a page (or a text widget). Confirm it shows the first-name
  field (if turned on), an email field and the consent checkbox with your own wording, and
  that submitting without ticking the checkbox is blocked by the browser.
- [ ] Submit with a real email address you can check. Confirm the page shows the form's
  success message, and Messaging → Contacts shows a new row with status "Unconfirmed".
- [ ] Open the confirmation email and click its link. Confirm the browser shows a plain
  "you're subscribed" page, and the contact's status is now "Subscribed".
- [ ] Click that same confirmation link again. Confirm it now says the link is not valid,
  and the contact's status is unaffected.
- [ ] View page source on the embedded form and confirm there is a hidden field a person
  would never notice or fill in (the honeypot).
- [ ] Submit the same form seven times in a row. Confirm the last couple still show the
  normal success message (never an error that would tip off a script trying this).

## 42. A contacts directory (3.4.0)

- [ ] Messaging → Contacts lists existing wholesale and retail accounts, backfilled
  automatically on update. Search by name, email or company; filter by status and source.
- [ ] Check out as a guest with an email not used before. Confirm a new row appears on
  Contacts with source "Guest checkout" and status "Transactional only".
- [ ] Check out again with that same guest email. Confirm it is still one row, not two.
- [ ] Open a contact's profile: order count, lifetime value and last order date match what
  WooCommerce → Orders shows for that person.
- [ ] Click "Unsubscribe" on a contact linked to a real account. Confirm their My Account →
  Notifications page now shows email marketing off, and the Compliance screen's consent log
  for that account shows the new entry, not only the Contacts screen's own history.
- [ ] Export contacts to CSV; confirm it opens in a spreadsheet with every visible column.
- [ ] Confirm Compose and Automations behave exactly as before: audience selection is
  unchanged, and no guest contact appears as a sendable recipient yet.

## 41. WooCommerce's own order emails become designable (3.3.0)

- [ ] Messaging → Emails shows a new "Order emails" list below "When someone joins", with
  nine rows, each showing "The WooCommerce default design" and a "Design this email" button.
- [ ] Click "Design this email" on Processing order: opens the editor with a heading, a line
  of text and Order items / Order totals blocks already in place, bound to that email.
- [ ] Place a wholesale test order and move it to Processing. Confirm the email that arrives
  is the designed one (not WooCommerce's default), with the real order number in the
  subject, the real products and quantities in Order items, and the real subtotal/shipping/
  tax/total in Order totals matching what the order screen shows.
- [ ] Confirm the subject still starts with "WHOLESALE ORDER" for a wholesale order's New
  order email (to the shop) once that slot is also designed.
- [ ] Place an order as a guest (log out first) and confirm the designed Processing order
  email still fills in the name from what was typed at checkout, not blank or an error.
- [ ] Back on the Order emails list, the row now shows "Designed: [name]" with "Edit design"
  and "Use the WooCommerce design"; clicking the latter reverts to WooCommerce's own email on
  the next order.
- [ ] In the editor, open the merge-tag picker on a Text block: {payment_method},
  {shipping_method}, {billing_address} and {shipping_address} are offered; add one to a
  bound order email and confirm it fills with the real value on the next order.
- [ ] Preview and Send a test on a template bound to an order email both use the store's
  most recent real order; if there are no orders yet, they still render (just without order
  content) rather than erroring.

## 40. Three new blocks, and hide-on/visible-to (3.2.0)

- [ ] Add a Social links block, fill in only Facebook and Instagram: Preview shows two round
  icons, nothing for X or YouTube. Leave every field blank and the block disappears entirely.
- [ ] Add a Video block with a link and no picture: Preview shows a "Watch the video" panel
  that links out. Choose a thumbnail picture: the picture replaces the panel, still linked.
  Leave the link blank: the block renders nothing.
- [ ] As an administrator, add a Custom HTML block and type some markup: it saves and Preview
  shows it exactly as typed. Log in as a Shop Manager (no `unfiltered_html`): the Custom HTML
  tile is not in "Add a block" at all.
- [ ] On any block, open "Spacing and background" and set "Hide on" to Phones: Preview at
  desktop width shows it, at 375px it is gone. Set it to "Everything but phones" and the
  opposite happens. Set "Show this block to" to "Wholesale customers only": a wholesale test
  send shows the block, a retail test send does not, and Preview (as the admin) always shows
  it regardless of the setting.
- [ ] Set a product's sale price in WooCommerce, add a Products block set to "Currently on
  sale": Preview shows that product; a regular-priced product does not appear. Remove the
  sale price and the block updates to show nothing (or another on-sale product, if any).

## 39. Global styles, a starter gallery, and categories (3.1.0)

- [ ] Messaging → Email templates → New template opens a gallery instead of a blank canvas:
  12 starters, grouped by category, with a search box and a category dropdown. "Blank
  template" is a button at the top.
- [ ] Search for part of a starter's name; only matching tiles remain. Pick a category from
  the dropdown; only that category's starters remain. Clear both and everything is back.
- [ ] Click a starter tile: the editor opens with that starter's content, unsaved (Save is
  still enabled/active, and closing without saving offers nothing since there is nothing to
  save yet). Click "Blank template" instead: the ordinary empty canvas.
- [ ] Open an existing template (not new): the gallery does not appear.
- [ ] In the Design panel, "Heading font" defaults to "Same as body"; choose a different one
  (e.g. Georgia while Body font is Helvetica) and Preview: the heading uses the chosen font,
  the body text does not.
- [ ] Choose a web font (Inter, Roboto or Merriweather) for either Body font or Heading font,
  save, then view the page source of a Preview or a sent test: a Google Fonts `<link>` is
  present, wrapped in `<!--[if !mso]>`/`<![endif]-->`, and the font-family list still ends in
  a real fallback typeface.
- [ ] Set "Link color" on a template with a text block that has a link; Preview shows the
  link in that color, not the brand color.
- [ ] Messaging → Settings → Email design: set "Heading font", "Link color" and "Mobile side
  padding". A template that leaves those fields alone now reflects the site-wide choice; a
  template with its own Link color still wins over the site setting.
- [ ] Set "Mobile side padding" to something other than 24 on a template, view the HTML
  source of a sent test: a `.pw-row{...}` rule appears in the `<style>` block. Leave it at
  the default (or empty) and confirm that rule is absent.
- [ ] Open a template saved before this release: it looks exactly as it did (colors, fonts,
  spacing, layout unchanged) at both 1280 and 360.
- [ ] The template library list still shows all 12 starters as "Start a new template"
  buttons (the old, unchanged list-based flow).

## 38. The new template editor (3.0.0)

- [ ] Messaging → Email templates → New template opens the new editor: a toolbar (name, undo,
  redo, Edit/Preview, Save), a canvas on the left, and a panel on the right with "Add a block"
  tiles when nothing is selected.
- [ ] Drag a Heading tile onto the canvas, or click it: it appears, selected, with its settings in
  the right panel. Type a new heading in the panel; the canvas updates immediately.
- [ ] Add a Text, a Button and a Columns block. Drag a block to reorder it, and drag one into a
  column. Use the panel's up/down arrows on a selected block too.
- [ ] Duplicate a block (Copy) and remove one (Remove); undo brings a removed block back; redo
  reapplies it. Ctrl/Cmd+Z and Shift+Ctrl/Cmd+Z work from the keyboard.
- [ ] In a Text block's field, use "Insert a personal detail" to add `{first_name}`; it lands at
  the cursor. Do the same on the Subject field and the Footer text field.
- [ ] Header and footer panel: choose a logo for this template only (separate from Messaging →
  Settings → Email design's site-wide logo); it shows here and only affects this template.
- [ ] Switch to Preview: it matches the canvas, and Desktop/Phone widths both look right. This is
  the real send output (`EmailRenderer`), not the canvas's own approximation.
- [ ] Send a preview to your own address; it arrives and matches Preview.
- [ ] Save. The page's address gets the new template's id (for a brand-new template) without a
  full reload. Reload the page: everything you set is still there.
- [ ] Start a new template, type something, then close the tab without saving and reopen the
  editor on that same template: it offers to restore the unsaved draft.
- [ ] Email settings, Sent automatically as and Design panels still save exactly what they did
  before (marketing/service type, subject, preview text, slot binding, colors, font, width).
- [ ] Open an existing template that has a bound slot (e.g. Welcome email): the slot picker shows
  it selected.
- [ ] At 360px, the layout stacks (canvas above the panel) and every control is usable.
- [ ] The template library list (search, duplicate, preview, delete) is unchanged.

## 37. The REST namespace and the provider interface (2.10.0)

- [ ] Open Messaging → Settings: a new "Sending" section shows "Send email through" and "Send
  texts through", both defaulted to Automatic. Save with both left on Automatic: nothing else on
  the page changes, and a Compose test send still works exactly as before.
- [ ] With Brevo connected, switch "Send email through" to "This site's mail" and save. Send a
  test email from Compose: it arrives, and Messaging → Log shows its provider as the site's mail,
  not Brevo.
- [ ] Switch "Send email through" back to Automatic and confirm a test email goes out through
  Brevo again (Log shows Brevo as the provider).
- [ ] With no Brevo key configured, "Send texts through" offers only Automatic and Brevo (not
  "This site's mail", which cannot carry a text). Automations and Compose still refuse to send SMS
  with the same message as before.
- [ ] The Email templates screen (list, editor, preview, send-a-preview, duplicate, delete) works
  exactly as it did before this release. Nothing in wp-admin uses the new REST routes yet.
- [ ] Visiting `/wp-json/protech/v1/templates/schema` directly in a private/incognito window (logged
  out) returns a JSON error, not the schema. Nothing else to check by hand here yet: this route has
  no admin screen wired to it until the next release.

## 36. Fix and unblock, and the admin file split (2.9.0, 2.9.1)

- [ ] Set Messaging → Settings quiet hours to a window covering right now. Send a text preview from Compose: the Log shows it queued with a send_after time after quiet hours end, not failed.
- [ ] Open a template with a 3-across product grid showing only 1 or 2 products. At 360px, each product is its own row rather than squeezed into part of a row.
- [ ] Messaging → Settings → Email design: the Logo image field is a "Choose logo" / preview / "Remove" picker, not a number box. Pick a logo, save, reload: it is still selected and shows in a sent email's header.
- [ ] Messaging → Log: type part of an email address or a subject word into a new search field and filter: only matching rows show.
- [ ] Approve, then reject (a different applicant), then check Messaging → Log: both application emails appear as their own rows, kind "lifecycle".
- [ ] Click the unsubscribe link in a marketing email as a wholesale customer: it lands on the real portal page (wherever it is set up), not a hard-coded address that happens to be wrong on this store.
- [ ] Every Messaging sub-page (Emails, Compose, Email templates, Log, Compliance, Settings) still loads, and every button, link and form on each one still works exactly as before (this release only moved code between files).

## 35. A visible "updating" state (2.8.2)

- [ ] In the template editor, drag a block, drop it, and watch the canvas: it dims briefly the moment the drop happens, then clears when the redrawn email appears, rather than sitting frozen for a couple of seconds.
- [ ] Type in a text field and stop: the same brief dim-then-clear happens once the pause-triggered refresh lands.
- [ ] With a fast connection the dim is barely visible; with the network throttled in devtools it clearly reads as "updating" rather than broken.

## 34. The canvas redraws live, not on a delay (2.8.1)

- [ ] Drag a palette block onto the canvas: the email updates immediately, not after a noticeable pause.
- [ ] Move, copy or remove a block from the side panel: each one updates the canvas right away.
- [ ] Choose a picture from the Media Library for an Image block: the canvas updates right away.
- [ ] Typing in a text, heading or color field still waits for a short pause before refreshing, so it is not one request per keystroke.
- [ ] The Refresh button still works with JavaScript disabled.

## 33. Canvas-first editor (2.8.0)

- [ ] Open a template: the email preview is the big, centered element; a narrower panel on the right holds Content (Add a block) and the block list.
- [ ] Click a block on the email itself: the panel swaps to just that block's settings, with "Back to blocks" at the top. Click Back to blocks: the palette and list return, nothing else changed.
- [ ] Drag a palette tile (say, Button) and drop it between two existing blocks on the email: it lands there, not at the end. Drop it above the first block and below the last: both work.
- [ ] With a block's settings open, its Up/Down/Copy/Remove still work without leaving the settings view. Copy: the new copy is the one now open, the original is back in the list. Remove the open block: you land back on the list.
- [ ] Open a Columns block, then open one of its inner blocks: this still behaves as a plain expand/collapse (not exclusive), same as before.
- [ ] The other panels (Send a preview, Email settings, Sent automatically as, Design, Header and footer) are collapsed by default and expand on click.
- [ ] Save, Duplicate, Delete and Preview-to-any-address from the templates list all still work exactly as before.
- [ ] At 1280px the canvas dominates the screen; at 360px it stacks above the panel and nothing scrolls sideways.

## 32. The Emails screen (2.7.0)

- [ ] Messaging opens on **Emails** (the first item, formerly Automations). Three groups: "When someone joins", "Automatic", "Sent".
- [ ] "When someone joins" lists Welcome, Application received, approved and rejected. Each says "Built-in wording" with a **Design this email** button. Press it: the editor opens on the ready-made template, already set as "Sent automatically as" that email. Back on Emails it now reads "Designed: <name>" with Edit design and Use built-in wording. Use built-in wording: back to the built-in.
- [ ] "Automatic" shows four rules (reorder reminder, win-back, first-order nudge, order shipped), each **Off**, each with a sentence under its name. Turn one on, then off again. Open one: the reminder and win-back show a Design; the others have their own written body.
- [ ] Duplicate a rule: you land in its editor as "Copy of ...", switched off. The original is unchanged.
- [ ] Compose and send something to yourself. "Sent" lists it with the audience and counts. **Duplicate and edit** opens Compose with the same audience, channel, design and wording, nothing sent.
- [ ] A store that already had rules before this update did not get four extra ones.

## 31. Designed lifecycle emails (2.6.0)

- [ ] Email templates now includes "Application approved" and "Application rejected" among the ready-made ones (added once; delete one and it does not come back on the next update).
- [ ] Open a template: the right-hand column has **Sent automatically as** with Welcome email, Application received, Application approved, Application rejected. Choose one, save: the list's "Used by" column shows it, the template's type is now Service email, and it can no longer be deleted while in use.
- [ ] Bind the "Application approved" template to Application approved. Approve a test applicant: the email is the designed one, the button opens the store's own set-your-password page and the link works once. With nothing bound, the old built-in email still goes out.
- [ ] Reject a test applicant with a reason: the designed email shows "Reason: ...". Reject with the reason left blank: no "Reason:" line and no gap.
- [ ] Bind "Welcome to wholesale" to the Welcome email. Customers tab → Send welcome email to a test wholesale customer: the designed one arrives, and the row still reads "Sent".
- [ ] Bind a second template to the same email: the first one loses it (only one at a time).
- [ ] Choose "Nothing" and save: the built-in email is back.
- [ ] Preview the Application rejected template: it shows a sample "Reason: ..." line so you can see where it goes.

## 30. Retail audiences (2.5.0)

- [ ] Compose has a **Customers** choice (Wholesale / Retail / Everyone) above "Send to". With Wholesale chosen, everything behaves as before.
- [ ] Retail, "All of them", Review: the audience reads "All retail customers" and the count is your shop's non-wholesale accounts. Nobody who is a wholesale account is in it.
- [ ] Retail, "Ordered in the last 30 days": only recent buyers. "No order in 60 days": only people who ordered before that (not people who never ordered). "Have never ordered": accounts with no orders.
- [ ] "Bought this product": search for a product; the count matches the buyers you expect. Leave the product empty and Review: an error asks for one.
- [ ] Channel Text or Both with a retail audience: the review says nobody is opted in to texts (or counts only those who are).
- [ ] Send a marketing email to yourself as a retail account (a test customer): the footer has your address and an Unsubscribe link and no "Manage preferences". Click Unsubscribe while logged out: a plain "You have been unsubscribed" page with a Back to the shop link, not the wholesale login. Then Review the same audience: you are left out as "unsubscribed".
- [ ] A wholesale account's email still has "Manage preferences" and Unsubscribe, and unsubscribing still lands on the wholesale page.
- [ ] Compliance → Consent on file: a table with a Wholesale and a Retail row, and the paragraph explaining email versus texts.

## 29. Designed emails and the review screen (2.4.0)

- [ ] Compose and an automation rule each have a **Design** row above Subject: "Plain message" plus every template. Both keep whatever they had before (Plain message).
- [ ] Compose, channel Email, Design "Welcome to wholesale", leave Body empty, click "Review and send": no error about the body. The review shows the audience and how many emails, the email in a frame exactly as designed, and a Subject line.
- [ ] Type a Subject in Compose with a template chosen: the review's Subject is yours, not the template's.
- [ ] Use an audience that includes someone unsubscribed from marketing: the review says "Left out: 1 unsubscribed", and the count excludes them. Tick "service message": they are included, and the review says so.
- [ ] Choose a channel with nobody able to receive it: the review says nobody would receive it and the Send button is disabled.
- [ ] From the review, "Send preview" to your own address: you land back on the review with a green line, and the email arrived with the template design and the marketing footer.
- [ ] "Back to edit" returns to Compose with everything still typed. Nothing was sent by any of the above (check the Log).
- [ ] "Send ... now": the Log shows the queued messages and they arrive as the template.
- [ ] Automation rule with a template: "Send preview" sends the template. Delete a template a rule uses (you cannot: it says where it is used).
- [ ] The Send preview and Review buttons on Compose and the rule form do only what they say (the Log stays empty after a preview).

## 28. Email template editor (2.3.0)

- [ ] Messaging → Email templates → New template: pick "Blank", the editor opens with a name, subject, preview text, a list of blocks (empty), the row of "Add a block" buttons, and the preview on the right.
- [ ] Add a Heading, a Text and a Button. Each opens with its own settings; the collapsed row shows the first words of what you typed. The preview refreshes a moment after you stop typing.
- [ ] Click into the Text box, choose "Insert a personal detail" → first name. It appears at the cursor and the preview shows your own name.
- [ ] Drag a block by the dots to a new place; press the up and down arrows on another. Save, reload: the order held and every setting is as you left it.
- [ ] Add Columns, set two, add a Text in each, then move the Columns block above the Heading and save. Everything inside came with it. Switch to three: a third column appears.
- [ ] Add an Image: Choose picture opens the Media Library; the thumbnail shows, the preview shows the picture, Remove clears it.
- [ ] Add a Products block with two products you chose and one with "The newest in the shop"; both show in the preview, with wholesale prices when you are a wholesaler.
- [ ] Copy a block: an identical block appears under it and editing it does not change the original.
- [ ] Phone/Desktop switch the preview width. Refresh redraws it.
- [ ] Send preview with an address typed, then with it blank: the first arrives at the typed address, the second at yours. Marketing shows unsubscribe links and the store address; a Service email shows neither.
- [ ] Leave the name empty and Save: an error says so and everything you typed is still there.
- [ ] Duplicate a template: "Copy of ..." appears. Delete it: gone after confirming. Bind a template to a lifecycle email (once 2.6.0 lands) or use it in a rule and try Delete: it refuses and says where it is used.
- [ ] At 1280px the editor is two columns; at 360px it is one, and nothing scrolls sideways.

## 27. Template rendering fixes (2.2.1)

- [ ] Preview "Welcome to wholesale" at phone width: the card fits the screen with nothing cut off, and the diagram text is in the same sans-serif font as the rest.
- [ ] Preview "Restock reminder": the product row shows visible products only (no Vendor Starter Kit or Sample Pack).
- [ ] The footer address reads like an address ("Evanston, IL 60204"), with no "US:IL".

## 26. Email templates library (2.2.0)

- [ ] Messaging → **Email templates** lists six starters: Welcome to wholesale, Application received, Restock reminder, Win-back offer, New arrivals announcement, Blank. Each shows its type, block count and a Preview link; "Used for" is empty for all of them.
- [ ] Preview **Welcome to wholesale**: a centered white card with the store name (or logo, if set), your first name, the three login steps with working links, a blue Log in button, the pack / display / case picture ("8 displays = 1 case"), the quantity levels, and a footer.
- [ ] Preview **Restock reminder**: a heading, text, a row of three newest products with prices, a button, and under it the unsubscribe and preferences links and the store's postal address.
- [ ] Preview **Application received**: no unsubscribe footer (it is a service email).
- [ ] Open one preview on a phone-width window: nothing overflows.
- [ ] Messaging → Settings → **Email design**: change the brand color and the width, Save, and reload a preview: buttons, links and headings use the new color and the card is the new width.
- [ ] Put a Media Library image's number in **Logo image**: the logo replaces the store name at the top of every preview.
- [ ] Nothing changes for real email: the Welcome, approval and automation emails a customer gets are exactly what they were.

## 25. Marketing footer (2.1.1)

- [ ] Compose a marketing email (not a service message) and send a preview to yourself: under the body there is "You are receiving this because you have a wholesale account with <site>", Manage preferences, Unsubscribe, and on the next line the store name and postal address.
- [ ] Clear the store address (WooCommerce → Settings → General) with messaging switched on: the Wholesale screen shows a notice about the postal address. Put it back and the notice goes.
- [ ] A service message (the checkbox) carries no footer at all.
- [ ] Merge-tag chips still insert at the caret in the subject, heading and body fields, on Compose and on an automation rule.
- [ ] The welcome email preview looks exactly as before (same diagram, same steps).

## 24. Messaging section (2.1.0)

- [ ] The sidebar has a **Messaging** item under WooCommerce and Products, with Automations, Compose, Log, Compliance and Settings under it, and the current page is highlighted.
- [ ] Each sub-page shows the same screen it did under Wholesale, with the same data (rules, past sends, Brevo settings).
- [ ] Open an old link such as `wp-admin/admin.php?page=protech-wholesale&tab=messaging&view=log`: it lands on Messaging → Log.
- [ ] WooCommerce → Wholesale no longer has a Messaging tab; the line under its heading has a Messaging link.
- [ ] Customers tab → a row's Message link, and "Send message to selected", both land on Compose.
- [ ] The Log filters (channel, status) and pagination still work, and Compose's preview and send still redirect back to the right page.

## 23. Welcome email and previews (1.6.0)

**Welcome email**
- [ ] WooCommerce → Wholesale → Customers → "Welcome email" (collapsed box): type your own address, "Send preview". It arrives with the branded header, "Hi <your first name>", your address in step 2, the working login and reset links, the three-tile picture (colors) and "8 displays = 1 case."
- [ ] Look at it on a phone and in Gmail: the tiles line up and nothing is cut off.
- [ ] The table has a Welcome email column ("Not sent" plus Send). Send to yourself as a test wholesale customer: the column now reads "Sent <today>" with Resend, and the Messaging → Log shows a "welcome" row.
- [ ] Tick two customers and press "Send welcome email": the notice says how many were sent.
- [ ] "Add existing customers to wholesale": the box is ticked by default; adding a plain customer with it ticked sends them the welcome email (not the "set your password" one) and adds no reset key.

**Page order**
- [ ] Logged in as a wholesaler, the Premium Matte Sleeves page runs: title and price, the legend, the pricing table, "Want every color?", colors, Order by, Add to cart, then the size/count/finish description, then Category/Brand. Logged out, the description is back under the price.

**Add one display of every color**
- [ ] On the Premium Matte Sleeves page, directly under the Wholesale pricing table (above the color picker): "Want every color?" with a swatch per color, the line "One display of each of the N colors: N displays, N0 packs", a price, and the outlined button.
- [ ] Press it with an empty cart: a green "Added N displays to your cart, one of every color." line, the cart badge, the sticky bar and the mini-cart update, and the cart holds one line per color at one display each.
- [ ] Mark one color out of stock and reload: a line with a small red "!" says "Out of stock: <color>", its swatch is crossed out, the line says "colors in stock", the button reads "Add one display of each color in stock", and the count drops by one. Add a color (with a wholesale price) and reload: the count goes up with nothing else changed.
- [ ] Not shown to a guest or a retail customer, and not on a product with only one color.

**Previews**
- [ ] Compose: fill in an email, type a friend's address in "Send a preview", press it. They receive it; your own inbox does not.
- [ ] Compose with the channel set to Both and a phone number typed: two green notices (email and text).
- [ ] An automation rule (reorder reminder): "Send a preview" works before saving, and the rule is not saved by it.
