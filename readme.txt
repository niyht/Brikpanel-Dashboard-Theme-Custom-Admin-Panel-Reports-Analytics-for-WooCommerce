=== BrikPanel: WooCommerce Dashboard, Abandoned Cart Recovery, Google Sheets Sync, Inventory Management & Bulk Editor ===
Contributors: brksoft
Donate link: https://donate.stripe.com/14AdR9ghJcxKaAqdzbc3m00
Tags: woocommerce dashboard, woocommerce inventory management, google sheets, woocommerce bulk editor, roas
Requires at least: 6.0
Tested up to: 7.0
Stable tag: 3.2.97
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

* **BrikMentor early access (optional).** After 100 completed orders, or from a dismissible card on the dashboard, BrikPanel may invite you to join the waiting list for BrikMentor, an upcoming separate AI assistant and email marketing platform. Only if you type your email address and tick the consent box is that email (and, if you answer the optional follow-up, which marketing tool you use) sent to our server at brksoft.com so we can email you the beta invite. Nothing is sent unless you fill in the form and consent, and you can unsubscribe at any time. Privacy policy: https://brksoft.com/privacy-policy/ . Terms: https://brksoft.com/terms-and-conditions/
* **Google Sheets sync and Google / Meta Ads (optional).** If you connect these, BrikPanel exchanges data with Google, Meta and our authentication helper at brksoft.com to run the sync and read your ad spend. They only run after you connect the relevant account.

= Will BrikPanel always be free? =

Yes. The dashboard, the bulk editor, the inventory tools, the order management, the coupon manager, the custom login, the conversion tracking, the customer analytics suite, and every other feature listed above will remain free forever. We may release a separate paid product (BrikMentor) on top of BrikPanel in the future, but it will be additive, BrikPanel itself stays 100% free.

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

== Changelog ==
= 3.2.97 (2026-09-05) =
* New: **The WhatsApp button on the abandoned cart list now tells you how many times you have opened that shopper's draft, and when you last did.** Until now the button left no trace at all, so the one channel most stores actually chase carts on was the one you had no record of. A small number sits on the corner of the icon and the button's tooltip spells it out ("opened 3 times, last ..."). It says opened, never sent: the draft is yours to edit or discard, and only you know whether it went. The counting itself is done by BrikMentor; on a store without it the button looks and behaves exactly as it did before, with no badge and no "0".
* Fix: **"Merge orders" no longer shows up for staff whose BrikPanel interface is switched off.** The bulk action skipped the check every other BrikPanel addition to the orders list makes, so an account moved back to the plain WooCommerce screens still saw it in the dropdown. It is now hidden for those accounts, and opening the merge page by its address is refused with the reason.
* Fix: **The merge confirmation can no longer be sent twice.** Going back and re-submitting the same confirmation, or a double click that got through, could run the merge a second time. The confirmation is now spent once and repeats are turned away. A confirmation that fails its checks is left untouched, so you can go back, fix the problem and carry on.
* Fix: **A failure while adding up the merged order no longer leaves a blank page.** The items had already moved and the old orders had already been cancelled by that point, so an error there gave you a white screen with no idea what had happened. You now get the merged order with a note asking you to press Recalculate.
* Fix: **BrikPanel's own merge bookkeeping is out of the field pickers.** Two internal entries appeared in the "Order custom fields" picker and in the Google Sheets order field list, where picking them would only ever produce blank cells. The two fields worth having, the order the goods went into and the numbers they came from, are still there to pick.

= 3.2.96 (2026-09-04) =
* New: **Merge orders.** When the same customer orders twice in a day you had two orders to pick, pack and post separately. Tick two or more orders in the list and a "Merge orders" button appears; it opens a preview screen where you choose which order stays, decide whether the customer is charged shipping once or for every order, and see the merged total before anything is written. On confirming, the products, fees and shipping move onto the order you picked, and the others are cancelled with a note linking to it. Nothing is deleted, so an old order number a customer quotes still opens and points at where their goods went. Stock is never counted twice, tax is carried across exactly as each order recorded it rather than being recalculated at today's rates, and coupon usage counts stay correct. Orders with a refund on them, orders in different currencies, and orders that disagree about whether prices include tax are refused with the reason shown. Switch the whole thing off under WooCommerce, Settings, BrikPanel, Orders List.

