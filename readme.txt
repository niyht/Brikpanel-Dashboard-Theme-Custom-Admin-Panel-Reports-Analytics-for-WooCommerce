=== BrikPanel: WooCommerce Dashboard, Abandoned Cart Recovery, Google Sheets Sync, Inventory Management & Bulk Editor ===
Contributors: brksoft
Donate link: https://donate.stripe.com/14AdR9ghJcxKaAqdzbc3m00
Tags: woocommerce dashboard, woocommerce inventory management, google sheets, woocommerce bulk editor, roas
Requires at least: 6.0
Tested up to: 7.0
Stable tag: 3.2.73
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
= 3.2.73 (2026-08-18) =
* Fix: **Bundles, composites and other add-on product types keep their contents when you save them in the BrikPanel editor.** The protection added in 3.2.60 works by asking your plugins which product sections they add, so BrikPanel can tell when a product type has settings this editor does not show and leave that plugin's data alone. It asked every plugin in one go, and a single plugin that failed to answer took the whole answer down with it, so the protection quietly never switched on. WooCommerce Composite Products fails to answer on any store, which meant the protection was off wherever it was installed and a bundle could still come back empty after a save. Each plugin is now asked on its own, so one bad answer costs only that plugin's sections. A plugin that cannot answer at all is now also kept away from the save, which protects composite products themselves for the first time. Everything else on the page continues to save as before, on every product type.
* Fix: **The list of extra product sections in settings is complete again.** Under WooCommerce, Settings, BrikPanel, the same failure emptied that list, so sections belonging to perfectly healthy plugins were missing and could not be added to the editor. Reported by devksec on the support forum, along with the two items above.
* Fix: **"Open in WooCommerce" now opens the WooCommerce editor.** The note shown on product types BrikPanel cannot fully display offered a link that led straight back to the BrikPanel editor, so on a store where everyone works in the BrikPanel interface there was no way to reach those settings at all. The link now opens the WooCommerce editor for that one product, and saving there leaves you on that screen. The next product you open still opens in BrikPanel.
* Developer: `brikpanel_pe_unrepresented_owner_paths` filters the plugins BrikPanel holds back from `woocommerce_admin_process_product_object` for a product type it cannot represent, alongside the existing `brikpanel_pe_unrendered_type_panels`. Adding `&brikpanel=0` to a product edit URL skips the redirect into the BrikPanel editor for that one request.

= 3.2.72 (2026-08-17) =
* Fix: **Prices no longer come out back to front in Arabic, Hebrew and Persian messages.** A store in Egypt sent a WhatsApp message quoting a total of 15.402,50 EGP and the customer read "EGP 50,15.402". The digits were right when they left the store; they were rearranged as the message was drawn on screen. Written inside a right-to-left sentence, the decimal mark can be read as an ordinary piece of punctuation rather than as part of the number, and the two halves of the amount are then laid out in the opposite order. WooCommerce protects prices from this on your shop pages, and that protection was being lost on the way into a plain-text message. It is now restored around every amount, date, phone number and order number in a WhatsApp draft and in a status-change email. Nothing changes for a store writing in a left-to-right language.
* Fix: **A status-change email subject that quotes the order total now shows the amount, not its markup.** A subject or heading written as "Your order {order_total} is on its way" arrived reading "&#36;1,299.00" instead of "$1,299.00".
* New: **The abandoned-cart WhatsApp draft understands {cart_total}.** The shopper's cart total, formatted in the currency the cart was captured in, so a message can quote the amount waiting to be paid. Your saved message is untouched; the placeholder is simply available now, listed with the others under WooCommerce, Settings, BrikPanel, Cart abandonment.
* Fix: **Variation names no longer show stray markup in the abandoned-cart list.** Where WooCommerce or another plugin puts markup between a variation's name and its attributes, a product recorded as "Hoodie<span> - </span>M" appeared exactly like that in the cart column, in the WhatsApp draft and in an exported CSV. Names are now reduced to plain text as they are read, so carts recorded before this release are corrected as well.
* Fix: **A price is no longer split across two lines in a WhatsApp message.** The no-break space WooCommerce places between the amount and the currency symbol was being turned into an ordinary space, leaving the symbol free to wrap onto the next line on a narrow phone screen.
* Developer: `brikpanel_money_text()`, `brikpanel_money_text_from_html()`, `brikpanel_bidi_isolate_ltr()`, `brikpanel_bidi_isolate_numbers()` and `brikpanel_plain_text_from_html()` are available for anything that renders a price, a date or a phone number as plain text. Defining `BRIKPANEL_NO_BIDI_ISOLATION` in wp-config.php turns the isolation off.

