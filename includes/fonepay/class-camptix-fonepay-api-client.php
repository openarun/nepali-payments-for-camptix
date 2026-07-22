<?php
/**
 * Fonepay third-party API client.
 *
 * @package CampTix_Nepali_Payments
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Auth, RSA signing, intent QR generation, and payment status for Fonepay.
 */
class CampTix_Fonepay_Api_Client {

	/**
	 * Base path shared by all third-party intent endpoints.
	 *
	 * @var string
	 */
	const API_BASE_PATH = '/api/merchant/third-party/v2';

	/**
	 * Seconds to subtract from expiresIn when caching a token.
	 *
	 * @var int
	 */
	const TOKEN_EXPIRY_BUFFER = 60;

	/**
	 * Default token cache TTL when expiresIn is missing (seconds).
	 *
	 * @var int
	 */
	const TOKEN_DEFAULT_TTL = 300;

	/**
	 * Gateway credentials and settings.
	 *
	 * @var array
	 */
	protected $options = array();

	/**
	 * Private key store for request signing.
	 *
	 * @var CampTix_Fonepay_Key_Store
	 */
	protected $key_store;

	/**
	 * Optional logging callback.
	 *
	 * @var callable|null
	 */
	protected $logger;

	/**
	 * Constructor.
	 *
	 * @param array                     $options   username, password, terminal_id, sandbox.
	 * @param CampTix_Fonepay_Key_Store $key_store Key store for signing.
	 * @param callable|null             $logger    Receives log message, optional attendee, optional data.
	 */
	public function __construct( $options, CampTix_Fonepay_Key_Store $key_store, $logger = null ) {
		$this->options   = $options;
		$this->key_store = $key_store;
		$this->logger    = is_callable( $logger ) ? $logger : null;
	}

	/**
	 * Resolve the Fonepay gateway host based on sandbox setting.
	 *
	 * @return string
	 */
	protected function api_base_url() {
		return ! empty( $this->options['sandbox'] )
			? 'https://dev-external-gateway-new.fonepay.com/merchantThirdparty'
			: 'https://thirdparty-merchantapi.fonepay.com';
	}

	/**
	 * Build a full endpoint URL under the third-party base path.
	 *
	 * @param string $endpoint Endpoint path beginning with a slash (e.g. /login).
	 * @return string
	 */
	protected function api_url( $endpoint ) {
		return $this->api_base_url() . self::API_BASE_PATH . $endpoint;
	}

	/**
	 * Transient key for the cached auth token (per sandbox + username).
	 *
	 * @return string
	 */
	protected function token_cache_key() {
		$sandbox  = ! empty( $this->options['sandbox'] ) ? '1' : '0';
		$username = isset( $this->options['username'] ) ? (string) $this->options['username'] : '';

		return 'camptix_fonepay_token_' . md5( $sandbox . '|' . $username );
	}

	/**
	 * Return a still-valid cached access token, or false.
	 *
	 * @return string|false
	 */
	protected function get_cached_access_token() {
		$cached = get_transient( $this->token_cache_key() );
		if ( ! is_array( $cached ) || empty( $cached['token'] ) ) {
			return false;
		}

		$expires_at = isset( $cached['expires_at'] ) ? (int) $cached['expires_at'] : 0;
		if ( $expires_at > 0 && time() >= $expires_at ) {
			$this->clear_cached_access_token();
			return false;
		}

		return (string) $cached['token'];
	}

	/**
	 * Persist an access token until near its expiry.
	 *
	 * @param string $token      Access token.
	 * @param int    $expires_in Lifetime in seconds from the login response.
	 * @return void
	 */
	protected function store_cached_access_token( $token, $expires_in ) {
		$expires_in = (int) $expires_in;
		if ( $expires_in <= 0 ) {
			$expires_in = self::TOKEN_DEFAULT_TTL;
		}

		$ttl = max( 30, $expires_in - self::TOKEN_EXPIRY_BUFFER );

		set_transient(
			$this->token_cache_key(),
			array(
				'token'      => $token,
				'expires_at' => time() + $ttl,
			),
			$ttl
		);
	}

	/**
	 * Drop the cached access token so the next login fetches a fresh one.
	 *
	 * @return void
	 */
	protected function clear_cached_access_token() {
		delete_transient( $this->token_cache_key() );
	}

