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

test.describe( 'Campaign Analytics', () => {
	let postId;

	test.beforeAll( async ( { requestUtils } ) => {
		await requestUtils.activatePlugin( 'bushido-almost-famous' );
		wpCli( 'eval', 'af_e2e_seed_connected_site("agency");' );

		const post = await requestUtils.rest( {
			path: '/wp/v2/posts',
			method: 'POST',
			data: {
				title: 'Campaign analytics',
				content:
					'<!-- wp:almost-famous/campaign-widget {"campaign_id":"cmp_e2e_analytics"} /-->',
				status: 'publish',
			},
		} );
		postId = post.id;
	} );

	test.afterAll( async ( { requestUtils } ) => {
		if ( postId ) {
			await requestUtils.rest( {
				path: `/wp/v2/posts/${ postId }`,
				method: 'DELETE',
				data: { force: true },
			} );
		}
	} );

	test( 'renders deterministic campaign metrics on the frontend', async ( {
		page,
	} ) => {
		await page.goto( `/?p=${ postId }` );

		const widget = page.locator( '.af-campaign-widget' );
		await expect(
			widget.getByRole( 'heading', { name: 'E2E Analytics Campaign' } )
		).toBeVisible();
		await expect(
			widget.getByText( '2.50', { exact: true } )
		).toBeVisible();
		await expect(
			widget.getByText( '5,000', { exact: true } )
		).toBeVisible();
		await expect(
			widget.getByText( '$100.00', { exact: true } )
		).toBeVisible();
	} );
} );
