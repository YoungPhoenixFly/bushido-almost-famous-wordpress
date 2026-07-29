<?php
/**
 * API key encryption and decryption using WordPress SALT keys.
 *
 * @package AlmostFamous
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace AlmostFamous\Api;

/**
 * Handles secure storage and retrieval of Bushido API key.
 *
 * Uses versioned AES-256-GCM authenticated encryption with WordPress
 * AUTH_KEY to protect API keys at rest. Readable legacy AES-256-CBC values
 * are migrated in place when the site key is next read.
 */
class Api_Auth {

	/**
	 * Encryption cipher.
	 *
	 * @var string
	 */
	private const CIPHER = 'aes-256-gcm';

	/**
	 * Legacy unauthenticated cipher used before version 2.
	 *
	 * @var string
	 */
	private const LEGACY_CIPHER = 'aes-256-cbc';

	/**
	 * Prefix identifying authenticated ciphertext.
	 *
	 * @var string
	 */
	private const CIPHERTEXT_PREFIX = 'v2:';

	/**
	 * AES-GCM authentication tag length.
	 */
	private const TAG_LENGTH = 16;

	/**
	 * WordPress option key for the encrypted API key.
	 *
	 * @var string
	 */
	private const OPTION_KEY = 'af_api_key';

	/**
	 * In-memory plaintext key override. When set (via with_plaintext_key()),
	 * decrypt_api_key() returns this directly and never touches wp_options.
	 * Used by Multisite\Site_Manager so it can hand the API client a network
	 * key without rewriting the site-level option.
	 *
	 * @var string|null
	 */
	private ?string $override_plaintext_key = null;

	/**
	 * Build an Api_Auth that always returns the given plaintext key when
	 * decrypt_api_key() is called, regardless of the af_api_key option.
	 *
	 * @param string $plaintext Decrypted key to pin.
	 * @return self
	 */
	public static function with_plaintext_key( string $plaintext ): self {
		$instance                         = new self();
		$instance->override_plaintext_key = $plaintext;
		return $instance;
	}

