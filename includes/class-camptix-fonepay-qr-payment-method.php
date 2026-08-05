<?php
/**
 * Fonepay QR Payment Gateway for CampTix
 *
 * @package CampTix_Nepali_Payments
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'CampTix_Fonepay_Key_Store' ) ) {
	require_once __DIR__ . '/fonepay/class-camptix-fonepay-key-store.php';
}

if ( ! class_exists( 'CampTix_Fonepay_Api_Client' ) ) {
	require_once __DIR__ . '/fonepay/class-camptix-fonepay-api-client.php';
}

/**
 * Fonepay QR Payment Gateway Class
 */
class CampTix_Fonepay_QR_Payment_Method extends CampTix_Payment_Method {

	/**
	 * Payment gateway ID
	 *
	 * @var string
	 */
	public $id = 'camptix_fonepay_qr';

	/**
	 * Payment gateway name
	 *
	 * @var string
	 */
	public $name = 'Fonepay QR';

	/**
	 * Payment gateway description
	 *
	 * @var string
	 */
	public $description = 'CampTix payment method for Fonepay QR (Intent Checkout)';

	/**
	 * Supported currencies
	 *
	 * @var array
	 */
	public $supported_currencies = array( 'NPR' );

	/**
	 * Gateway options
	 *
	 * @var array
	 */
	protected $options = array();

	/**
	 * Max length for Fonepay billId / referenceLabel.
	 *
	 * @var int
	 */
	const REFERENCE_LABEL_MAX_LENGTH = 20;

	/**
	 * Max length for the optional per-event reference prefix.
	 *
	 * @var int
	 */
	const FONEPAY_PREFIX_MAX_LENGTH = 6;

	/**
	 * QR session cache TTL (seconds).
	 *
	 * @var int
	 */
	const QR_SESSION_TTL = 30 * MINUTE_IN_SECONDS;

	/**
	 * Minimum seconds between outbound Fonepay status API calls per payment token.
	 *
	 * @var int
	 */
	const STATUS_AJAX_THROTTLE_SECONDS = 5;

	/**
	 * Nonce action prefixes.
	 *
	 * @var string
	 */
	const NONCE_ACTION_CANCEL = 'camptix_fonepay_qr_cancel';
	const NONCE_ACTION_STATUS = 'camptix_fonepay_qr_status';
	const NONCE_ACTION_RETURN = 'camptix_fonepay_qr_return';
	const NONCE_ACTION_QR     = 'camptix_fonepay_qr_page';

	/**
	 * Tokens reconciled in this timeout sweep.
	 *
	 * @var array<string, true>
	 */
	protected static $timeout_reconciled_tokens = array();

	/**
	 * Initialize the gateway
	 *
	 * @return void
	 */
	public function camptix_init() {
		wp_register_style(
			'camptix-fonepay-qr',
			plugin_dir_url( __DIR__ ) . 'css/fonepay-qr.css',
			array(),
			filemtime( plugin_dir_path( __DIR__ ) . 'css/fonepay-qr.css' )
		);

		wp_register_script(
			'camptix-fonepay-qrcode',
			plugin_dir_url( __DIR__ ) . 'js/vendor/qrcode.js',
			array(),
			filemtime( plugin_dir_path( __DIR__ ) . 'js/vendor/qrcode.js' ),
			true
		);

		wp_register_script(
			'camptix-fonepay-qr',
			plugin_dir_url( __DIR__ ) . 'js/fonepay-qr.js',
			array( 'camptix-fonepay-qrcode' ),
			filemtime( plugin_dir_path( __DIR__ ) . 'js/fonepay-qr.js' ),
			true
		);

		$this->options = array_merge(
			array(
				'ref_code'    => '',
				'username'    => '',
				'password'    => '',
				'private_key' => '',
				'terminal_id' => '',
				'sandbox'     => true,
			),
			$this->get_payment_options()
		);

		add_action( 'template_redirect', array( $this, 'template_redirect' ) );
		add_action( 'camptix_pre_attendee_timeout', array( $this, 'pre_attendee_timeout' ) );
	}

	/**
	 * Add settings fields
	 *
	 * @return void
	 */
	public function payment_settings_fields() {
		$this->add_settings_field_helper(
			'ref_code',
			__( 'Reference Prefix', 'nepali-payments-for-camptix' ),
			array( $this, 'render_ref_code_field' ),
			__( 'Short alphanumeric code unique to this event (e.g. KTM26). Becomes KTM26… on Fonepay references (no separator; Fonepay IDs are alphanumeric only). Use a different prefix when multiple WordCamps share one merchant account.', 'nepali-payments-for-camptix' )
		);
		$this->add_settings_field_helper( 'username', __( 'Merchant Username', 'nepali-payments-for-camptix' ), array( $this, 'field_text' ) );
		$this->add_settings_field_helper( 'password', __( 'Merchant Password', 'nepali-payments-for-camptix' ), array( $this, 'field_password' ) );
		$this->add_settings_field_helper( 'private_key', __( 'Client Private Key (RSA, PEM)', 'nepali-payments-for-camptix' ), array( $this, 'render_private_key_field' ) );
		$this->add_settings_field_helper( 'terminal_id', __( 'Terminal ID', 'nepali-payments-for-camptix' ), array( $this, 'field_text' ) );
		$this->add_settings_field_helper( 'sandbox', __( 'Sandbox Mode', 'nepali-payments-for-camptix' ), array( $this, 'field_yesno' ) );
	}

