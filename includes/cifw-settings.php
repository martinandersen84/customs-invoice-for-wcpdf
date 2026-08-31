<?php
/**
 * Settings inheritance.
 *
 * The customs invoice has no settings of its own: it must look exactly like
 * the real invoice. Merging the invoice settings into our document object is
 * not enough, because WCPDF (and especially the Professional extension) reads
 * per-document settings straight from the option
 * `wpo_wcpdf_documents_settings_{document_type}` — which does not exist for
 * our type, so features such as the shop VAT/COC number were silently skipped.
 *
 * Filtering that option makes every consumer — core, Pro and custom templates
 * alike — see the invoice configuration. Nothing is written to the database:
 * the option stays virtual and therefore always in sync with the real invoice.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings of the customs invoice = settings of the invoice, minus everything
 * that would automate or duplicate the real invoice (emails, My Account, order
 * list columns, numbering, filename...). Numbering and dates are delegated to
 * the invoice document itself, see CIFW_Customs_Invoice.
 *
 * @param array $settings Settings stored for our document type (normally empty).
 * @return array
 */
function cifw_inherited_invoice_settings( $settings = array() ) {
	$invoice  = get_option( 'wpo_wcpdf_documents_settings_invoice', array() );
	$invoice  = is_array( $invoice ) ? $invoice : array();
	$settings = is_array( $settings ) ? $settings : array();

	// anything explicitly stored for our own type wins over the inherited value
	$settings = $settings + $invoice;

	$exclude = apply_filters( 'cifw_excluded_settings', array(
		// automation: this document is only ever generated manually
		'attach_to_email_ids',
		'include_email_link',
		'include_email_link_placement',
		'my_account_buttons',
		'my_account_restrict',
		'disable_for_statuses',
		'disable_for_require',
		'disable_free',
		'mark_printed',
		'unmark_printed',
		'auto_generate_on_refunds',
		// admin columns and search belong to the real invoice
		'invoice_number_column',
		'invoice_date_column',
		'invoice_number_search',
		'invoice_number_partial_search',
		// numbering and dates are taken from the invoice document
		'number_format',
		'next_invoice_number',
		'reset_number_yearly',
		'number_sequence',
		'due_date',
		'due_date_title',
		// our own title and filename
		'title',
		'filename',
	) );

	foreach ( $exclude as $key ) {
		unset( $settings[ $key ] );
	}

	// a customs invoice without a number/date is useless: make sure both show
	if ( empty( $settings['display_number'] ) ) {
		$settings['display_number'] = 'invoice_number';
	}
	if ( empty( $settings['display_date'] ) ) {
		$settings['display_date'] = 'document_date';
	}

	$settings['enabled'] = 1;

	return apply_filters( 'cifw_document_settings', $settings );
}

/**
 * Serve the inherited settings as if they were stored in the database.
 * `default_option_` covers the (normal) case where the option does not exist,
 * `option_` the case where something did store it.
 */
add_filter( 'option_wpo_wcpdf_documents_settings_' . CIFW_DOCUMENT_TYPE, 'cifw_filter_settings_option', 10, 1 );
add_filter( 'default_option_wpo_wcpdf_documents_settings_' . CIFW_DOCUMENT_TYPE, 'cifw_filter_settings_option', 10, 1 );

function cifw_filter_settings_option( $value ) {
	return cifw_inherited_invoice_settings( is_array( $value ) ? $value : array() );
}