	/**
	 * Encrypt a plaintext value for storage.
	 *
	 * Uses a random IV and authentication tag prepended to the ciphertext.
	 *
	 * @param string $plaintext The plaintext value to encrypt.
	 * @return string Versioned, base64-encoded IV + tag + ciphertext, or empty string on failure.
	 */
	public function encrypt( string $plaintext ): string {
		if ( empty( $plaintext ) ) {
			return '';
		}

		$encryption_key = $this->get_encryption_key();

		if ( '' === $encryption_key ) {
			return '';
		}

		$iv_length = openssl_cipher_iv_length( self::CIPHER );
		try {
			$iv = $this->generate_random_bytes( $iv_length );
		} catch ( \Throwable ) {
			return '';
		}
		$tag       = '';
		$encrypted = openssl_encrypt(
			$plaintext,
			self::CIPHER,
			$encryption_key,
			OPENSSL_RAW_DATA,
			$iv,
			$tag,
			'',
			self::TAG_LENGTH
		);

		if ( false === $encrypted || self::TAG_LENGTH !== strlen( $tag ) ) {
			return '';
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		return self::CIPHERTEXT_PREFIX . base64_encode( $iv . $tag . $encrypted );
	}

	/**
	 * Generate cryptographic randomness behind an overridable test seam.
	 *
	 * @param int $length Requested byte length.
	 * @return string Random bytes.
	 */
	protected function generate_random_bytes( int $length ): string {
		return random_bytes( $length );
	}

	/**
	 * Decrypt a value that was encrypted with encrypt().
	 *
	 * Version 2 values are authenticated before plaintext is returned.
	 * Unprefixed values are treated as legacy CBC ciphertext for migration.
	 *
	 * @param string $encrypted_value The base64-encoded IV + ciphertext.
	 * @return string The decrypted plaintext, or empty string on failure.
	 */
	public function decrypt( string $encrypted_value ): string {
		if ( empty( $encrypted_value ) ) {
			return '';
		}

		$encryption_key = $this->get_encryption_key();

		if ( '' === $encryption_key ) {
			return '';
		}

		if ( str_starts_with( $encrypted_value, self::CIPHERTEXT_PREFIX ) ) {
			return $this->decrypt_authenticated( substr( $encrypted_value, strlen( self::CIPHERTEXT_PREFIX ) ), $encryption_key );
		}

		// A version marker must never fall through to unauthenticated legacy
		// decryption. This makes malformed or future-version ciphertext fail
		// closed instead of being interpreted as CBC bytes.
		if ( preg_match( '/^v[0-9]+:/', $encrypted_value ) ) {
			return '';
		}

		return $this->decrypt_legacy( $encrypted_value, $encryption_key );
	}

	/**
	 * Decrypt authenticated version 2 ciphertext.
	 *
	 * @param string $encoded        Base64-encoded IV + tag + ciphertext.
	 * @param string $encryption_key Encryption key.
	 * @return string Plaintext or empty string when authentication fails.
	 */
	private function decrypt_authenticated( string $encoded, string $encryption_key ): string {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		$decoded = base64_decode( $encoded, true );

		if ( false === $decoded ) {
			return '';
		}

		$iv_length = openssl_cipher_iv_length( self::CIPHER );

		if ( strlen( $decoded ) <= $iv_length + self::TAG_LENGTH ) {
			return '';
		}

		$iv         = substr( $decoded, 0, $iv_length );
		$tag        = substr( $decoded, $iv_length, self::TAG_LENGTH );
		$ciphertext = substr( $decoded, $iv_length + self::TAG_LENGTH );
		$decrypted  = openssl_decrypt(
			$ciphertext,
			self::CIPHER,
			$encryption_key,
			OPENSSL_RAW_DATA,
			$iv,
			$tag
		);

		return ( false === $decrypted ) ? '' : $decrypted;
	}

	/**
	 * Decrypt an unprefixed legacy AES-256-CBC value.
	 *
	 * @param string $encoded        Base64-encoded IV + ciphertext.
	 * @param string $encryption_key Encryption key.
	 * @return string Plaintext or empty string on failure.
	 */
	private function decrypt_legacy( string $encoded, string $encryption_key ): string {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		$decoded = base64_decode( $encoded, true );

		if ( false === $decoded ) {
			return '';
		}

		$iv_length = openssl_cipher_iv_length( self::LEGACY_CIPHER );
		if ( strlen( $decoded ) <= $iv_length ) {
			return '';
		}

		$iv         = substr( $decoded, 0, $iv_length );
		$ciphertext = substr( $decoded, $iv_length );
		$decrypted  = openssl_decrypt( $ciphertext, self::LEGACY_CIPHER, $encryption_key, OPENSSL_RAW_DATA, $iv );

		return ( false === $decrypted ) ? '' : $decrypted;
	}

	/**
	 * Encrypt an API key for storage.
	 *
	 * @param string $key The plaintext API key.
	 * @return string The encrypted, base64-encoded key.
	 */
	public function encrypt_api_key( string $key ): string {
		return $this->encrypt( $key );
	}

	/**
	 * Decrypt the stored API key.
	 *
	 * @return string The decrypted API key, or empty string if not set.
	 */
	public function decrypt_api_key(): string {
		if ( null !== $this->override_plaintext_key ) {
			return $this->override_plaintext_key;
		}

		$stored = get_option( self::OPTION_KEY, '' );

		if ( empty( $stored ) ) {
			return '';
		}

		$plaintext = $this->decrypt( $stored );

		if ( '' !== $plaintext && ! str_starts_with( $stored, self::CIPHERTEXT_PREFIX ) ) {
			$this->migrate_legacy_api_key( $stored, $plaintext );
		}

		return $plaintext;
	}

	/**
	 * Replace readable legacy ciphertext only after the authenticated value can
	 * be read back. A failed or corrupted write restores the original value.
	 *
	 * @param string $legacy_ciphertext Original stored ciphertext.
	 * @param string $plaintext         Decrypted API key.
	 * @return void
	 */
	private function migrate_legacy_api_key( string $legacy_ciphertext, string $plaintext ): void {
		$migrated = $this->encrypt_api_key( $plaintext );
		if ( '' === $migrated ) {
			return;
		}

		update_option( self::OPTION_KEY, $migrated );
		$stored = (string) get_option( self::OPTION_KEY, '' );
		if (
			hash_equals( $migrated, $stored )
			&& hash_equals( $plaintext, $this->decrypt( $stored ) )
		) {
			return;
		}

		update_option( self::OPTION_KEY, $legacy_ciphertext );
	}

	/**
	 * Encrypt and store an API key.
	 *
	 * @param string $key The plaintext API key to store.
	 * @return bool True on success, false on failure.
	 */
	public function store_api_key( string $key ): bool {
		$encrypted = $this->encrypt_api_key( $key );

		if ( empty( $encrypted ) && ! empty( $key ) ) {
			return false;
		}

		if ( update_option( self::OPTION_KEY, $encrypted ) ) {
			return true;
		}

		// update_option() also returns false when no database change was
		// required. Verify what is actually persisted before reporting a
		// storage failure to the connection flow.
		$stored = (string) get_option( self::OPTION_KEY, '' );
		$actual = $this->decrypt( $stored );
		return '' !== $actual && hash_equals( $key, $actual );
	}

	/**
	 * Check if an API key is stored.
	 *
	 * @return bool True if a non-empty API key exists.
	 */
	public function has_api_key(): bool {
		return ! empty( get_option( self::OPTION_KEY, '' ) );
	}

	/**
	 * Delete the stored API key.
	 *
	 * @return bool True on success, false on failure.
	 */
	public function delete_api_key(): bool {
		return delete_option( self::OPTION_KEY );
	}

	/**
	 * Get the encryption key derived from WordPress AUTH_KEY.
	 *
	 * Returns empty string and triggers a warning if AUTH_KEY is not defined,
	 * since a hardcoded fallback would be insecure.
	 *
	 * @return string The encryption key, or empty string if not available.
	 */
	private function get_encryption_key(): string {
		if ( ! defined( 'AUTH_KEY' ) || '' === AUTH_KEY || 'put your unique phrase here' === AUTH_KEY ) {
			_doing_it_wrong(
				__METHOD__,
				esc_html__( 'AUTH_KEY is not defined in wp-config.php. API key encryption is unavailable.', 'bushido-almost-famous' ),
				'1.0.0'
			);
			return '';
		}

		return AUTH_KEY;
	}
}
