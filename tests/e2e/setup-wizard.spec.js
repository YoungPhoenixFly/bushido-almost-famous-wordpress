const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const { execFileSync } = require( 'node:child_process' );

// Helper: invoke wp-cli inside the wp-env tests container via npx wp-env.
const wpCli = ( ...args ) =>
	execFileSync(
		'npx',
		[ 'wp-env', 'run', 'tests-cli', 'wp', ...args, '--quiet' ],
		{
			stdio: 'pipe',
		}
	).toString();

test.describe( 'Setup Wizard', () => {
	test.beforeAll( () => {
		try {
			wpCli( 'plugin', 'activate', 'bushido-almost-famous' );
		} catch ( _ ) {}
		wpCli( 'eval', 'af_e2e_reset_connection();' );
		wpCli( 'plugin', 'deactivate', 'bushido-almost-famous' );
		wpCli( 'plugin', 'activate', 'bushido-almost-famous' );
	} );

	test.afterAll( () => {
		wpCli( 'eval', 'af_e2e_seed_connected_site("agency");' );
	} );

	test( 'auto-redirects to wizard, walks all 3 steps, then renders dashboard', async ( {
		admin,
		page,
	} ) => {
		// Step 1 — landing on wp-admin triggers the one-shot redirect transient.
		await admin.visitAdminPage( 'index.php' );
		await page.waitForURL( /page=af-setup-wizard/ );
		await expect(
			page.getByRole( 'heading', {
				name: /Bushido Almost Famous Setup/i,
			} )
		).toBeVisible();
		await expect( page.getByText( /SSL is active/i ) ).toBeVisible();

		// Continue to Step 2.
		await page
			.getByRole( 'link', { name: /Continue to API Setup/i } )
			.click();
		await page.waitForURL( /step=2/ );
		await page
			.getByText( 'I already have an API key', { exact: true } )
			.click();
		await page
			.locator( '#af_api_key' )
			.fill( 'bsh_live_e2e_test_key_xxxxxx' );
		await page.getByRole( 'button', { name: /Save & Verify/i } ).click();

		// Step 3 — confirmation, then complete.
		await page.waitForURL( /step=3/ );
		await expect(
			page.getByRole( 'heading', { name: /Connection Successful/i } )
		).toBeVisible();
		await page.getByRole( 'button', { name: /Go to Dashboard/i } ).click();

		// Dashboard with no wizard banner.
		await page.waitForURL( /page=bushido-almost-famous/ );
		await expect(
			page.getByRole( 'heading', { name: /^Bushido Almost Famous$/i } )
		).toBeVisible();
		await expect( page.locator( '.af-setup-wizard' ) ).toHaveCount( 0 );
	} );
} );
