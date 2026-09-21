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
    class-tier-ladder.php             Product-page quantity-tier price table (un-named base, Standard, Volume)
    class-starter-kit.php             Starter kits: one display of every color of a source product, admin fields, quote + add endpoints
    class-setup-checks.php            Shipping zone / priced product / portal page / form ID checks behind the setup notice
    class-updater.php                 Updates from GitHub releases (Update URI header, plugins_api details, check-now)
    class-global-tier-bar.php         Sticky site-wide progress bar + AJAX state endpoint
    class-reorder.php                 "Reorder" links + admin-post handler that adds a past order to the cart
    class-my-account.php              Login redirects, pending notice, dashboard wholesale panel
    class-portal.php                  /wholesale page: login / pending / rejected / retail-only states
    class-orders-admin.php            Order wholesale flag (classic + Store API), Orders list column + filter (legacy + HPOS)
    class-emails.php                  Application-flow emails via the WooCommerce mailer; "WHOLESALE ORDER" subject prefix
    class-logger.php                  wc_get_logger() wrapper, source "protech-wholesale"
    functions-helpers.php             Free functions for theme code
    --- Messaging & automations (1.5.0) ---
    class-messaging-settings.php      Messaging Settings tab: Brevo key, sender, quiet hours, frequency cap, consent wording
    class-brevo-client.php            Brevo v3 API client: send_email/send_sms/get_account/get_contact/upsert_contact
    class-merge-tags.php              {tag} context + rendering (html/text/subject) + AST tracking bridge + SMS segment count
    class-message-log.php             Custom table {prefix}protech_wholesale_messages: enqueue (dedup)/claim/finish/sweep
    class-message-transport.php       Turns a claimed log row into an actual send; Brevo or WC-mailer fallback for email
    class-automations.php             Rule storage/validation, anchor/window evaluation, order-status listener
    class-automation-runner.php       Action Scheduler contract: daily job, delivery queue, order-event delay, self-heal
    class-audience.php                Compose/campaign segment resolution (all/tier/inactive/never-ordered/selected)
    class-campaigns.php               Manual sends: create/validate, launch (queue + batches), progress, send_test
    class-sms-consent.php             Phone normalization, the two SMS consents + email opt-out, gating, consent log, CSV
    class-unsubscribe.php             Per-user token link → email-marketing opt-out (no login required)
    class-notifications-endpoint.php  My Account "Notifications" endpoint: SMS/email preferences, self-service
    class-messaging-tab.php           Messaging admin tab: Automations / Compose / Log / Compliance / Settings views
  templates/                          Overridable via yourtheme/woocommerce/: application-form, portal, global-tier-bar,
                                      tier-ladder, account-wholesale-panel, account-wholesale-header, starter-kit,
                                      account-notifications, quantity-legend
  assets/css/wholesale.css            Protech Blue (#42649d) wholesale UI — portal, bar, ladder, selector, account, cart badge
  assets/css/admin.css                Messaging tab admin styling (merge-tag chips, form layout) — wholesale screens only
  assets/js/unit-selector.js          Display/Case → packs, Store API add-to-cart, protech:cart-changed + protech:qty-preview events
  assets/js/global-tier-bar.js        Bar refresh on cart events, tier celebration, add preview, live price-table row
  assets/js/portal.js                 /wholesale login form: show/hide password
  assets/js/starter-kit.js            Kit page: quantity, live quote, AJAX add, protech:qty-preview
  assets/js/admin.js                  Override rows, bulk actions, approve/reject prompts, Messaging tab interactions
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
| `woocommerce_cart_contents_count` | Header cart badge / Blocks mini-cart count in displays, wholesale customers only |
| `woocommerce_get_item_data`, `woocommerce_checkout_create_order_line_item` | "Displays: N" on cart lines and order items |
| `woocommerce_check_cart_items`, `woocommerce_checkout_process`, `woocommerce_store_api_cart_errors` | Cart-level re-check (classic + Blocks) |
| `woocommerce_shipping_methods`, `woocommerce_package_rates` | Register the wholesale method; hide retail methods when it's present |
| `woocommerce_shipping_free_shipping_is_available` (Portal) | Retail free-shipping rule never applies to wholesale |

### Storefront UI
| Hook | Purpose |
|---|---|
| `woocommerce_single_product_summary` (25, TierLadder) | Price table above the add-to-cart form |
| `update_plugins_github.com`, `plugins_api`, `plugin_row_meta`, `admin_post_protech_check_updates`, `plugin_action_links_<basename>` (Plugin) | Updates from GitHub releases; Plugins-list links |
| `load-woocommerce_page_protech-wholesale`, `manage_product_posts_columns` / `_custom_column` | Help tab; Wholesale column on the products list |
| `woocommerce_single_product_summary` (30, StarterKit), `woocommerce_is_purchasable`, `woocommerce_get_price_html` (20), `wp_ajax_protech_kit_quote`, `wp_ajax_protech_add_kit`, `admin_post_protech_add_kit`, `protech_wholesale_product_data_panel` | Starter kit page in place of the add-to-cart form; kit never purchasable itself; live kit price; quote/add endpoints; admin fields |
| `wp_footer` (GlobalTierBar) + `wp_ajax_protech_global_tier_bar_state` | Sticky bar (not on checkout) + refresh endpoint |
| `woocommerce_account_dashboard` (5 MyAccount, 10 Reorder) | Wholesale panel; "reorder last order" prompt |
| `woocommerce_my_account_my_orders_actions`, `woocommerce_order_details_after_order_table`, `admin_post_protech_reorder` | Reorder |
| `login_redirect`, `woocommerce_login_redirect`, `template_redirect` (Portal) | Redirects; portal login form |
| `woocommerce_before_account_navigation` (5 header, 10 notice) | "Wholesale Partner" / "under review" strip on every account page; pending notice |
| `body_class` (Plugin) | `protech-wholesale` on <body> for wholesale customers (scopes theme overrides) |

### Orders / admin
| Hook | Purpose |
|---|---|
| `woocommerce_checkout_create_order` (classic) and `woocommerce_store_api_checkout_update_order_meta` (Blocks) | Stamp `_protech_is_wholesale` |
| `manage_edit-shop_order_columns` / `manage_woocommerce_page_wc-orders_columns` (+ custom_column, restrict_manage, `request` / `woocommerce_order_query_args`) | Wholesale column and filter, legacy + HPOS |
| `woocommerce_email_subject_new_order` | "WHOLESALE ORDER" prefix (flag, falling back to the customer's role) |
| `woocommerce_product_data_tabs` / `woocommerce_product_data_panels`, `woocommerce_process_product_meta`, `woocommerce_product_after_variable_attributes`, `woocommerce_save_product_variation`, `woocommerce_variable_product_bulk_edit_actions`, `woocommerce_bulk_edit_variations` | Product fields, variation fields, bulk actions, has-wholesale-price flag sync |
| `init` (20, Plugin) | `maybe_upgrade()` — one-off migrations keyed by `Plugin::DB_VERSION` |
| `before_woocommerce_init` | HPOS + cart/checkout Blocks compatibility declarations |

### Messaging & automations (1.5.0)
| Hook | Purpose |
|---|---|
| `add_user_role`, `set_user_role` (Approval) | Stamp `_protech_wholesale_approved_at` the first time the wholesale role is granted |
| `woocommerce_order_status_changed` (Automations) | Order-status rule: schedule a delayed `protech_wholesale_order_event` action |
| `protech_wholesale_daily_automations` (recurring, AS group `protech-wholesale`) | Evaluate day-based rules, queue message-log rows |
| `protech_wholesale_deliver_messages` (single/batched, AS) | Claim + `MessageTransport::deliver()` a queued row |
| `protech_wholesale_order_event` (single/delayed, AS) | `Automations::fire_order_event()` — re-checks the order hasn't moved on |
| `protech_wholesale_sync_contact` (async, AS) | Push a customer's SMS number to Brevo when marketing consent is granted |
| `protech_wholesale_purge_messages` (recurring, AS) | `MessageLog::sweep()` — stuck/expired rows, retention purge |
| `init` (30, AutomationRunner) | `self_heal()` — re-schedules the daily job if a GitHub update dropped it |
| `show_user_profile`/`edit_user_profile` + `personal_options_update`/`edit_user_profile_update` (SmsConsent) | Admin "Messaging" profile section: phone, consents, required note |
| `protech_wholesale_application_submitted` (SmsConsent) | Records phone + consent from a submitted application |
| `admin_post_protech_unsubscribe` (+ `_nopriv_`) | Unsubscribe link → `SmsConsent::record()` email opt-out |
| `init`, `woocommerce_get_query_vars`, `woocommerce_account_menu_items`, `woocommerce_account_wholesale-notifications_endpoint` (NotificationsEndpoint) | My Account "Notifications" self-service page |
| `admin_post_protech_{save,preview,toggle,delete}_automation`, `_run_automations_now`, `_message_customers`, `_preview_message`, `_send_message`, `_send_test_message`, `_export_consent`, `_test_brevo_connection` (MessagingTab) | Messaging tab actions (each redirects; validation/preview state carried via a short-lived per-admin transient) |
| `protech_wholesale_order_tracking` (filter) | Lets a shipment-tracking source (or a test) supply `{tracking_*}` merge-tag data |
| `protech_wholesale_automation_catchup_days` (filter) | How many days past a rule's trigger day it still catches a customer up (default 7) |