	/**
	 * Generate a Base64-encoded RSA (SHA256) signature for a JSON request body.
	 *
	 * @param string $json_body Raw JSON request body / payload to sign.
	 * @return string|false Base64 signature on success, false on failure.
	 */
	protected function generate_signature( $json_body ) {
		$private_key = $this->key_store->get_private_key();

		if ( empty( $private_key ) ) {
			$this->log( 'Fonepay: missing client private key for signature generation.' );
			return false;
		}

		// Accept a bare base64 key body or a full PEM block.
		if ( false === strpos( $private_key, 'BEGIN' ) ) {
			$private_key = "-----BEGIN PRIVATE KEY-----\n" . chunk_split( trim( $private_key ), 64, "\n" ) . "-----END PRIVATE KEY-----\n";
		}

		$key_resource = openssl_pkey_get_private( $private_key );
		if ( false === $key_resource ) {
			$this->log( 'Fonepay: invalid client private key; unable to load for signing.' );
			return false;
		}

		$signature = '';
		$signed    = openssl_sign( $json_body, $signature, $key_resource, OPENSSL_ALGO_SHA256 );

		if ( ! $signed ) {
			$this->log( 'Fonepay: openssl_sign failed while generating signature.' );
			return false;
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Fonepay Signature header encoding.
		return base64_encode( $signature );
	}

	/**
	 * Authenticate with Fonepay and return an access token (Bearer ...).
	 *
	 * Reuses a site transient until near expiresIn to avoid /login rate limits.
	 *
	 * @return string|false Access token on success, false on failure.
	 */
	public function get_access_token() {
		$cached = $this->get_cached_access_token();
		if ( false !== $cached ) {
			return $cached;
		}

		$payload = wp_json_encode(
			array(
				'username' => $this->options['username'],
				'password' => $this->options['password'],
			)
		);

		$signature = $this->generate_signature( $payload );
		if ( false === $signature ) {
			return false;
		}

		$url = $this->api_url( '/login' );

		$remote_response = wp_remote_post(
			$url,
			array(
				'method'   => 'POST',
				'headers'  => array(
					// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- HTTP Basic auth per Fonepay login API.
					'Authorization' => 'Basic ' . base64_encode( $this->options['username'] . ':' . $this->options['password'] ),
					'Signature'     => $signature,
					'Content-Type'  => 'application/json',
				),
				'body'     => $payload,
				'timeout'  => 30,
				'blocking' => true,
			)
		);

		if ( is_wp_error( $remote_response ) ) {
			$this->log( 'Fonepay auth request failed: ' . esc_html( $remote_response->get_error_message() ) );
			return false;
		}

		$response_code = (int) wp_remote_retrieve_response_code( $remote_response );
		$result        = json_decode( wp_remote_retrieve_body( $remote_response ), true );
		if ( ! is_array( $result ) ) {
			$result = array();
		}

		if ( 429 === $response_code ) {
			$this->log(
				'Fonepay auth rate limited (HTTP 429).',
				null,
				array(
					'message'     => isset( $result['message'] ) ? $result['message'] : '',
					'retry_after' => isset( $result['retryAfter'] ) ? $result['retryAfter'] : null,
					'response'    => $result,
				)
			);
			return false;
		}

		if ( $response_code < 200 || $response_code >= 300 || empty( $result['accessToken'] ) ) {
			$this->log(
				'Fonepay auth response missing accessToken.',
				null,
				array(
					'http_status' => $response_code,
					'response'    => $result,
				)
			);
			return false;
		}

		$token = sanitize_text_field( $result['accessToken'] );
		$this->store_cached_access_token(
			$token,
			isset( $result['expiresIn'] ) ? (int) $result['expiresIn'] : 0
		);

		return $token;
	}

	/**
	 * Normalize a Bearer token to the value expected in an Authorization header.
	 *
	 * @param string $access_token Token from the auth/login response.
	 * @return string
	 */
	protected function bearer_header( $access_token ) {
		// The login response may already prefix the token with "Bearer ".
		if ( 0 === stripos( $access_token, 'Bearer ' ) ) {
			return $access_token;
		}

		return 'Bearer ' . $access_token;
	}

	/**
	 * Generate an Intent QR via the Fonepay API.
	 *
	 * @param string $access_token Access token.
	 * @param array  $payload      Request payload.
	 * @return array|false Decoded response on success, false on failure.
	 */
	public function generate_intent_qr( $access_token, $payload ) {
		$json_body = wp_json_encode( $payload );

		$signature = $this->generate_signature( $json_body );
		if ( false === $signature ) {
			return false;
		}

		$url = $this->api_url( '/generate-intent-qr' );

		$remote_response = wp_remote_post(
			$url,
			array(
				'method'   => 'POST',
				'headers'  => array(
					'Authorization' => $this->bearer_header( $access_token ),
					'Signature'     => $signature,
					'Content-Type'  => 'application/json',
				),
				'body'     => $json_body,
				'timeout'  => 30,
				'blocking' => true,
			)
		);

		if ( is_wp_error( $remote_response ) ) {
			$this->log( 'Fonepay generate-intent-qr request failed: ' . esc_html( $remote_response->get_error_message() ) );
			return false;
		}

		$response_code = (int) wp_remote_retrieve_response_code( $remote_response );
		$raw_body      = wp_remote_retrieve_body( $remote_response );
		$result        = json_decode( $raw_body, true );

		if ( 401 === $response_code ) {
			$this->clear_cached_access_token();
		}

		if ( 409 === $response_code ) {
			$this->log(
				'Fonepay generate-intent-qr rejected duplicate referenceLabel.',
				null,
				array(
					'http_status' => $response_code,
					'payload'     => $payload,
					'response'    => is_array( $result ) ? $result : $raw_body,
				)
			);
			return false;
		}

		if ( $response_code < 200 || $response_code >= 300 || ! is_array( $result ) ) {
			$this->log(
				sprintf( 'Fonepay generate-intent-qr failed with HTTP %d.', $response_code ),
				null,
				array(
					'http_status' => $response_code,
					'payload'     => $payload,
					'response'    => is_array( $result ) ? $result : $raw_body,
				)
			);
			return false;
		}

		if ( empty( $result['qrString'] ) && empty( $result['qrMessage'] ) ) {
			$this->log(
				'Fonepay generate-intent-qr response missing QR payload.',
				null,
				array(
					'http_status' => $response_code,
					'payload'     => $payload,
					'response'    => $result,
				)
			);
			return false;
		}

		return $result;
	}

	/**
	 * Query the Fonepay payment status for a reference label.
	 *
	 * @param string $access_token    Access token.
	 * @param string $reference_label Reference label (PRN) used during QR generation.
	 * @return array {
	 *     @type string $status   One of: success, pending, failed, timeout, not_found, unknown.
	 *     @type array  $response Raw decoded API body (may be empty).
	 * }
	 */
	public function get_payment_status( $access_token, $reference_label ) {
		$empty = array(
			'status'   => 'unknown',
			'response' => array(),
		);

		$payload = wp_json_encode(
			array(
				'terminalId'     => $this->options['terminal_id'],
				'referenceLabel' => $reference_label,
			)
		);

		$signature = $this->generate_signature( $payload );
		if ( false === $signature ) {
			return $empty;
		}

		$url = $this->api_url( '/thirdPartyDynamicQrGetStatus' );

		$remote_response = wp_remote_post(
			$url,
			array(
				'method'   => 'POST',
				'headers'  => array(
					'Authorization' => $this->bearer_header( $access_token ),
					'Signature'     => $signature,
					'Content-Type'  => 'application/json',
				),
				'body'     => $payload,
				'timeout'  => 30,
				'blocking' => true,
			)
		);

		if ( is_wp_error( $remote_response ) ) {
			$this->log( 'Fonepay status request failed: ' . esc_html( $remote_response->get_error_message() ) );
			return $empty;
		}

		$response_code = (int) wp_remote_retrieve_response_code( $remote_response );
		$result        = json_decode( wp_remote_retrieve_body( $remote_response ), true );
		if ( ! is_array( $result ) ) {
			$result = array();
		}

		if ( 401 === $response_code ) {
			$this->clear_cached_access_token();
			return $empty;
		}

		// Status API 409 = "Terminal detail not found" (misconfigured terminal_id).
		if ( 409 === $response_code ) {
			$this->log(
				'Fonepay status returned HTTP 409 Terminal detail not found; check terminal_id.',
				null,
				array(
					'http_status' => $response_code,
					'terminal_id' => isset( $this->options['terminal_id'] ) ? $this->options['terminal_id'] : '',
					'response'    => $result,
				)
			);
			return array(
				'status'   => 'config_error',
				'response' => $result,
			);
		}

		if ( ! isset( $result['paymentStatus'] ) ) {
			$this->log( 'Fonepay status response missing paymentStatus.', null, $result );
			return array(
				'status'   => 'unknown',
				'response' => $result,
			);
		}

		$raw_status = preg_replace( '/\s+/', ' ', strtolower( trim( (string) $result['paymentStatus'] ) ) );
		$allowed    = array( 'success', 'pending', 'failed', 'timeout' );
		$status     = in_array( $raw_status, $allowed, true ) ? $raw_status : 'unknown';

		if ( 'unknown' === $status && '' !== $raw_status ) {
			$this->log(
				sprintf( 'Fonepay returned unrecognized paymentStatus "%s".', esc_html( $raw_status ) ),
				null,
				$result
			);
		}

		return array(
			'status'   => $status,
			'response' => $result,
		);
	}

	/**
	 * Forward a log message to the injected logger when present.
	 *
	 * @param string     $message  Log message.
	 * @param mixed      $attendee Optional attendee context for CampTix log().
	 * @param array|null $data     Optional extra log data.
	 * @return void
	 */
	protected function log( $message, $attendee = null, $data = null ) {
		if ( null !== $this->logger ) {
			call_user_func( $this->logger, $message, $attendee, $data );
		}
	}
}
