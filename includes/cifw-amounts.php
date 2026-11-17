<?php
/**
 * Invoice amounts: the figures printed on the PDF (and previewed in the
 * modal) for every line, shipping, taxes and the total.
 *
 * Two modes, chosen per order in the modal ("proportion" in the order meta):
 *
 * - 10, 20 … 100: every amount of the real order is scaled to that
 *   percentage — lines, shipping and taxes — so nothing has to be typed.
 * - 'custom': the amounts typed by hand in the modal, exactly as in 0.1.x
 *   (subtotal + shipping = total, no tax rows).
 *
 * All the arithmetic works on integer cents and assets/js/admin.js mirrors
 * it step by step, so the modal preview and the PDF always show the same
 * figures. Rounding keeps the printed figures adding up exactly:
 * - the lines are scaled with the largest remainder method, so their sum is
 *   the subtotal of the order scaled and rounded once;
 * - shipping and each tax rate are scaled and rounded once (half up);
 * - the total is the sum of the printed rows.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Percentages offered in the modal, in display order. "Custom values" is
 * appended after them.
 *
 * @return int[]
 */
function cifw_proportion_options() {
	return range( 10, 100, 10 );
}

/**
 * Normalizes a proportion coming from the request or the order meta.
 *
 * @param mixed $value
 * @return int|string A percentage from cifw_proportion_options() or 'custom'.
 */
function cifw_sanitize_proportion( $value ) {
	if ( 'custom' === $value ) {
		return 'custom';
	}
	$value = (int) $value;
	return in_array( $value, cifw_proportion_options(), true ) ? $value : 100;
}

/**
 * Proportion that applies to an order, from its saved customs data.
 *
 * - Saved choice: remembered per order.
 * - Data saved by 0.1.x (no choice stored): the amounts were typed by hand,
 *   so keep them ('custom').
 * - Nothing saved yet: 100 % (the invoice as is), filterable.
 *
 * @param array|mixed $data Customs data stored in CIFW_META_KEY.
 * @return int|string
 */
function cifw_get_proportion( $data ) {
	$data = is_array( $data ) ? $data : array();

	if ( isset( $data['proportion'] ) ) {
		return cifw_sanitize_proportion( $data['proportion'] );
	}
	if ( ! empty( $data['items'] ) ) {
		return 'custom';
	}

	return cifw_sanitize_proportion( apply_filters( 'cifw_default_proportion', 100 ) );
}

function cifw_to_cents( $amount ) {
	return (int) round( (float) $amount * 100 );
}

function cifw_from_cents( $cents ) {
	return $cents / 100;
}

/**
 * Scales an amount in cents to a percentage, rounding half up.
 */
function cifw_scale_cents( $cents, $percent ) {
	return (int) floor( ( $cents * $percent + 50 ) / 100 );
}

/**
 * Scales a list of line amounts so that their sum equals the scaled (and
 * once-rounded) sum of the originals: every line gets its floor, and the
 * missing cents go to the lines with the largest remainders (ties: first
 * line first).
 *
 * @param int[] $amounts Cents, keyed by item id, in display order.
 * @param int   $percent
 * @return int[] Cents, same keys.
 */
function cifw_scale_lines( $amounts, $percent ) {
	$scaled     = array();
	$remainders = array();
	$sum        = 0;
	$sum_floor  = 0;
	$position   = 0;

	foreach ( $amounts as $id => $cents ) {
		$exact          = $cents * $percent;
		$floor          = (int) floor( $exact / 100 );
		$scaled[ $id ]  = $floor;
		$remainders[]   = array( $exact - 100 * $floor, $position++, $id );
		$sum           += $cents;
		$sum_floor     += $floor;
	}

	usort( $remainders, function ( $a, $b ) {
		return $a[0] === $b[0] ? $a[1] - $b[1] : $b[0] - $a[0];
	} );

	$missing = cifw_scale_cents( $sum, $percent ) - $sum_floor;
	for ( $i = 0; $i < $missing && isset( $remainders[ $i ] ); $i++ ) {
		$scaled[ $remainders[ $i ][2] ] += 1;
	}

	return $scaled;
}

