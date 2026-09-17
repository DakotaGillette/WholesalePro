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
- [ ] Click that link, set a password, and log in. Confirm you are redirected straight to **My Account → Quick Order**, and that wholesale pricing is visible.

## 2. Rejection flow

- [ ] Submit a second test application.
- [ ] In **WooCommerce → Wholesale**, click **Reject** on this applicant and enter a reason (e.g. "Outside our current distribution area").
- [ ] Confirm the applicant receives "Update on your Protech Sleeves wholesale application" and that it includes the reason text you entered.
- [ ] Confirm the rejected user's role was **not** changed to Wholesale Customer, and that logging in as them still shows the "under review"/retail experience — i.e. no wholesale access was granted.

## 3. Pricing correctness across every surface

Set up: on the approved test account from Section 1, confirm a group wholesale price is set on at least one sleeve variation (e.g. $5.00/pack), and add one **per-customer price override** on that same account's profile for a *different* variation (a different price than the group price, e.g. $4.50/pack).

For **the group-priced variation**, logged in as the approved wholesale customer, check that the wholesale price (not retail) appears, labeled "Wholesale price", on:
- [ ] the single product page (color/variation selected),
- [ ] the shop/category grid,
- [ ] the cart,
- [ ] the mini-cart,
- [ ] the checkout page,
- [ ] the order-received (thank you) page,
- [ ] the customer's order confirmation email,
- [ ] the admin new-order notification email, and its subject line starts with **"WHOLESALE ORDER"**.

For **the per-customer-override variation**, repeat the same checks and confirm the override price is what's shown everywhere (not the group price) — on the product page, shop grid, cart, mini-cart, checkout, order-received page, and both emails.

- [ ] Log out and view the same two variations as a guest (or as a plain retail account): confirm retail pricing shows, with no "Wholesale price" label and no sign a wholesale price exists.

## 4. Products/variations with no wholesale price

- [ ] Pick a variation with no group wholesale price and no per-customer override for the test account. With the default "Products with no wholesale price" setting (**hide**), confirm it does **not** appear on the Quick Order form, and does not appear in the wholesale customer's shop/category view.
- [ ] Switch **Settings → Products with no wholesale price** to **"Show retail price with a 'retail only' note"**. Reload that variation's product page as the wholesale customer and confirm the retail price now shows along with a "(retail only — not available at wholesale)" note. Switch the setting back to "hide" when done.

## 5. Case-size and minimum-order enforcement

- [ ] As the wholesale customer, try adding a quantity that is **not** a multiple of the case size (default case size **10** packs) to the cart — e.g. 15 packs of one color. Confirm it is blocked with a message asking for a multiple of the case size (default message references "multiples of 10 packs").
- [ ] Add a valid multiple (e.g. 20 packs = 2 cases) and confirm the cart line shows a "Cases" annotation (e.g. "Cases: 2") alongside the pack quantity.
- [ ] With a cart subtotal below the **$800** default minimum, confirm the cart page shows a notice like "Add [amount] more to reach your $800.00 wholesale order minimum" and that checkout is blocked.
- [ ] Add enough cases to clear $800 and confirm checkout proceeds normally.
- [ ] Set a **Minimum order override** of a different amount (e.g. $400) on the test account's user profile. Confirm that account's cart/checkout minimum now enforces $400, not $800, while a different wholesale account with no override still enforces the global $800.

## 6. Quick Order form

- [ ] Open **My Account → Quick Order** as the approved wholesale customer. Confirm all 14 sleeve color variations appear as rows, each with image, SKU, stock (shown in cases), price per case, a quantity input, and a line total.
- [ ] Enter case quantities on 2–3 rows and confirm the line totals and the sticky summary bar (cases total, subtotal, progress toward the order minimum) update live as you type, before adding to cart.
- [ ] Click **"Add all to cart"**. Confirm the correct pack quantities were added (cases × case size), and the summary bar updates again to reflect the now-current cart state.
- [ ] Resize the browser to a phone-sized viewport (or use device emulation) and repeat the above — confirm the table/summary bar remain usable (scrollable table, readable inputs, no broken layout).

## 7. Reorder

- [ ] From **My Account → Orders**, find a past wholesale order and click its **Reorder** action. Confirm it navigates to Quick Order with that order's quantities pre-filled (in cases).
- [ ] From an individual order's detail page (My Account → Orders → view an order), confirm the **Reorder** button there does the same thing.
- [ ] Set a variation from that past order to "Out of stock" (or reduce its stock to 0) via the product edit screen, then repeat the reorder. Confirm that variation's line is skipped with a visible note (not silently dropped or silently re-added), and that the rest of the order still pre-fills correctly.
- [ ] With no `reorder` link, confirm the Quick Order tab shows a "Your last order (#..., date) is ready to reorder" card with its own "Reorder last order" button when the account has at least one past order.

## 8. `/wholesale` portal page — all four states