= 3.2.95 (2026-09-03) =
* Fix: **"Use the full screen width" now really uses the width, and arranges the page the way the WordPress editor does: a main column with a narrow side column beside it.** It used to widen only the variations table and the two description editors, leaving every other card in a narrow centre strip, and it stopped growing at a fixed 1500 pixels, so on a large monitor the editor still sat marooned in the middle with a wide empty margin on each side. It now scales with the screen, and the short setting cards (price, cost, stock, category, brand, tags, shipping, linked products) move into the side column, so a card holding one price box no longer takes a whole line to itself. The product name, the permalink under it, the images, the variations table, both description editors, the SEO card and panels added by other plugins keep the main column, since their content is what asked for the width in the first place. Each column flows on its own, so a short card never leaves an empty gap beside it, and your own section order is kept within each column: the list is split, never reordered. The side column grows with the screen and then stops, so the variations table still fits without a scrollbar on a wide monitor and the main column is never narrower than it is with the setting switched off. Nothing changes while the setting is off, or on screens narrower than about 1360 pixels, where the side column simply stacks underneath.
* Fix: **The "+ Add new category" and "+ Add new brand" forms fit the side column.** Their name box, parent picker and button sat on a single line that needs about 500 pixels, which pushed the parent picker out of a column half that wide. They now stack, and the fields in the side column fill it rather than keeping the narrower width they are given inside a wide card.

= 3.2.94 (2026-09-02) =
* Fix: **Turning a variable product into a simple one now asks first, and no longer throws the variations away when the save fails.** Switching a product to simple deletes every variation for good — they do not go to the trash and cannot be brought back — and it was the only place in the editor that did that without asking, even though clearing the variation list and deleting a single row both ask. It now confirms first and tells you how many variations are at stake. The deletion also waits until the product has actually been saved: if the save is stopped by a missing product name, or by another plugin raising an error, the product keeps its variations and its type instead of losing them behind a message saying nothing was saved.
* New: **The Permalink (URL slug) field is far easier to find, and lands right under the product name.** It has been in the editor since 3.1.46 but sat in the middle of the section list, so merchants reported there was no way to change a product's web address at all. It is still off out of the box; switching it on now places it directly under the product name, where the WordPress editor puts it. Turn it on under WooCommerce → Settings → BrikPanel → Products → "Visible editor sections".
* Fix: **Your SEO plugin's panel now appears in the editor on stores that use the block editor for products.** Some themes and plugins switch the block editor on for products. SEO plugins notice that and offer their settings as a block-editor sidebar instead of a panel, so BrikPanel's SEO card came up empty with a message blaming a plugin setting that was not the cause — SEOPress was the clearest case, but any plugin that works this way was affected. The BrikPanel editor now identifies itself correctly as a classic editing screen, so the SEO panel is registered and drawn where you would expect it. Nothing about the standard WooCommerce product screen changes.
* Fix: **The SEO "Canonical URL" box is no longer half the size of the field beside it.** It was the only web-address field in the editor and had been left out of the styling rule the other fields share, so it kept the browser's default width and sat noticeably short next to "Focus keyword".

= 3.2.93 (2026-08-30) =
* New: **Categories and tags can now be assigned in bulk from the selection bar itself.** Ticking a few products used to offer nothing but Export, Publish, Set as draft and the delete actions; the taxonomy actions existed, but behind the "Bulk update" button and on a tab that did not follow your selection. "Categories" and "Tags" buttons now sit in that bar and open the panel on "Selected products" with the matching action already chosen.
* New: **A category or tag can be removed from the selected products.** Until now the only way to take one off in bulk was "Set" with nothing ticked, which cleared every category the products had. Removing the last category leaves the product on your default one instead of none at all, unless the default is what you asked to remove.
* New: **New tags can be typed into the bulk panel, separated by commas,** as in WordPress' own bulk edit. Categories stay pick-only, since a new category needs a parent, and the box is only shown to users allowed to create terms.
* New: **An attribute's values can be dragged into the order shoppers should see them in.** A merchant reported a "Pack Size" dropdown listing 100, 12, 24, 50, 6: WooCommerce orders those values from a setting BrikPanel never wrote, so they all tied and fell back to alphabetical order. Values are now reorderable in the editor and that order is saved with the attribute, for variation attributes and plain ones alike. If the attribute is set to sort itself, the editor says so, because the order will not reach the product page.
* New: **The Cart share screen now has a home in the menu.** It was only reachable from "Create" in the top bar, and now also appears under "More", where it can be renamed, moved or hidden from Settings → Navigation like any other item.

