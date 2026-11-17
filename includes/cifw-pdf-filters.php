<?php
/**
 * Filters that customize the PDF content when the document being rendered is
 * the customs invoice. They all no-op for every other document type, so the
 * real invoice and packing slip are never affected.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Never expose the customs invoice to customers in My Account.
 */
add_filter( 'wpo_ips_document_is_allowed_in_my_account', 'cifw_block_my_account', 10, 4 );
function cifw_block_my_account( $allowed, $default, $output_format, $document ) {
	if ( is_object( $document ) && is_callable( array( $document, 'get_type' ) ) && CIFW_DOCUMENT_TYPE === $document->get_type() ) {
		return false;
	}
	return $allowed;
}

/**
 * Reuse the shop's configured invoice template: when WCPDF looks for
 * customs-invoice.php (which doesn't exist), hand it invoice.php from the
 * active template, falling back to the bundled Simple template.
 */
add_filter( 'wpo_wcpdf_template_file', 'cifw_redirect_template_file', 10, 3 );
function cifw_redirect_template_file( $file_path, $document_type, $order ) {
	if ( CIFW_DOCUMENT_TYPE !== $document_type || basename( $file_path ) !== CIFW_DOCUMENT_TYPE . '.php' ) {
		return $file_path;
	}

	$template_invoice = WPO_WCPDF()->settings->get_template_path() . '/invoice.php';
	$fallback_invoice = WPO_WCPDF()->plugin_path() . '/templates/Simple/invoice.php';

	if ( file_exists( $template_invoice ) ) {
		return $template_invoice;
	} elseif ( file_exists( $fallback_invoice ) ) {
		return $fallback_invoice;
	}

	return $file_path;
}

/**
 * The PDF is titled like the real invoice ("Invoice"), so WCPDF's own admin
 * buttons would show two identical "PDF Invoice" entries. Relabel ours in
 * the admin only; the PDF itself is not affected.
 */
add_filter( 'wpo_wcpdf_listing_actions', 'cifw_relabel_admin_actions', 20, 1 );
add_filter( 'wpo_wcpdf_meta_box_actions', 'cifw_relabel_admin_actions', 20, 1 );
function cifw_relabel_admin_actions( $actions ) {
	if ( ! is_array( $actions ) || ! isset( $actions[ CIFW_DOCUMENT_TYPE ] ) || ! is_array( $actions[ CIFW_DOCUMENT_TYPE ] ) ) {
		return $actions;
	}

	$label = 'PDF ' . __( 'Customs invoice', 'customs-invoice-for-wcpdf' );
	foreach ( array( 'alt', 'title' ) as $key ) {
		if ( isset( $actions[ CIFW_DOCUMENT_TYPE ][ $key ] ) ) {
			$actions[ CIFW_DOCUMENT_TYPE ][ $key ] = $label;
		}
	}

	return $actions;
}

add_filter( 'wpo_wcpdf_bulk_actions', 'cifw_relabel_bulk_action', 20, 1 );
function cifw_relabel_bulk_action( $actions ) {
	if ( is_array( $actions ) && isset( $actions[ CIFW_DOCUMENT_TYPE ] ) && is_string( $actions[ CIFW_DOCUMENT_TYPE ] ) ) {
		$actions[ CIFW_DOCUMENT_TYPE ] = 'PDF ' . __( 'Customs invoice', 'customs-invoice-for-wcpdf' );
	}
	return $actions;
}

/**
 * Replace product names, descriptions and amounts with the customs data
 * saved for the order. Items unchecked in the modal are removed. Amounts come
 * from cifw_get_invoice_amounts(): the order scaled to the chosen proportion,
 * or the values typed by hand.
 */
