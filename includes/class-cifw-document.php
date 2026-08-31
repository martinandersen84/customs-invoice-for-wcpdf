<?php
/**
 * Customs Invoice document type for WooCommerce PDF Invoices & Packing Slips.
 *
 * Registered via the `wpo_wcpdf_document_classes` filter. It reuses the shop's
 * configured invoice template, inherits the invoice display settings, and
 * delegates number/date to the real invoice document, so the customs invoice
 * always carries the same invoice number and date. The real invoice document
 * and its meta are never written to.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WPO\IPS\Documents\OrderDocumentMethods;

class CIFW_Customs_Invoice extends OrderDocumentMethods {

	public function __construct( $order = 0 ) {
		$this->type  = CIFW_DOCUMENT_TYPE;
		// deliberately not translated: the PDF is addressed to customs officers,
		// so the default document title stays in English regardless of the site
		// locale; it is editable per order and filterable
		$this->title = apply_filters( 'cifw_default_document_title', 'Customs Invoice', $order );

		if ( function_exists( 'WPO_WCPDF' ) ) {
			$this->icon = WPO_WCPDF()->plugin_url() . '/assets/images/invoice.svg';
		}

		parent::__construct( $order );
	}

	/**
	 * Settings come from the virtual option filtered in cifw-settings.php
	 * (the invoice settings, minus automation), merged by the parent with the
	 * common settings. Nothing document-specific is stored for this type.
	 */

	public function use_historical_settings() {
		return false;
	}

	public function storing_settings_enabled() {
		return false;
	}

	public function is_enabled( $output_format = 'pdf' ) {
		return apply_filters( 'wpo_wcpdf_document_is_enabled', true, $this->type, $output_format );
	}

	public function is_allowed() {
		return apply_filters( 'wpo_wcpdf_document_is_allowed', true, $this );
	}

	/**
	 * Never attach this document to WooCommerce emails.
	 */
	public function get_attach_to_email_ids( $output_format = 'pdf' ) {
		return array();
	}

	/**
	 * The document "exists" once customs data has been saved for the order.
	 */
	public function exists() {
		if ( empty( $this->order ) ) {
			return false;
		}
		$data = $this->order->get_meta( CIFW_META_KEY );
		return ! empty( $data ) && is_array( $data );
	}

	/**
	 * Document title shown on the PDF; editable per order from the modal.
	 */
	public function get_title() {
		$title = $this->title;

		if ( ! empty( $this->order ) ) {
			$data = $this->order->get_meta( CIFW_META_KEY );
			if ( is_array( $data ) && ! empty( $data['document_title'] ) ) {
				$title = $data['document_title'];
			}
		}

		return apply_filters( 'wpo_wcpdf_document_title', $title, $this );
	}

	/**
	 * Same number as the real invoice: requests for this document's own number
	 * are redirected to the linked invoice document (read-only).
	 */
	public function get_number( $document_type = '', $order = null, $context = 'view', $formatted = false ) {
		if ( empty( $document_type ) || $document_type === $this->type ) {
			$document_type = 'invoice';
		}
		return parent::get_number( $document_type, $order, $context, $formatted );
	}

	/**
	 * Same date as the real invoice (see get_number()).
	 */
	public function get_date( $document_type = '', $order = null, $context = 'view', $formatted = false ) {
		if ( empty( $document_type ) || $document_type === $this->type ) {
			$document_type = 'invoice';
		}
		return parent::get_date( $document_type, $order, $context, $formatted );
	}

	/**
	 * The number shown is the invoice number, so label it as such.
	 */
	public function get_number_title() {
		$title = __( 'Invoice Number:', 'woocommerce-pdf-invoices-packing-slips' );
		return apply_filters( 'wpo_wcpdf_document_number_title', $title, $this );
	}

	public function get_date_title() {
		$title = __( 'Invoice Date:', 'woocommerce-pdf-invoices-packing-slips' );
		return apply_filters( 'wpo_wcpdf_document_date_title', $title, $this );
	}

	public function get_filename( $context = 'download', $args = array() ) {
		$invoice_number = $this->get_number( 'invoice', null, 'view', true );
		$order_number   = ! empty( $this->order ) && is_callable( array( $this->order, 'get_order_number' ) )
			? $this->order->get_order_number()
			: $this->order_id;

		$suffix   = ! empty( $invoice_number ) ? $invoice_number : $order_number;
		$filename = 'customs-invoice-' . $suffix . '.pdf';

		$order_ids = isset( $args['order_ids'] ) ? $args['order_ids'] : array( $this->order_id );
		$filename  = apply_filters( 'wpo_wcpdf_filename', $filename, $this->get_type(), $order_ids, $context, $args );

		return sanitize_file_name( $filename );
	}

	/**
	 * No own number/date sequence to initiate: everything comes from the
	 * invoice document, and the customs data lives in our own order meta.
	 */
	public function init() {
		do_action( 'wpo_wcpdf_init_document', $this );
	}
}