/**
 * Amounts of the real order in cents, as the input of the proportion mode.
 * The same structure is passed to the modal script (data-base attribute).
 *
 * Fees are left out, as they always were on this document.
 *
 * @param WC_Order $order
 * @return array
 */
function cifw_get_proportion_base( $order ) {
	$items = array();
	foreach ( $order->get_items() as $item_id => $item ) {
		$taxes = $item->get_taxes();
		$cents = array();
		foreach ( (array) ( isset( $taxes['total'] ) ? $taxes['total'] : array() ) as $rate_id => $amount ) {
			$cents[ $rate_id ] = cifw_to_cents( $amount );
		}
		$items[ $item_id ] = array(
			'amount' => cifw_to_cents( $item->get_total() ),
			'taxes'  => $cents,
		);
	}

	$shipping_taxes = array();
	foreach ( $order->get_items( 'shipping' ) as $shipping_item ) {
		$taxes = $shipping_item->get_taxes();
		foreach ( (array) ( isset( $taxes['total'] ) ? $taxes['total'] : array() ) as $rate_id => $amount ) {
			$shipping_taxes[ $rate_id ] = ( isset( $shipping_taxes[ $rate_id ] ) ? $shipping_taxes[ $rate_id ] : 0 ) + (float) $amount;
		}
	}
	$shipping_taxes = array_map( 'cifw_to_cents', $shipping_taxes );

	// tax rows as on the real invoice: one per rate, with the rate total of
	// the order (used as is when every line is included)
	$rates = array();
	foreach ( $order->get_items( 'tax' ) as $tax_item ) {
		$rates[] = array(
			'id'    => (int) $tax_item->get_rate_id(),
			'code'  => sanitize_title( $tax_item->get_rate_code() ),
			'label' => $tax_item->get_label(),
			'full'  => cifw_to_cents( (float) $tax_item->get_tax_total() + (float) $tax_item->get_shipping_tax_total() ),
		);
	}

	return array(
		'items'     => $items,
		'shipping'  => array(
			'amount' => cifw_to_cents( $order->get_shipping_total() ),
			'taxes'  => $shipping_taxes,
		),
		'rates'     => $rates,
		'singleTax' => 'single' === get_option( 'woocommerce_tax_total_display' ),
		'taxLabel'  => function_exists( 'WC' ) && WC()->countries ? WC()->countries->tax_or_vat() : __( 'Tax', 'woocommerce' ),
	);
}

/**
 * Item ids included in the document (unchecked lines are dropped; lines
 * without saved data, e.g. added to the order later, are included).
 *
 * @param array $base  From cifw_get_proportion_base().
 * @param array $data  Customs data.
 * @return array
 */
function cifw_get_included_item_ids( $base, $data ) {
	$saved = isset( $data['items'] ) && is_array( $data['items'] ) ? $data['items'] : array();
	$ids   = array();

	foreach ( array_keys( $base['items'] ) as $item_id ) {
		if ( isset( $saved[ $item_id ] ) && empty( $saved[ $item_id ]['include'] ) ) {
			continue;
		}
		$ids[] = $item_id;
	}

	return $ids;
}

/**
 * Proportion mode: the order scaled to $percent.
 *
 * @return array See cifw_get_invoice_amounts().
 */
