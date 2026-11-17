<?php
/**
 * Plugin Name:       Customs Invoice for WCPDF
 * Description:       Generates a parallel, customizable customs invoice alongside WooCommerce PDF Invoices & Packing Slips. Same buyer data, invoice number and date; editable product titles, descriptions/materials and amounts. Stored separately — the original invoice is never modified.
 * Version:           0.2.0
 * Author:            IDEAA Lab
 * Author URI:        https://www.ideaalab.com
 * Text Domain:       customs-invoice-for-wcpdf
 * Domain Path:       /languages
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce, woocommerce-pdf-invoices-packing-slips
 * License:           GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Auto-update from GitHub
require_once __DIR__ . '/vendor/plugin-update-checker/plugin-update-checker.php';
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

PucFactory::buildUpdateChecker(
	'https://github.com/ideaalab/customs-invoice-for-wcpdf/',
	__FILE__,
	'customs-invoice-for-wcpdf'
);

define( 'CIFW_VERSION', '0.2.0' );
define( 'CIFW_PLUGIN_FILE', __FILE__ );
define( 'CIFW_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'CIFW_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'CIFW_DOCUMENT_TYPE', 'customs-invoice' );
define( 'CIFW_META_KEY', '_cifw_customs_data' );

// Translations (English source, Spanish bundled in /languages)
add_action( 'init', function () {
	load_plugin_textdomain( 'customs-invoice-for-wcpdf', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}, 1 );

// HPOS compatibility
add_action( 'before_woocommerce_init', function () {
	if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	}
} );

/**
 * Check that WooCommerce PDF Invoices & Packing Slips (>= 4.0, WPO\IPS namespace) is available.
 */
function cifw_wcpdf_available() {
	return function_exists( 'WPO_WCPDF' ) && class_exists( '\\WPO\\IPS\\Documents\\OrderDocumentMethods' );
}

add_action( 'admin_notices', function () {
	if ( ! function_exists( 'WPO_WCPDF' ) ) {
		printf(
			'<div class="notice notice-error"><p><strong>Customs Invoice for WCPDF:</strong> %s</p></div>',
			esc_html__( 'requires the WooCommerce PDF Invoices & Packing Slips plugin (WP Overnight) to be active.', 'customs-invoice-for-wcpdf' )
		);
	} elseif ( ! class_exists( '\\WPO\\IPS\\Documents\\OrderDocumentMethods' ) ) {
		printf(
			'<div class="notice notice-error"><p><strong>Customs Invoice for WCPDF:</strong> %s</p></div>',
			esc_html__( 'requires WooCommerce PDF Invoices & Packing Slips version 4.0 or higher.', 'customs-invoice-for-wcpdf' )
		);
	}
} );

/**
 * Default title printed on the PDF: a regular, translated "Invoice", so the
 * document reads like any other invoice of the shop.
 */
function cifw_default_document_title( $order = null ) {
	return apply_filters( 'cifw_default_document_title', __( 'Invoice', 'customs-invoice-for-wcpdf' ), $order );
}

/**
 * Up to 0.1.x the default title was "Customs Invoice" and it was stored with
 * the order data; treat it as "no custom title" so those orders get the new
 * default when the PDF is regenerated.
 */
function cifw_is_legacy_document_title( $title ) {
	return 'customs invoice' === strtolower( trim( (string) $title ) );
}

// Amounts printed on the document (proportion of the order or custom values)
require_once CIFW_PLUGIN_DIR . 'includes/cifw-amounts.php';

// Settings inheritance: make the customs invoice read the invoice configuration
require_once CIFW_PLUGIN_DIR . 'includes/cifw-settings.php';

// Register the custom document type with WCPDF
add_filter( 'wpo_wcpdf_document_classes', function ( $documents ) {
	if ( class_exists( '\\WPO\\IPS\\Documents\\OrderDocumentMethods' ) ) {
		require_once CIFW_PLUGIN_DIR . 'includes/class-cifw-document.php';
		$documents['\\CIFW_Customs_Invoice'] = new \CIFW_Customs_Invoice();
	}
	return $documents;
} );

// PDF content filters (template redirect, items, totals)
require_once CIFW_PLUGIN_DIR . 'includes/cifw-pdf-filters.php';

// Admin UI (meta box, modal, AJAX endpoints)
if ( is_admin() ) {
	require_once CIFW_PLUGIN_DIR . 'includes/class-cifw-admin.php';
	CIFW_Admin::instance();
}
