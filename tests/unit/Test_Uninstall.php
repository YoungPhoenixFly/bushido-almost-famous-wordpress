<?php
/**
 * Tests for `uninstall.php`: option, transient, and role removal when invoked
 * by WordPress through `WP_UNINSTALL_PLUGIN`, plus the early-return guard when
 * the constant is missing.
 *
 * The uninstall script terminates the request with `exit;` when invoked
 * without the constant set, which is incompatible with PHPUnit's
 * `@runInSeparateProcess` machinery. We therefore spawn the runner script as
 * a child PHP process and read the resulting state from its STDOUT.
 *
 * @package AlmostFamous
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Test uninstall.php lifecycle.
 */
class Test_Uninstall extends TestCase {

	/**
	 * Invoke the runner with the given inputs and decode the final state it
	 * emits via a shutdown handler.
	 *
	 * @param bool                $define_constant Whether to set WP_UNINSTALL_PLUGIN.
	 * @param array<string,mixed> $options         Initial options state.
	 * @param array<int,string>   $roles           Initial role slugs.
	 * @return array{options:array<string,mixed>,roles:array<int,string>}
	 */
	private function run_uninstall( bool $define_constant, array $options, array $roles ): array {
		$runner = __DIR__ . '/../fixtures/uninstall-runner.php';
		$env    = array(
			'AF_UNINSTALL_DEFINE' => $define_constant ? '1' : '0',
			'AF_UNINSTALL_STATE'  => (string) json_encode(
				array(
					'options' => $options,
					'roles'   => $roles,
				)
			),
			'PATH'                => getenv( 'PATH' ) ?: '/usr/bin:/bin',
		);

		$process = proc_open(
			array( PHP_BINARY, $runner ),
			array(
				1 => array( 'pipe', 'w' ),
				2 => array( 'pipe', 'w' ),
			),
			$pipes,
			null,
			$env
		);

		$this->assertIsResource( $process );
		$stdout = stream_get_contents( $pipes[1] );
		$stderr = stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		$exit_code = proc_close( $process );

		$this->assertSame( 0, $exit_code, "Runner failed: $stderr" );
		$this->assertNotFalse( $stdout );

		if ( false === preg_match( '/__AF_UNINSTALL_RESULT__:(.*)$/m', $stdout, $matches ) || empty( $matches[1] ) ) {
			$this->fail( "Could not find result marker in runner output: $stdout" );
		}

		$decoded = json_decode( trim( $matches[1] ), true );
		$this->assertIsArray( $decoded );
		$this->assertArrayHasKey( 'options', $decoded );
		$this->assertArrayHasKey( 'roles', $decoded );

		return $decoded;
	}

	/**
	 * Without WP_UNINSTALL_PLUGIN defined, the script exits immediately and
	 * does not mutate any state.
	 *
	 * @return void
	 */
	public function test_uninstall_no_constant_is_a_noop(): void {
		$initial_options = array(
			'af_api_key'                => 'secret',
			'af_settings'               => array( 'cache_ttl_active' => 60 ),
			'af_role_mapping'           => array( 'administrator' => 'bushido_admin' ),
			'_transient_af_campaigns'   => array( 'id' => 1 ),
			'_transient_timeout_af_x'   => 9999,
			'_transient_other'          => 'keep',
		);
		$initial_roles = array( 'bushido_admin', 'administrator' );

		$result = $this->run_uninstall( false, $initial_options, $initial_roles );

		$this->assertSame( $initial_options, $result['options'] );
		$this->assertSame( $initial_roles, $result['roles'] );
	}

	/**
	 * With WP_UNINSTALL_PLUGIN defined, every `af_*` option is removed.
	 *
	 * @return void
	 */
	public function test_uninstall_removes_af_options(): void {
		$result = $this->run_uninstall(
			true,
			array(
				'af_api_key'      => 'secret',
				'af_settings'     => array( 'x' => 1 ),
				'af_role_mapping' => array( 'administrator' => 'bushido_admin' ),
				'other_plugin'    => 'keep',
			),
			array( 'bushido_admin' )
		);

		$this->assertArrayNotHasKey( 'af_api_key', $result['options'] );
		$this->assertArrayNotHasKey( 'af_settings', $result['options'] );
		$this->assertArrayNotHasKey( 'af_role_mapping', $result['options'] );
		$this->assertArrayHasKey( 'other_plugin', $result['options'] );
	}

	/**
	 * With WP_UNINSTALL_PLUGIN defined, every `_transient_af_*` and
	 * `_transient_timeout_af_*` row is removed.
	 *
	 * @return void
	 */
	public function test_uninstall_removes_af_transients(): void {
		$result = $this->run_uninstall(
			true,
			array(
				'_transient_af_campaigns'         => array( 'id' => 1 ),
				'_transient_timeout_af_campaigns' => time() + 60,
				'_transient_af_analytics'         => array(),
				'_transient_other'                => 'keep',
			),
			array()
		);

		$this->assertArrayNotHasKey( '_transient_af_campaigns', $result['options'] );
		$this->assertArrayNotHasKey( '_transient_timeout_af_campaigns', $result['options'] );
		$this->assertArrayNotHasKey( '_transient_af_analytics', $result['options'] );
		$this->assertArrayHasKey( '_transient_other', $result['options'] );
	}

	/**
	 * The `bushido_admin` role is removed from the in-memory role registry.
	 *
	 * @return void
	 */
	public function test_uninstall_removes_bushido_admin_role(): void {
		$result = $this->run_uninstall(
			true,
			array(),
			array( 'bushido_admin', 'administrator', 'editor' )
		);

		$this->assertNotContains( 'bushido_admin', $result['roles'] );
		$this->assertContains( 'administrator', $result['roles'] );
		$this->assertContains( 'editor', $result['roles'] );
	}

	/**
	 * Sanity check: when no plugin state exists, uninstall still completes
	 * cleanly under the WP_UNINSTALL_PLUGIN guard.
	 *
	 * @return void
	 */
	public function test_uninstall_is_safe_with_empty_state(): void {
		$result = $this->run_uninstall( true, array(), array() );

		$this->assertSame( array(), $result['options'] );
		$this->assertSame( array(), $result['roles'] );
	}
}