function cifw_compute_proportional_amounts( $base, $included_ids, $percent ) {
	$lines = array();
	foreach ( $included_ids as $item_id ) {
		$lines[ $item_id ] = $base['items'][ $item_id ]['amount'];
	}
	$scaled_lines = cifw_scale_lines( $lines, $percent );

	$items = array();
	foreach ( $scaled_lines as $item_id => $cents ) {
		$items[ $item_id ] = array(
			'amount' => $cents,
			// line tax is only informative (templates with a tax column)
			'tax'    => cifw_scale_cents( array_sum( $base['items'][ $item_id ]['taxes'] ), $percent ),
		);
	}

	$all_included = count( $included_ids ) === count( $base['items'] );
	$taxes        = array();

	foreach ( $base['rates'] as $rate ) {
		$rate_id = $rate['id'];

		if ( $all_included ) {
			$cents = $rate['full'];
		} else {
			$cents = isset( $base['shipping']['taxes'][ $rate_id ] ) ? $base['shipping']['taxes'][ $rate_id ] : 0;
			foreach ( $included_ids as $item_id ) {
				if ( isset( $base['items'][ $item_id ]['taxes'][ $rate_id ] ) ) {
					$cents += $base['items'][ $item_id ]['taxes'][ $rate_id ];
				}
			}
		}

		$taxes[] = array(
			'code'   => $rate['code'],
			'label'  => $rate['label'],
			'amount' => cifw_scale_cents( $cents, $percent ),
		);
	}

	return array(
		'items'    => $items,
		'shipping' => cifw_scale_cents( $base['shipping']['amount'], $percent ),
		'taxes'    => $taxes,
	);
}

/**
 * Custom mode: the amounts typed in the modal, no taxes (0.1.x behaviour).
 *
 * @return array See cifw_get_invoice_amounts().
 */
function cifw_compute_custom_amounts( $order, $base, $included_ids, $data ) {
	$saved = isset( $data['items'] ) && is_array( $data['items'] ) ? $data['items'] : array();
	$items = array();

	foreach ( $included_ids as $item_id ) {
		$custom = isset( $saved[ $item_id ] ) ? $saved[ $item_id ] : array();
		$items[ $item_id ] = array(
			'amount' => isset( $custom['total'] ) && '' !== $custom['total']
				? cifw_to_cents( $custom['total'] )
				: $base['items'][ $item_id ]['amount'],
			'tax'    => 0,
		);
	}

	return array(
		'items'    => $items,
		'shipping' => isset( $data['shipping'] ) ? cifw_to_cents( $data['shipping'] ) : $base['shipping']['amount'],
		'taxes'    => array(),
	);
}

/**
 * Everything the document prints, in cents.
 *
 * @param WC_Order $order
 * @param array    $data Customs data.
 * @return array {
 *     @type string|int $proportion
 *     @type array      $items    item_id => [ 'amount' => cents, 'tax' => cents ] (included lines only)
 *     @type int        $shipping
 *     @type array      $taxes    list of [ 'code', 'label', 'amount' ] (proportion mode only)
 *     @type bool       $single_tax Print taxes as a single row (WooCommerce setting).
 *     @type string     $tax_label  Label of that single row.
 *     @type int        $subtotal
 *     @type int        $total
 * }
 */
function cifw_get_invoice_amounts( $order, $data ) {
	$data       = is_array( $data ) ? $data : array();
	$base       = cifw_get_proportion_base( $order );
	$included   = cifw_get_included_item_ids( $base, $data );
	$proportion = cifw_get_proportion( $data );

	$amounts = 'custom' === $proportion
		? cifw_compute_custom_amounts( $order, $base, $included, $data )
		: cifw_compute_proportional_amounts( $base, $included, $proportion );

	$subtotal = 0;
	foreach ( $amounts['items'] as $line ) {
		$subtotal += $line['amount'];
	}
	$tax_total = 0;
	foreach ( $amounts['taxes'] as $tax ) {
		$tax_total += $tax['amount'];
	}

	$amounts['proportion'] = $proportion;
	$amounts['single_tax'] = $base['singleTax'];
	$amounts['tax_label']  = $base['taxLabel'];
	$amounts['subtotal']   = $subtotal;
	$amounts['total']      = $subtotal + $amounts['shipping'] + $tax_total;

	return apply_filters( 'cifw_invoice_amounts', $amounts, $order, $data );
}