= 3.2.92 (2026-08-30) =
* Fix: **The SEOPress panel now fits the product editor instead of being squeezed into a column too narrow for it.** With SEOPress Pro the panel gains five more tabs, and its own layout sizes the tab list as a share of the window rather than of the card it sits in, so every tab label broke onto two or three lines and the Google preview lost its web address off the right edge. The tab list now keeps a comfortable width, and the Google preview moves below the fields when there is not enough room beside them. If you use the Widescreen setting, the SEO card now takes the full width and shows the same roomy layout as the standard WooCommerce product page.
* Fix: **A product's barcode / GTIN is no longer emptied when you save.** SEOPress Pro adds those two fields to WooCommerce's Inventory section and rewrites them on every save even when they are not on the screen, so hiding the "Additional product data" card silently wiped them each time you saved a product. The fields are now left alone when they are not shown, and still save normally when they are.

= 3.2.91 (2026-08-30) =
* New: **SEOPress now draws its own SEO panel inside the product editor, so everything it offers is there, including its AI buttons.** The editor used to replace SEOPress with five plain fields of its own. Your meta title and description were saved correctly, but everything else SEOPress puts in that panel was missing: the "generate with AI" buttons, the Social tab for the Facebook and X preview, per-product redirections and the content analysis. The real panel is now shown, exactly as on the standard WooCommerce product page, and it saves from its own Save button as well as from the editor's.
* Fix: **Saving a variable product no longer copies the parent's SEO settings onto its variations.** Reordering variations, or switching one on or off, could write the product's meta title, description, canonical and redirection settings onto every variation as well.
* Fix: **A duplicate Google preview and a duplicate "Primary category" selector are gone when SEOPress is in charge.** The editor's own preview could also show a leftover template from a different SEO plugin.
* Fix: Opening the product editor with SEOPress active no longer logs a script error in the browser, and SEOPress's editor code is no longer loaded at all on a product where the SEO section is switched off.

= 3.2.90 (2026-08-28) =
* Fix: **Saving a product no longer loses the colour and size chosen for each variation.** On a product whose attributes had been rewritten by an import, a stock sync or an ERP feed, WooCommerce stops showing the variations' choices and every row reads "Any Colour, Any Size" even though the choices are still stored. The editor believed that emptiness and wrote it back on the next save, which deleted the choices for good. It now reads what is actually stored, and a value it was never shown is left exactly as it was instead of being erased.
* Fix: **A variable product can no longer be turned into a simple one, and its variations deleted, without anybody asking for it.** When the same kind of import left the product's attributes unusable, the editor opened with an empty variation list and took that to mean the product was no longer variable — so saving it removed every variation permanently. The editor now loads the variations regardless, and the save refuses to change the product type or remove variations unless the merchant actually made that choice.
* Fix: **An attribute whose options were emptied by an import is repaired instead of removed.** Where the product's list of colours or sizes had been wiped but the variations still held theirs, saving used to drop the attribute from the product as well, leaving a variable product with variations and no options at all. The list is now rebuilt from the variations themselves, which restores the product in one save. Clearing an attribute's options yourself still removes it, as before.

= 3.2.84 (2026-08-27) =
* Fix: **Payment fees are now read from more payment plugins, not just the official Stripe and PayPal ones.** The "Payment fees" expense line only looked at the fields written by official Stripe, older PayPal Standard and WooPayments, so a store taking cards through FunnelKit Stripe or the Payment Plugins PayPal gateway had the setting switched on and still saw nothing: the total came out at zero, and a zero line was hidden, which reads as a broken feature rather than as no data. Both gateways are now read. The currency of a fee is also taken from the field belonging to the gateway that wrote it instead of one shared field, so a fee charged in another currency is converted rather than quietly treated as being in the order's currency. When the setting is on and the total is still zero, the Expenses card now says so instead of hiding the line.
* Fix: **A one-day range on the dashboard chart no longer draws two days.** The chart grouped orders by the UTC calendar day while the range itself was your local day, so on every store outside UTC a single-day selection produced two points, the first labelled with yesterday's date. Days are now folded to your store's timezone, correctly on whole-hour, half-hour and quarter-hour offsets and across daylight saving changes. The totals in the cards above the chart were always right; only the chart was wrong.
* Fix: **"Last 7 Days" now covers 7 days rather than 8.** The 7, 30 and 90 day ranges each reached back one day too far, and because the previous period they were compared against was the correct length, the percentage change shown beside each figure was off as well. Your numbers will move slightly as a result: they are now the numbers the label promises.
* Fix: **The advertising card no longer counts a day's sales as pure profit.** Its own cost-of-goods lookup compared local dates against a date stored in UTC, which on a single-day range narrowed the window to about an hour and found nothing, so product cost was left out of that card's profit entirely.
* Fix: **A Google Sheets order sync can no longer repeat the same orders forever without writing anything.** After exporting a batch, the sync marks each order as sent. That mark was written straight to the database, and if it landed in one table while the part that looks for unsent orders reads another, which is what happens on a store part-way through WooCommerce's move to its own order tables, the database reported a perfect success and the mark never appeared. The same orders were picked up again on the next run, and every run after that, each one writing no rows at all. The mark is now read back from the table the sync actually reads, and any order missing it is recorded again through WooCommerce itself.
* Fix: **A brief outage of BrikPanel's Google session service no longer looks like a lost connection.** When the service that renews your Google session could not be reached, the sync reported that your connection had been disconnected, which sent store owners hunting for a fault on their own hosting. It now says the interruption is temporary and that the sync will carry on by itself.
* Developer: `brikpanel_payment_fee_meta_keys` covers the two new gateway fields, and the new `brikpanel_payment_fee_currency_meta_keys` filter maps each fee field to the field that holds its currency.