	/**
	 * Render the Reference Prefix settings field.
	 *
	 * @param array $args Settings field arguments from add_settings_field_helper().
	 * @return void
	 */
	public function render_ref_code_field( $args ) {
		?>
		<input
			type="text"
			name="<?php echo esc_attr( $args['name'] ); ?>"
			value="<?php echo esc_attr( $args['value'] ); ?>"
			class="regular-text"
			maxlength="<?php echo esc_attr( (string) self::FONEPAY_PREFIX_MAX_LENGTH ); ?>"
			pattern="[A-Za-z0-9]*"
			autocomplete="off"
			spellcheck="false"
		/>
		<?php if ( ! empty( $args['description'] ) ) : ?>
			<p class="description"><?php echo esc_html( $args['description'] ); ?></p>
		<?php endif; ?>
		<?php
	}

	/**
	 * Render a write-only password input for a settings field.
	 *
	 * The saved password is never echoed back. Enter a new value only to replace it.
	 *
	 * @param array $args Settings field arguments from add_settings_field_helper().
	 * @return void
	 */
	public function field_password( $args ) {
		$has_password = ! empty( $this->options['password'] );
		?>
		<input
			type="password"
			name="<?php echo esc_attr( $args['name'] ); ?>"
			value=""
			class="regular-text"
			autocomplete="new-password"
			spellcheck="false"
			placeholder="<?php echo esc_attr( $has_password ? __( 'Password is saved. Enter a new password only to replace it.', 'nepali-payments-for-camptix' ) : '' ); ?>"
		/>
		<?php if ( $has_password ) : ?>
			<p class="description"><?php esc_html_e( 'A password is already stored. Leave this field empty to keep the current password.', 'nepali-payments-for-camptix' ); ?></p>
		<?php endif; ?>
		<?php if ( ! empty( $args['description'] ) ) : ?>
			<p class="description"><?php echo esc_html( $args['description'] ); ?></p>
		<?php endif; ?>
		<?php
	}

	/**
	 * Render a write-only textarea for the RSA private key setting.
	 *
	 * The saved key is never echoed back. Paste a new key only to replace it.
	 *
	 * @param array $args Settings field arguments from add_settings_field_helper().
	 * @return void
	 */
	public function render_private_key_field( $args ) {
		$has_key = $this->get_key_store()->has_stored_private_key();
		?>
		<textarea
			class="large-text code"
			rows="8"
			name="<?php echo esc_attr( $args['name'] ); ?>"
			placeholder="<?php echo esc_attr( $has_key ? __( 'A private key is saved. Paste a new key only to replace it.', 'nepali-payments-for-camptix' ) : __( 'Paste your RSA private key (PEM).', 'nepali-payments-for-camptix' ) ); ?>"
			autocomplete="off"
			spellcheck="false"
		></textarea>
		<?php if ( $has_key ) : ?>
			<p class="description"><?php esc_html_e( 'A private key is already stored. Leave this field empty to keep the current key.', 'nepali-payments-for-camptix' ); ?></p>
		<?php endif; ?>
		<?php if ( ! empty( $args['description'] ) ) : ?>
			<p class="description"><?php echo esc_html( $args['description'] ); ?></p>
		<?php endif; ?>
		<?php
	}

	/**
	 * Validate options
	 *
	 * @param array $input Input data.
	 * @return array
	 */
	public function validate_options( $input ) {
		$output = $this->options;

		if ( isset( $input['ref_code'] ) ) {
			$output['ref_code'] = $this->sanitize_fonepay_ref_code( $input['ref_code'] );
		}
		if ( isset( $input['username'] ) ) {
			$output['username'] = sanitize_text_field( $input['username'] );
		}
		if ( isset( $input['password'] ) ) {
			$submitted_password = sanitize_text_field( $input['password'] );
			if ( '' !== $submitted_password ) {
				$output['password'] = $submitted_password;
			}
		}
		if ( isset( $input['private_key'] ) ) {
			$submitted_key = trim( sanitize_textarea_field( $input['private_key'] ) );
			if ( '' !== $submitted_key ) {
				$encrypted_key = $this->get_key_store()->encrypt_private_key( $submitted_key );
				if ( false !== $encrypted_key ) {
					$output['private_key'] = $encrypted_key;
				}
			}
		}
		if ( isset( $input['terminal_id'] ) ) {
			$output['terminal_id'] = sanitize_text_field( $input['terminal_id'] );
		}
		if ( isset( $input['sandbox'] ) ) {
			$output['sandbox'] = (bool) $input['sandbox'];
		}

		return $output;
	}

	/**
	 * Handle template redirect
	 *
	 * @return void
	 */
	public function template_redirect() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Each handler verifies its own nonce.
		if ( ! isset( $_REQUEST['tix_payment_method'] ) || sanitize_text_field( wp_unslash( $_REQUEST['tix_payment_method'] ) ) !== $this->id ) {
			return;
		}

