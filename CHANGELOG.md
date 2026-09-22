# Changelog

All notable changes to the Protech Commerce plugin (called Protech Wholesale before 2.0.0). Dates are the day the
change landed on staging.

## 3.4.0 - 2026-09-22

### Added
- Messaging → Contacts: a directory of everyone the store has a relationship with, wholesale,
  retail or a guest who has checked out, searchable by name, email or company and filterable
  by status and source. Each contact's profile shows their order count, lifetime value, last
  order date and a consent history. Existing accounts were backfilled automatically.
  Export to CSV from the list.
- A guest checkout now creates a contact from the billing details, so a repeat guest is
  recognised even without an account.
- "Unsubscribe" on a contact's row, for a contact linked to an account, actually turns off
  their email marketing the same way doing it from their profile would.

### Changed
- None of this yet decides who a campaign or automation reaches; that still works exactly as
  before, by WordPress account. Contacts is a directory today, not an audience source (see
  DECISIONS.md for what a later release still needs to add before that changes).

## 3.3.2 - 2026-09-22

### Fixed
- The Order items and Order totals blocks showed their own type name ("order_items",
  "order_totals") on the editor canvas instead of a real placeholder. Found in real-browser
  verification right after deploying 3.3.1. Sending was never affected, since a real send
  always used the finished PHP render, only the canvas approximation was wrong.

## 3.3.1 - 2026-09-22

### Fixed
- "Design this email" and "Use the WooCommerce design" on the new Order emails list gave
  "The link you followed has expired" for every one of the nine order emails: the slot key
  they carry has a colon in it, which the link-checking step was silently stripping before
  comparing it against the link's own signature. Fixed; a plain rule or campaign link never
  had a colon, so nothing else was affected.

## 3.3.0 - 2026-09-22

### Added
- Nine of WooCommerce's own order emails (new order, cancelled order, failed order, on-hold,
  processing, completed, refunded, invoice, and note added to order) can now be designed the
  same way as the welcome email, from a new "Order emails" list on the Emails screen.
  WooCommerce keeps deciding whether each one is turned on and who it goes to; only the
  design changes.
- Two new blocks for a designed order email: Order items (picture, name, quantity and price
  for each product on the order) and Order totals (subtotal, shipping, discount, tax and
  total, exactly as WooCommerce itself would show them).
- Merge tags for payment method, shipping method, billing address and shipping address, and
  the existing order tags (order number, date, total, status, tracking) now work in any
  order email design, not only the order-status automation.
- A guest order's emails fill in the customer's name and email from what they typed at
  checkout, since there is no account to read them from.

### Changed
- Order emails and Order totals blocks only show anything inside a designed order email;
  elsewhere they render nothing, the same way the wholesale-only explainer blocks already
  behave for a retail reader.

## 3.2.0 - 2026-09-22

### Added
- Three new blocks: Social links (icons for Facebook, Instagram, X and YouTube, only the
  ones you fill in), Video (a linked thumbnail), and Custom HTML (your own code, for anyone
  with the "unfiltered_html" capability).
- Every block now has "Show this block to" (everyone, wholesale customers only, or retail
  customers only) and "Hide on" (phones, or everything but phones), under "Spacing and
  background" in the editor.
- Products blocks can show "Currently on sale" as well as picked or newest.

### Changed
- A Custom HTML block is refused when saved by someone without the "unfiltered_html"
  capability, the same way it would be anywhere else in WordPress, rather than silently
  losing its content.

## 3.1.0 - 2026-09-22

### Added
- A heading font, separate from the body font, with three web fonts (Inter, Roboto,
  Merriweather) alongside the existing Helvetica, Georgia and System choices. A web font
  loads only when one is actually chosen; Outlook never sees the request and uses the
  fallback typeface in the same list.
- A link color setting, for templates and site-wide (Messaging → Settings → Email design),
  separate from the brand color.
- A mobile side padding setting (0 to 24 pixels), for tighter margins on a phone.
- Four new starters: Thank you for your order, Sale announcement, Back in stock, and
  Newsletter update. Every starter (12 in total) is now grouped into a category (Welcome,
  Account, Orders, Promotions, Newsletter, Blank).
- Starting a new, blank template now opens a gallery of starters grouped by category, with
  search, instead of an empty canvas. Blank template is still one click away.

