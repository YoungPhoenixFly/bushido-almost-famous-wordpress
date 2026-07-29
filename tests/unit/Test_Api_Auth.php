<?php
/**
 * Tests for Api_Auth encryption and decryption.
 *
 * @package AlmostFamous
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

use AlmostFamous\Api\Api_Auth;
use PHPUnit\Framework\TestCase;

/**
 * Test Api_Auth encrypt/decrypt functionality.
 */
class Test_Api_Auth extends TestCase {

	/**
	 * Random source failures fail closed without escaping encryption.
	 *
	 * @return void
	 */
	public function test_randomness_failure_fails_closed_without_throwing(): void {
		$auth = new class() extends Api_Auth {
			protected function generate_random_bytes( int $length ): string {
				unset( $length );
				throw new RuntimeException( 'randomness unavailable' );
			}
		};

		$this->assertSame( '', $auth->encrypt( 'af_secret' ) );
		$this->assertFalse( $auth->store_api_key( 'af_secret' ) );
	}

	/**
	 * Api_Auth instance.
	 *
	 * @var Api_Auth
	 */
	private Api_Auth $auth;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		af_test_reset();
		$this->auth = new Api_Auth();
	}

	/**
	 * Test encrypt/decrypt roundtrip produces original key.
	 *
	 * @return void
	 */
	public function test_encrypt_decrypt_roundtrip(): void {
		$original  = 'bsh_test_api_key_12345';
		$encrypted = $this->auth->encrypt_api_key( $original );
		$decrypted = $this->auth->decrypt( $encrypted );

		$this->assertNotEmpty( $encrypted );
		$this->assertNotEquals( $original, $encrypted );
		$this->assertStringStartsWith( 'v2:', $encrypted );
		$this->assertSame( $original, $decrypted );
	}

	/**
	 * Test empty key returns empty string.
	 *
	 * @return void
	 */
	public function test_encrypt_empty_key_returns_empty(): void {
		$result = $this->auth->encrypt_api_key( '' );
		$this->assertSame( '', $result );
	}

	/**
	 * Test encryption produces different output for different keys.
	 *
	 * @return void
	 */
	public function test_different_keys_produce_different_output(): void {
		$encrypted1 = $this->auth->encrypt_api_key( 'key_one' );
		$encrypted2 = $this->auth->encrypt_api_key( 'key_two' );

		$this->assertNotEquals( $encrypted1, $encrypted2 );
	}

	/**
	 * Test encryption is intentionally non-deterministic because of random IVs.
	 *
	 * @return void
	 */
	public function test_encryption_is_non_deterministic(): void {
		$key        = 'bsh_deterministic_test';
		$encrypted1 = $this->auth->encrypt_api_key( $key );
		$encrypted2 = $this->auth->encrypt_api_key( $key );

		$this->assertNotSame( $encrypted1, $encrypted2 );
	}

	/**
	 * Authenticated ciphertext must fail closed after any modification.
	 *
	 * @return void
	 */
	public function test_tampered_authenticated_ciphertext_is_rejected(): void {
		$encrypted = $this->auth->encrypt_api_key( 'bsh_tamper_test' );
		$decoded   = base64_decode( substr( $encrypted, 3 ), true );

		$this->assertIsString( $decoded );
		$last                    = strlen( $decoded ) - 1;
		$decoded[ $last ]        = chr( ord( $decoded[ $last ] ) ^ 1 );
		$tampered                = 'v2:' . base64_encode( $decoded );

		$this->assertSame( '', $this->auth->decrypt( $tampered ) );
	}

	/**
	 * Readable legacy CBC site keys are rewritten as authenticated v2 data.
	 *
	 * @return void
	 */
	public function test_decrypt_api_key_migrates_legacy_cbc_value(): void {
		$legacy = $this->encrypt_legacy( 'bsh_legacy_key' );
		update_option( 'af_api_key', $legacy );

		$this->assertSame( 'bsh_legacy_key', $this->auth->decrypt_api_key() );

		$migrated = (string) get_option( 'af_api_key', '' );
		$this->assertStringStartsWith( 'v2:', $migrated );
		$this->assertSame( 'bsh_legacy_key', $this->auth->decrypt( $migrated ) );
	}

	/**
	 * A failed migration write must leave the readable legacy key intact.
	 *
	 * @return void
	 */
	public function test_decrypt_api_key_preserves_legacy_value_when_migration_write_fails(): void {
		$legacy = $this->encrypt_legacy( 'bsh_legacy_survivor' );
		update_option( 'af_api_key', $legacy );
		af_test_fail_option_updates( 'af_api_key' );

		$this->assertSame( 'bsh_legacy_survivor', $this->auth->decrypt_api_key() );
		$this->assertSame( $legacy, get_option( 'af_api_key', '' ) );
		$this->assertSame( 'bsh_legacy_survivor', $this->auth->decrypt_api_key() );
	}

	/**
	 * Build a ciphertext in the pre-v2 storage format.
	 *
	 * @param string $plaintext Plaintext fixture.
	 * @return string Legacy base64 ciphertext.
	 */
	private function encrypt_legacy( string $plaintext ): string {
		$iv         = random_bytes( openssl_cipher_iv_length( 'aes-256-cbc' ) );
		$ciphertext = openssl_encrypt( $plaintext, 'aes-256-cbc', AUTH_KEY, OPENSSL_RAW_DATA, $iv );

		$this->assertIsString( $ciphertext );
		return base64_encode( $iv . $ciphertext );
	}
}