add_filter( 'wpo_wcpdf_order_items_data', 'cifw_filter_order_items', 20, 3 );
function cifw_filter_order_items( $data_list, $order, $document_type ) {
	if ( CIFW_DOCUMENT_TYPE !== $document_type || empty( $order ) ) {
		return $data_list;
	}

	$data = $order->get_meta( CIFW_META_KEY );
	if ( empty( $data['items'] ) || ! is_array( $data['items'] ) ) {
		return $data_list;
	}

	$amounts = cifw_get_invoice_amounts( $order, $data );
	$args    = array( 'currency' => $order->get_currency() );

	foreach ( $data_list as $item_id => $item ) {
		if ( ! isset( $amounts['items'][ $item_id ] ) ) {
			// unchecked in the modal
			if ( isset( $data['items'][ $item_id ] ) ) {
				unset( $data_list[ $item_id ] );
			}
			continue;
		}

		if ( isset( $data['items'][ $item_id ] ) ) {
			$custom = $data['items'][ $item_id ];

			if ( isset( $custom['name'] ) && '' !== trim( $custom['name'] ) ) {
				$data_list[ $item_id ]['name'] = $custom['name'];
			}

			// replace variation/item meta with the customs description & materials
			$description = isset( $custom['description'] ) ? trim( $custom['description'] ) : '';
			$data_list[ $item_id ]['meta'] = '' !== $description
				? '<div class="cifw-description">' . nl2br( esc_html( $description ) ) . '</div>'
				: '';
			$data_list[ $item_id ]['sku'] = '';
		}

		$total = cifw_from_cents( $amounts['items'][ $item_id ]['amount'] );
		$tax   = cifw_from_cents( $amounts['items'][ $item_id ]['tax'] );
		$qty   = max( 1, abs( (int) $item['quantity'] ) );

		$line_price      = wc_price( $total, $args );
		$single_price    = wc_price( $total / $qty, $args );
		$line_tax        = wc_price( $tax, $args );
		$single_line_tax = wc_price( $tax / $qty, $args );

		foreach ( array( 'line_total', 'line_subtotal', 'price', 'ex_price', 'order_price' ) as $key ) {
			$data_list[ $item_id ][ $key ] = $line_price;
		}
		foreach ( array( 'single_line_total', 'single_price', 'ex_single_price' ) as $key ) {
			$data_list[ $item_id ][ $key ] = $single_price;
		}
		foreach ( array( 'line_tax', 'line_subtotal_tax' ) as $key ) {
			$data_list[ $item_id ][ $key ] = $line_tax;
		}
		$data_list[ $item_id ]['single_line_tax'] = $single_line_tax;
	}

	return $data_list;
}

/**
 * Rebuild the totals block from the document amounts: subtotal (sum of the
 * included lines), shipping, taxes (proportion mode only, one row per rate
 * as on the real invoice, or a single row if WooCommerce is set that way)
 * and total. Discount and payment rows are dropped: line amounts are already
 * net of discounts.
 */
add_filter( 'wpo_wcpdf_woocommerce_totals', 'cifw_filter_totals', 20, 3 );
function cifw_filter_totals( $totals, $order, $document_type ) {
	if ( CIFW_DOCUMENT_TYPE !== $document_type || empty( $order ) ) {
		return $totals;
	}

	$data = $order->get_meta( CIFW_META_KEY );
	if ( empty( $data['items'] ) || ! is_array( $data['items'] ) ) {
		return $totals;
	}

	$amounts = cifw_get_invoice_amounts( $order, $data );
	$args    = array( 'currency' => $order->get_currency() );

	$new_totals = array(
		'cart_subtotal' => array(
			'label' => isset( $totals['cart_subtotal']['label'] ) ? $totals['cart_subtotal']['label'] : __( 'Subtotal', 'woocommerce' ),
			'value' => wc_price( cifw_from_cents( $amounts['subtotal'] ), $args ),
		),
	);

	if ( $amounts['shipping'] > 0 ) {
		$new_totals['shipping'] = array(
			'label' => isset( $totals['shipping']['label'] ) ? $totals['shipping']['label'] : __( 'Shipping', 'woocommerce' ),
			'value' => wc_price( cifw_from_cents( $amounts['shipping'] ), $args ),
		);
	}

	if ( $amounts['single_tax'] ) {
		$tax_total = 0;
		foreach ( $amounts['taxes'] as $tax ) {
			$tax_total += $tax['amount'];
		}
		if ( 0 !== $tax_total ) {
			$new_totals['tax'] = array(
				'label' => $amounts['tax_label'] . ':',
				'value' => wc_price( cifw_from_cents( $tax_total ), $args ),
			);
		}
	} else {
		foreach ( $amounts['taxes'] as $tax ) {
			if ( 0 !== $tax['amount'] ) {
				$new_totals[ 'tax_' . $tax['code'] ] = array(
					'label' => $tax['label'] . ':',
					'value' => wc_price( cifw_from_cents( $tax['amount'] ), $args ),
				);
			}
		}
	}

	$new_totals['order_total'] = array(
		'label' => isset( $totals['order_total']['label'] ) ? $totals['order_total']['label'] : __( 'Total', 'woocommerce' ),
		'value' => wc_price( cifw_from_cents( $amounts['total'] ), $args ),
	);

	return apply_filters( 'cifw_totals', $new_totals, $totals, $order );
}