= 3.2.71 (2026-08-17) =
* Fix: **A shopper still typing their email address no longer leaves a second cart behind.** The checkout captures an address as it is typed, and it cannot tell a pause from a finished address: someone who had reached "name@gmail.co" and typed the rest three seconds later ended up with two carts, one of them at an address nobody reads. That row counted as an abandoned cart and would have been sent follow-up mail that bounces. On one live store six shoppers had such a row, and all six had in fact completed their order. A further address from the same browser, with the same wording before the "@" and within fifteen minutes, is now read as the same shopper still correcting the same address, so it updates the row already open instead of opening a new one. Two genuinely different addresses, or the same address from two different browsers, still keep their own rows as before. Popup signups are left alone, because a coupon may already have been issued against that address.
* Fix: **Customers who really did buy are no longer moved back to "Abandoned".** The repair that reopens carts credited to an order that failed asked one question only: did this address ever buy anything? For the half-typed rows above the answer was no, because the order was placed under the corrected address, so the rows of people who had genuinely bought were reopened and put back on the follow-up list. Two further signs are now accepted: the browser recorded on the order itself, and another row for the same shopper closed at the same moment, which also covers orders placed before that record existed.
* Fix: **An old abandoned cart no longer gets today's date, or a second round of follow-up.** A reopened cart was stamped with the time of the repair rather than its own, so about an hour later it was marked abandoned all over again with today's date, and everything listening for an abandoned cart, including follow-up mail, started afresh on carts that had been left behind weeks earlier. On the reporting store 26 of 27 rows were re-announced this way. Reopened carts now keep their own dates, and a cart is announced as abandoned once and once only, whatever happens to it afterwards.
* Fix: **Rows already on your list are tidied once on update.** Where the same shopper has several rows from one bout of typing, they are merged into the one that carries the real address, keeping the row that became an order, and dates that had been pulled forward are put back. Carts from the same address that are simply duplicates, and rows outside the fifteen-minute window, are left as they were. The repair now works its way through in small batches, so a store with tens of thousands of carts can no longer run out of memory on the first admin page after updating.
* New: **A short note about BrikMentor now appears on Customer Analytics and Abandoned Carts.** BrikMentor is the AI assistant and email marketing engine being built for WooCommerce, and the card explains what it will do with the figures on the screen you are looking at, with a button to join the private beta list. Dismissing it anywhere hides it everywhere, including the one on the dashboard, and it disappears for good once BrikMentor is out.
* Fix: **Spanish stores now get the right wording where a count can be zero.** The Spanish translation file carried a French heading and France's rule for choosing between singular and plural, and the two disagree at zero, so a line such as "0 products" could come through in the wrong form. The heading and the rule are corrected.
* Tweak: The new waitlist wording is translated in all nine languages BrikPanel ships with: German, Spanish, French, Italian, Dutch, Polish, Brazilian Portuguese, Russian and Turkish.
* Developer: `brikpanel_cartab_email_captured` fires again, with the same row, when an address is corrected, so a mailing tool always ends up with the address the shopper actually uses. The fifteen-minute window can be changed with the new `brikpanel_cartab_typo_window` filter.