		if ( isset( $_GET['tix_action'] ) && ! empty( sanitize_text_field( wp_unslash( $_GET['tix_action'] ) ) ) ) {
			$action = sanitize_text_field( wp_unslash( $_GET['tix_action'] ) );

			if ( 'payment_return' === $action ) {
				$this->payment_return();
			} elseif ( 'payment_cancel' === $action ) {
				$this->payment_cancel();
			} elseif ( 'payment_status' === $action ) {
				$this->payment_status_ajax();
			} elseif ( 'payment_qr' === $action ) {
				$this->payment_qr_page();
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Before CampTix marks a draft attendee as timed out, complete the order if
	 * Fonepay already shows the payment as success (paid but return never ran).
	 *
	 * The Fonepay referenceLabel is derived from the payment token (shared by all
	 * attendees), so no per-attendee gateway meta is required.
	 *
	 * @param int $attendee_id Attendee post ID about to time out.
	 * @return void
	 */
	public function pre_attendee_timeout( $attendee_id ) {
		$attendee_id = absint( $attendee_id );
		if ( ! $attendee_id || 'draft' !== get_post_field( 'post_status', $attendee_id ) ) {
			return;
		}

		if ( $this->id !== get_post_meta( $attendee_id, 'tix_payment_method', true ) ) {
			return;
		}

		$payment_token = (string) get_post_meta( $attendee_id, 'tix_payment_token', true );
		if ( '' === $payment_token ) {
			return;
		}

		if ( isset( self::$timeout_reconciled_tokens[ $payment_token ] ) ) {
			return;
		}
		self::$timeout_reconciled_tokens[ $payment_token ] = true;

		$reference_label = $this->get_fonepay_reference_label( $payment_token );
		if ( '' === $reference_label ) {
			return;
		}

		$api_client   = $this->get_api_client();
		$access_token = $api_client->get_access_token();
		if ( false === $access_token ) {
			return;
		}

		$status_result = $api_client->get_payment_status( $access_token, $reference_label );
		$status        = isset( $status_result['status'] ) ? (string) $status_result['status'] : '';
		if ( 'success' !== $status ) {
			return;
		}

		$amount_ok = $this->payment_amount_matches_order( $payment_token, $status_result );
		if ( true !== $amount_ok ) {
			$this->log(
				null === $amount_ok
					? 'Fonepay timeout reconcile: amount unverifiable; leaving draft.'
					: 'Fonepay timeout reconcile: amount mismatch.',
				$attendee_id,
				array(
					'payment_token'   => $payment_token,
					'reference_label' => $reference_label,
					'status'          => $status_result,
				)
			);
			return;
		}

		$this->clear_qr_session( $payment_token );
		$this->payment_result(
			$payment_token,
			CampTix_Plugin::PAYMENT_STATUS_COMPLETED,
			$this->build_payment_data( $reference_label, $status_result ),
			false // Non-interactive: do not redirect.
		);
	}

	/**
	 * Token-bound nonce action.
	 *
	 * @param string $action_prefix One of the NONCE_ACTION_* constants.
	 * @param string $payment_token Payment token.
	 * @return string
	 */
	protected function get_nonce_action( $action_prefix, $payment_token ) {
		return $action_prefix . '_' . hash( 'sha256', (string) $payment_token );
	}

	/**
	 * Whether the request carries a valid WP nonce for the given action.
	 *
	 * @param string $action Nonce action name.
	 * @return bool
	 */
	protected function verify_request_nonce( $action ) {
		$nonce = isset( $_REQUEST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) ) : '';

		return (bool) wp_verify_nonce( $nonce, $action );
	}

	/**
	 * Redirect to the tickets page when a first-party nonce check fails.
	 *
	 * @return void
	 */
	protected function reject_invalid_nonce() {
		wp_safe_redirect( esc_url_raw( $this->get_tickets_url() ) );
		exit;
	}

	/**
	 * Attach a WP nonce to a Fonepay callback URL.
	 *
	 * @param string $url    Absolute URL.
	 * @param string $action Nonce action name.
	 * @return string
	 */
	protected function add_request_nonce( $url, $action ) {
		return add_query_arg( '_wpnonce', wp_create_nonce( $action ), $url );
	}

	/**
	 * Read the payment token from the request.
	 *
	 * @return string
	 */
	protected function get_request_payment_token() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Caller verifies nonce next.
		return isset( $_REQUEST['tix_payment_token'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['tix_payment_token'] ) ) : '';
	}

	/**
	 * FAILED unless attendees already publish/pending/refund.
	 *
	 * @param string $payment_token Payment token.
	 * @param array  $payment_data  Optional payment data for payment_result().
	 * @param bool   $interactive   Whether to redirect.
	 * @return mixed
	 */
	protected function payment_result_failed( $payment_token, $payment_data = array(), $interactive = true ) {
		// Uncached status check.
		$finalized = get_posts(
			array(
				'posts_per_page' => 1,
				'post_type'      => 'tix_attendee',
				'post_status'    => array( 'publish', 'pending', 'refund' ),
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Single-row tix_payment_token lookup; core FAILED has no finalize guard.
				'meta_query'     => array(
					array(
						'key'   => 'tix_payment_token',
						'value' => $payment_token,
					),
				),
			)
		);

		if ( ! empty( $finalized ) ) {
			$attendee = $finalized[0];
			$this->log(
				sprintf( 'Refusing to mark payment failed; attendee already in %s status.', $attendee->post_status ),
				$attendee->ID,
				$payment_data
			);

			if ( ! $interactive ) {
				return false;
			}

			if ( in_array( $attendee->post_status, array( 'publish', 'pending' ), true ) ) {
				$access_token = get_post_meta( $attendee->ID, 'tix_access_token', true );
				$url          = add_query_arg(
					array(
						'tix_action'       => 'access_tickets',
						'tix_access_token' => $access_token,
					),
					$this->get_tickets_url()
				);
				wp_safe_redirect( $url . '#tix' );
				exit;
			}

			wp_safe_redirect( esc_url_raw( $this->get_tickets_url() ) );
			exit;
		}

		return $this->payment_result( $payment_token, CampTix_Plugin::PAYMENT_STATUS_FAILED, $payment_data, $interactive );
	}

