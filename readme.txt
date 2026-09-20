=== BrikPanel: WooCommerce Dashboard, Abandoned Cart Recovery, Google Sheets Sync, Inventory Management & Bulk Editor ===
Contributors: brksoft
Donate link: https://donate.stripe.com/14AdR9ghJcxKaAqdzbc3m00
Tags: woocommerce dashboard, woocommerce inventory management, google sheets, woocommerce bulk editor, roas
Requires at least: 6.0
Tested up to: 7.1
Stable tag: 3.3.15
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Free WooCommerce dashboard & sales report: abandoned cart recovery, Google Sheets sync, ROAS, bulk editor & inventory management

== Description ==

**Live demo (no install needed):** [Explore the full BrikPanel admin on a real WooCommerce store](https://code.brksoft.com/wp-admin/)

https://www.youtube.com/watch?v=pmtmVQifZME&t

**BrikPanel turns the default WooCommerce admin panel into a clean, fast, all-in-one cockpit**: a modern WooCommerce dashboard, a real-time WooCommerce sales report, a powerful WooCommerce bulk editor, an inventory management workspace, an order management center, a coupon manager, a custom WP login page, and a real-time conversion tracking suite. Everything is free. Forever. No premium tier, no feature locks, no monthly subscriptions. A self-hosted **Shopify alternative for WooCommerce**: own your data, your products, and your customer list, with no monthly platform fee and no transaction fee.

= Who is BrikPanel for? =

* Store owners who want a **modern WooCommerce dashboard** with real numbers, not the slow built-in reports, and a **self-hosted WooCommerce analytics** solution instead of paying monthly fees to external SaaS tools
* Stores that want a lighter **woocommerce inventory management** workspace built into a complete admin redesign
* Anyone who needs to **bulk edit WooCommerce products**, including variations, without a premium plugin
* Agencies handing off stores to non-technical clients who need a **simplified WooCommerce admin**
* Shop owners migrating from Shopify who want a familiar, modern admin for their WooCommerce store, a free, self-hosted **Shopify alternative**

== What you get (all free) ==

= Modern WooCommerce Dashboard & Sales Report with Real-Time Analytics =

The heart of BrikPanel is a **modern WooCommerce dashboard**, a true **woocommerce admin panel plugin**, not a styling layer.

* **Total Sales, Total Orders, Average Order Value (AOV)**: today, yesterday, last 7/30 days, or any custom range, with **±% period-over-period delta** on every metric
* **Visitors** counted from your own database (admins excluded), and **Conversion Rate** computed live from real visitors and real orders
* **Beautiful sales chart** powered by Chart.js, plus an **order status donut** (Completed, Processing, Cancelled, Refunded, Failed)
* **WooCommerce conversion funnel**: Visitors → Add to Cart → Checkout → Orders, with the conversion percentage at every step

This is a complete **WooCommerce sales report** and **reporting** layer: real-time **sales reports**, charts and KPIs inside a **modern WooCommerce admin**, with no external analytics service.

= Customer Analytics: LTV, RFM Segmentation & Cohort Retention =

BrikPanel ships a complete **WooCommerce customer analytics** suite, calculated from your store data and visualized in the dashboard, no external service.

* **Customer Lifetime Value (LTV)**: total customers, average and top LTV, full LTV distribution histogram, and a sortable top-customers table
* **RFM segmentation**: every customer scored on Recency, Frequency, and Monetary, then bucketed into Champions, Loyal, At Risk, About to Sleep, Hibernating, and Lost, with revenue per segment
* **Cohort retention**: month-by-month cohort retention grid plus an average retention by month-offset trend line
* **Advanced filtering and segmentation**: combine spend range, product, location, date and more to build saved segments for both customers and orders

= Live Visitors & Real-Time Conversion Tracking =

BrikPanel ships a built-in **WooCommerce live visitors** widget, see who is on your store right now, what page they are on, and whether they have items in the cart. Refreshes every 30 seconds by default (configurable). No external service, no Hotjar, no monthly fee.

* **WooCommerce real time visitors** widget with cart status (*Browsing / Has items in cart / On thank-you page*), current page, and customer info
* **WooCommerce conversion tracking** in the same database that powers the dashboard
* Visitor IPs are never stored, only a salted SHA-256 hash, and live visitor data stays in a short-lived cache, never in the database
* Privacy switches: make tracking wait for cookie consent (WordPress Consent API or your own banner), turn front-end tracking off entirely, or keep it on while excluding logged-in customer details from the Live view
* Most-viewed pages and most added-to-cart products reports

A free **woocommerce statistics plugin** and **woocommerce sales tracker** without any external SaaS.

= Geographic Analytics: WooCommerce Sales by Country =

A 3D rotating globe (Cobe.js) plots every order on its real location, see **WooCommerce sales by country** and city without exporting a CSV, with **Top 10 Countries** and **Top 10 Cities** tables. Works with both HPOS and legacy order storage.

= Lightning-Fast Order Search: Cmd/Ctrl + K from Anywhere =

Hit `Ctrl + K` (or `Cmd + K` on Mac) anywhere in wp-admin and an order search overlay opens, the free **woocommerce order search plugin**. Searches order ID, customer name, email, phone and product SKU inside line items at once. True **woocommerce quick search**, with results as you type, status badges, totals and dates.

= Modern WooCommerce Order Management =

BrikPanel replaces the cluttered default orders page with a clean **woocommerce order list plugin** screen.

* **30-day overview bar**: total orders, completed, refunded, cancelled, revenue
* **Inline status change** without opening the edit page
* HPOS (`wc_get_orders`) and legacy storage (`WP_Query`) both supported
* Two new statuses: **Return Draft** and **Change**
* Reskinned order edit page with copy-to-clipboard for billing/shipping
* **Sold downloadable products column** on the order edit page
* Optional BrikMarket marketplace stats integration

A real **woocommerce order management plugin**, not a reskin. Disable from settings anytime.

= WooCommerce Product List Plugin: Built for People Who Actually Edit Products =

The default **WooCommerce product list** is fine for browsing, painful for editing. BrikPanel ships a complete **woocommerce product list plugin** that fixes it.

* Thumbnail, name, SKU, regular/sale price, stock badge, category
* **Publish status toggle**: flip draft ↔ published with one click, no reload
* Edit, Duplicate, Delete actions; bulk publish, draft, delete
* Status tabs (All / Published / Draft / Trash), live search by name or SKU
* Configurable per-page (5–100, default 20), AJAX pagination
* **Per-user toggles for any third-party / SEO column** added by Yoast, Rank Math, ASE and other plugins
* **Admin and Site Enhancements (ASE) custom columns** are respected in the BrikPanel product, order and customer lists

= Quick Edit Sidebar: Edit Without Leaving the List =

A slide-in panel from any product row to edit name, SKU, regular/sale price, stock and category, saved without leaving the list. The **woocommerce quick edit** WooCommerce should have shipped years ago: update **woocommerce quick edit price**, stock or category in two clicks.

= Bulk Edit WooCommerce Products with the Variation Editor: Full Variation Support =

This is where BrikPanel pulls ahead of every other free **woocommerce bulk editor**. Most free plugins only handle simple products and only "increase price by X%". BrikPanel does far more, on variable products too.

* **WooCommerce bulk price update** (regular and sale): percentage, fixed amount, or absolute value, across the whole catalog or filtered by category
* **Bulk update WooCommerce products** stock quantities (in/out of stock, set quantity, add/subtract)
* **WooCommerce bulk price by category**: pick a category, set a rule, every product updates
* **WooCommerce bulk sale price** updates with a date range
* Confirmation dialog on every bulk action

Now the part nobody else does for free: **variation support**.

* **WooCommerce variation editor**: open any variable product and edit every variation in one modal (regular price, sale price, stock, SKU)
* **Bulk edit variation prices WooCommerce**: set the same price for all variations of an attribute (every "Red" variation, every "L" size), or apply a percentage rule
* **Bulk update variation stock**: set or adjust the stock of every variation in one click
* Attribute filter to narrow visible variations when a product has 50+ combinations

**How to bulk edit WooCommerce products** including variations without buying a $79/year plugin? BrikPanel handles both simple and variable products for free.

= Simplified WooCommerce Product Editor =

The default WooCommerce add-product screen has 11 metaboxes, 3 tabs and 40+ fields. BrikPanel ships a complete **woocommerce product editor plugin** with the noise removed.

* **Featured image + product gallery** with drag-and-drop upload, unlimited images, drag-to-reorder
* Regular price, sale price with decimal validation
* **Searchable category picker** with multi-select + **quick create category** without leaving the page
* **Brand field**: the WooCommerce `product_brand` taxonomy is now first-class alongside categories and tags
* Short description + full rich-text description (wp_editor)
* **SEO fields**: custom slug, meta title, meta description, live Google SERP preview
* **Full SEO plugin compatibility**: Yoast SEO, Rank Math, All in One SEO and SEOPress metaboxes (including the SEO score panel) render and save inside the BrikPanel product editor
* Product type (Simple, Variable), **attribute management** with inline create
* **Auto-generate variations** from attribute combinations, per-variation price/sale/SKU/stock
* Duplicate any product in one click

Opt-in. Keep the default WooCommerce product page if you prefer.

= WooCommerce Variation Gallery =

Attach a separate image gallery to each product variation, the frontend swaps gallery automatically when a customer picks a variation. Image metadata (srcset, sizes, alt text) is fully preserved.

= WooCommerce Categories Page: Drag-and-Drop Parent/Child Management =

BrikPanel rebuilds the dated WooCommerce category screen with per-page settings (5–200) and **drag-and-drop parent/child nesting** with circular reference prevention, for both `product_cat` and `product_tag`.

= Best WooCommerce Coupon Plugin: Free Coupon Manager =

A complete **WooCommerce coupon manager** that makes coupons first-class in the admin, and we think the **best WooCommerce coupon plugin** in the free repository.

* Coupon table with code copy-to-clipboard, discount type icon, amount, usage count, expiry highlighting, and status
* Status tabs, AJAX pagination, **slide-over coupon panel**: create/edit without a reload
* Auto-generate random coupon codes; one-click duplicate
* Discount types: percentage, fixed cart, fixed product + free shipping toggle
* Expiry date picker, total + per-customer usage limits, min/max spend, individual use toggle, product/category include/exclude rules

= WooCommerce Cart Abandonment & Cart Recovery =

A built-in **WooCommerce cart abandonment** and **cart recovery** system, with no external email SaaS. A dedicated **Abandoned Carts** screen captures the checkout email of shoppers who do not finish (classic and block checkout, plus logged-in add-to-cart) and snapshots each cart down to the exact variation. Carts move Active to Abandoned to Recovered automatically, and an optional popup hands each subscriber a single-use **cart recovery coupon**. Search and date filters, plus CSV / Excel export.

= Custom WordPress Login Page: Custom WP Login Page for WooCommerce =

A **custom WP login page** that fully replaces the default `wp-login.php` look, a real **WordPress login customizer** for WooCommerce stores.

* Centered card layout with your site name as logo
* Minimal, distraction-free fields, AJAX submission (no reload)
* Toast notification on errors, footer site branding
* Default WordPress login styles fully hidden

= WooCommerce Inventory Management =

A complete **woocommerce inventory management** workspace: the product list, bulk editor, variation editor and quick edit sidebar work together as one inventory workflow.

* Current stock for every product and variation in one place, with stock badges in the product list (in stock / low stock / out of stock)
* Update stock inline from the quick edit sidebar, or bulk update across categories and variations
* HPOS-enabled stores supported

A free **woocommerce inventory management plugin** that covers the daily workflow, no heavy stock control plugin needed.

= Custom Top Admin Bar & Notifications =

A **Custom BrikPanel-styled top admin bar** replaces the default WordPress toolbar with an e-commerce notification bell and quick links, toggleable from settings. Sound, confetti and a popup the moment a completed order arrives.

= Google Sheets Sync: Real-Time WooCommerce Google Sheets Integration =

BrikPanel ships a free **WooCommerce Google Sheets sync**, a fully native **WooCommerce to Google Sheets** integration that streams orders, customers and analytics into a Google Sheet you control. The free **GSheetConnector alternative** with no Zapier, no Make, no monthly fee.

* **Real-time order sync**: every new WooCommerce order is appended within seconds, one row per line item so variations get their own columns. Free **woocommerce order sync to google sheets** with no external automation tool
* **Scheduled WooCommerce Google Sheets export**: hourly, every 4h or daily catch-up; idempotent so re-runs never duplicate rows
* **Analytics report snapshots**: Sales Summary, Daily KPIs, Top Products and Funnel tabs refreshed on an interval for pivots and dashboards in Sheets
* **Customer + RFM snapshot**: chained to the nightly RFM recompute

HPOS-compatible: a real **google sheets woocommerce sync**, free.

= WooCommerce ROAS, Net Profit & Ad Spend: Google Ads + Meta Ads =

BrikPanel pulls daily spend from **Google Ads** and **Meta Ads** (Facebook / Instagram) so you see real **WooCommerce ROAS**, **Net Profit** and **ad spend** next to revenue. Multi-currency aware. A free **Triple Whale alternative** and **woocommerce profit tracking** dashboard with no monthly fee.

= BrikMarket Marketplace Analytics =

When BrikMarket is active, marketplace orders are excluded from the storefront conversion rate, and a dashboard block breaks down orders, share and top categories per marketplace.

= Subscription & Membership Plugin Compatibility =

Subscription products and member orders (WooCommerce Subscriptions, MemberPress, Paid Memberships Pro and more) show up in the same product list, order screens and customer analytics.

= Developer Hooks & Filters =

A **developer hooks and filters system** for agencies, actions and filters like `brikpanel_after_product_save`, plus a built-in docs popup in settings with one-click copy buttons.

= Navigation & Admin UI Cleanup =

* BrikPanel dashboard becomes the first WordPress admin menu item; admin bar gains quick links, footer rebranded
* Optional **simplified mode** hides the full WordPress menu, showing only BrikPanel + WooCommerce for non-technical clients

== A Free, Self-Hosted WooCommerce Analytics & Inventory Suite ==

Store owners pay monthly SaaS fees for parts of what BrikPanel does free:

* **Self-hosted WooCommerce analytics**: sales, AOV, conversion, funnels, geo data, customer LTV, RFM, cohort retention, no third-party
* A free Metorik and Triple Whale alternative: analytics, ROAS and profit on your own server
* **Shopify alternative for WooCommerce**: the clean admin experience of Shopify with your storefront, customer data and orders on your own server

== Why BrikPanel and not the default WooCommerce admin? ==

WooCommerce's built-in analytics are slow, refresh hourly, and have no live visitor tracking, conversion funnel, geographic data, customer LTV / RFM / cohort reports, Cmd+K order search, quick edit sidebar, variation bulk editor, custom login or coupon manager. BrikPanel fixes every one of those gaps inside a single **free WooCommerce admin plugin**.

== WooCommerce HPOS Compatibility & Performance ==

* **Zero impact on storefront speed**: only loads inside wp-admin
* **Hardened performance for low-resource hosting**: heavy queries are batched, cached and run through Action Scheduler so the dashboard, customer analytics and bulk editor stay responsive on shared hosting
* **HPOS (High-Performance Order Storage)** fully supported with dual code paths
* WooCommerce 7.x, 8.x, and newer; works alongside Admin Menu Editor, Slider Revolution, Yoast SEO, RankMath, WPML, Polylang
* Translation-ready (`.pot` file included), with all JavaScript / jQuery strings routed through `wp_localize_script`
* All AJAX actions verify nonces and `manage_woocommerce` capability; DB writes use prepared statements; visitor IPs stored only as truncated salted SHA-256 hashes; admin activity excluded from analytics; front-end tracking can be disabled entirely from settings

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/brikpanel`, or install via **Plugins → Add New → Upload Plugin**.
2. Activate through the **Plugins** menu.
3. Open **BrikPanel** in the admin sidebar, the dashboard loads immediately.
4. (Optional) Visit **WooCommerce → Settings → BrikPanel** to enable or disable specific modules.

That is it. No license key, no email signup, no external account.

== Frequently Asked Questions ==

= Is BrikPanel really 100% free? =

Yes. Every feature on this page is in the free version. There is no premium tier, no feature lock, no trial period, no upsell. We built this because we needed it for our own 1000+ WooCommerce stores and decided to release it.

= Is BrikPanel a self-hosted WooCommerce analytics solution? =

Yes. BrikPanel gives you a complete WooCommerce analytics suite that runs entirely on your own server with no external dependencies. Sales analytics, product reports, conversion tracking, customer LTV, RFM segmentation, cohort retention and customer data are all included, nothing is sent to any third-party SaaS.

= Does BrikPanel include a WooCommerce sales report? =

Yes. The BrikPanel dashboard ships a complete **WooCommerce sales report** out of the box, total sales, total orders, average order value (AOV), refunds, and net revenue, each with a ±% period-over-period delta. Filter the sales report by today, yesterday, last 7 days, last 30 days, or any custom date range. The sales chart is rendered with Chart.js and pairs with the order status donut and conversion funnel for a full sales report you can read at a glance, without ever leaving wp-admin and without paying for an external analytics service.

= Does BrikPanel offer custom WooCommerce reports, KPIs and a profit report? =

Yes. The dashboard goes far beyond the built-in screens with a complete set of **WooCommerce reports** and **WooCommerce sales analytics** computed live from your own store data: sales, orders, AOV, conversion rate, customer LTV, RFM segments and cohort retention. Every headline metric is shown as a **WooCommerce KPI** card with a period-over-period delta, and a real **profit report** (revenue minus COGS, ad spend and manual expenses) sits right next to revenue. Because the LTV, RFM, cohort and geographic views are not part of core, BrikPanel effectively ships **advanced reports** for **WooCommerce** and **custom WooCommerce reports** as a free, self-hosted **WooCommerce reporting** layer, with no external SaaS and nothing sent off your server.

= Can I customize the dashboard widgets, sales charts and graphs? =

Yes. The BrikPanel **admin dashboard** is built from modular **dashboard widgets** (sales, orders, AOV, the conversion funnel, live visitors, the geographic globe, customer analytics and more), and the modules you do not need can be turned off from **WooCommerce → Settings → BrikPanel**. The **sales charts** and **sales graphs** are rendered with Chart.js and redraw for any date range you pick, so your **custom dashboard** shows exactly the **sales charts**, KPIs and reports you care about and nothing you do not.

= Does BrikPanel work with multi-currency stores (CURCY, WCML)? =

Yes. When your store takes orders in more than one currency, BrikPanel converts every order to your store's base currency before summing, so Revenue, AOV and the sales chart are never a meaningless mix of currencies. With **CURCY (WooCommerce Multi Currency)** the exact day-of-sale rate is read from the snapshot CURCY stores on each order. With **WCML (WooCommerce Multilingual & Multicurrency)** the current WCML rate is applied and snapshotted onto the order the moment it is placed, which captures the day-of-sale rate for every order going forward. For any other multi-currency setup you can enter flat fallback rates under **WooCommerce → Settings → BrikPanel → Currency**, or supply a rate programmatically through the `brikpanel_order_base_factor` filter (parameters: current factor, `WC_Order`, order currency, base currency — return the multiplier that converts one unit of the order currency into the base currency).

= Where does BrikPanel read Cost of Goods (COGS) from? Can I use my own cost field? =

BrikPanel reads product cost from **WooCommerce's own native Cost of Goods Sold field** (`_cogs_total_value`, WooCommerce 9.5+) — the same field the WooCommerce product screen edits — so any plugin or import pipeline that writes the native cost is picked up automatically, including direct database writes. Costs saved by older BrikPanel versions are migrated into the native field automatically. Variation costs follow WooCommerce's semantics, including the "additive" flag that adds a variation's cost on top of the parent's. If you keep cost somewhere else entirely, hook the `brikpanel_product_cogs` filter (parameters: resolved cost or null, product id, variation id) to point BrikPanel's per-product cost reads at your own source.

= Can I turn off BrikPanel's front-end visitor tracking? =

Yes. If you already run a dedicated analytics tool, disable **Visitor tracking** under **WooCommerce → Settings → BrikPanel → Analytics** and BrikPanel adds zero scripts and zero requests to your storefront. You can also keep tracking on but raise the live-visitor refresh interval to reduce server load, or exclude logged-in customer details from the Live view for a fully anonymous setup. If you only want tracking to wait for cookie consent rather than switching it off, see the next question.

= Does BrikPanel work with a cookie consent banner? (GDPR / consent mode) =

Yes. Tick **Wait for cookie consent** under **WooCommerce → Settings → BrikPanel → Analytics** and BrikPanel's visitor tracking creates no cookie, no browser storage and no request at all until the visitor allows analytics. Consent is accepted from any of three sources, so any consent platform can drive it:

* the **WordPress Consent API** (the `statistics` category), which BrikPanel also listens to for live changes;
* a banner calling **`brikpanel_start_tracking()`** in JavaScript, with **`brikpanel_stop_tracking()`** on withdrawal;
* the **`brikpanel_frontend_tracking_allowed`** PHP filter, for agencies wiring up a CMP without touching plugin files.

Consent takes effect immediately, with no page reload. When it is withdrawn, tracking stops at once and BrikPanel deletes its own cookies and browser storage for that visitor and drops them from the Live view. The anonymous daily totals already recorded are untouched, because they contain no visitor identifier to erase.

Tested against the most-installed consent banners. Working with no setup at all: **Complianz**, **CookieYes**, **GDPR Cookie Compliance (Moove)**, **WPConsent**, **Cookiebot**, **iubenda** and **Beautiful Cookie Consent Banner** — they all speak the WordPress Consent API, so ticking the setting is the only step. **Cookie Notice / Compliance by Hu-manity** needs its Compliance mode connected, because its free unconnected mode never reports a category decision. **CookieAdmin**, **Real Cookie Banner** and **Termly** do not use the WordPress Consent API at all; bridge them with a few lines, using the pattern below (this exact snippet was tested against CookieAdmin, swap the cookie name and button ids for another banner):

`add_filter( 'brikpanel_frontend_tracking_allowed', function ( $allowed ) {`
`    return isset( $_COOKIE['my_banner_cookie'] ) && $_COOKIE['my_banner_cookie'] === 'accepted';`
`} );`

and in your theme's footer, so a click takes effect without a reload:

`document.addEventListener('click', function (e) {`
`    if (e.target.closest('#my-banner-accept') && window.brikpanel_start_tracking) window.brikpanel_start_tracking();`
`    if (e.target.closest('#my-banner-reject') && window.brikpanel_stop_tracking) window.brikpanel_stop_tracking();`
`}, true);`

What visitor tracking stores in the browser, and only after consent when the setting is on: `brikpanel_vid` (a random id, 1 year, so a visit is counted once instead of once per page), `brikpanel_consent` (the value `1`, 30 days, remembering the choice), `brikpanel_add_to_cart_count_cookie` and `brikpanel_checkout_count_cookie` (until midnight, one funnel count per day), and the local storage keys `brikpanel_visitor_viewed_<date>` and `brikpanel_product_viewed_<date>`. All of it is first-party and stays on your own site.

This setting governs analytics. Abandoned-cart email capture is a separate feature with its own switch under **Cart abandonment**, and it stores nothing at all until a customer types their email address themselves; when they do, it reuses the same `brikpanel_vid` id to tie the cart to that address.

= Does the signup popup appear on top of my cookie banner? =

No. **Wait for cookie banner** under **WooCommerce → Settings → BrikPanel → Cart abandonment** is on by default, and the popup then holds back until the visitor has answered the banner. Accepting and declining both release it, because signing up for an offer is not tracking. On a store with no cookie banner nothing changes at all: the popup opens after its normal delay, exactly as before.

It cannot be lost. Whatever happens with the banner, the popup opens no later than 30 seconds after the page loads, and the small floating tab that holds a visitor's own coupon code is never held back.

Banners that speak the WordPress Consent API (**Complianz**, **CookieYes**, **GDPR Cookie Compliance (Moove)**, **WPConsent**, **Cookiebot**, **iubenda**, **Beautiful Cookie Consent Banner**) need no setup. For a banner that does not, such as **CookieAdmin**, **Real Cookie Banner** or **Termly**, tell the popup when your banner was answered:

`document.addEventListener('click', function (e) {`
`    var answered = e.target.closest('#my-banner-accept') || e.target.closest('#my-banner-reject');`
`    if (answered && window.brikpanel_popup_consent_answered) window.brikpanel_popup_consent_answered();`
`}, true);`

= Does BrikPanel support WordPress multisite? =

Yes, both ways: network-activate it to run on every store in the network, or activate it on individual subsites only. Each site gets its own tables and settings either way. When network-activated, super admins additionally get network-wide access rules under **Network Admin → Settings → BrikPanel Access**.

= Does BrikPanel show customer LTV, RFM segments and cohort retention? =

Yes. BrikPanel ships a full **WooCommerce customer analytics** suite directly in the dashboard. Customer Lifetime Value (LTV) is calculated for every customer with average, top, and full distribution histogram. RFM segmentation scores every customer on Recency, Frequency and Monetary and groups them into Champions, Loyal, At Risk, About to Sleep, Hibernating and Lost. Cohort retention shows a month-by-month grid plus an average retention trend line. All three are computed from your own store data, no external service involved.

= Is BrikPanel a free Shopify alternative for WooCommerce? =

Yes, for store owners who want to stay self-hosted. BrikPanel gives your WooCommerce store the clean, modern admin experience of Shopify: product list with inline editing, bulk price and stock updates, live visitors, conversion tracking, geographic analytics, customer LTV / RFM / cohort reports, a branded login page, but your storefront, your customer data, and your orders stay on your own server. No monthly platform fee, no transaction fee, no vendor lock-in. If you were evaluating Shopify but want to own your stack, this is the **Shopify alternative for WooCommerce** we built for that exact use case.

= Is BrikPanel an ATUM alternative for inventory management? =

For most stores, yes. BrikPanel includes complete **woocommerce inventory management**: stock levels, low stock badges, bulk stock updates, variation stock updates, all integrated into the same dashboard you use for sales and orders. If you only need daily stock work without advanced supplier or purchase order features, BrikPanel is a much lighter **ATUM alternative**.

= How do I get a faster WooCommerce product list with bulk actions and quick edit? =

The default **WooCommerce product list** is built for browsing, searching, sorting and editing it is slow. BrikPanel ships a complete **woocommerce product list plugin** with thumbnail, SKU, regular and sale price, stock badge, category, AJAX pagination, live search, status tabs, one-click publish toggle and a slide-in quick edit panel for every row. Works on both simple and variable products, and the same **woocommerce product list** screen powers the bulk price and bulk stock updates so you never leave the page to edit your catalog.

= Can I search products by my own SKU field, like a supplier or manufacturer code? =

Yes. The product list search matches the product title, the description and the WooCommerce SKU out of the box, including a variation SKU, which returns the parent product. If your warehouse also stamps a supplier code, a manufacturer part number or an EAN onto each product in its own custom field, point BrikPanel at it with the `brikpanel_product_search_meta_keys` filter, which receives the list of meta keys (just `_sku` by default) and the search term. Add your own key to the list and staff can find a product by typing it, on simple and variable products alike, because a match on a variation returns the parent. Up to ten keys are scanned. The filter is documented with a copyable example under WooCommerce, Settings, BrikPanel, Developers.

= How do I bulk edit WooCommerce products including variations? =

Open **BrikPanel → Products** and click the **Bulk Update** button in the toolbar. You can update prices, sale prices, and stock for all products, by category, or for selected products. For variable products, open any product, click **Edit Variations**, and bulk update prices and stock across every variation in one modal. This is the part most free **WooCommerce bulk editor** plugins do not handle, BrikPanel does.

= Can I bulk edit variation prices in WooCommerce with the free version? =

Yes. **Bulk edit variation prices WooCommerce** is a core BrikPanel feature, and it is free. Set a percentage rule, set a fixed price, or update by attribute (every "Red" variation, every "Large" size). The same modal handles **bulk update variation stock** for the same products.

= Does BrikPanel slow down my WooCommerce store? =

No. BrikPanel only loads inside wp-admin. It has zero impact on your storefront speed, customer experience, page weight, or Core Web Vitals. The frontend never loads any BrikPanel code.

= Is BrikPanel compatible with HPOS (High-Performance Order Storage)? =

Yes. Every order query has dual code paths, `wc_get_orders()` for HPOS, `WP_Query` for legacy. BrikPanel declares HPOS compatibility via `FeaturesUtil::declare_compatibility('custom_order_tables', ...)` and is tested on stores running both modes.

= How do I see WooCommerce sales by country? =

Open the BrikPanel dashboard. Scroll to the geographic analytics section. The 3D globe shows every order on its real geographic location, and the **Top 10 Countries** and **Top 10 Cities** tables update in real time. BrikPanel extracts country and city from the billing or shipping address of every order, so this works with no extra setup.

= How do I customize the WordPress login page for my WooCommerce store? =

BrikPanel includes a built-in **wordpress login customizer**. Enable the **custom wp login page** module from BrikPanel settings and the default `wp-login.php` is replaced with a clean, branded login form that matches the rest of the BrikPanel admin. No CSS knowledge required.

= How do I search WooCommerce orders by customer name or phone number? =

Press `Ctrl + K` (or `Cmd + K` on Mac) anywhere inside wp-admin. The BrikPanel quick search overlay opens and searches across order ID, customer name, email, phone, and product SKU at the same time. This is the **woocommerce search orders** experience the WooCommerce admin should ship with by default.

= Can I see who is on my WooCommerce store right now? =

Yes. BrikPanel includes a **woocommerce live visitors** widget on the dashboard that updates every 30 seconds. You can see what page each visitor is on, whether they have items in the cart, and whether they are an existing customer. This is real **woocommerce real time visitors** tracking, not estimates.

= Does BrikPanel track WooCommerce conversion rate and conversion funnel? =

Yes. BrikPanel includes a complete **woocommerce conversion tracking** system that records visitors, add-to-cart events, checkout starts, and completed orders. The dashboard shows your **woocommerce conversion funnel** as a four-step visual: Visitors → Add to Cart → Checkout → Orders, with the conversion percentage at every step.

= Is there a free WooCommerce conversion tracking plugin built into BrikPanel? =

Yes. BrikPanel ships a free **WooCommerce conversion tracking plugin** that records every visitor, add-to-cart, checkout start and completed order in your own database, no Google Analytics setup, no Hotjar, no monthly fee. The funnel and conversion-rate widgets on the dashboard are computed from this same dataset in real time.

= Does BrikPanel recover abandoned carts? =

Yes. BrikPanel includes a built-in **WooCommerce cart abandonment** and **cart recovery** system in the free version: no Klaviyo, Mailchimp or external email SaaS. It captures the email of shoppers who begin checkout but do not complete the order (from both the classic shortcode checkout and the newer block checkout, and from logged-in customers the moment they add to cart) and lists every one on a dedicated **Abandoned Carts** screen. Each entry keeps a full snapshot of the cart, including the exact variation, quantity and total, and moves through Active, Abandoned and Recovered automatically, even if the shopper later checks out with a different email. The screen has search, status, source and date filters, per-row product details, CSV and Excel export, and statistics cards. It works on both simple and variable products.

= How does the WooCommerce cart recovery coupon popup work? =

Switch on the optional email popup and BrikPanel shows a clean, on-brand sign-up offer to your visitors. Anyone who subscribes is issued their own single-use percentage **cart recovery coupon** (10% by default, and you set the rate), restricted to their email and valid for 30 days, shown right there with a one-click Copy button. You control the heading, message, button and success text, the delay before it appears, the cooldown after it is dismissed, and which of six animated reveal styles the coupon uses (Sealed envelope, Pocket card, Scratch card, Slot machine, Magnetic assembly or Classic ticket), all of which respect a visitor's reduced-motion preference. Close the popup and it folds into a small floating tab, one click from reopening.

= How do I sync WooCommerce orders to Google Sheets for free? =

Open **WooCommerce → BrikPanel → Google Sheets**, click "Connect Google account", pick or create a target spreadsheet, and toggle "Real-time order sync" on. Every new WooCommerce order is then appended to your Sheet within seconds, with one row per line item so variations land in their own columns. Status changes update the existing row in place. No Zapier, no Make, no monthly fee, a real **woocommerce google sheets sync** built into BrikPanel.

= Does BrikPanel work as a free GSheetConnector or WPSyncSheets alternative? =

Yes. BrikPanel includes a complete **WooCommerce to Google Sheets** integration in the free version: real-time order sync, scheduled bulk export, analytics snapshot tabs (Sales Summary, Daily KPIs, Top Products, Funnel) and a customer + RFM snapshot. All four flows ship free with no row limit, no premium tier, and OAuth-based authentication that requests minimum scopes only (`drive.file`, never full Drive access).

= How do I see real ROAS and net profit in WooCommerce? =

Connect **Google Ads** and/or **Meta Ads** from the BrikPanel Ad Platforms page. BrikPanel then pulls your daily ad spend and shows three new dashboard cards: **Ad Spend** (summed across every connected platform for the active date range), **WooCommerce ROAS** (store revenue ÷ ad spend), and **Net Profit** (revenue − COGS − ad spend − manual expenses). COGS comes from WooCommerce's native order cost meta and expenses from the BrikPanel expenses table, so the **woocommerce roas** and net profit numbers are real, not estimates. The cards are multi-currency aware, if an ad account reports in a different currency than the store, spend is shown split and ROAS / Net Profit are omitted instead of printing a misleading converted number.

= Is BrikPanel a free Triple Whale alternative for WooCommerce? =

For self-hosted stores, yes. BrikPanel gives you the **WooCommerce ROAS** and **net profit** view store owners buy Triple Whale, TrueProfit or BeProfit for: daily **Google Ads** and **Meta Ads** spend pulled in next to store revenue, COGS and expenses, but it runs entirely on your own server with no monthly fee and no data sent to a third party. If you only need true ROAS and profit (not full multi-touch ad attribution), this is the free **Triple Whale alternative** built for that exact use case.

= Does BrikPanel connect to Google Ads and Meta (Facebook / Instagram) Ads? =

Yes. BrikPanel connects to both **Google Ads** and **Meta Ads** through a secure OAuth proxy (the plugin only ever stores encrypted tokens, never your password). It pulls daily spend per platform, backfills history, and re-syncs recent days automatically so the dashboard ROAS and net profit stay accurate. The integration is spend-and-profit focused, it does not install a Facebook pixel or do multi-touch attribution; it gives you true **woocommerce roas** and net profit without a paid SaaS.

= Is there a free WooCommerce variation editor for bulk price and stock updates? =

Yes. BrikPanel includes a complete **WooCommerce variation editor** in the free version. Open any variable product, click "Edit Variations", and you can bulk update every variation's price, sale price, stock and SKU in one modal, with attribute filtering when a product has 50+ combinations. The same **woocommerce variation editor** also supports per-attribute rules ("set every Red variation to $X").

= What makes BrikPanel different from the built-in WooCommerce analytics? =

The built-in WooCommerce analytics are slow, refresh on a delay, only show historical data, and have no live visitor tracking, no conversion funnel, no geographic globe, no customer LTV / RFM / cohort reports, no Cmd+K order search, no quick edit sidebar, no variation bulk editor, no custom login page, and no coupon manager. BrikPanel adds every one of those features inside a single free plugin.

= Is BrikPanel just a CSS reskin of the WooCommerce admin? =

No. BrikPanel is a real **woocommerce admin dashboard plugin** with custom database tables for visitor tracking, custom AJAX endpoints for every interaction, real conversion analytics, a working bulk editor, a real product editor, a real coupon manager, and a real custom login system. Other plugins (Dashify, UiPress) only restyle the admin. BrikPanel rebuilds the parts of WooCommerce that needed to be rebuilt.

= Can I use BrikPanel as a WordPress admin theme or admin skin for my store? =

In practice, yes. BrikPanel is built specifically for WooCommerce, but for store owners it behaves like a focused **WordPress admin theme**: it reskins the WooCommerce parts of wp-admin into a clean, Shopify-style **custom admin panel**, replaces the default toolbar, and restyles the product, order, customer and coupon screens. If you have been looking for a **wp admin theme** or an **admin skin** that makes the WooCommerce admin genuinely pleasant to work in (rather than a generic restyle that breaks on the next WooCommerce update), this is built for exactly that. You can also **hide admin menu** items for non-technical clients with the optional simplified mode, leaving only BrikPanel and WooCommerce in the sidebar.

= Does BrikPanel work with Yoast SEO, RankMath, Elementor, WPML, and Polylang? =

Yes. BrikPanel does not interfere with frontend rendering, so it works with every page builder and SEO plugin we have tested. Yoast SEO, Rank Math, All in One SEO and SEOPress metaboxes (including their SEO score panels) render and save inside the BrikPanel product editor. It also has its own translation files and is fully compatible with WPML and Polylang for multilingual stores.

= Does BrikPanel work with WooCommerce Subscriptions and membership plugins? =

Yes. BrikPanel is compatible with WooCommerce Subscriptions, Subscriptions for WooCommerce (WP Swings), MemberPress, Paid Memberships Pro, WooCommerce Memberships, YITH WooCommerce Subscription, SUMO Subscriptions, WebToffee Subscriptions for WooCommerce and Restrict Content Pro. Subscription products and member orders show up in the same product list, order screens and customer analytics as the rest of your catalog.

= Where does BrikPanel store data? =

Everything stays in your WordPress database. Visitor tracking writes to `wp_brikpanel_visitors` (daily totals), `wp_brikpanel_visited_pages`, `wp_brikpanel_referrers` and `wp_brikpanel_cart_tracking` — all anonymous counters with no visitor identifier in them. Other features add their own tables as you use them (expenses, suppliers, customer metrics, abandoned carts). Live visitor data is stored in a transient that auto-expires every 2 minutes and is never written to the database permanently. Your store, order, customer and visitor data is never sent anywhere. BrikPanel only contacts an external service for optional features you switch on yourself, described in the next question.

= What data does BrikPanel send outside my site? =

By default, nothing. BrikPanel only contacts an external service for features you explicitly opt into:

* **Newsletter (optional).** From a dismissible card on the dashboard, or from the Newsletter row in WooCommerce > Settings > BrikPanel, BrikPanel offers to email you occasionally about new features, WooCommerce tips and ideas for growing your store. Only if you type your email address and tick the consent box is that address sent to our server at brksoft.com, together with your site address, site language and BrikPanel version, so we can add you to the list. Nothing is sent unless you fill in the form and consent, and you can unsubscribe from any email we send. Privacy policy: https://brksoft.com/privacy-policy/ . Terms: https://brksoft.com/terms-and-conditions/
* **Google Sheets sync and Google / Meta Ads (optional).** If you connect these, BrikPanel exchanges data with Google, Meta and our authentication helper at brksoft.com to run the sync and read your ad spend. They only run after you connect the relevant account.

= Will BrikPanel always be free? =

Yes. The dashboard, the bulk editor, the inventory tools, the order management, the coupon manager, the custom login, the conversion tracking, the customer analytics suite, and every other feature listed above will remain free forever. We also sell a separate paid product (BrikMentor) on top of BrikPanel, but it is additive, BrikPanel itself stays 100% free.

== Screenshots ==

1. Dashboard
2. Cart Recovery
3. Ads ROAS
4. Sheets Sync
5. Product List
6. Quick Edit
7. Bulk Edit
8. Product Editor
9. Customer LTV
10. RFM Segments
11. Cohort Retention
12. Geo Analytics
13. Live Visitors
14. Order Search
15. Orders Explorer
16. Customers Explorer
17. Order Management
18. Categories
19. Coupons
20. Add Coupon
21. Login Page
22. Order Page

== Changelog ==
The full release history of every version is in changelog.txt, included with the plugin. The most recent releases are listed below.

= 3.3.15 (2026-09-19) =
* Fix: **The changelog on the WordPress.org plugin page is complete again.** The release history had grown longer than WordPress.org accepts, so the page cut it off part way through and the newest entries were the only ones anybody could read in full. The page now carries the most recent releases, and the complete history of every version ships with the plugin in `changelog.txt`.

= 3.3.14 (2026-09-19) =
* New: **Invoice, shipping label and tracking boxes are back in the right column of the order page.** Since the order page moved to tabs, every box another plugin adds went under "More", so printing an invoice or entering a tracking number cost a click on every single order. BrikPanel now recognises more than forty boxes from the widely used invoice, packing slip, courier and payment plugins and opens them in the narrow right column, where they were before. The test a box has to pass is whether you do something with it on the order, so a box that only reports something stays under "More". Everything it does not recognise stays under "More" exactly as now, and the "More" tab disappears when nothing is left in it. Screen Options has a new "Show in the sidebar" list: tick any box to move it to the right column, untick one to send it back, with a "Reset to defaults" link. The choice is saved per person, so each member of staff can arrange the screen their own way. Developers can add their own box with the new `brikpanel_order_sidebar_boxes` filter.
* Fix: **Google Ads, Meta Ads and Google Sheets connections no longer disconnect themselves.** A connection could vanish seconds after it was made, and the log blamed a "corrupted" stored credential that was in fact perfectly intact. The cause was the site address: WordPress reports it as `https` or `http` depending on how each individual request arrived, and BrikPanel locked its stored credentials to that value — so a connection made in your browser over HTTPS could not be read by the scheduled task that ran moments later, and BrikPanel deleted it. It took both ad platforms at once, because they share one stored record. Credentials are now locked to the site's own security keys instead, which do not change between requests, and **nothing is ever deleted because it could not be read**: existing connections are upgraded automatically the first time they are opened, and a credential that genuinely cannot be read is kept and reported rather than thrown away.
* Fix: **A connection you have just re-made is no longer deleted by a renewal already in progress.** A background renewal that was still using the previous credential could get a rejection back from Meta and act on it, removing the connection you had created seconds earlier. The renewal now checks that the credential it was rejected for is still the stored one.
* Fix: **A background renewal no longer reverts the ad account you just picked.** Choosing a different ad account while a scheduled sync was running could silently put the old choice back.
* Fix: **The Ad Platforms and Google Sheets cards now say when stored credentials cannot be read**, instead of simply showing "Not connected" with no explanation, and name the usual causes: a changed site address, a move to a new server, or new security keys in wp-config.php.
= 3.3.13 (2026-09-18) =
* New: **The signup popup waits for your cookie banner.** On a store with a cookie banner, visitors used to meet two things at once: the banner and, seconds later, the signup popup on top of it. The popup now holds back until the visitor has answered the banner, and accepting or declining both release it, because signing up for an offer is not tracking. Nothing changes on a store without a banner, the floating coupon tab is never held back, and the popup opens after 30 seconds whatever the banner does, so no signup can be lost. Turn it off under WooCommerce → Settings → BrikPanel → Cart abandonment → "Wait for cookie banner".
* Fix: **Orders renumbered by a sequential order number plugin now show that number everywhere, not just in the orders list.** Opening an order showed WooCommerce's internal ID in the header instead of the number the list had just shown. The dashboard's recent orders, the Segments table, the recovered-order link on Abandoned Carts and the status-change bar on the orders list had the same problem.
* Fix: **Cmd/Ctrl + K finds an order by phone number however it was typed.** A number kept as "+44 7911 123456" was not found by typing "07911 123456", or the other way round. Spaces, dashes, brackets and a leading 0 or 00 no longer matter, and the last digits of a number are enough to find it. Names now match inside a word and across spellings, so "yilmaz" finds "Yılmaz", and an exact name comes before a partial one. Trashed and draft orders no longer take up result slots, an order that matches twice is listed once, the closest match is first again, and a search beginning with "-" no longer fails. On a store with 700,000 orders a search by customer name went from 761 ms to 1 ms.
* Dev: **New filters `brikpanel_search_terms`** (add your own spellings of what was typed) **and `brikpanel_search_order_ids`** (add or drop the orders the palette found), and **`window.brikpanel_popup_consent_answered()`**, which tells the signup popup that a banner not speaking the WordPress Consent API has been answered. All three are documented on the Developer page.

= 3.3.12 (2026-09-17) =
* Fix: **The order total is back in front of the columns other plugins add.** With the compact order list, a column you keep in the row through Screen Options ("Show in the row"), such as a profit or tracking number column, was placed ahead of Total, so the amount ended up at the far right of the row. Total now sits right after Shipping, where WooCommerce has always put it, and those columns follow it. Nothing changes for stores that keep no extra column in the row.
* New: **One button cleans bot traffic out of your figures, with undo.** The Store Health check "Bot Traffic" replaces "Add-to-Cart History" and now covers everything a scripted crawl can inflate: daily visitors, product views, page views, traffic sources, device counts, store and per-product add-to-carts, checkout visits, and the abandoned-cart entries the "Abandoned Cart Entries" check rates as certainly scripted. One click lowers each flagged day to the highest figure it could honestly have had and deletes the scripted entries. Everything it changes is kept in a restore point that never expires on its own, so "Undo last correction" puts it all back whenever you want. A restore point left by the old check is replayed by the same undo.
* Tweak: **Store Health (BrikControl) is on by default again.** New installs start with it enabled, the same as stores that installed BrikPanel earlier. Turn it off under WooCommerce → Settings → BrikPanel → Store Health if you do not want it.
* Tweak: **The red "critical store health issue" banner on the dashboard is gone.** Store Health findings now show only on the topbar shield and the Store Health page.
* Fix: **Visitor, product-view and Live figures are no longer inflated by scripted browsers.** The "one visitor, once a day" rule lived only in the browser's own storage, so a crawler that runs the page but starts every visit with a blank profile was counted as a new visitor on every page it opened, and every one of its pings became a new entry in the Live view; one store with under a hundred real visitors a day showed eleven thousand and forty people live at once. The rule is now also kept on the server, the same way the add-to-cart and checkout counters have been since 3.3.1: a browser with no memory of the store gets one visitor count and one product-view count per day and at most one Live entry at a time. Returning browsers and signed-in customers are unaffected, and browsers that declare themselves automated are not counted at all. Figures already recorded are left as they are.

= 3.3.11 (2026-09-17) =
* Fix: **Attributes page on phones no longer overlaps the column names and their values on right-to-left languages** (Persian, Arabic, Hebrew). The same applied to the expand arrow in the tag and category tables.

= 3.3.10 (2026-09-17) =
* Fix: **Other plugins' product fields now work in the automatic mode of "Additional product data".** When that card was switched on with "Show these fields" on a store with no SEO card and no hand-picked box, the scripts of the plugins behind those fields were never loaded, so their controls did nothing. Measurement Price Calculator showed every calculator section at once instead of only the selected one; it now behaves like the native WooCommerce screen, including the per-unit price label ("$ / sq cm") on right-to-left stores.
* Fix: **The "Ship to" box in the order list no longer repeats the customer's name.** Some shipping plugins (for example AgenWebsite Shipping) add the phone number to the name line of the address, such as "Jane Doe (Phone: +62...)". The expanded order panel did not recognise that as the name line and printed it again inside the address. It now shows the name once, then the phone, then the address.
* Fix: **Three PHP 8.4 deprecation notices removed** (Store Health and Google Sheets order sync used an older way of writing an optional parameter).

= 3.3.9 (2026-09-16) =
* New: **A switch to hide every BrikMentor promotion.** WooCommerce → Settings → BrikPanel → General → "Show BrikMentor promotion". Turn it off and the dashboard card, the menu item, the corner button, the launch notice and the padlocked contact buttons on Abandoned Carts all go, and the screen is what it was before. Agencies can pin it for every client store with `define( 'BRIKPANEL_BRIKMENTOR_PROMO', false );` in wp-config.php. The setting travels with Import / Export.

= 3.3.8 (2026-09-16) =
* New: **BrikMentor in the left menu.** A "BrikMentor · New" item sits under Marketing and opens a short page: your own store's numbers, the offer, the eight ready flows (abandoned cart, win-back, post-purchase, back in stock and more) and what you do not need to set up. It only shows while BrikMentor is not installed, and you can hide it from Settings → Navigation.
* New: **Dashboard card with your own numbers.** Under the KPI cards, a card shows how much was left in abandoned carts over the last 30 days. It only appears when there are abandoned carts, and the X hides it for 30 days.
* New: **Customer Analytics card.** Between the title and the tabs, a card shows how many customers are drifting away (At Risk, Can't Lose Them and Hibernating segments). It is hidden when there are none, and shares the 30 day dismiss with the dashboard card.
* New: **"Recover these carts on autopilot" link** under the abandoned value card on the Abandoned Carts screen.
* Tweak: **The BrikMentor star in the corner no longer moves.** It still grows slightly on hover.
* Tweak: **Store Health (BrikControl) starts turned off on new installs.** Stores that already use BrikPanel keep their current setting. Turn it on any time under WooCommerce → Settings → BrikPanel → Store Health.
* Fix: **New menu items now show up in a customized menu.** If you had customized the menu under Settings → Navigation, a new BrikPanel store screen was added to the end of the closed site management group, where you could not see it. It now lands at the end of the store section.
* Fix: **A stray "New Porto Builder" form no longer shows at the bottom of BrikPanel screens** when the Porto theme is active.
* Dev: **New filters `brikpanel_nav_new_item_after`** (place a new menu item after a given store item) **and `brikpanel_nav_item_title`** (change a menu item's title when it is drawn).
* Tweak: **Tested with WordPress 7.1.**

= 3.3.7 (2026-09-16) =
* New: **Keep any column in the order row.** With the compact order list, columns from other plugins (such as Profit, Tracking Number or Invoice) and WooCommerce's Actions only showed under the order. Screen Options now has a "Show in the row" group: tick a column and it stays in the row. The choice is saved per user, and nothing changes until you tick one.
* Fix: **Buttons from other plugins work in the order details panel,** a column hidden in Screen Options no longer shows there, and column names with capital letters are remembered.
* Fix: **Measurement Price Calculator works in the product editor.** Its options no longer stack, the unit price shows in the price box, and the Pricing Table, Area and Volume settings, calculator settings and variation minimum price, area and volume are no longer lost on save. Yoast WooCommerce SEO no longer moves the calculator card into the SEO box.
* Fix: **Multi-currency (CURCY) prices on the product page.** The extra currency fields show once, variation and sale prices in the extra currency are saved, and opening a product no longer counts as an unsaved change, which made published products autosave every minute.

Older releases (3.3.6 and earlier) are listed in changelog.txt, included with the plugin.
