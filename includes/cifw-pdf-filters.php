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
 * Replace product names, descriptions and amounts with the customs data
 * saved for the order. Items unchecked in the modal are removed.
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

	$currency = $order->get_currency();

	foreach ( $data_list as $item_id => $item ) {
		if ( ! isset( $data['items'][ $item_id ] ) ) {
			continue;
		}

		$custom = $data['items'][ $item_id ];

		if ( empty( $custom['include'] ) ) {
			unset( $data_list[ $item_id ] );
			continue;
		}

		if ( isset( $custom['name'] ) && '' !== trim( $custom['name'] ) ) {
			$data_list[ $item_id ]['name'] = $custom['name'];
		}

		// replace variation/item meta with the customs description & materials
		$description = isset( $custom['description'] ) ? trim( $custom['description'] ) : '';
		$data_list[ $item_id ]['meta'] = '' !== $description
			? '<div class="cifw-description">' . nl2br( esc_html( $description ) ) . '</div>'
			: '';
		$data_list[ $item_id ]['sku'] = '';

		if ( isset( $custom['total'] ) && '' !== $custom['total'] ) {
			$total = (float) $custom['total'];
			$qty   = max( 1, abs( (int) $item['quantity'] ) );
			$args  = array( 'currency' => $currency );

			$line_price   = wc_price( $total, $args );
			$single_price = wc_price( $total / $qty, $args );
			$zero_price   = wc_price( 0, $args );

			foreach ( array( 'line_total', 'line_subtotal', 'price', 'ex_price', 'order_price' ) as $key ) {
				$data_list[ $item_id ][ $key ] = $line_price;
			}
			foreach ( array( 'single_line_total', 'single_price', 'ex_single_price' ) as $key ) {
				$data_list[ $item_id ][ $key ] = $single_price;
			}
			foreach ( array( 'line_tax', 'single_line_tax', 'line_subtotal_tax' ) as $key ) {
				$data_list[ $item_id ][ $key ] = $zero_price;
			}
		}
	}

	return $data_list;
}

/**
 * Rebuild the totals block from the customs amounts: subtotal (sum of the
 * included lines), shipping (editable in the modal) and total. Tax and
 * payment rows are dropped — they belong to the fiscal invoice.
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

	$subtotal = 0.0;
	foreach ( $order->get_items() as $item_id => $item ) {
		$custom = isset( $data['items'][ $item_id ] ) ? $data['items'][ $item_id ] : null;

		if ( $custom && empty( $custom['include'] ) ) {
			continue;
		}

		if ( $custom && isset( $custom['total'] ) && '' !== $custom['total'] ) {
			$subtotal += (float) $custom['total'];
		} else {
			$subtotal += (float) $item->get_total();
		}
	}

	$shipping = isset( $data['shipping'] ) ? (float) $data['shipping'] : (float) $order->get_shipping_total();
	$args     = array( 'currency' => $order->get_currency() );

	$new_totals = array(
		'cart_subtotal' => array(
			'label' => isset( $totals['cart_subtotal']['label'] ) ? $totals['cart_subtotal']['label'] : __( 'Subtotal', 'woocommerce' ),
			'value' => wc_price( $subtotal, $args ),
		),
	);

	if ( $shipping > 0 ) {
		$new_totals['shipping'] = array(
			'label' => isset( $totals['shipping']['label'] ) ? $totals['shipping']['label'] : __( 'Shipping', 'woocommerce' ),
			'value' => wc_price( $shipping, $args ),
		);
	}

	$new_totals['order_total'] = array(
		'label' => isset( $totals['order_total']['label'] ) ? $totals['order_total']['label'] : __( 'Total', 'woocommerce' ),
		'value' => wc_price( $subtotal + $shipping, $args ),
	);

	return apply_filters( 'cifw_totals', $new_totals, $totals, $order );
}
