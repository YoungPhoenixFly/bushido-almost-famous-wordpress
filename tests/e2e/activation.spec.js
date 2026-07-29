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

test.describe( 'Bushido Almost Famous Plugin', () => {
	test.beforeAll( async ( { requestUtils } ) => {
		await requestUtils.activatePlugin( 'bushido-almost-famous' );
		wpCli( 'eval', 'af_e2e_reset_connection();' );
	} );

	test.afterAll( async ( { requestUtils } ) => {
		await requestUtils.deactivatePlugin( 'bushido-almost-famous' );
	} );

	test( 'should render the plugin settings page without fatal errors', async ( {
		admin,
		page,
	} ) => {
		await admin.visitAdminPage( 'admin.php?page=bushido-almost-famous' );

		const heading = page.getByRole( 'heading', {
			name: /^Bushido Almost Famous$/i,
		} );
		await expect( heading ).toBeVisible();
		await expect(
			page.getByRole( 'link', { name: /Connect your Bushido account/i } )
		).toBeVisible();

		const notices = page.locator( '.notice-error' );
		await expect( notices ).toHaveCount( 0 );
	} );
} );