### Changed
- Messaging → Settings → Email design gained "Heading font", "Link color" and "Mobile side
  padding" fields. Every existing template keeps the exact look it already had: none of
  these change anything unless set.

## 3.0.0 - 2026-09-22

### Added
- Email templates now open in a new editor: click into any block's text or setting and it
  updates instantly, drag blocks in from the panel or move them by dragging, undo and redo
  (Ctrl/Cmd+Z), and a Preview tab that shows the exact email a send would produce, at desktop
  and phone widths. Saving, live preview and sending a test now go through the REST routes
  added in 2.10.0 instead of a full-page reload.
- A logo for one template (Header and footer panel), separate from the site-wide one in
  Email design. Previously only the site-wide logo could be set.
- "Insert a personal detail" is back on every field that takes one (subject, preview text,
  footer text, and each block's text fields), the same plain-language picker the old editor had.
- Unsaved changes are kept locally and offered back if the tab is closed or reloaded before
  saving.

### Changed
- The template editor's underlying HTML is different (a mount point the new app fills in);
  no template's saved content changes, and the Emails, Email settings, Sent automatically as
  and Design panels store exactly what they did before.

## 2.10.0 - 2026-09-22

### Added
- Messaging → Settings has two new choices: "Send email through" and "Send texts through",
  each Automatic (Brevo when it's connected, otherwise this site's own mail for email) or a
  specific one you pick. Nothing changes for a store that leaves both on Automatic.
- A REST foundation for the template library (`protech/v1/templates`), used by nothing in the
  admin yet. The existing Email templates screen keeps working exactly as it does today; this
  is the base the next release's editor builds on.

### Changed
- Email and SMS sending now goes through a small provider interface instead of asking Brevo
  directly. Behavior with both sending settings on Automatic is unchanged.

## 2.9.1 - 2026-09-21

### Fixed
- Messaging → Log now actually has a search box for recipient or subject. 2.9.0 wired up
  the filter underneath but never added the field to type into.

## 2.9.0 - 2026-09-21

### Fixed
- A text message queued during quiet hours no longer crashes the delivery worker. It is held
  and sent once quiet hours end, as intended, instead of throwing a fatal error that stopped
  every other queued message behind it.
- A product grid with fewer columns than its column count now stacks properly on a phone. The
  blank cells that pad out a short last row were missing the class the phone layout looks for.
- The messages table is recreated automatically whenever the plugin next updates itself, not
  only the one time (long ago) it was first created. The Log screen's own text now says so
  accurately.
- The site-wide email logo is now a proper Media Library picker in Messaging → Settings,
  instead of typing an attachment's numeric ID into a box.
- The three application-flow emails (received, approved, rejected) are now recorded in the
  message Log, the same as every other email this plugin sends.
- Unsubscribing now lands a wholesale customer on the real portal page, wherever it actually
  is, instead of assuming it sits at "/wholesale".
- The built-in application emails use the store's own brand name instead of a hard-coded one.

### Changed
- The 1,600-line Messaging admin file was split into one class per screen (Automations,
  Compose, Log, Compliance, Settings) with a shared helper for the transient "stash" every
  screen uses to carry a form back across a redirect. No visible change; every button and
  link works exactly as before.

## 2.8.2 - 2026-09-21

### Changed
- The canvas dims briefly while a change is on its way to it (adding, dragging, moving,
  copying or removing a block, or typing settling down), so it reads as "updating" rather than
  frozen on a slower connection. It clears the moment the redrawn email arrives.

## 2.8.1 - 2026-09-21

### Fixed
- Adding, dragging, moving, copying or removing a block now redraws the canvas right away,
  instead of waiting up to 0.7 seconds. Typing still waits for a pause, so it isn't one request
  per keystroke: only the things that fire once per action (a drop, a click, a dropdown, a
  picture chosen) are now instant. The Refresh button still works, for a slow connection or with
  JavaScript off.

## 2.8.0 - 2026-09-21

### Changed
- **The email template editor now looks and works like a drag-drop composer.**
  The email itself is the big thing in the middle of the screen; a narrower
  panel on the right holds what to add and every setting.
  - Click a block right on the email to open just its settings. The rest of
    the panel steps aside while you edit it, with a "Back to blocks" link to
    return.
  - Drag a block from the panel and drop it onto the email where you want it,
    or click Add as before.
  - The other settings (Send a preview, Email settings, Sent automatically as,
    Design, Header and footer) are now collapsible sections, so the panel
    isn't one long scroll.
  - Reordering, copying and removing a block are unchanged; so is everything
    about what gets saved or sent. This is a look-and-feel change only.

## 2.7.0 - 2026-09-21

### Added
- **The Emails screen.** The first page of Messaging (formerly "Automations") is
  now one list of every email the shop can send, in three groups:
  - **When someone joins**: the welcome email and the three application emails.
    Each shows whether it is the built-in wording or a designed template, with
    "Design this email" (starts from the ready-made template and opens the
    editor), "Edit design" and "Use built-in wording".
  - **Automatic**: your rules, each with a plain sentence saying when it fires
    ("30 days after their last order, if they have not ordered since"), its
    on/off switch, and a new **Duplicate**.
  - **Sent**: past messages from Compose, with who they went to and how they did,
    and **Duplicate and edit** to open Compose filled in to send one again.
- **The four standard automations are created for you, switched off:** reorder
  reminder, win-back, first-order nudge and order shipped, with the wording
  written. The reminder and win-back use their designed templates. Nothing sends
  until you turn a rule on. Only added to a store that has no rules yet.

## 2.6.0 - 2026-09-21

### Added
- **Designed versions of the emails the shop sends on its own.** In the template
  editor, "Sent automatically as" lets one template stand in for the welcome
  email, "Application received", "Application approved" or "Application
  rejected". Only one template can have each, and picking "Nothing" goes back to
  the built-in wording. With none picked, nothing changes: every one of these
  emails is exactly what it was.
- Two ready-made templates to start from, "Application approved" and
  "Application rejected", added to your library on update (deleted ones do not
  come back). Neither is switched on.
- Two merge tags for those emails: `{set_password_url}` (the approval email's
  one-time link to set a password; if a link cannot be made it becomes your My
  Account page, so the button always goes somewhere) and
  `{application_reject_reason}` ("Reason: ..." when you typed one, empty
  otherwise). Anywhere else they are harmless: the first is the ordinary
  password-reset page and the second is empty.

### Changed
- A template bound to one of these emails is a service email: it carries no
  unsubscribe link, and saving it sets its type to Service email.

## 2.5.1 - 2026-09-21

### Fixed
- Reviewing or sending a large audience (a few hundred retail customers) no
  longer waits on Brevo for every person while the page loads, which made the
  page time out. The review and the queueing use the local unsubscribe record,
  and Brevo is asked about each person as their message is delivered, so
  nobody blacklisted there is ever sent to.

## 2.5.0 - 2026-09-21

### Added
- **Compose can reach retail customers.** A new "Customers" choice above the
  audience: wholesale customers (as before), retail customers (shop accounts that
  are not wholesale), or everyone. New segments: "Ordered in the last N days" and
  "Bought this product" (a product or any of its variations; cancelled and
  refunded orders do not count). "All of them", "No order in N days" and "Have
  never ordered" work for whichever group you chose.
- The review screen names the audience ("All retail customers", "Retail
  customers who ordered in the last 30 days") and shows who is left out and why.
- Compliance shows consent on file for wholesale and retail customers side by
  side, and says plainly why email and texts differ.

### Changed
- Marketing email to a retail customer carries the unsubscribe link and your
  postal address, but no "Manage preferences" link (that page is part of the
  wholesale My Account). After unsubscribing, a retail customer sees a plain
  confirmation page instead of the wholesale login page.
- Texts are unchanged: marketing texts still go only to people who have opted in
  to them, so a retail audience gets email only unless someone has said yes.

## 2.4.0 - 2026-09-21

### Added
- **Designed emails on automations and Compose.** Both forms have a Design
  picker: choose an email template and it supplies the layout and wording (the
  typed Heading and Body are then ignored), or leave it on "Plain message" for
  exactly the behavior of before. A Subject typed on the rule or message still
  replaces the template's own. A template that has since been deleted falls back
  to the typed body if there is one, and otherwise the message fails in the Log
  with a clear reason instead of sending an empty email.
- **A review screen before anything is sent.** Compose now ends in "Review and
  send": who it goes to and how many people, who is left out and why
  (unsubscribed, no phone number, not opted in), the email drawn as it will look
  (or the text message with its length), and a reminder of what kind of message
  it is. From there: send a preview to any address, go back and edit, or send.
  The old browser pop-up confirmation is gone; this screen replaces it.

### Changed
- "Preview recipients" on Compose is replaced by the review screen. The
  automation form's own "Preview recipients" is unchanged.

## 2.3.1 - 2026-09-21

### Fixed
- **"Send preview" and "Preview recipients" on Compose, and "Preview recipients"
  on an automation, did not do what they said.** On a live site those buttons ran
  the form's main action instead: on Compose that is the real send (to the whole
  audience), on an automation it is Save rule. They now run their own action. If
  you pressed either of them before this update, check Messaging → Log for
  messages you did not intend to send. A test now fails if a button is built that
  way again.

## 2.3.0 - 2026-09-21

### Added
- **The email template editor**, under Messaging → Email templates: New template
  (or start from a starter), Edit, Duplicate and Delete. Add blocks with the
  buttons under the email, click a block to open it, drag it by the dots or use
  the arrows to reorder, Copy or Remove it. Columns hold blocks of their own.
  Pictures come from the Media Library. "Insert a personal detail" puts a
  customer's name, shop link and so on into whichever text box you last clicked.
- A live preview beside the editor, drawn by the same code that sends the
  email, with Desktop and Phone widths. It fills in your own name.
- "Send preview" from the editor to any address (blank sends it to you), and a
  Service email or Marketing type (marketing always carries the unsubscribe
  links and the store address).
- A template that is bound to a lifecycle email, or used by an automation or
  campaign, cannot be deleted; the list says where it is used.

Templates are still not used by any rule, campaign or automatic email. Nothing a
customer receives has changed.

## 2.2.1 - 2026-09-21

### Fixed
- **The store address in the marketing footer** read "Evanston, 60204, US:IL"
  (WooCommerce keeps country and state in one value). It now reads "Evanston,
  IL 60204". This affected the footer added in 2.1.1.
- Email templates on a phone: the card no longer overflows the screen (it is
  fluid up to the set width, with a fixed-width fallback for Outlook on
  Windows).
- The pack, display and case picture in a template drew its text in a serif
  font, and its dot grids lost their spacing.
- "Newest products" in a product block no longer includes products hidden from
  the catalog (an unlisted kit, for example).

## 2.2.0 - 2026-09-21

### Added
- **Email templates**, under Messaging → Email templates: a library of designed
  emails built from blocks (heading, text, button, image, products, quantity
  explainers, columns, divider, space) with their own header and footer. This
  release adds the library, six starter templates (Welcome to wholesale,
  Application received, Restock reminder, Win-back offer, New arrivals
  announcement, Blank) and a Preview for each. Previews use your own name and
  show marketing templates with the footer a real send adds. Templates are not
  used by any rule, campaign or automatic email yet, and none is switched on:
  nothing a customer receives has changed.
- **Email design** settings (Messaging → Settings): logo, brand color, email
  width and footer text, which every template inherits.
- Two merge tags, `{login_url}` (the wholesale login page) and
  `{lost_password_url}`.

## 2.1.1 - 2026-09-21

### Changed
- **Marketing emails now carry the store's postal address** under the
  unsubscribe and preferences links, which US law (CAN-SPAM) requires in every
  marketing message. It comes from WooCommerce → Settings → General; while it
  is empty and messaging is on, the Wholesale screen's setup notice says so.
- The footer sentence fits the reader: "because you have a wholesale account
  with X" for a wholesale customer, "because you have shopped with X" for
  anyone else.
- Internal groundwork for the email composer, with no visible effect: merge
  tag substitution is split out (`MergeTags::fill()`), sending is split into
  "decide the footer" and "hand to Brevo" (`MessageTransport::footer_html_for()`
  and `dispatch()`), the welcome email's diagram drawing moved to a shared
  `EmailBlocks`, and merge-tag insertion now also works on fields added after
  the page loads.

## 2.1.0 - 2026-09-21

### Changed
- **Messaging is now its own menu in the wp-admin sidebar** (Messaging, below
  WooCommerce and Products), with a sub-page each for Automations, Compose,
  Log, Compliance and Settings. It moved out from under WooCommerce →
  Wholesale because it is no longer a wholesale-only feature. Old links and
  bookmarks to the Wholesale tab redirect to the same view, and the Wholesale
  screen keeps a Messaging link beside its Log link. Nothing else changed: the
  same screens, the same settings, the same data.

## 2.0.0 - 2026-09-21

### Changed
- **The plugin is renamed Protech Commerce** (folder and file `protech-commerce`),
  because it is growing beyond wholesale: a visual email composer and messaging
  for every customer are coming next. This is a new plugin slug, so WordPress
  treats it as a different plugin: install the new zip, activate it, then delete
  the old "Protech Wholesale" one. **Untick "Purge data on uninstall" first**
  (Settings tab) or deleting the old copy erases the settings. Nothing else
  moved: options, user meta, roles, the message table, hooks and the text domain
  all keep their `protech_wholesale_*` / `protech-wholesale` names, so every
  setting, customer and message log entry carries over as is.
- Releases now attach `protech-commerce.zip`. An installed 1.x copy looks for
  the old file name, finds none, and simply reports no update instead of
  offering a package that would break it.

## 1.6.3 - 2026-09-21

### Changed
- The out-of-stock line in "Want every color?" has a soft red "!" instead of
  yellow, which was hard to see on the light blue, and no longer repeats
  "It will not be added. The other N colors are added." (the count line and the
  button already say it).

## 1.6.2 - 2026-09-21

### Changed
- On a product page, a wholesale customer sees the short description (size,
  count, finish) below the Add to cart button instead of above the pricing.
  Retail visitors and guests are unchanged.
- The out-of-stock notice in "Want every color?" is quieter: a small icon and a
  line of text, no colored box.

### Fixed
- The price line under "Want every color?" and on the starter kit panel showed
  "&#36;5.00/pack" after the cart changed, because the script printed an HTML
  entity as text. The quote now carries plain text with a real dollar sign.

## 1.6.1 - 2026-09-21

### Changed
- "Add one display of every color" moved to right under the Wholesale pricing
  table.
- An out-of-stock color is now hard to miss in that block: an amber "Out of
  stock: <color>" notice saying it will not be added and how many colors will,
  a crossed-out swatch, a count that says "in stock", and a button that reads
  "Add one display of each color in stock".

## 1.6.0 - 2026-09-21

### Added
- **Welcome email** for accounts upgraded to wholesale (Customers tab): how to
  log in, the pack / display / case picture, and what each quantity level
  unlocks. Send it from "Add existing customers to wholesale" (a box, ticked
  by default), from ticked rows ("Send welcome email"), or from Send / Resend
  on a row. A new Welcome email column shows when each customer got it. It is
  logged like any other message and never touches a password.
- **"Add one display of every color" button** under a variable product's
  add-to-cart form (wholesale customers). One click adds one display of every
  color that has a wholesale price and is in stock, priced at the tier the cart
  reaches. It reads the product's live colors on each page load, so new colors
  are included automatically, and shows the color count, displays, packs and
  price. Needs no kit product.
- **Send a preview** on Compose, on every automation rule, and for the welcome
  email: type any email address (and a phone number for texts) and the message
  goes there as written. It replaces "Send test to me", which could only reach
  your own account. "Both" now previews both the email and the text.

### Changed
- "colour" is now "color" everywhere (screens, emails, docs, tests).
- The quantity legend moved from above the order control to above the
  Wholesale pricing table, full width to match it.

## 1.5.1 - 2026-09-21

### Changed
- The crossed-out MSRP in the main price is the same grey as the one in the
  price table, and the "MSRP" tag in front of it is gone.
- The quantity legend has more room (wider, larger tiles, more spacing), says
  "8 displays = 1 case", puts the free-shipping line on its own, and uses US
  spelling ("color") on the legend, the price table footnote and the
  `/wholesale` page.

## 1.5.0 - 2026-09-18

### Added
- **Messaging & automations**, under WooCommerce → Wholesale → Messaging.
  Email and SMS to wholesale customers through Brevo (email falls back to
  the site's WooCommerce mailer if Brevo isn't connected):
  - **Automations**: reorder reminder, win-back (repeating), first-order
    nudge, and order-status (e.g. "shipped") rules, each with a
    tier filter, email/SMS content with merge tags, and a "Preview
    recipients" dry run. A day-based rule only fires inside a 7-day
    window from its trigger day, so enabling one never reaches back
    into old history, and never repeats for the same order.
  - **Compose**: one-off email/SMS to a chosen audience (all, a tier, no
    order in N days, never ordered, prefers texts but hasn't opted in,
    or specific customers ticked on the Customers tab), plus "send test
    to me."
  - **Log**: every message ever queued, its status, and why it was
    skipped when it was.
  - **Compliance**: the SMS opt-in wording in use, message types
    configured, a consent-records CSV export, and suggested
    privacy-policy/terms text (the proof-of-opt-in package for Brevo's
    toll-free-number verification).
  - **Settings**: Brevo connection (own key, or the Brevo plugin's own
    key), sender, brand, quiet hours, frequency cap, unsubscribe footer.
  - Self-service SMS/email preferences under My Account → Notifications;
    an admin can also record consent on a customer's profile (a note is
    required). The wholesale application form gained two SMS consent
    checkboxes (order updates, marketing/reorder reminders).
  - Delivery runs entirely on Action Scheduler. Nothing is ever sent
    from a checkout or admin request. First custom database table in
    this plugin (`{prefix}protech_wholesale_messages`), created via
    `DB_VERSION` 3.
