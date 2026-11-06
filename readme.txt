=== WCU File Upload ===
Contributors: lumiboard
Tags: woocommerce, file upload, product customization, artwork
Requires at least: 6.2
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.2.1
License: GPLv2 or later

Secure customer artwork uploads for WooCommerce custom products.

== Description ==

WCU File Upload adds an AJAX drag-and-drop uploader to eligible WooCommerce product pages. Uploaded files follow the cart item into the order and are shown in customer order details, WooCommerce emails and the admin order screen.

Highlights:

* Multiple files with configurable count and size limits.
* PDF, AI, EPS, PNG, JPG/JPEG and WEBP allowlist.
* Extension and file-signature validation.
* Random server-side filenames; original names are metadata only.
* Session- and product-bound opaque upload tokens.
* Category rules plus per-product inherit/enable/disable override.
* HPOS-compatible order item metadata.
* Signed, expiring guest/email links; logged-in customers must own the order.
* Admin download links require WooCommerce management capability and a nonce.
* Daily cleanup of abandoned temporary uploads.
* Elementor single-product templates can place `[wcu_upload]` where needed.

== Installation ==

1. Upload `wcu-file-upload.zip` under Plugins > Add New > Upload Plugin.
2. Activate WCU File Upload.
3. Open WooCommerce > File Upload and review the settings.
4. If an Elementor single-product template does not use the normal WooCommerce add-to-cart hooks, insert the `[wcu_upload]` shortcode inside the product form area.
5. Test uploads, cart, checkout, email and order downloads on a staging site before production use.

== Security and server notes ==

Files are stored under `wp-content/uploads/wcu-files/` with random names. The plugin writes Apache `.htaccess`, IIS `web.config`, and an empty `index.php`, but server behavior varies.

For Nginx, add a rule similar to:

`location ^~ /wp-content/uploads/wcu-files/ { deny all; }`

Then verify that a direct file URL returns 403 or 404. Random filenames are not a substitute for server access control.

PDF, AI and EPS files are treated as downloads only. The plugin does not render, execute, rasterize or virus-scan them. If your workflow opens untrusted customer documents in automated software, add malware scanning and sandboxing.

Email attachments are disabled by default and, when enabled, are attached only to the admin New Order email. Large attachments can cause email delivery failures; download links are recommended.

== Compatibility ==

* WordPress 6.2+
* PHP 7.4+
* WooCommerce 7.8+
* WooCommerce HPOS declared compatible
* Classic WooCommerce product add-to-cart forms, cart and checkout
* Elementor single-product templates through standard WooCommerce hooks or `[wcu_upload]`

WooCommerce Product/Add-to-Cart Blocks that bypass the classic product form, product archive cards, quick-view modals and arbitrary non-product forms are not supported.

== Data lifecycle ==

Each upload is stored as a non-public internal `wcu_upload` record. Unclaimed temporary files and cart-reserved files use separate configurable retention periods and are removed by the daily cleanup when expired. Files attached to an order are not automatically deleted by this version; define an operational retention/deletion procedure for privacy compliance. Removing the plugin does not automatically delete business files or settings.

== Changelog ==

= 1.2.1 =
* Fixed: the uploaded-file row collapsed to a one-character-wide column on themes with aggressive list/flex rules, so filenames disappeared and file sizes wrapped one character per line.
* File list now uses a fixed block layout with explicit width, nowrap and word-break overrides; theme-level li::before/::after markers are suppressed.
* Version bumped only. No PHP behaviour, settings, storage or data format changed.

= 1.2.0 =
* Reworked upload ownership around server-side records and opaque session-bound tokens.
* Added expiring order-bound guest/email download signatures.
* Added logged-in order ownership and administrator capability checks.
* Added per-session upload rate limiting.
* Added tri-state per-product override.
* Added Apache/IIS protection files and Nginx guidance.
* Limited optional attachments to the admin New Order email.
* Added Elementor shortcode duplicate-render protection.

== Important ==

Back up the site before installation. Validate the complete purchase flow on staging. This plugin reduces common upload risks but is not an antivirus or document sanitization service.
