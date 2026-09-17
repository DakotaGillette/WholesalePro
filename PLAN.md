# PLAN.md — Protech Wholesale

## Scope note (read first)

This plugin was built without live access to the Protech Sleeves staging
site — no real Cloudways SSH credentials were supplied to this environment
(`.env` is a template only, per the master prompt's own instruction to
never commit real credentials). Two "verify on staging" items from the
master prompt are therefore answered with a best-effort, adapter-based
design instead of a confirmed fact:

- **Which form plugin renders `/wholesale-application`** — unknown. The
  plugin ships adapters for the four most common candidates (Gravity
  Forms, WPForms, Contact Form 7, Fluent Forms) plus a native fallback
  shortcode form, and a settings field to pick which one is active. See
  `DECISIONS.md`.
- **Classic vs. Blocks cart/checkout** — unknown. The plugin hooks both:
  classic cart/checkout action hooks (`woocommerce_check_cart_items`,
  `woocommerce_after_cart_totals`, etc.) and the Store API
  (`woocommerce_store_api_cart_errors` / `ExtendSchema`) so minimum-order
  and case-multiple validation fire under either. See `DECISIONS.md`.

Everything else below was implementable directly from the requirements
and the documented catalog shape (1 variable product, 14 color
variations, 7 fixed bundles).

## File layout

```
protech-wholesale/
  protech-wholesale.php        Bootstrap: constants, autoloader, activation hooks, HPOS declare
  uninstall.php                 Purges data only if "purge on uninstall" setting is on
  includes/
    class-plugin.php            Singleton; wires every other class's hooks on plugins_loaded
    class-activator.php         Roles, pages, default options, capabilities (runs on activation)
    class-roles.php              wholesale_pending / wholesale_customer role definitions + helpers
    class-settings.php           WooCommerce Settings API page: WooCommerce → Wholesale → Settings
    class-application-form.php   Adapters for GF/WPForms/CF7/Fluent Forms + native fallback form
    class-approval.php           WP_List_Table admin screen + approve/reject actions + emails
    class-pricing.php            Price filters, precedence, per-role variation price cache busting
    class-case-rules.php         Case size, multiple-of-case validation, order minimum enforcement
    class-global-tier-bar.php    Sticky site-wide tier progress bar (see DECISIONS.md)
    class-reorder.php            Reorder button — adds a past order straight to cart (My Account orders + order detail + dashboard)
    class-my-account.php         Login redirect (wholesale → shop), pending-application notice
    [class-order-form.php removed 2026-09-17 — the Quick Order page it powered was removed; see DECISIONS.md]
    class-portal.php             /wholesale page + [protech_wholesale_portal] shortcode
    class-orders-admin.php       Orders list wholesale filter/column, _protech_is_wholesale flag
    class-emails.php             Admin "WHOLESALE ORDER" subject prefix, applicant/approval emails
    class-logger.php             Thin wrapper over wc_get_logger(), source "protech-wholesale"
    functions-helpers.php        Free functions: is_wholesale_customer(), get_wholesale_price(), etc.
  admin/views/                   PHP partials rendered by Settings/Approval/user-profile screens
  templates/                     Overridable via wc_get_template(): order-form.php, portal-*.php
  assets/css/wholesale.css       Inherits Salient CSS variables where present, falls back cleanly
  assets/js/order-form.js        Vanilla JS: case-qty math, AJAX add-all-to-cart, sticky summary bar
  assets/js/admin.js             "Set all variations to $___" bulk helper, approval screen confirm
  tests/                         PHPUnit tests (WP_UnitTestCase) + bootstrap.php
  .wp-env.json
bin/deploy.sh                    rsync plugin to Cloudways staging + wp cache flush
.env.example
README.md, QA.md, DECISIONS.md, PLAN.md
```

## Hook map

### Roles & approval (R1)
| Hook | Purpose |
|---|---|
| `init` (Activator, safety net) | Ensure `wholesale_pending`/`wholesale_customer` roles exist |
| `protech_wholesale_application_submitted` (custom action, fired by each form adapter) | Create pending user, store meta, send admin+applicant emails |
| `admin_menu` | Add WooCommerce → Wholesale (list table) and → Wholesale → Settings |
| `admin_post_protech_approve_applicant` / `admin_post_protech_reject_applicant` | Approve/reject actions (nonce + `manage_woocommerce` check) |
| `show_user_profile` / `edit_user_profile` + `personal_options_update` / `edit_user_profile_update` | Manual "Flag as wholesale" + per-customer price/minimum overrides |