= 3.2.83 (2026-08-26) =
* Fix: **The Products screen and the product editor no longer break on hosts without the Multibyte String PHP library.** A store owner reported a Products page that never finished loading, and a critical error under the Category box on Add product, whether or not the store had any products. Both came from the same place: BrikPanel used a text function that this optional PHP library provides, without first checking the library was there. WordPress and WooCommerce both run happily without it, so the store looked fine everywhere else, which is what made it so hard to place. Because every store has at least one product category, the two screens failed on every single load. On the product editor the page stopped right after the "Category" heading, so the error was at least visible; on the Products screen it stopped inside the hidden Quick Edit panel, so nothing was shown at all and the loading circle simply turned forever. Every place BrikPanel handles text this way now falls back to a plain-text equivalent when the library is missing, including a lowercase conversion that keeps Turkish, Central European and accented category names searchable rather than leaving them out.
* Fix: **The products list now tells you when something went wrong instead of spinning forever.** If the server answered with something the list could not read at all, or a column added by another plugin failed while the table was being drawn, the loading circle stayed on screen with no message and no way forward but a page reload. Those cases now clear the circle and show "An error occurred. Please try again." A request that never comes back at all gives up after two minutes and does the same, rather than leaving the screen loading indefinitely.
* Fix: **Category and Brand boxes draw quickly on large catalogues, and say so when they are empty.** Building the category tree re-read the whole list of categories once for every category in it, which is unnoticeable on a small store and can exhaust the server's memory or time limit on one with thousands, producing a critical error on both the product editor and the products list. The tree is now built in a single pass. An empty Category or Brand box also says "No categories found." / "No brands found." instead of showing an empty white area that reads as broken.

= 3.2.82 (2026-08-26) =
* Fix: **BrikPanel no longer takes a store offline on older WooCommerce versions.** On WooCommerce 4.0 through 5.8 the plugin called a scheduling function that those versions do not have, and because the call ran on every page load the result was not a broken feature but a broken site: the storefront, the login page and the whole admin area returned a critical error, and the plugin could not be switched off from the admin or from WP-CLI to undo it. BrikPanel now asks the same question in a way every supported WooCommerce understands, so background jobs are still scheduled exactly once on those versions rather than the plugin simply going quiet. Stores on WooCommerce 5.9 and later were never affected.
* Fix: **A background job that cannot start no longer breaks the page it started from.** While WooCommerce is moving its scheduled-jobs storage to its newer tables, which happens by itself shortly after a fresh install, it can refuse to accept new jobs. That refusal used to travel up and return a critical error on every page of a brand new store. The job is now simply left for the next page load, which is where it gets picked up.
* Fix: **The Scheduled Tasks screen no longer breaks after a job has been cancelled.** On WooCommerce 4.0 the list could not draw a cancelled job and the whole screen failed to load, which happened the first time anyone switched a Google Sheets sync off. Rows that cannot be drawn are now left out instead.
* Developer: `Brikpanel_Cron::has_any_scheduled( $hook )` answers whether any job is queued for a hook regardless of its arguments. `Brikpanel_Cron::REQUIRED_FUNCTIONS` lists every Action Scheduler function the class depends on, and `is_available()` now checks all of them.

