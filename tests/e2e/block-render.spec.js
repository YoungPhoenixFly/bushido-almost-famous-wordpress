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

// Block matrix: name, raw block markup with required attribute(s), and a
// frontend selector we expect to find on the rendered page.
const blocks = [
	{
		name: 'campaign-widget',
		markup: '<!-- wp:almost-famous/campaign-widget {"campaign_id":"cmp_e2e_block"} /-->',
		selector: '.af-campaign-widget',
	},
];

test.describe( 'Block render', () => {
	const created = [];

	test.beforeAll( async ( { requestUtils } ) => {
		await requestUtils.activatePlugin( 'bushido-almost-famous' );
		wpCli( 'eval', 'af_e2e_seed_connected_site("agency");' );
	} );

	test.afterAll( async ( { requestUtils } ) => {
		for ( const id of created ) {
			await requestUtils.rest( {
				path: `/wp/v2/posts/${ id }`,
				method: 'DELETE',
				data: { force: true },
			} );
		}
	} );

	for ( const block of blocks ) {
		test( `renders ${ block.name } on the frontend`, async ( {
			requestUtils,
			page,
		} ) => {
			const post = await requestUtils.rest( {
				path: '/wp/v2/posts',
				method: 'POST',
				data: {
					title: `Block render — ${ block.name }`,
					content: block.markup,
					status: 'publish',
				},
			} );
			created.push( post.id );

			await page.goto( `/?p=${ post.id }` );
			await expect(
				page.locator( block.selector ).first()
			).toBeVisible();
			await expect(
				page.getByText( 'E2E Analytics Campaign', { exact: true } )
			).toBeVisible();
		} );
	}
} );