	/**
	 * Handle a cancelled payment from the QR checkout Cancel link.
	 *
	 * @return mixed
	 */
	public function payment_cancel() {
		$payment_token = $this->get_request_payment_token();
		if ( empty( $payment_token ) || ! $this->verify_request_nonce( $this->get_nonce_action( self::NONCE_ACTION_CANCEL, $payment_token ) ) ) {
			$this->reject_invalid_nonce();
		}

		$this->clear_qr_session( $payment_token );

		return $this->payment_result( $payment_token, CampTix_Plugin::PAYMENT_STATUS_CANCELLED );
	}

	/**
	 * Handle payment checkout
	 *
	 * @param string $payment_token Payment token.
	 * @return bool|void
	 */
	public function payment_checkout( $payment_token ) {
		global $camptix;

		if ( ! $payment_token || empty( $payment_token ) ) {
			return false;
		}

		if ( ! in_array( $this->camptix_options['currency'], $this->supported_currencies, true ) ) {
			wp_die( esc_html__( 'The selected currency is not supported by this payment method.', 'nepali-payments-for-camptix' ) );
		}

		$order = $this->get_order( $payment_token );
		if ( ! $order ) {
			return false;
		}

		// Final availability check before the buyer is sent to pay.
		if ( ! $camptix->verify_order( $order ) ) {
			$this->log( 'Could not verify CampTix order before Fonepay checkout.', $order['attendee_id'] );
			return $this->payment_result_failed( $payment_token );
		}

		$total_amount = round( (float) $order['total'], 2 );
		if ( $total_amount < 1 || $total_amount > 9999999 ) {
			$camptix->error( esc_html__( 'Payment amount is outside the range supported by Fonepay.', 'nepali-payments-for-camptix' ) );
			return $this->payment_result_failed( $payment_token );
		}

		$api_client = $this->get_api_client();
		if ( false === $api_client->get_access_token() ) {
			$camptix->error( esc_html__( 'Could not authenticate with Fonepay. Please try again.', 'nepali-payments-for-camptix' ) );
			return $this->payment_result_failed( $payment_token );
		}

		$qr_page_data = $this->create_intent_qr_page_data( $payment_token, $order );
		if ( false === $qr_page_data ) {
			$camptix->error( esc_html__( 'Could not generate a Fonepay QR. Please try again.', 'nepali-payments-for-camptix' ) );
			return $this->payment_result_failed( $payment_token );
		}

		$this->store_qr_session( $payment_token, $qr_page_data );

		wp_safe_redirect( esc_url_raw( $this->get_payment_qr_url( $payment_token ) ) );
		exit;
	}

	/**
	 * Display the QR checkout page for an existing pending order (GET-safe).
	 *
	 * Refreshing this URL reuses the cached QR session instead of creating a new
	 * attendee via the checkout POST handler.
	 *
	 * @return void
	 */
	public function payment_qr_page() {
		global $camptix;

		nocache_headers();

		$payment_token = $this->get_request_payment_token();
		if ( empty( $payment_token ) || ! $this->verify_request_nonce( $this->get_nonce_action( self::NONCE_ACTION_QR, $payment_token ) ) ) {
			$this->reject_invalid_nonce();
		}

		$attendees = $camptix->get_attendees_from_payment_token( $payment_token );
		if ( empty( $attendees ) || ! in_array( $attendees[0]->post_status, array( 'draft', 'pending' ), true ) ) {
			wp_safe_redirect( esc_url_raw( $this->get_tickets_url() ) );
			exit;
		}

		$session = $this->get_qr_session( $payment_token );
		if ( false === $session || empty( $session['qrString'] ) ) {
			// Session expired; regenerating the same referenceLabel usually hits Fonepay 409.
			wp_safe_redirect( esc_url_raw( $this->get_tickets_url() ) );
			exit;
		}

		// Rebuild page data so callback URLs and ticket summary stay fresh.
		$qr_page_data = $this->build_qr_page_data(
			$payment_token,
			(string) $session['qrString'],
			isset( $session['websocketId'] ) ? (string) $session['websocketId'] : '',
			isset( $session['amount'] ) ? (float) $session['amount'] : 0
		);

		$this->output_fonepay_qr_page( $qr_page_data );
	}

