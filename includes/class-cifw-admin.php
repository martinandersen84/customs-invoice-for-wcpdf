<?php
/**
 * Admin UI: order meta box with the "Customs invoice" button, the editing
 * modal (pre-filled with the real order data) and the AJAX endpoints to save
 * the customs data, generate the PDF and download it.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CIFW_Admin {

	const CAPABILITY = 'edit_shop_orders';

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ), 20 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		add_action( 'wp_ajax_cifw_save', array( $this, 'ajax_save' ) );
		add_action( 'wp_ajax_cifw_generate', array( $this, 'ajax_generate' ) );
		add_action( 'wp_ajax_cifw_reset', array( $this, 'ajax_reset' ) );
		add_action( 'wp_ajax_cifw_download', array( $this, 'ajax_download' ) );
	}

	private function get_order_screen_id() {
		return function_exists( 'wc_get_page_screen_id' ) ? wc_get_page_screen_id( 'shop-order' ) : 'shop_order';
	}

	private function get_default_document_title( $order = null ) {
		// English on purpose: the PDF is addressed to customs officers (see CIFW_Customs_Invoice)
		return apply_filters( 'cifw_default_document_title', 'Customs Invoice', $order );
	}

	public function add_meta_box() {
		if ( ! cifw_wcpdf_available() ) {
			return;
		}

		add_meta_box(
			'cifw-meta-box',
			__( 'Customs invoice', 'customs-invoice-for-wcpdf' ),
			array( $this, 'render_meta_box' ),
			$this->get_order_screen_id(),
			'side',
			'default'
		);
	}

	public function enqueue_assets( $hook ) {
		$screen = get_current_screen();
		if ( ! $screen || $screen->id !== $this->get_order_screen_id() ) {
			return;
		}

		wp_enqueue_style( 'cifw-admin', CIFW_PLUGIN_URL . 'assets/css/admin.css', array(), CIFW_VERSION );
		wp_enqueue_script( 'cifw-admin', CIFW_PLUGIN_URL . 'assets/js/admin.js', array(), CIFW_VERSION, true );
		wp_localize_script( 'cifw-admin', 'cifwL10n', array(
			'saving'        => __( 'Saving…', 'customs-invoice-for-wcpdf' ),
			'saveError'     => __( 'Could not save.', 'customs-invoice-for-wcpdf' ),
			'generating'    => __( 'Generating PDF…', 'customs-invoice-for-wcpdf' ),
			/* translators: %s: PDF filename */
			'generated'     => __( 'PDF generated: %s', 'customs-invoice-for-wcpdf' ),
			'generateError' => __( 'Could not generate the PDF.', 'customs-invoice-for-wcpdf' ),
			'resetConfirm'  => __( 'Reset the customs data to the original order values?', 'customs-invoice-for-wcpdf' ),
			'resetError'    => __( 'Could not reset.', 'customs-invoice-for-wcpdf' ),
			'connError'     => __( 'Connection error.', 'customs-invoice-for-wcpdf' ),
		) );
	}

	/**
	 * Meta box: status + button. The modal markup is printed here (hidden)
	 * and moved to <body> by JS to escape the order form and metabox styles.
	 */
	public function render_meta_box( $post_or_order ) {
		$order = ( $post_or_order instanceof WC_Order ) ? $post_or_order : wc_get_order( $post_or_order->ID );
		if ( ! $order ) {
			return;
		}

		$data         = $order->get_meta( CIFW_META_KEY );
		$data         = is_array( $data ) ? $data : array();
		$pdf_file     = $order->get_meta( '_cifw_pdf_file' );
		$generated_at = (int) $order->get_meta( '_cifw_pdf_generated_at' );

		echo '<div class="cifw-metabox">';

		if ( ! empty( $data ) ) {
			echo '<p class="cifw-status">✔ ' . esc_html__( 'Customs data saved.', 'customs-invoice-for-wcpdf' ) . '</p>';
		} else {
			echo '<p class="cifw-status cifw-muted">' . esc_html__( 'No custom data yet.', 'customs-invoice-for-wcpdf' ) . '</p>';
		}

		if ( $pdf_file && $generated_at ) {
			printf(
				'<p class="cifw-status">%s<br><a href="%s" target="_blank">%s</a></p>',
				sprintf(
					/* translators: %s: date and time */
					esc_html__( 'PDF generated: %s', 'customs-invoice-for-wcpdf' ),
					esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $generated_at ) )
				),
				esc_url( $this->get_download_url( $order->get_id() ) ),
				esc_html__( 'Download customs PDF', 'customs-invoice-for-wcpdf' )
			);
		}

		echo '<button type="button" class="button button-primary cifw-open-modal">' . esc_html__( 'Customs invoice…', 'customs-invoice-for-wcpdf' ) . '</button>';
		echo '</div>';

		$this->render_modal( $order, $data );
	}

	private function get_download_url( $order_id ) {
		return add_query_arg(
			array(
				'action'   => 'cifw_download',
				'order_id' => $order_id,
				'nonce'    => wp_create_nonce( 'cifw_download_' . $order_id ),
			),
			admin_url( 'admin-ajax.php' )
		);
	}

	private function render_modal( $order, $data ) {
		$items           = $order->get_items();
		$saved_items     = isset( $data['items'] ) && is_array( $data['items'] ) ? $data['items'] : array();
		$document_title  = ! empty( $data['document_title'] ) ? $data['document_title'] : $this->get_default_document_title( $order );
		$shipping        = isset( $data['shipping'] ) ? $data['shipping'] : wc_format_decimal( $order->get_shipping_total(), 2 );
		$currency_symbol = get_woocommerce_currency_symbol( $order->get_currency() );
		?>
		<div class="cifw-modal-backdrop" id="cifw-modal" style="display:none"
			data-order-id="<?php echo esc_attr( $order->get_id() ); ?>"
			data-currency="<?php echo esc_attr( $currency_symbol ); ?>"
			data-nonce="<?php echo esc_attr( wp_create_nonce( 'cifw_edit_' . $order->get_id() ) ); ?>"
			data-ajaxurl="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>">
			<div class="cifw-modal">
				<div class="cifw-modal-header">
					<h2>
						<?php
						printf(
							/* translators: %s: order number */
							esc_html__( 'Customs invoice — Order #%s', 'customs-invoice-for-wcpdf' ),
							esc_html( $order->get_order_number() )
						);
						?>
					</h2>
					<button type="button" class="cifw-close" aria-label="<?php esc_attr_e( 'Close', 'customs-invoice-for-wcpdf' ); ?>">&times;</button>
				</div>

				<div class="cifw-modal-body">
					<p class="cifw-muted"><?php echo wp_kses_post( __( 'A new PDF is generated with the same buyer data and the same number and date as the original invoice. The original invoice is <strong>not modified</strong>. If the invoice does not exist yet, it will be created when generating the PDF.', 'customs-invoice-for-wcpdf' ) ); ?></p>

					<p class="cifw-field">
						<label><?php esc_html_e( 'Document title', 'customs-invoice-for-wcpdf' ); ?></label>
						<input type="text" id="cifw-document-title" value="<?php echo esc_attr( $document_title ); ?>">
					</p>

					<table class="cifw-items">
						<thead>
							<tr>
								<th class="cifw-col-include" title="<?php esc_attr_e( 'Include in the customs invoice', 'customs-invoice-for-wcpdf' ); ?>">✔</th>
								<th><?php esc_html_e( 'Product and description / materials', 'customs-invoice-for-wcpdf' ); ?></th>
								<th class="cifw-col-qty"><?php esc_html_e( 'Qty', 'customs-invoice-for-wcpdf' ); ?></th>
								<th class="cifw-col-total">
									<?php
									printf(
										/* translators: %s: currency symbol */
										esc_html__( 'Amount (%s)', 'customs-invoice-for-wcpdf' ),
										esc_html( $currency_symbol )
									);
									?>
								</th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $items as $item_id => $item ) :
								$saved   = isset( $saved_items[ $item_id ] ) && is_array( $saved_items[ $item_id ] ) ? $saved_items[ $item_id ] : null;
								$include = $saved ? ! empty( $saved['include'] ) : true;
								$name    = $saved && '' !== ( $saved['name'] ?? '' ) ? $saved['name'] : $item->get_name();
								$desc    = $saved ? ( $saved['description'] ?? '' ) : '';
								$total   = $saved && '' !== ( $saved['total'] ?? '' ) ? $saved['total'] : wc_format_decimal( $item->get_total(), 2 );
								?>
								<tr class="cifw-item" data-item-id="<?php echo esc_attr( $item_id ); ?>">
									<td class="cifw-col-include">
										<input type="checkbox" data-field="include" <?php checked( $include ); ?>>
									</td>
									<td>
										<input type="text" data-field="name" value="<?php echo esc_attr( $name ); ?>" placeholder="<?php esc_attr_e( 'Product title', 'customs-invoice-for-wcpdf' ); ?>">
										<textarea data-field="description" rows="2" placeholder="<?php esc_attr_e( 'Description and list of materials', 'customs-invoice-for-wcpdf' ); ?>"><?php echo esc_textarea( $desc ); ?></textarea>
									</td>
									<td class="cifw-col-qty"><?php echo esc_html( $item->get_quantity() ); ?></td>
									<td class="cifw-col-total">
										<input type="number" step="0.01" min="0" data-field="total" value="<?php echo esc_attr( $total ); ?>">
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
						<tfoot>
							<tr>
								<td colspan="3" class="cifw-right"><?php esc_html_e( 'Shipping', 'customs-invoice-for-wcpdf' ); ?></td>
								<td class="cifw-col-total"><input type="number" step="0.01" min="0" id="cifw-shipping" value="<?php echo esc_attr( $shipping ); ?>"></td>
							</tr>
							<tr class="cifw-total-row">
								<td colspan="3" class="cifw-right"><?php esc_html_e( 'Total', 'customs-invoice-for-wcpdf' ); ?></td>
								<td class="cifw-col-total"><strong id="cifw-total">—</strong></td>
							</tr>
						</tfoot>
					</table>
				</div>

				<div class="cifw-modal-footer">
					<span class="cifw-feedback" id="cifw-feedback"></span>
					<button type="button" class="button cifw-reset"><?php esc_html_e( 'Reset', 'customs-invoice-for-wcpdf' ); ?></button>
					<button type="button" class="button cifw-cancel"><?php esc_html_e( 'Cancel', 'customs-invoice-for-wcpdf' ); ?></button>
					<button type="button" class="button cifw-save"><?php esc_html_e( 'Save', 'customs-invoice-for-wcpdf' ); ?></button>
					<button type="button" class="button button-primary cifw-generate"><?php esc_html_e( 'Save and generate PDF', 'customs-invoice-for-wcpdf' ); ?></button>
				</div>
			</div>
		</div>
		<?php
	}

	/*
	|--------------------------------------------------------------------------
	| AJAX
	|--------------------------------------------------------------------------
	*/

	/**
	 * Validates the request and returns the order, or sends a JSON error.
	 */
	private function validate_request() {
		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;

		if ( ! $order_id || ! check_ajax_referer( 'cifw_edit_' . $order_id, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid request (nonce).', 'customs-invoice-for-wcpdf' ) ), 403 );
		}
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'customs-invoice-for-wcpdf' ) ), 403 );
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wp_send_json_error( array( 'message' => __( 'Order not found.', 'customs-invoice-for-wcpdf' ) ), 404 );
		}

		return $order;
	}

	/**
	 * Sanitizes and persists the customs data sent by the modal.
	 */
	private function save_customs_data( $order ) {
		$raw = isset( $_POST['data'] ) ? json_decode( wp_unslash( $_POST['data'] ), true ) : null;
		if ( ! is_array( $raw ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid data.', 'customs-invoice-for-wcpdf' ) ), 400 );
		}

		$items       = array();
		$valid_items = $order->get_items();

		if ( isset( $raw['items'] ) && is_array( $raw['items'] ) ) {
			foreach ( $raw['items'] as $item_id => $item ) {
				$item_id = absint( $item_id );
				if ( ! isset( $valid_items[ $item_id ] ) || ! is_array( $item ) ) {
					continue;
				}
				$items[ $item_id ] = array(
					'include'     => ! empty( $item['include'] ) ? 1 : 0,
					'name'        => isset( $item['name'] ) ? sanitize_text_field( $item['name'] ) : '',
					'description' => isset( $item['description'] ) ? sanitize_textarea_field( $item['description'] ) : '',
					'total'       => isset( $item['total'] ) && '' !== $item['total'] ? wc_format_decimal( $item['total'], 2 ) : '',
				);
			}
		}

		$data = array(
			'document_title' => isset( $raw['document_title'] ) && '' !== trim( $raw['document_title'] )
				? sanitize_text_field( $raw['document_title'] )
				: $this->get_default_document_title( $order ),
			'shipping'       => isset( $raw['shipping'] ) && '' !== $raw['shipping'] ? wc_format_decimal( $raw['shipping'], 2 ) : '0',
			'items'          => $items,
			'updated_at'     => time(),
		);

		$order->update_meta_data( CIFW_META_KEY, $data );
		$order->save_meta_data();

		return $data;
	}

	public function ajax_save() {
		$order = $this->validate_request();
		$this->save_customs_data( $order );
		wp_send_json_success( array( 'message' => __( 'Data saved.', 'customs-invoice-for-wcpdf' ) ) );
	}

	public function ajax_generate() {
		$order = $this->validate_request();
		$this->save_customs_data( $order );

		if ( ! cifw_wcpdf_available() ) {
			wp_send_json_error( array( 'message' => __( 'WooCommerce PDF Invoices & Packing Slips is not available.', 'customs-invoice-for-wcpdf' ) ), 500 );
		}

		try {
			// make sure the real invoice exists so we can mirror its number & date
			// (same effect as clicking the Invoice button in WCPDF)
			$invoice = wcpdf_get_document( 'invoice', $order, true );
			if ( ! $invoice ) {
				wp_send_json_error( array( 'message' => __( 'Could not create/load the original invoice (disabled for this order status?).', 'customs-invoice-for-wcpdf' ) ), 500 );
			}

			$document = wcpdf_get_document( CIFW_DOCUMENT_TYPE, $order );
			if ( ! $document ) {
				wp_send_json_error( array( 'message' => __( 'Could not load the customs document.', 'customs-invoice-for-wcpdf' ) ), 500 );
			}

			$pdf = $document->get_pdf();
			if ( empty( $pdf ) ) {
				wp_send_json_error( array( 'message' => __( 'PDF generation returned an empty document.', 'customs-invoice-for-wcpdf' ) ), 500 );
			}

			$filename = $document->get_filename();
			$dir      = $this->get_storage_dir();
			$written  = file_put_contents( trailingslashit( $dir ) . $filename, $pdf );

			if ( false === $written ) {
				wp_send_json_error( array(
					/* translators: %s: directory path */
					'message' => sprintf( __( 'Could not write the PDF to %s', 'customs-invoice-for-wcpdf' ), $dir ),
				), 500 );
			}

			$order->update_meta_data( '_cifw_pdf_file', $filename );
			$order->update_meta_data( '_cifw_pdf_generated_at', time() );
			$order->save_meta_data();

			wp_send_json_success( array(
				'message'      => __( 'PDF generated.', 'customs-invoice-for-wcpdf' ),
				'download_url' => $this->get_download_url( $order->get_id() ),
				'filename'     => $filename,
			) );
		} catch ( \Throwable $e ) {
			wp_send_json_error( array(
				/* translators: %s: error message */
				'message' => sprintf( __( 'Error generating the PDF: %s', 'customs-invoice-for-wcpdf' ), $e->getMessage() ),
			), 500 );
		}
	}

	public function ajax_reset() {
		$order = $this->validate_request();
		$order->delete_meta_data( CIFW_META_KEY );
		$order->save_meta_data();
		wp_send_json_success( array( 'message' => __( 'Data reset.', 'customs-invoice-for-wcpdf' ) ) );
	}

	/**
	 * The storage folder is protected against direct access; downloads are
	 * served through this endpoint (admin users only).
	 */
	public function ajax_download() {
		$order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;
		$nonce    = isset( $_GET['nonce'] ) ? sanitize_text_field( wp_unslash( $_GET['nonce'] ) ) : '';

		if ( ! $order_id || ! wp_verify_nonce( $nonce, 'cifw_download_' . $order_id ) || ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Invalid request.', 'customs-invoice-for-wcpdf' ), '', array( 'response' => 403 ) );
		}

		$order = wc_get_order( $order_id );
		$file  = $order ? $order->get_meta( '_cifw_pdf_file' ) : '';
		$path  = trailingslashit( $this->get_storage_dir() ) . basename( $file );

		if ( ! $file || ! file_exists( $path ) ) {
			wp_die( esc_html__( 'The customs PDF does not exist. Generate it from the order.', 'customs-invoice-for-wcpdf' ), '', array( 'response' => 404 ) );
		}

		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: attachment; filename="' . basename( $path ) . '"' );
		header( 'Content-Length: ' . filesize( $path ) );
		readfile( $path );
		exit;
	}

	/**
	 * uploads/customs-invoices/, kept apart from the WCPDF invoice folders,
	 * with direct web access blocked.
	 */
	private function get_storage_dir() {
		$uploads = wp_upload_dir();
		$dir     = trailingslashit( $uploads['basedir'] ) . 'customs-invoices';

		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		if ( ! file_exists( $dir . '/.htaccess' ) ) {
			file_put_contents( $dir . '/.htaccess', "deny from all\n" );
		}
		if ( ! file_exists( $dir . '/index.php' ) ) {
			file_put_contents( $dir . '/index.php', "<?php // Silence is golden.\n" );
		}

		return apply_filters( 'cifw_storage_dir', $dir );
	}
}
