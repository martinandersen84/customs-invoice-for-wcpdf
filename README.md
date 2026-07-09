# Customs Invoice for WCPDF

Generates a parallel, customizable **customs invoice** alongside [WooCommerce PDF Invoices & Packing Slips](https://wordpress.org/plugins/woocommerce-pdf-invoices-packing-slips/) (WP Overnight). Customs authorities increasingly require a description and list of materials for every product; this plugin lets you edit the product titles, descriptions and amounts of a per-order customs document **without ever touching the real invoice**.

- **Slug:** `customs-invoice-for-wcpdf`
- **Version:** 1.0.0
- **Author:** IDEAA Lab
- **Requires:** WordPress 6.0+, PHP 7.4+, WooCommerce, WooCommerce PDF Invoices & Packing Slips 4.0+ (free or Pro)
- **Text domain:** `customs-invoice-for-wcpdf`

## Features

- Adds a **"Factura de aduanas"** meta box to the order edit screen with a modal editor.
- Per line item: include/exclude checkbox, editable title, description/materials textarea, and editable amount — all pre-filled from the real order data.
- Editable document title (default *Customs Invoice*) and shipping amount; the total is recalculated automatically.
- The generated PDF uses your **existing WCPDF invoice template**, the same buyer data, and the **same invoice number and date** as the real invoice (if the invoice doesn't exist yet, it is created exactly as WCPDF would).
- The customs data is stored as its own order meta and the PDF is saved to `wp-content/uploads/customs-invoices/`, separate from the regular invoices. **Nothing in the original invoice or in WCPDF's data is modified.**
- The document is registered as a proper WCPDF document type (`customs-invoice`), so it also appears in WCPDF's own PDF buttons for quick preview.
- Never attached to WooCommerce emails and never visible in the customer's My Account page.
- Downloads are served through an authenticated admin endpoint; direct web access to the storage folder is blocked.

## Installation

1. Clone or upload this repository to `wp-content/plugins/customs-invoice-for-wcpdf`.
2. Activate **Customs Invoice for WCPDF** in the WordPress plugins screen.
3. Open any WooCommerce order and use the **Factura de aduanas** box.

## Updates

Updates are delivered from this GitHub repository via the bundled [plugin-update-checker](https://github.com/YahnisElsts/plugin-update-checker); they appear in the regular WordPress updates screen. To ship a new version: bump the `Version:` header, commit, and push a `v*` tag — the release workflow builds the ZIP automatically.

## Changelog

### 1.0.0
- Initial release: customs invoice document type, order modal editor, PDF generation to a separate folder, GitHub auto-updates.

## License

GPL-2.0-or-later