	/**
	 * Single on-demand status check triggered by the "Check Status" button.
	 *
	 * Resolves the payment token to a draft/pending attendee of this gateway and
	 * applies a short per-token throttle before calling Fonepay. Returns the
	 * normalized status as JSON. Does not change the order state; the front-end
	 * only navigates to payment_return when the status is success (or when the
	 * WebSocket reports payment failure).
	 *
	 * @return void
	 */
	public function payment_status_ajax() {
		global $camptix;

		nocache_headers();

		$payment_token = $this->get_request_payment_token();
		if ( empty( $payment_token ) || ! $this->verify_request_nonce( $this->get_nonce_action( self::NONCE_ACTION_STATUS, $payment_token ) ) ) {
			wp_send_json( array( 'status' => 'unknown' ), 403 );
		}

		$attendees = $camptix->get_attendees_from_payment_token( $payment_token );
		if ( empty( $attendees ) || ! in_array( $attendees[0]->post_status, array( 'draft', 'pending' ), true ) ) {
			wp_send_json( array( 'status' => 'unknown' ) );
		}

		if ( $this->id !== get_post_meta( $attendees[0]->ID, 'tix_payment_method', true ) ) {
			wp_send_json( array( 'status' => 'unknown' ) );
		}

		$throttle_key = 'camptix_fonepay_status_' . md5( $payment_token );
		$cached       = get_transient( $throttle_key );
		if ( false !== $cached && is_array( $cached ) && isset( $cached['status'] ) ) {
			wp_send_json( array( 'status' => (string) $cached['status'] ) );
		}

		$reference_label = $this->get_fonepay_reference_label( $payment_token );
		if ( empty( $reference_label ) ) {
			wp_send_json( array( 'status' => 'unknown' ) );
		}

		$api_client   = $this->get_api_client();
		$access_token = $api_client->get_access_token();
		if ( false === $access_token ) {
			wp_send_json( array( 'status' => 'unknown' ) );
		}

		$status_result = $api_client->get_payment_status( $access_token, $reference_label );
		$status        = isset( $status_result['status'] ) ? (string) $status_result['status'] : 'unknown';

		set_transient(
			$throttle_key,
			array( 'status' => $status ),
			self::STATUS_AJAX_THROTTLE_SECONDS
		);

		wp_send_json( array( 'status' => $status ) );
	}

	/**
	 * Build the GET URL for the QR display page.
	 *
	 * @param string $payment_token Payment token.
	 * @return string
	 */
	protected function get_payment_qr_url( $payment_token ) {
		return $this->get_action_url( 'payment_qr', $payment_token, self::NONCE_ACTION_QR );
	}

	/**
	 * Build a Fonepay action URL with a token-bound nonce.
	 *
	 * @param string $action         tix_action value (e.g. payment_return).
	 * @param string $payment_token  Payment token.
	 * @param string $nonce_action   Nonce action prefix constant.
	 * @return string
	 */
	protected function get_action_url( $action, $payment_token, $nonce_action ) {
		return $this->add_request_nonce(
			add_query_arg(
				array(
					'tix_action'         => $action,
					'tix_payment_token'  => $payment_token,
					'tix_payment_method' => $this->id,
				),
				$this->get_tickets_url()
			),
			$this->get_nonce_action( $nonce_action, $payment_token )
		);
	}

	/**
	 * Build data array for the QR checkout page and front-end script.
	 *
	 * @param string $payment_token Payment token.
	 * @param string $qr_string     QR payload from Fonepay.
	 * @param string $websocket_id  WebSocket URL from Fonepay.
	 * @param float  $total_amount  Order total in NPR.
	 * @return array
	 */
	protected function build_qr_page_data( $payment_token, $qr_string, $websocket_id, $total_amount ) {
		$ticket_summary = $this->get_ticket_summary( $payment_token );
		$assets_base    = plugin_dir_url( __DIR__ ) . 'images/fonepay/';

		return array(
			'qrString'            => $qr_string,
			'websocketId'         => $websocket_id,
			'returnUrl'           => esc_url_raw( $this->get_action_url( 'payment_return', $payment_token, self::NONCE_ACTION_RETURN ) ),
			'statusUrl'           => esc_url_raw( $this->get_action_url( 'payment_status', $payment_token, self::NONCE_ACTION_STATUS ) ),
			'cancelUrl'           => esc_url_raw( $this->get_action_url( 'payment_cancel', $payment_token, self::NONCE_ACTION_CANCEL ) ),
			'amount'              => $total_amount,
			'logoUrl'             => esc_url_raw( $assets_base . 'checkout-by-fonepay.png' ),
			'eventName'           => $ticket_summary['eventName'],
			'ticketItems'         => $ticket_summary['ticketItems'],
			'attendees'           => $ticket_summary['attendees'],
			// Delay before first Check Status click; early checks often return timeout/pending/not_found.
			'checkStatusDelay'    => 30,
			// Cooldown after a non-success check before the button can be used again.
			'checkStatusCooldown' => 15,
			'i18n'                => array(
				'checkStatus'         => __( 'Check Status', 'nepali-payments-for-camptix' ),
				/* translators: %d: seconds remaining until the button is enabled */
				'checkStatusIn'       => __( 'Check Status (%ds)', 'nepali-payments-for-camptix' ),
				'checkingStatus'      => __( 'Checking payment status...', 'nepali-payments-for-camptix' ),
				'paymentConfirmed'    => __( 'Payment confirmed. Redirecting...', 'nepali-payments-for-camptix' ),
				'paymentFailedReturn' => __( 'Payment failed or was cancelled. Redirecting...', 'nepali-payments-for-camptix' ),
				'paymentFailedStay'   => __( 'Payment failed or was cancelled. Please start a new payment.', 'nepali-payments-for-camptix' ),
				'paymentNotReceived'  => __( "We haven't received your payment yet. Complete the payment in your app, then click the button again.", 'nepali-payments-for-camptix' ),
				'paymentConfigError'  => __( 'Payment status is temporarily unavailable. Please contact the event organizer if you have already paid.', 'nepali-payments-for-camptix' ),
			),
		);
	}