= 3.2.70 (2026-08-16) =
* Fix: **BrikPanel no longer costs a database lookup per setting on every page load.** Settings were read one at a time, in the shop front as well as the admin, and settings belonging to a section you had never opened were never saved at all, which costs just as much to ask for as one that is there. All of them are now fetched together in one go. Measured on a store with every section saved: fifteen lookups on each shop page and forty-nine on each admin page, down to none. Measured again on a store that had never opened those sections, where between eleven and thirty-one lookups per admin page survived the first pass: also down to none. Reported by a store owner.
* Fix: **The command palette no longer rewrites its menu index on every admin page.** To let Ctrl+K find any admin page, BrikPanel keeps a copy of your menu. That copy, around 30 KB on a plugin-heavy store, was written back to the database every time you opened any admin page even when nothing about the menu had changed, and on the reporting store that single write took 1.2 seconds per page. It is now written only when the menu actually changes, so opening admin pages costs nothing at all. Reported by a store owner.
* Fix: **That menu copy is now kept only for people who can use the palette, and is removed with the account.** It was saved for every user who opened any admin screen, including those without permission to open the palette at all, and it was still saved with the palette's navigation search switched off. Because the copy no longer expires on its own, it also had to be cleared when an account is deleted, and that only happened for deletions made through the admin screens: deleting a user with WP-CLI, through the REST API or from another plugin left the copy behind for good.
* Fix: **The palette now notices when a plugin moves one of its own pages.** A plugin that changes the address of a menu entry while keeping the same name used to go undetected, so Ctrl+K kept offering the old address and there was nothing left to correct it. Every index is rebuilt once on update.
* Fix: **The store health scan no longer floods the scheduled-jobs queue.** Turning plugins on or off queued a fresh scan for each plugin rather than one for the operation, so switching off twenty plugins queued twenty scans at once, and a plugin that disables itself on each page load could keep the queue permanently full. High CPU and a sluggish admin followed. One plugin change now queues one scan, it waits for the operation to finish before measuring, and there is a floor on how often it can run. A further plugin change inside that window moves the waiting scan rather than being ignored, so what finally runs sees the plugins you ended up with. Stores already carrying a backlog have the duplicates cleared once on update, leaving the normal daily scan untouched. Reported by a store owner.
* Fix: **Notices are no longer hidden behind the header on the order screen.** The space kept clear under the fixed bar at the top of an order was measured against the wrong part of the page, so the "Order updated" message and any warning sat partly underneath it, and the problem got worse rather than better whenever a notice was actually on screen. The space is now measured against the top of the content column at every window size, with the top bar on or off, and on phones, so it stays right even when another plugin adds a banner of its own above the page or changes the height of the WordPress toolbar. Reported by a store owner.

= 3.2.69 (2026-08-15) =
* Fix: **A payment that fails no longer leaves the cart marked "Recovered".** A cart was counted as won back the moment WooCommerce created the order, which is before the payment gateway has even run. A card that was then declined left the cart reading "Recovered" for good, so the Recovered count and its value included money the store never took, and your follow-up emails stopped going to exactly the shopper who still needed them. A cart is now recovered only once its order reaches a status that means a real sale, which covers bank transfer, cash on delivery and any custom order status your store uses, and the row re-opens by itself if that order is later declined, cancelled or deleted. Reported by a store owner.
* Fix: **Retrying a failed payment no longer splits one cart into two rows.** Because the first attempt had already closed the cart, the shopper's next try opened a fresh row for the same cart, which became another abandoned cart an hour later. One shopper could end up with an active cart and several recovered ones side by side. One cart now stays one row however many times payment is attempted.
* Fix: **Figures already recorded are corrected once on update.** Carts credited to an order that failed, was cancelled or has since been deleted go back to Abandoned or Active, and duplicate rows left behind for the same cart are merged into one. Carts that really were bought are left untouched.
* Fix: **A cart abandoned at the payment page no longer disappears from the list.** With gateways that send the shopper to the bank's own site, the cart is emptied on the way out, and a shopper who gave up there had their saved cart contents overwritten with an empty one, which took the row out of the abandoned list altogether. The saved cart is now kept while payment is in flight, so the shoppers most worth chasing stay visible.
* Fix: **Saving a product can no longer clear its images.** When the image gallery had not finished loading, a save said nothing about images at all and the server read that as "the merchant removed them", so the product's featured image and gallery were quietly emptied. Because the editor also saves by itself every minute, this could happen without anyone pressing Save. The editor now stays silent about images until it knows what they are, and removing every image by hand still works exactly as before, on simple and variable products alike.
* Developer: A new `brikpanel_cart_recovery_reverted` action fires when a recovery is undone, so an email tool that cancelled its follow-up series can queue it again. Documented, with the rest, under WooCommerce → Settings → BrikPanel → Developers.