- [ ] **Logged out:** visit `/wholesale`. Confirm a login form (email/username + password + "Remember me" + "Lost your password?") appears along with a "Not a partner yet? Apply here" button linking to `/wholesale-application`. Submit valid wholesale-customer credentials through this form and confirm it logs you in and redirects to Quick Order. Submit invalid credentials and confirm a clear error shows on the page itself (not a redirect to wp-login.php).
- [ ] **Pending:** log in as a still-pending applicant and visit `/wholesale`. Confirm the "Your application is under review" message and a contact link appear.
- [ ] **Approved:** log in as an approved wholesale customer and visit `/wholesale`. Confirm you're redirected straight to Quick Order (you should never see the portal's login/pitch body).
- [ ] **Retail-only:** log in as an ordinary (non-wholesale) customer and visit `/wholesale`. Confirm a short "This page is for approved wholesale accounts" message with an "Apply here" link appears — no login form, no wholesale pricing.

## 9. Retail customers are unaffected

- [ ] As a plain retail customer (or guest), confirm product pricing, the shop grid, cart, and checkout all look and behave exactly as before this plugin was installed.
- [ ] Confirm the retail free-shipping-over-$30 threshold still applies normally to a retail order.
- [ ] Confirm retail coupons still apply normally to a retail order.
- [ ] Confirm no wholesale-related UI appears anywhere for a retail account: no "Quick Order" item in the My Account navigation, no "Wholesale price" label on any product, no wholesale-only notices.

## 10. Coupon behavior per setting

- [ ] With **Settings → Allow retail coupons for wholesale customers** unchecked (default), try applying a valid retail coupon to a wholesale cart. Confirm it's rejected with "This coupon is not valid for wholesale accounts."
- [ ] Check that setting on, and confirm the same coupon now applies successfully to a wholesale cart.
- [ ] Uncheck it again afterward to restore the default.

## 11. HPOS compatibility

- [ ] Confirm the store's current order-storage mode (**WooCommerce → Settings → Advanced → Features**, "High-performance order storage").
- [ ] Place one wholesale order and one retail order. In **WooCommerce → Orders**, confirm the **Wholesale** column shows a "Wholesale" badge on the wholesale order and a dash (—) on the retail order.
- [ ] Use the **wholesale filter dropdown** above the orders list to filter to "Wholesale only" and confirm only the wholesale order(s) appear; filter to "Retail only" and confirm only the retail order(s) appear.
- [ ] If possible, toggle HPOS on/off (on a copy of staging, not live) and repeat the column + filter checks in the other mode to confirm both the legacy (posts-based) and HPOS orders screens show the same information.

## 12. Salient-theme visual spot-checks

- [ ] Product price HTML on a single sleeve product page: confirm the "Wholesale price" label renders visibly next to the price and isn't clipped, hidden, or stripped by Salient's price markup.
- [ ] Quantity stepper on a single product page as a wholesale customer: confirm the +/- stepper (if Salient adds one) respects the case-size step/minimum rather than allowing single-pack increments.
- [ ] My Account navigation: confirm the "Quick Order" tab is styled consistently with the other My Account tabs (icon/spacing/active state), not just functional.
- [ ] `/wholesale` portal page in each of its four states: confirm the login form, pending message, and retail-only message inherit Salient's page/typography styling rather than looking like unstyled fallback HTML.

## 13. "Wholesale only" products

> Added 2026-09-17, verified only by code review + confirming no fatal errors on the relevant admin screens (product editor, Products/Tiers tabs) — not yet tested against a live product's actual front-end visibility. Spot-check this section carefully before relying on it.

- [ ] On a **test product** (not a real catalog item), check "Wholesale only" (General pricing section) and set a wholesale price. Save.
- [ ] As a guest/retail account: confirm the product does **not** appear on the shop page, in search, or in its category.
- [ ] As that same guest/retail account, visit the product's direct URL. Confirm the title/images/description still render, but in place of the price and Add to Cart button you see "Available to approved wholesale accounts only." with a working "Apply for a wholesale account" link — and that there's no way to add it to cart.
- [ ] As the approved wholesale customer: confirm the product appears normally on the Quick Order form with its wholesale price, and (if you also check the shop page) is visible there too.
- [ ] Open **WooCommerce → Wholesale → Products** and confirm this test product is listed with "Yes" under Wholesale only.
- [ ] Uncheck "Wholesale only" on the test product and confirm it reappears in the shop/search for retail visitors.

## 14. Wholesale tiers

> Also added 2026-09-17, same caveat as above — verified by code review and confirming the Tiers tab and profile tier dropdown render without errors, not yet tested end-to-end against a live order.

- [ ] Open **WooCommerce → Wholesale → Tiers**. Set Silver's minimum order override to a distinct test value (e.g. $200) and its discount to 10%.
- [ ] On a test wholesale account with **no** per-customer minimum-order or price override set, assign the **Silver** tier from their user profile.
- [ ] Confirm that account's cart/checkout minimum is now $200 (Silver's override), not the global default — and that a *different* wholesale account left on **Bronze** still enforces the global default.
- [ ] Confirm a product with a group wholesale price (e.g. $5.00) shows as $4.50 for the Silver-tier account (10% off), while showing the plain $5.00 for a Bronze-tier account.
- [ ] On the Silver account, add a **per-customer price override** for that same product (e.g. $3.00). Confirm the override wins — the account now sees $3.00, not the tier-discounted $4.50.
- [ ] Confirm the tier is never visible anywhere the customer can see it (Quick Order, My Account, emails, the portal page).