	/**
	 * Build a compact ticket/attendee summary for the QR checkout page.
	 *
	 * @param string $payment_token Payment token.
	 * @return array {
	 *     @type string $eventName   Event title from CampTix options.
	 *     @type array  $ticketItems Order line items (name, quantity, price).
	 *     @type array  $attendees   Attendee display rows (name, ticket).
	 * }
	 */
	protected function get_ticket_summary( $payment_token ) {
		global $camptix;

		$summary = array(
			'eventName'   => isset( $this->camptix_options['event_name'] ) ? (string) $this->camptix_options['event_name'] : '',
			'ticketItems' => array(),
			'attendees'   => array(),
		);

		$order = $this->get_order( $payment_token );
		if ( ! empty( $order['items'] ) && is_array( $order['items'] ) ) {
			foreach ( $order['items'] as $item ) {
				$summary['ticketItems'][] = array(
					'name'     => isset( $item['name'] ) ? sanitize_text_field( $item['name'] ) : '',
					'quantity' => isset( $item['quantity'] ) ? absint( $item['quantity'] ) : 0,
					'price'    => isset( $item['price'] ) ? round( (float) $item['price'], 2 ) : 0,
				);
			}
		}

		$attendees = $camptix->get_attendees_from_payment_token( $payment_token );
		if ( empty( $attendees ) ) {
			return $summary;
		}

		foreach ( $attendees as $attendee ) {
			$first = (string) get_post_meta( $attendee->ID, 'tix_first_name', true );
			$last  = (string) get_post_meta( $attendee->ID, 'tix_last_name', true );
			$name  = trim( $first . ' ' . $last );

			$ticket_id   = (int) get_post_meta( $attendee->ID, 'tix_ticket_id', true );
			$ticket_name = $ticket_id ? get_the_title( $ticket_id ) : '';

			$summary['attendees'][] = array(
				'name'   => $name,
				'ticket' => $ticket_name ? sanitize_text_field( $ticket_name ) : '',
			);
		}

		return $summary;
	}

	/**
	 * Create Intent QR page data for an order (auth + generate + build).
	 *
	 * @param string $payment_token Payment token.
	 * @param array  $order         CampTix order array.
	 * @return array|false
	 */
	protected function create_intent_qr_page_data( $payment_token, array $order ) {
		$total_amount = round( (float) $order['total'], 2 );
		if ( $total_amount < 1 || $total_amount > 9999999 ) {
			return false;
		}

		$reference_label = $this->get_fonepay_reference_label( $payment_token );
		if ( empty( $reference_label ) ) {
			return false;
		}

		$api_client   = $this->get_api_client();
		$access_token = $api_client->get_access_token();
		if ( false === $access_token ) {
			return false;
		}

		// billId and referenceLabel must be the same short alphanumeric id.
		$qr_payload = array(
			'amount'         => $total_amount,
			'billId'         => $reference_label,
			'terminalId'     => $this->options['terminal_id'],
			'paymentMode'    => 'QR',
			'referenceLabel' => $reference_label,
			'qrType'         => 'INTENT_QR',
		);

		$qr_response = $api_client->generate_intent_qr( $access_token, $qr_payload );
		if ( false === $qr_response ) {
			return false;
		}

		return $this->build_qr_page_data(
			$payment_token,
			$this->extract_qr_payload( $qr_response ),
			$this->extract_websocket_id( $qr_response ),
			$total_amount
		);
	}

	/**
	 * Transient key for a payment token's QR session.
	 *
	 * @param string $payment_token Payment token.
	 * @return string
	 */
	protected function get_qr_session_key( $payment_token ) {
		return 'camptix_fonepay_qr_' . md5( $payment_token );
	}

	/**
	 * Cache QR checkout page data for the payment token.
	 *
	 * @param string $payment_token Payment token.
	 * @param array  $data          QR page data.
	 * @return void
	 */
	protected function store_qr_session( $payment_token, $data ) {
		set_transient( $this->get_qr_session_key( $payment_token ), $data, self::QR_SESSION_TTL );
	}

	/**
	 * Retrieve cached QR checkout page data for the payment token.
	 *
	 * @param string $payment_token Payment token.
	 * @return array|false
	 */
	protected function get_qr_session( $payment_token ) {
		$session = get_transient( $this->get_qr_session_key( $payment_token ) );

		return is_array( $session ) ? $session : false;
	}

	/**
	 * Drop the cached QR session for a payment token.
	 *
	 * @param string $payment_token Payment token.
	 * @return void
	 */
	protected function clear_qr_session( $payment_token ) {
		delete_transient( $this->get_qr_session_key( $payment_token ) );
	}

	/**
	 * Output the QR display page and enqueue the rendering script.
	 *
	 * @param array $data Data passed to the front-end script.
	 * @return void
	 */
	protected function output_fonepay_qr_page( $data ) {
		wp_enqueue_style( 'camptix-fonepay-qr' );
		wp_enqueue_script( 'camptix-fonepay-qr' );
		wp_localize_script( 'camptix-fonepay-qr', 'camptixFonepayData', $data );

		require __DIR__ . '/fonepay/views/qr-checkout-page.php';
		exit;
	}

