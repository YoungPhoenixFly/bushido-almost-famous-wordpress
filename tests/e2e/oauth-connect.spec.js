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

test.describe( 'OAuth Connect — Meta', () => {
	test.beforeAll( async ( { requestUtils } ) => {
		await requestUtils.activatePlugin( 'bushido-almost-famous' );
		wpCli( 'eval', 'af_e2e_seed_connected_site("own");' );
	} );

	test( 'Connect Meta button starts OAuth and renders the Connected banner', async ( {
		admin,
		page,
	} ) => {
		// The plugin's REST start endpoint normally bounces through the backend.
		// We intercept it to keep the redirect inside wp-env.
		const bounceUrl =
			'http://localhost:8992/wp-admin/admin.php?page=af-accounts&af_connected=meta';

		await page.route( '**/almost-famous/v1/oauth/start', ( route ) =>
			route.fulfill( {
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify( { authorizationUrl: bounceUrl } ),
			} )
		);

		// Visit the Accounts page.
		await admin.visitAdminPage( 'admin.php?page=af-accounts' );
		await expect( page.getByLabel( 'My own ad accounts' ) ).toBeChecked();

		// Click the Connect button for Meta.
		const connectMeta = page
			.locator( 'tr[data-platform="meta"]' )
			.getByRole( 'button', { name: /^Connect$/ } );
		await expect( connectMeta ).toBeVisible();

		await Promise.all( [
			page.waitForURL( /af_connected=meta/ ),
			connectMeta.click(),
		] );

		// Connected banner should appear on the bounce-back URL.
		await expect(
			page.getByText( /Meta connected successfully/i )
		).toBeVisible();
	} );
} );