= 3.2.68 (2026-08-15) =
* Fix: **"Active carts" now counts carts, not empty email signups.** An email captured with nothing in the cart was counted as an active cart, which is why the card could read "Active carts 6" next to "Value in cart $0.00". Those rows also never aged out, because only carts holding items are moved to abandoned, so the number could only grow. Emails captured without a cart still count under "Emails collected" and are listed as "Email only". Reported by a store owner.
* Fix: **"Recovered" now means a cart that really was abandoned and then bought.** Any order matching the shopper marked their cart as recovered, including one placed moments after the email was captured, without the cart ever having been left behind. Those are ordinary sales, and counting them made the card and its "Recovered value" read far higher than the carts you actually won back. They are now listed separately as "Converted", and Recovered counts only carts that were abandoned first.
* Fix: **One order no longer counts as several recoveries.** A shopper captured more than once (a popup signup and a checkout entry, say) had every one of those rows credited with the same single order, so both the Recovered count and the recovered value were inflated by carts that never separately sold. One order now credits one cart. Figures already recorded are corrected once on update, so existing stores will see Recovered fall to its true value.
* New: **You can set how long a cart stays eligible to be counted as recovered.** WooCommerce → Settings → BrikPanel → Cart abandonment has a new "Count as recovered within" setting, seven days by default. A customer returning long after they left a cart behind is a new sale rather than a recovery, and previously a cart from a month earlier was still credited to that order.
* Fix: **Changing an order's status in the admin no longer credits the wrong cart.** Recovery matched the browser making the request as well as the billing email. On the order screen that browser is yours, not the shopper's, so a store admin with their own captured cart could have it credited to whichever order they were editing. Browser matching now applies only where it belongs, at the checkout itself.

= 3.2.67 (2026-08-15) =
* New: **You can now decide, per attribute, whether it shows on the product page.** Every attribute row in the product editor has a "Show on product page" switch, matching the checkbox WooCommerce offers on its own product screen. Switch it off and the attribute disappears from the Additional information table on the storefront while everything else about it stays the same. On a variable product you can hide an attribute from that table and still keep it as an option buyers choose, exactly as WooCommerce allows. Reported by a store owner.
* Fix: **Attributes you had hidden no longer come back on the next save.** The editor did not read WooCommerce's "Visible on the product page" setting and switched it back on every time a product was saved, so an attribute hidden from the WooCommerce product screen reappeared on the storefront as soon as the product was saved in BrikPanel. Your setting is now read, shown and kept, on both simple and variable products.

= 3.2.66 (2026-08-15) =
* New: **Abandoned Carts lets you choose which columns you see, and in what order.** A "Columns" button above the table switches any column off, and you can drag the entries to put the columns in the order you prefer, or move them with Alt and the arrow keys. Your choice is remembered for you alone, so it does not change what anyone else on the store sees. Two columns you could not see before are now available: "Cart total" on its own, and "Created", the date the cart was first started.
* New: **You can sort abandoned carts by cart value or by age.** A "Sort by" picker above the table offers highest and lowest cart value, and oldest and newest cart, alongside the usual last activity. Sorting by highest value makes the big carts worth chasing easy to find, and oldest first surfaces the ones that have been sitting the longest. Exports follow whatever sort you have chosen, so the file matches the screen. Requested by a store owner.
* New: **Pick a date range in one click instead of typing two dates.** The two empty date boxes above the abandoned carts list are gone, replaced by a single picker with Today, Last 7 days, Last 30 days and Last 90 days. Two specific dates are still available under "Custom range", which reveals the date boxes only when you ask for them. The list opens on "All time" as before, so nothing changes until you choose a range.
* Fix: **Date filtering now follows your store's timezone.** Filtering the abandoned carts list by date compared your dates against UTC rather than your store's own clock, so on a store several hours ahead of UTC a day's filter was shifted by that many hours: carts from the early hours were missing and some from the previous evening appeared instead. Dates are now read in your store's timezone.
* Tweak: **The filters above the abandoned carts list are now a single tidy row that applies itself.** Search, status, source, date range and sorting sit on one line with their labels inside them, searching happens as you type, and every other control applies the moment you change it, so the "Apply" button is gone. A "Clear" link appears whenever a filter is active.