	/**
	 * Handle payment return (authoritative server-side verification).
	 *
	 * @return void
	 */
	public function payment_return() {
		$payment_token = $this->get_request_payment_token();
		if ( empty( $payment_token ) || ! $this->verify_request_nonce( $this->get_nonce_action( self::NONCE_ACTION_RETURN, $payment_token ) ) ) {
			$this->reject_invalid_nonce();
		}

		$reference_label = $this->get_fonepay_reference_label( $payment_token );

		$status_result = array(
			'status'   => 'unknown',
			'response' => array(),
		);
		if ( ! empty( $reference_label ) ) {
			$api_client   = $this->get_api_client();
			$access_token = $api_client->get_access_token();
			if ( false !== $access_token ) {
				$status_result = $api_client->get_payment_status( $access_token, $reference_label );
			}
		}

		$status       = isset( $status_result['status'] ) ? (string) $status_result['status'] : 'unknown';
		$payment_data = $this->build_payment_data( $reference_label, $status_result );

		switch ( $status ) {
			case 'success':
				$amount_ok = $this->payment_amount_matches_order( $payment_token, $status_result );

				if ( null === $amount_ok ) {
					// Success reported but amount unverifiable — leave draft for reconcile.
					$this->log(
						'Fonepay reported success but amount could not be verified; leaving order as draft.',
						null,
						array(
							'payment_token' => $payment_token,
							'status'        => $status_result,
						)
					);
					wp_safe_redirect( esc_url_raw( $this->get_tickets_url() ) );
					exit;
				}

				if ( ! $amount_ok ) {
					$this->log(
						'Fonepay payment amount does not match the CampTix order total; marking failed.',
						null,
						array(
							'payment_token' => $payment_token,
							'status'        => $status_result,
						)
					);
					$this->clear_qr_session( $payment_token );
					$this->payment_result_failed( $payment_token, $payment_data );
					break;
				}

				$this->clear_qr_session( $payment_token );
				$this->payment_result( $payment_token, CampTix_Plugin::PAYMENT_STATUS_COMPLETED, $payment_data );
				break;

			case 'failed':
				$this->clear_qr_session( $payment_token );
				$this->payment_result_failed( $payment_token, $payment_data );
				break;

			case 'pending':
				// Intent QR "pending" means unpaid / in-flight — leave draft; do not issue tickets.
			case 'timeout':
			case 'not_found':
			case 'config_error':
			default:
				if ( 'config_error' === $status ) {
					$this->log(
						sprintf(
							'Fonepay terminal configuration error for reference %s; leaving order as draft.',
							esc_html( $reference_label )
						),
						null,
						$status_result
					);
				} else {
					$this->log(
						sprintf(
							'Fonepay payment not confirmed for reference %s (status: %s)',
							esc_html( $reference_label ),
							esc_html( $status )
						)
					);
				}
				wp_safe_redirect( esc_url_raw( $this->get_tickets_url() ) );
				exit;
		}
	}

	/**
	 * Whether the Fonepay paid/requested amount matches the CampTix order total.
	 *
	 * @param string $payment_token Payment token.
	 * @param array  $status_result Result from get_payment_status().
	 * @return bool|null True if match, false if mismatch, null if amount unverifiable.
	 */
	protected function payment_amount_matches_order( $payment_token, array $status_result ) {
		$order = $this->get_order( $payment_token );
		if ( empty( $order ) || ! isset( $order['total'] ) ) {
			return null;
		}

		$response = ( isset( $status_result['response'] ) && is_array( $status_result['response'] ) )
			? $status_result['response']
			: array();

		$paid = $this->fonepay_response_string(
			$response,
			array( 'totalTransactionAmount', 'requestedAmount' )
		);
		if ( '' === $paid ) {
			return null;
		}

		$paid_npr = $this->parse_fonepay_amount_npr( $paid );
		if ( null === $paid_npr ) {
			return null;
		}

		return (int) round( $paid_npr * 100 ) === (int) round( (float) $order['total'] * 100 );
	}

	/**
	 * Parse a Fonepay amount string into NPR as a float.
	 *
	 * Accepts documented values like "100.00" and strips common noise (commas, currency labels).
	 *
	 * @param string $raw Raw amount string from the status API.
	 * @return float|null Parsed NPR amount, or null if unusable.
	 */
	protected function parse_fonepay_amount_npr( $raw ) {
		$normalized = preg_replace( '/[^0-9.]/', '', (string) $raw );
		if ( null === $normalized || '' === $normalized || '.' === $normalized ) {
			return null;
		}

		// Reject multiple decimal points (e.g. mangled "1.234.50").
		if ( substr_count( $normalized, '.' ) > 1 ) {
			return null;
		}

		return (float) $normalized;
	}

	/**
	 * Build CampTix payment_result data from a Fonepay status lookup.
	 *
	 * @param string $reference_label Fonepay billId / referenceLabel.
	 * @param array  $status_result   Result from get_payment_status().
	 * @return array
	 */
	protected function build_payment_data( $reference_label, array $status_result ) {
		$response = isset( $status_result['response'] ) && is_array( $status_result['response'] )
			? $status_result['response']
			: array();

		$details = $this->structure_fonepay_status_response( $response, $reference_label );

		return array(
			// Always our Fonepay reference — generated at checkout and used for status lookups.
			'transaction_id'      => $reference_label,
			'transaction_details' => $details,
		);
	}

