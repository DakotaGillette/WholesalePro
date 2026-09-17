# PLAN.md — Protech Wholesale

Current file layout and hook map (kept in sync with the code as of 1.1.0).
History of the decisions behind it: `DECISIONS.md`. Staging checklist: `QA.md`.

## File layout

```
protech-wholesale/
  protech-wholesale.php               Bootstrap: constants, autoloader, activation hooks, HPOS/Blocks compat
  uninstall.php                       Purges data only if "purge on uninstall" is on
  includes/
    class-plugin.php                  Singleton; wires every class; asset enqueue; one-off upgrades (DB_VERSION)
    class-activator.php               Roles, default options, /wholesale page (on activation)
    class-roles.php                   wholesale_pending / wholesale_customer; grant()/revoke() (additive); is_privileged(); memoized role checks
    class-settings.php                Settings tab (WooCommerce Settings API fields) + option getters
    class-tiers.php                   Bronze/Silver/Gold/Platinum customer tiers (discount %); Tiers tab
    class-volume-pricing.php          Standard/Volume/Bulk ladder math, tier-bar state, Pricing & Shipping tab
    class-application-form.php        Fluent Forms / GF / WPForms / CF7 adapters + native form; create_pending_applicant()
    class-approval.php                Admin tab shell, approve/reject handlers, user-profile fields + application details
    class-applicants-list-table.php   Pending / Approved / Rejected views (status-driven), search, pagination
    class-customers-tab.php           Customers tab
    class-product-fields.php          "Wholesale" product data tab, variation fields, bulk actions, has-wholesale-price flag
    class-pricing.php                 Price filters (cart-aware, memoized), label, catalog-visibility fallback, coupons
    class-catalog-query.php           pre_get_posts exclusions (wholesale-only; no-wholesale-price under "hide")
    class-case-rules.php              Packs-per-display rules, unit selector, Store API quantity limits, shipping-rate hiding
    class-wholesale-shipping-method.php  WC_Shipping_Method: flat rate below Volume threshold, free at/above
    class-tier-ladder.php             Product-page Standard/Volume/Bulk price table
    class-global-tier-bar.php         Sticky site-wide progress bar + AJAX state endpoint
    class-reorder.php                 "Reorder" links + admin-post handler that adds a past order to the cart
    class-my-account.php              Login redirects, pending notice, dashboard wholesale panel
    class-portal.php                  /wholesale page: login / pending / rejected / retail-only states
    class-orders-admin.php            Order wholesale flag (classic + Store API), Orders list column + filter (legacy + HPOS)
    class-emails.php                  Application-flow emails via the WooCommerce mailer; "WHOLESALE ORDER" subject prefix
    class-logger.php                  wc_get_logger() wrapper, source "protech-wholesale"
    functions-helpers.php             Free functions for theme code
  templates/                          Overridable via yourtheme/woocommerce/: application-form, portal, global-tier-bar,
                                      tier-ladder, account-wholesale-panel
  assets/css/wholesale.css            Site palette (black/white/#f7f7f7/8px) — portal, bar, ladder, selector, panel
  assets/js/unit-selector.js          Display/Case → packs, Store API add-to-cart, protech:cart-changed event
  assets/js/global-tier-bar.js        Bar refresh on cart events (classic, Blocks store, custom event)
  assets/js/admin.js                  Override rows (product picker), variations bulk actions, approve/reject prompts
  tests/                              PHPUnit (WP_UnitTestCase) + helpers; run inside wp-env
  composer.json, phpunit.xml.dist, .phpcs.xml.dist, phpstan.neon.dist   Dev tooling (never deployed)
.github/workflows/ci.yml             Lint/analyse (advisory) + PHPUnit in wp-env
bin/deploy.sh                        rsync to Cloudways staging, purge object + Breeze caches
.wp-env.json, .env.example, .gitattributes
README.md, QA.md, DECISIONS.md, CHANGELOG.md, PLAN.md
```

## Hook map

### Roles & approval
| Hook | Purpose |
|---|---|
| `init` (Roles) | Re-create the two roles if missing |
| `set_user_role` / `add_user_role` / `remove_user_role` / `clean_user_cache` / `wp_login` / `wp_logout` | Flush the memoized role checks |
| `fluentform_submission_inserted`, `gform_after_submission`, `wpforms_process_complete`, `wpcf7_mail_sent` | Form adapters → `create_pending_applicant()` (only the configured source + form ID) |
| `admin_post(_nopriv)_protech_submit_application` | Native fallback form |
| `admin_menu` | WooCommerce → Wholesale |
| `admin_post_protech_approve_applicant` / `admin_post_protech_reject_applicant` | Approve (GET, nonce) / Reject (POST with reason, or GET fallback) |
| `show_user_profile` / `edit_user_profile` + `personal_options_update` / `edit_user_profile_update` | Flag, tier, overrides, application details |