### Pricing (R2)
| Hook | Purpose |
|---|---|
| `woocommerce_product_get_price`, `woocommerce_product_get_regular_price` | Simple product price override |
| `woocommerce_product_variation_get_price`, `woocommerce_product_variation_get_regular_price` | Variation price override |
| `woocommerce_get_variation_prices_hash` | Add role+user to the hash so Woo's variation price transient never leaks across roles |
| `woocommerce_variation_prices_price`, `_regular_price`, `_sale_price` (via `woocommerce_variation_prices_array` filter args) | Keep the min/max variation price range consistent with the override |
| `woocommerce_get_price_html` / `woocommerce_get_variation_price_html` | Append "Wholesale price" label |
| `woocommerce_product_is_visible` | Hide wholesale-only-priced products from non-wholesale users when "hide" mode is on |
| `rest_pre_dispatch` / Store API `woocommerce_store_api_product_query` | Refuse to leak wholesale prices via REST for non-wholesale requests |
| `woocommerce_coupon_is_valid` | Enforce "wholesale may use retail coupons" setting |

### Case & minimum rules (R3)
| Hook | Purpose |
|---|---|
| `woocommerce_add_to_cart_validation` | Block/round add-to-cart quantities that aren't a multiple of case size |
| `woocommerce_before_cart_item_quantity_zero`, `woocommerce_cart_item_quantity` (qty input args) | Show cases in cart, step/min = case size |
| `woocommerce_check_cart_items` | Classic cart/checkout: enforce multiples + order minimum, add cart notice |
| `woocommerce_store_api_cart_errors` | Blocks cart/checkout: same enforcement via Store API |
| `woocommerce_checkout_process` | Server-side re-check at checkout (classic) |
| `woocommerce_quantity_input_args` | Wholesale step/min = case size on product pages |

### Fast reorder (R4)
> The Quick Order page/tab this section originally described was removed 2026-09-17 (see DECISIONS.md) — wholesale pricing is visible directly on ordinary shop/product pages instead.

| Hook | Purpose |
|---|---|
| `woocommerce_my_account_my_orders_actions`, `woocommerce_order_details_after_order_table`, `woocommerce_account_dashboard` | "Reorder" button/prompt (orders list, order detail, dashboard shortcut) |
| `admin_post_protech_reorder` | Adds a past order's still-available items straight to the cart, redirects to `/cart/` |
| `wp_footer` (GlobalTierBar) | Sticky site-wide tier progress bar |
| `wp_ajax_protech_global_tier_bar_state` | Live AJAX refresh of the sticky bar after any cart change |

### Portal, redirects, shipping (R5/R5b)
| Hook | Purpose |
|---|---|
| `init` (Activator) | Create `/wholesale` page with `[protech_wholesale_portal]` if missing |
| `login_redirect` | Send wholesale users to the shop page after login |
| `template_redirect` | Pending users hitting the shop see the "under review" notice instead of prices |
| `woocommerce_shipping_free_shipping_is_available` | Exclude wholesale orders from retail free-shipping threshold |

### Admin/reporting (R6)
| Hook | Purpose |
|---|---|
| `woocommerce_checkout_create_order` | Stamp `_protech_is_wholesale = yes` via `$order->update_meta_data()` (HPOS-safe) |
| `manage_edit-shop_order_columns` / `manage_woocommerce_page_wc-orders_columns` | Wholesale column (HPOS + legacy) |
| `restrict_manage_posts` / `woocommerce_order_list_table_restrict_manage_orders` | Wholesale filter dropdown |
| `woocommerce_email_subject_new_order` | Prefix "WHOLESALE ORDER" |
| `before_woocommerce_init` | `FeaturesUtil::declare_compatibility( 'custom_order_tables', ... )` |

## Build order

R1 (roles/approval) → R2 (pricing) → R3 (case/minimum rules) → R4 (order form/reorder) →
R5/R5b (portal, redirects, shipping) → R6 (admin/reporting) → assets/polish → tests → docs.
Each milestone is a separate commit.
