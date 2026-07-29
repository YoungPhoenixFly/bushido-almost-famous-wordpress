const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const { execFileSync } = require( 'node:child_process' );

const wpCli = ( ...args ) =>
	execFileSync(
		'npx',
		[ 'wp-env', 'run', 'tests-cli', 'wp', ...args, '--quiet' ],
		{
			stdio: 'pipe',
		}
	).toString();

test.describe( 'Uninstall', () => {
	test( 'removes all af_* options and the bushido_admin role', () => {
		// Activate, then plant a couple of options so we can prove they get cleared.
		try {
			wpCli( 'plugin', 'activate', 'bushido-almost-famous' );
		} catch ( _ ) {}
		wpCli( 'option', 'update', 'af_setup_complete', '1' );
		wpCli( 'option', 'update', 'af_e2e_marker', 'present' );

		// Run uninstall.php directly after deactivation. The plugin is bind-mounted
		// in wp-env, so `wp plugin uninstall` would delete the repository source.
		wpCli( 'plugin', 'deactivate', 'bushido-almost-famous' );
		wpCli(
			'eval',
			'define("WP_UNINSTALL_PLUGIN", true); require WP_PLUGIN_DIR . "/bushido-almost-famous/uninstall.php";'
		);

		const sourceState = wpCli(
			'eval',
			'echo file_exists(WP_PLUGIN_DIR . "/bushido-almost-famous/bushido-almost-famous.php") ? "present" : "missing";'
		).trim();
		expect( sourceState ).toBe( 'present' );

		const optionsJson = wpCli(
			'option',
			'list',
			'--search=af_*',
			'--format=json'
		).trim();
		const options = optionsJson ? JSON.parse( optionsJson ) : [];
		expect( options ).toEqual( [] );

		const rolesJson = wpCli( 'role', 'list', '--format=json' ).trim();
		const roles = JSON.parse( rolesJson );
		expect(
			roles.find( ( r ) => r.role === 'bushido_admin' )
		).toBeUndefined();
	} );
} );
