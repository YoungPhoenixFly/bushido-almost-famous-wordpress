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

test.describe( 'Creative Upload', () => {
	test.beforeAll( async ( { requestUtils } ) => {
		await requestUtils.activatePlugin( 'bushido-almost-famous' );
		wpCli( 'eval', 'af_e2e_seed_connected_site("agency");' );
	} );

	test( 'uploads bytes through create, signed PUT, and confirm', async ( {
		admin,
		page,
	} ) => {
		await admin.visitAdminPage( 'admin.php?page=af-creatives' );
		await page.locator( '#af-creative-name' ).fill( 'E2E Uploaded Asset' );
		await page.locator( '#af-source-asset' ).setInputFiles( {
			name: 'e2e-upload.png',
			mimeType: 'image/png',
			buffer: Buffer.from(
				'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
				'base64'
			),
		} );
		await page.getByRole( 'button', { name: 'Upload Asset' } ).click();

		await page.waitForURL( /creative_id=cre_e2e_uploaded/ );
		await expect(
			page.getByText( 'Creative asset uploaded successfully.' )
		).toBeVisible();
		await expect(
			page.getByRole( 'heading', { name: /E2E Uploaded Asset/ } )
		).toBeVisible();
		await expect(
			page.locator(
				'[data-af-creative-id="cre_e2e_uploaded"] [data-af-creative-badge]'
			)
		).toHaveText( /Complete/i );
	} );

	test( 'processing → complete updates the badge without reload', async ( {
		admin,
		page,
	} ) => {
		const creativeId = 'cre_e2e_poll';

		await admin.visitAdminPage( 'admin.php?page=af-creatives' );

		const card = page.locator( `[data-af-creative-id="${ creativeId }"]` );
		await expect( card.locator( '[data-af-creative-badge]' ) ).toHaveText(
			/Processing/i
		);

		await expect( card.locator( '[data-af-creative-badge]' ) ).toHaveText(
			/Complete/i,
			{
				timeout: 10000,
			}
		);
	} );
} );