- **MSRP crossed out next to the wholesale price** everywhere a price
  shows to a wholesale customer (product page, shop grid, the per-color
  price swap): "~~$9.99~~ $5.00 Save 50%", using WooCommerce's own
  del/ins sale markup so the theme's price styles still apply. The MSRP
  is the store's regular price; a product whose MSRP isn't above the
  wholesale price shows the plain price. `Pricing::get_msrp_range()`.
- **"After a wholesale login, go to"** (Settings → Catalog): where an
  approved wholesale customer lands after logging in on `/wholesale` or
  My Account. Empty = the shop page, as before. Off-site URLs fall back
  to the shop. `Settings::login_landing_url()`.
- **"How wholesale quantities work" legend** above the Display/Case
  control on every wholesale product page: pack → display → case tiles
  drawn from the product's own composition, then "8 displays = 1 case.
  Mix and match your displays however you'd like. Free shipping from 16
  displays (2 cases), any combination of colors." Every number comes
  from the settings. `templates/quantity-legend.php`.

### Changed
- **Tier names.** The first wholesale tier no longer has a name on the
  storefront: it is shown by its range ("Under 16 displays") in the
  price table, the sticky bar's marker and the My Account card, and the
  bar's chip reads just "Wholesale" while a cart is in it. What was
  called Volume is now **Standard** and what was called Bulk is now
  **Volume**; the message reads "Add N more displays to unlock free
  shipping and Standard pricing." In admin the three rows are Base /
  Standard / Volume, and the product-field labels follow ("Standard
  price override", "Volume price override"). Slugs, option names and
  meta keys are unchanged (`standard`/`volume`/`bulk`), so nothing
  migrates and every existing override keeps working.

## 1.4.0 - 2026-09-18

### Added
- `[protech_header_notice]` shortcode for Salient's "Text To Display In
  Header" customizer field: wrap the existing retail text in it and a
  logged-in wholesale customer sees a wholesale line instead ("FREE
  SHIPPING ON WHOLESALE ORDERS OF 16+ DISPLAYS" by default, built from the
  live Volume threshold), with a `wholesale` attribute and a
  `protech_wholesale_header_notice` filter for custom text. No theme edit.

## 1.3.1 - 2026-09-18

### Fixed
- On a variable product's page the theme's own quantity stepper was back
  beside the Display/Case control, with no read-back line and a plain "Add
  to cart" button, and the quantity no longer stepped by the display. The
  1.3.0 "sold at wholesale" check looked at the variable product's parent,
  which never carries a price itself; it now looks through to the colors.
  Regression test added. (Sample packs without any wholesale price are still
  exempt, as intended.)

## 1.3.0 - 2026-09-18

Admin-side audit, and updates from GitHub.

### Added
- **Updates.** New versions are published as GitHub releases by CI on every
  push to `main` (tag from the plugin header, `protech-wholesale.zip` attached,
  notes from this file). The plugin's `Update URI` routes WordPress's update
  check to that release, so it shows in the Plugins list with View details
  and one-click Update. "Check for updates" under the plugin row, and an
  Updates block on Settings.
- Plugins list row links: Applicants, Settings, Changelog, Check for updates.
- A count bubble on the WooCommerce → Wholesale menu item while applications
  are waiting; quick links (login page, application form, log) under the
  title; a Help pull-down explaining the tabs.
- A setup notice on the Wholesale screen while something is missing: the
  shipping method not in any zone, no priced product, no portal page, or an
  application form ID Fluent Forms does not have. Each with a link to fix it.
- "Apply to all variations" price fields on a variable product's Wholesale
  tab, with a line saying what the variations currently hold.
- A "Wholesale" column on Products → All Products.
- Tier changes from the Customers tab.
- "Send new-application emails to" setting.

### Changed
- Pricing & Shipping is three titled groups (quantity pricing, displays and
  cases, wholesale shipping) with one Save button; the retail free-shipping
  exclusion moved here from Settings, next to the rest of shipping; the tab
  says which zones have the wholesale method.
- Settings is grouped: Catalog, Applications, Updates, Uninstall.
- "Standard (Tier 1)" / "Volume (Tier 2)" / "Bulk (Tier 3)" are now just
  Standard, Volume, Bulk. "Tier" means only the Bronze to Platinum customer
  levels.
- **Display and case rules apply only to products that carry a wholesale
  price.** A product without one is bought on retail terms by everyone,
  quantity 1 allowed, and does not count toward tiers or the cart badge.
  This is what lets the Vendor Starter Kit be a plain, unlisted $800 product
  sold from a link to new vendors.
- Customers tab reads order count and spend from WooCommerce's cached
  per-customer figures instead of loading every order of every row.

### Removed
- The unenforced dollar "Minimum order" fields (Tiers tab, per-tier
  overrides, user profile) and the code behind them. Old saved values are
  ignored and still deleted on purge.

## 1.2.0 - 2026-09-17

Storefront polish: everything a wholesale customer sees.

### Changed
- **Protech Blue (`#42649D`) is now the color of all wholesale UI**: the
  sticky bar, the "Wholesale price" label, the price table, the account
  card and the `/wholesale` page. Theme buttons (Add to cart, Checkout) stay
  black. Plugin text now inherits the site font (Poppins) instead of forcing
  Open Sans.
- **Product page quantity is one control.** Display/Case are two radio
  cards, with a −/+ stepper, a live "3 displays · 30 packs" read-back, and an
  Add to cart button that says what it will add ("Add 3 displays to cart").
  A successful add is confirmed in place, and the chosen unit is remembered.
- **Sticky tier bar rebuilt** as a floating blue dock (full width on
  phones, two rows instead of three): tier chip, a Standard marker and
  per-pack prices on the Volume/Bulk markers, whole-case tick marks, a
  "Saving $X" figure once a tier is reached, and a preview segment showing
  where the quantity being dialled in on a product page would land.
  Reaching a tier is celebrated once (marker pop, sheen, confetti), including
  when it happened through a full page reload. All motion is off under
  `prefers-reduced-motion`.
- The bar's track is now two segments, with the Volume marker at 40%
  rather than 12.5% (the first tier is no longer crammed into the left edge).
- **Header cart badge counts displays** for wholesale customers (it also
  drives the Blocks mini-cart count), and grows into a pill instead of
  overflowing at three digits. Retail is unchanged. Filter:
  `protech_wholesale_cart_count_in_displays`.
- **My Account** shows a "Wholesale Partner" strip with the business name on
  every account page (an "Application under review" one for pending
  applicants), and the dashboard panel is now a card with a progress rail,
  cart stats, tier savings and a checklist of unlocked tiers.
- **`/wholesale` login page** is a full-width two-panel card: a blue pitch
  panel whose benefits come from the store's real thresholds, beside a
  larger form with a show/hide password button. Pending, rejected and
  retail-only share one status card; pending gets a three-step tracker.
- The product page price table highlights the active row in blue, shows a
  "Save N%" chip per tier, and moves its highlight live when the cart
  crosses a tier.

### Fixed
- The theme's own pack-quantity stepper was never actually hidden on the
  product page (Salient's `.cart div.quantity { display: flex }` outranked
  the plugin's hiding rule), so customers saw two quantity controls.
- Bar messages read "Add 12 more display worth…"; they now use real
  singular/plural wording and name the tier being unlocked.
- The login form had no `autocomplete` attributes, lost the username after a
  failed attempt, and printed the login error's HTML tags as literal text.
- The price table's "Your cart" row stayed stale after an AJAX add to cart.
- The bar's fill could drift from the real tier for products with a
  non-default case composition; it is now pinned to the tier reached.
- (1.1.0 regression) A wholesale-only product's own page returned a 404 to
  anyone who isn't a wholesale customer, staff included, because the
  query-level catalog filter also ran on the single-product query. It now
  leaves single-product pages alone, so the "approved accounts only" notice
  shows as documented.
- A white band showed under the footer at the very end of every page: the
  room reserved for the floating bar was `<body>` padding, which paints the
  body's background. It is now a spacer that takes the color of whatever
  the page ends with (found on staging, first look).
- The sticky bar is now the same width as the site header instead of a
  fixed 1180px: it matches the floating header card edge for edge (measured
  in the script, with a stylesheet fallback built on the theme's own
  container variables). The two-row layout starts at 1160px so the track's
  captions never collide. Verified by rendering the real staging page in
  headless Chrome at six widths.

### Added
- **Starter kits.** A simple product can be marked as a kit on its Wholesale
  tab: its page adds one display of every color of a chosen variable
  product to the cart, topped up to a target (default: the Volume threshold)
  with chosen filler colors. Real variations go in the cart, so stock,
  pricing and Reorder all just work; a 16-display kit costs 160 packs at the
  Volume price with free shipping. The page lists the kit's contents, quotes
  it live for any number of kits, and adds it in place (the sticky bar
  previews the jump to Volume). Set up: README, "Starter kits".
- Filters: `protech_wholesale_tier_bar_show_prices`,
  `protech_wholesale_cart_count_in_displays`,
  `protech_wholesale_portal_benefits`,
  `protech_wholesale_tier_bar_align_selector`,
  `protech_wholesale_tier_bar_spacer_color`.
- DOM events: `protech:tier-state` (every new bar state) and
  `protech:qty-preview` (the quantity dialled into the product page control).
- Template `account-wholesale-header.php`; script `assets/js/portal.js`.

## 1.1.0 - 2026-09-17

### Security
- A public application-form submission carrying an existing account's email
  could replace that account's roles (an administrator's email demoted the
  administrator to a read-only pending applicant). The two wholesale roles
  are now only ever added to or removed from an account, and accounts with
  staff capabilities are never changed automatically. Their application is
  recorded for manual review instead.

### Fixed
- Orders placed through the (Blocks) checkout were never flagged as
  wholesale, so the Wholesale column/filter and the "WHOLESALE ORDER" email
  subject never fired on this site.
- The "Set wholesale prices" variations bulk action did nothing.
- "Wholesale only" and "Displays per case" were unreachable on variable
  products (the fields rendered inside a simple-products-only panel).
- Rejected applicants stayed in the pending role: still counted as pending,
  and shown "your application is under review" forever.
- A variable product's cached price range ignored the cart's quantity tier
  and per-customer overrides.
- Wholesale-only products leaked (name and retail price) through the public
  Store API product listing, and template-level hiding left pagination holes.
- The Blocks cart's quantity controls ignored the display size.
- Reorder showed "invalid link" to a customer whose session had expired.
- The My Account login form did not redirect wholesale customers to the shop.
- The HPOS orders filter dropdown rendered twice.

### Added
- "Wholesale" product data tab; Volume/Bulk override bulk actions.
- Wholesale price table (Standard/Volume/Bulk) on the product page.
- Customers tab; application details on the user profile; product search
  picker for per-customer overrides; search and pagination on Applicants;
  Rejected view with a reason prompt; confirmation before Approve.
- "Your wholesale account" panel on the My Account dashboard.
- Branded emails via the WooCommerce mailer; approval link goes to the
  My Account password form; the admin notice includes the full application.
- Tier bar: hidden on checkout, screen-reader announcements, safe-area
  padding, reduced-motion support.
- Test suite (PHPUnit inside wp-env), PHPCS/PHPStan configs, GitHub Actions.

### Changed
- Price filters memoize the cart tier and role checks per request.
- Wholesale shipping is not offered for a cart with nothing wholesale-eligible;
  the retail methods it hides are filterable.
- Packs per display falls back variation → parent → store default.
- `pre_get_posts` catalog filtering replaces template-level hiding; a one-off
  upgrade backfills the parent-level has-wholesale-price flag.

## 1.0.0 - 2026-09-17

Initial build: roles and approval flow, wholesale pricing engine, Display/Case
quantity-tier ladder, wholesale shipping method, sticky tier bar, unit
selector, reorder, portal page, admin reporting.