### Pricing
| Hook | Purpose |
|---|---|
| `woocommerce_product_get_price` / `_regular_price`, `woocommerce_product_variation_get_price` / `_regular_price` | Wholesale price at the cart's current tier (role checked first; tier memoized) |
| `woocommerce_product_get_sale_price`, `woocommerce_product_variation_get_sale_price` | Never a sale badge on a wholesale price |
| `woocommerce_variation_prices_price` / `_regular_price` / `_sale_price` | Same for the cached min/max range |
| `woocommerce_get_variation_prices_hash` | Keyed by user + cart tier + customer tier + overrides |
| `woocommerce_get_price_html` | "Wholesale price" label; wholesale-only notice for non-wholesale visitors on the product page |
| `woocommerce_product_is_visible` | Template-level fallback for the two catalog rules |
| `woocommerce_is_purchasable` | Wholesale-only products unbuyable for non-wholesale visitors |
| `woocommerce_coupon_is_valid` / `woocommerce_coupon_error` | Retail coupon block |
| `pre_get_posts` (20, CatalogQuery) | Query-level exclusions: wholesale-only for non-wholesale; no-wholesale-price for wholesale under "hide" |
| cart change hooks (`woocommerce_add_to_cart`, `…after_cart_item_quantity_update`, `…cart_item_removed/restored`, `…cart_emptied`, `…cart_loaded_from_session`), role hooks, `*_user_meta` / `*_post_meta` for `_protech_*` keys | Flush the memoized tier / availability caches |

### Case rules, unit selector, shipping
| Hook | Purpose |
|---|---|
| `woocommerce_add_to_cart_validation` | Quantity must be a multiple of packs-per-display (classic and Store API add-item) |
| `woocommerce_store_api_product_quantity_multiple_of` / `_minimum` | Blocks cart stepper + Store API validation use the display size |
| `woocommerce_quantity_input_args` | Classic quantity input step/min; marks the input for the unit selector |
| `woocommerce_before_add_to_cart_quantity` | Render the Display/Case selector |
| `woocommerce_available_variation` | Per-variation case data for the selector |
| `woocommerce_get_item_data`, `woocommerce_checkout_create_order_line_item` | "Displays: N" on cart lines and order items |
| `woocommerce_check_cart_items`, `woocommerce_checkout_process`, `woocommerce_store_api_cart_errors` | Cart-level re-check (classic + Blocks) |
| `woocommerce_shipping_methods`, `woocommerce_package_rates` | Register the wholesale method; hide retail methods when it's present |
| `woocommerce_shipping_free_shipping_is_available` (Portal) | Retail free-shipping rule never applies to wholesale |

### Storefront UI
| Hook | Purpose |
|---|---|
| `woocommerce_single_product_summary` (25, TierLadder) | Price table above the add-to-cart form |
| `wp_footer` (GlobalTierBar) + `wp_ajax_protech_global_tier_bar_state` | Sticky bar (not on checkout) + refresh endpoint |
| `woocommerce_account_dashboard` (5 MyAccount, 10 Reorder) | Wholesale panel; "reorder last order" prompt |
| `woocommerce_my_account_my_orders_actions`, `woocommerce_order_details_after_order_table`, `admin_post_protech_reorder` | Reorder |
| `login_redirect`, `woocommerce_login_redirect`, `template_redirect` (Portal) | Redirects; portal login form |
| `woocommerce_before_account_navigation` | Pending notice |

### Orders / admin
| Hook | Purpose |
|---|---|
| `woocommerce_checkout_create_order` (classic) and `woocommerce_store_api_checkout_update_order_meta` (Blocks) | Stamp `_protech_is_wholesale` |
| `manage_edit-shop_order_columns` / `manage_woocommerce_page_wc-orders_columns` (+ custom_column, restrict_manage, `request` / `woocommerce_order_query_args`) | Wholesale column and filter, legacy + HPOS |
| `woocommerce_email_subject_new_order` | "WHOLESALE ORDER" prefix (flag, falling back to the customer's role) |
| `woocommerce_product_data_tabs` / `woocommerce_product_data_panels`, `woocommerce_process_product_meta`, `woocommerce_product_after_variable_attributes`, `woocommerce_save_product_variation`, `woocommerce_variable_product_bulk_edit_actions`, `woocommerce_bulk_edit_variations` | Product fields, variation fields, bulk actions, has-wholesale-price flag sync |
| `init` (20, Plugin) | `maybe_upgrade()` — one-off migrations keyed by `Plugin::DB_VERSION` |
| `before_woocommerce_init` | HPOS + cart/checkout Blocks compatibility declarations |
