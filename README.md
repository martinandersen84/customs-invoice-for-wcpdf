# Customs Invoice for WCPDF

Generates a parallel, customizable **customs invoice** alongside [WooCommerce PDF Invoices & Packing Slips](https://wordpress.org/plugins/woocommerce-pdf-invoices-packing-slips/) (WP Overnight). Customs authorities increasingly require a description and list of materials for every product; this plugin lets you edit the product titles, descriptions and amounts of a per-order customs document **without ever touching the real invoice**.

- **Slug:** `customs-invoice-for-wcpdf`
- **Version:** 0.1.0
- **Author:** IDEAA Lab
- **Requires:** WordPress 6.0+, PHP 7.4+, WooCommerce, WooCommerce PDF Invoices & Packing Slips 4.0+ (free or Pro)
- **Text domain:** `customs-invoice-for-wcpdf` (English source, Spanish `es_ES` translation bundled)

## Features

- **"Customs invoice"** meta box on the order edit screen with a modal editor.
- Per line item: include/exclude checkbox, editable title, description/materials textarea, and editable amount — all pre-filled from the real order data.
- Editable document title (default *Customs Invoice*) and shipping amount; the total is recalculated live in the modal and rebuilt server-side for the PDF (subtotal + shipping = total; tax, discount and payment rows are dropped).
- The generated PDF uses your **existing WCPDF invoice template**, the same buyer data, and the **same invoice number and date** as the real invoice (if the invoice doesn't exist yet, it is created exactly as WCPDF would).
- The customs data is stored as its own order meta and the PDF is saved to `wp-content/uploads/customs-invoices/`, separate from the regular invoices. **Nothing in the original invoice or in WCPDF's data is modified.**
- Registered as a proper WCPDF document type (`customs-invoice`), so it also appears in WCPDF's own "Create PDF" buttons for quick browser preview.
- Never attached to WooCommerce emails and never visible in the customer's My Account page.
- Downloads are served through an authenticated admin endpoint; direct web access to the storage folder is blocked.

## Installation

1. Download the latest `customs-invoice-for-wcpdf-vX.Y.Z.zip` from the [Releases page](https://github.com/ideaalab/customs-invoice-for-wcpdf/releases) and install it via **Plugins → Add New → Upload Plugin** (or clone this repository into `wp-content/plugins/customs-invoice-for-wcpdf`).
2. Activate **Customs Invoice for WCPDF**.
3. Make sure **WooCommerce PDF Invoices & Packing Slips** (4.0 or higher) is active — the plugin shows an admin notice if it isn't.

No settings page is needed: the document inherits your WCPDF invoice template and display settings automatically.

## Usage

1. Open any WooCommerce order. In the sidebar you'll find the **Customs invoice** box, showing the current status (data saved / date of the last generated PDF with a download link).
2. Click **Customs invoice…** to open the editor. Every order line comes pre-filled with the real product name and amount. For each product you can:
   - Uncheck it to exclude it from the customs document.
   - Edit the title.
   - Write the description and list of materials required by customs.
   - Edit the line amount (quantity is shown as ordered).
3. Adjust the shipping amount and the document title if needed. The total recalculates as you type.
4. Choose an action:
   - **Save** — stores the customs data on the order (you can come back later).
   - **Save and generate PDF** — stores the data, generates the PDF and downloads it. The PDF is also kept in `uploads/customs-invoices/` and re-downloadable from the meta box.
   - **Reset** — deletes the customs data and returns to the original order values.
5. Regenerating overwrites the previous PDF for that order.

The customs PDF carries the invoice number and date of the real invoice. If the order has no invoice yet, one is created at generation time — identical to pressing WCPDF's own Invoice button.

## Languages

The plugin is written in English and ships with a Spanish (`es_ES`) translation in `languages/`. WordPress picks the language automatically from the site (or user profile) locale. To add another language, translate `languages/customs-invoice-for-wcpdf.pot` with Poedit and drop the resulting `.po`/`.mo` files in the same folder.

Note: the default document title printed on the PDF is deliberately *Customs Invoice* (English) regardless of the site language, since the document is addressed to customs officers. It can be changed per order in the modal, or globally with the `cifw_default_document_title` filter.

## Hooks for developers

- `cifw_default_document_title` — default PDF document title.
- `cifw_document_settings` — settings inherited from the WCPDF invoice document.
- `cifw_totals` — totals rows printed on the PDF.
- `cifw_storage_dir` — storage folder for generated PDFs.

## Updates

Updates are delivered from this GitHub repository via the bundled [plugin-update-checker](https://github.com/YahnisElsts/plugin-update-checker); they appear in the regular WordPress updates screen. To ship a new version: bump the `Version:` header, commit, and push a `v*` tag — the release workflow builds the ZIP automatically.

## Changelog

### 0.1.0
- Initial release: customs invoice document type, order modal editor, PDF generation to a separate folder, English/Spanish i18n, GitHub auto-updates.

## License

GPL-2.0-or-later
