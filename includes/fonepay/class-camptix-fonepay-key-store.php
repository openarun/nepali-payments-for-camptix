<?php
/**
 * Fonepay private key storage and encryption.
 *
 * @package CampTix_Nepali_Payments
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Encrypts, decrypts, and retrieves the Fonepay RSA private key.
 */
class CampTix_Fonepay_Key_Store {

	/**
	 * Prefix for encrypted private keys stored in the database.
	 *
	 * @var string
	 */
	const PRIVATE_KEY_ENCRYPTED_PREFIX = 'enc:v1:';

	/**
	 * Stored private key value (encrypted or legacy plaintext).
	 *
	 * @var string
	 */
	protected $stored_key = '';

	/**
	 * Optional logging callback.
	 *
	 * @var callable|null
	 */
	protected $logger;

	/**
	 * Constructor.
	 *
	 * @param string        $stored_key Stored private key option value.
	 * @param callable|null $logger     Receives a single log message string.
	 */
	public function __construct( $stored_key, $logger = null ) {
		$this->stored_key = is_string( $stored_key ) ? $stored_key : '';
		$this->logger     = is_callable( $logger ) ? $logger : null;
	}

	/**
	 * Whether a private key value is stored (encrypted or legacy plaintext).
	 *
	 * @return bool
	 */
	public function has_stored_private_key() {
		return ! empty( $this->stored_key );
	}

	/**
	 * Derive the encryption key from WordPress salts (not stored in the database).
	 *
	 * @return string 32-byte key for AES-256.
	 */
	protected function get_private_key_encryption_key() {
		return hash( 'sha256', wp_salt( 'auth' ) . wp_salt( 'secure_auth' ) . 'camptix_fonepay_private_key', true );
	}

	/**
	 * Encrypt a private key for storage.
	 *
	 * @param string $plain_key PEM or base64 private key.
	 * @return string|false Encrypted payload or false on failure.
	 */
	public function encrypt_private_key( $plain_key ) {
		$plain_key = trim( $plain_key );
		if ( '' === $plain_key ) {
			return false;
		}

		$encryption_key = $this->get_private_key_encryption_key();
		$iv             = random_bytes( 12 );
		$tag            = '';
		$ciphertext     = openssl_encrypt( $plain_key, 'aes-256-gcm', $encryption_key, OPENSSL_RAW_DATA, $iv, $tag );

		if ( false === $ciphertext ) {
			$this->log( 'Fonepay: failed to encrypt private key for storage.' );
			return false;
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Encodes AES-GCM ciphertext for option storage.
		return self::PRIVATE_KEY_ENCRYPTED_PREFIX . base64_encode( $iv . $tag . $ciphertext );
	}

	/**
	 * Decrypt a stored private key.
	 *
	 * @param string $stored_key Encrypted or legacy plaintext value.
	 * @return string|false Plain key on success, false on failure.
	 */
	public function decrypt_private_key( $stored_key ) {
		if ( 0 !== strpos( $stored_key, self::PRIVATE_KEY_ENCRYPTED_PREFIX ) ) {
			// Legacy plaintext value saved before encryption was added.
			return $stored_key;
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decodes AES-GCM ciphertext from option storage.
		$payload = base64_decode( substr( $stored_key, strlen( self::PRIVATE_KEY_ENCRYPTED_PREFIX ) ), true );
		if ( false === $payload || strlen( $payload ) < 29 ) {
			$this->log( 'Fonepay: encrypted private key payload is invalid.' );
			return false;
		}

		$iv         = substr( $payload, 0, 12 );
		$tag        = substr( $payload, 12, 16 );
		$ciphertext = substr( $payload, 28 );
		$plain_key  = openssl_decrypt( $ciphertext, 'aes-256-gcm', $this->get_private_key_encryption_key(), OPENSSL_RAW_DATA, $iv, $tag );

		if ( false === $plain_key ) {
			$this->log( 'Fonepay: failed to decrypt stored private key.' );
			return false;
		}

		return $plain_key;
	}

	/**
	 * Retrieve the private key for signing (decrypted when stored encrypted).
	 *
	 * @return string
	 */
	public function get_private_key() {
		if ( ! $this->has_stored_private_key() ) {
			return '';
		}

		$plain_key = $this->decrypt_private_key( $this->stored_key );

		return is_string( $plain_key ) ? $plain_key : '';
	}

	/**
	 * Forward a log message to the injected logger when present.
	 *
	 * @param string $message Log message.
	 * @return void
	 */
	protected function log( $message ) {
		if ( null !== $this->logger ) {
			call_user_func( $this->logger, $message );
		}
	}
}