	/**
	 * Map a Fonepay status API body into a stable attendee transaction_details shape.
	 *
	 * @param array  $response        Raw decoded status API body.
	 * @param string $reference_label Reference used for the lookup.
	 * @return array
	 */
	protected function structure_fonepay_status_response( array $response, $reference_label ) {
		$reference_label = sanitize_text_field( (string) $reference_label );

		return array(
			'reference_label'          => $reference_label,
			'prn'                      => $this->fonepay_response_string( $response, array( 'prn', 'referenceLabel' ), $reference_label ),
			'merchant_code'            => $this->fonepay_response_string( $response, array( 'merchantCode' ) ),
			'payment_status'           => $this->fonepay_response_string( $response, array( 'paymentStatus' ) ),
			'requested_amount'         => $this->fonepay_response_string( $response, array( 'requestedAmount' ) ),
			'total_transaction_amount' => $this->fonepay_response_string( $response, array( 'totalTransactionAmount' ) ),
			'payment_message'          => $this->fonepay_response_string( $response, array( 'paymentMessage' ) ),
			'fonepay_trace_id'         => $this->fonepay_response_string( $response, array( 'fonepayTraceId' ) ),
			'terminal_id'              => isset( $this->options['terminal_id'] )
				? sanitize_text_field( (string) $this->options['terminal_id'] )
				: '',
		);
	}

	/**
	 * Read the first non-empty string field from a Fonepay API response.
	 *
	 * @param array  $response Response body.
	 * @param array  $keys     Candidate keys in preference order.
	 * @param string $fallback Fallback when none are set.
	 * @return string
	 */
	protected function fonepay_response_string( array $response, array $keys, $fallback = '' ) {
		foreach ( $keys as $key ) {
			if ( ! array_key_exists( $key, $response ) || null === $response[ $key ] || '' === $response[ $key ] ) {
				continue;
			}

			return sanitize_text_field( (string) $response[ $key ] );
		}

		return sanitize_text_field( (string) $fallback );
	}

	/**
	 * Build Fonepay billId / referenceLabel from optional event prefix + payment token.
	 *
	 * Fonepay expects alphanumeric IDs (no separators). Example:
	 * prefix KTM26 + token a1b2c3… → KTM26A1B2C3D4E5F67890 (≤ 20 chars).
	 *
	 * @param string $payment_token CampTix payment token.
	 * @return string
	 */
	protected function get_fonepay_reference_label( $payment_token ) {
		$prefix = $this->sanitize_fonepay_ref_code(
			isset( $this->options['ref_code'] ) ? $this->options['ref_code'] : ''
		);

		$token_part = preg_replace( '/[^A-Za-z0-9]/', '', (string) $payment_token );
		if ( empty( $token_part ) && empty( $prefix ) ) {
			return '';
		}

		$remaining = self::REFERENCE_LABEL_MAX_LENGTH - strlen( $prefix );
		if ( $remaining < 1 ) {
			return substr( $prefix, 0, self::REFERENCE_LABEL_MAX_LENGTH );
		}

		return $prefix . substr( $token_part, 0, $remaining );
	}

	/**
	 * Sanitize a per-event reference prefix (alphanumeric, uppercase, length-capped).
	 *
	 * @param string $ref_code Raw setting value.
	 * @return string
	 */
	protected function sanitize_fonepay_ref_code( $ref_code ) {
		$ref_code = strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', (string) $ref_code ) );

		return substr( $ref_code, 0, self::FONEPAY_PREFIX_MAX_LENGTH );
	}

	/**
	 * QR text payload from a generate-intent-qr response.
	 *
	 * @param array $qr_response API response.
	 * @return string
	 */
	protected function extract_qr_payload( array $qr_response ) {
		if ( ! empty( $qr_response['qrString'] ) ) {
			return (string) $qr_response['qrString'];
		}
		if ( ! empty( $qr_response['qrMessage'] ) ) {
			return (string) $qr_response['qrMessage'];
		}

		return '';
	}

	/**
	 * Extract the WebSocket URL from a generate-intent-QR response.
	 *
	 * @param array $qr_response API response.
	 * @return string
	 */
	protected function extract_websocket_id( array $qr_response ) {
		if ( ! empty( $qr_response['websocketId'] ) ) {
			return (string) $qr_response['websocketId'];
		}
		if ( ! empty( $qr_response['webSocketId'] ) ) {
			return (string) $qr_response['webSocketId'];
		}

		return '';
	}

	/**
	 * Create a key store for the configured private key.
	 *
	 * @return CampTix_Fonepay_Key_Store
	 */
	protected function get_key_store() {
		$stored_key = isset( $this->options['private_key'] ) ? $this->options['private_key'] : '';

		return new CampTix_Fonepay_Key_Store( $stored_key, array( $this, 'log' ) );
	}

	/**
	 * Create an API client with the current gateway options.
	 *
	 * @return CampTix_Fonepay_Api_Client
	 */
	protected function get_api_client() {
		return new CampTix_Fonepay_Api_Client( $this->options, $this->get_key_store(), array( $this, 'log' ) );
	}
}
