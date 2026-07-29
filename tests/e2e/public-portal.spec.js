const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

test.describe( 'Public Portal', () => {
	let postId;

	test.beforeAll( async ( { requestUtils } ) => {
		await requestUtils.activatePlugin( 'bushido-almost-famous' );
		const post = await requestUtils.rest( {
			path: '/wp/v2/posts',
			method: 'POST',
			data: {
				title: 'Portal Test',
				content:
					'<!-- wp:shortcode -->\n[almost-famous-portal demo="1"]\n<!-- /wp:shortcode -->',
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
		await requestUtils.deactivatePlugin( 'bushido-almost-famous' );
	} );

	test( 'should mount the React SPA on the frontend', async ( { page } ) => {
		await page.goto( `/?p=${ postId }` );

		// Check if the portal wrapper rendered
		await page.waitForSelector( '#af-public-portal', {
			state: 'attached',
		} );

		// Wait for the app to mount — the nav should appear
		const nav = page.locator( '.af-portal-nav' );
		await expect( nav ).toBeVisible( { timeout: 10000 } );

		// Dashboard heading should be visible
		const heading = page.getByRole( 'heading', { name: /Campaigns/i } );
		await expect( heading ).toBeVisible( { timeout: 10000 } );
	} );

	test( 'should show empty state or campaign list on dashboard', async ( {
		page,
	} ) => {
		await page.goto( `/?p=${ postId }` );
		await page.waitForSelector( '.af-portal-nav', { state: 'visible' } );

		// Wait for loading to finish
		await page.waitForFunction(
			() => ! document.querySelector( '.af-loading' ),
			null,
			{
				timeout: 10000,
			}
		);

		// Should see either campaign cards or the empty state
		const hasCards = await page.locator( '.af-campaign-card' ).count();
		const hasEmpty = await page.locator( '.af-empty' ).count();
		expect( hasCards > 0 || hasEmpty > 0 ).toBe( true );
	} );

	test( 'should navigate to create campaign form', async ( { page } ) => {
		await page.goto( `/?p=${ postId }#/campaigns/new` );
		await page.waitForSelector( '.af-portal-nav', { state: 'visible' } );

		// Should see create campaign heading
		const heading = page.getByRole( 'heading', {
			name: /Create Campaign/i,
		} );
		await expect( heading ).toBeVisible( { timeout: 10000 } );

		// Should see form inputs
		await expect( page.locator( '#af-name' ) ).toBeVisible();
		await expect( page.locator( '#af-objective' ) ).toBeVisible();
		await expect( page.locator( '#af-budget-total' ) ).toBeVisible();
	} );

	test( 'should navigate to payments page', async ( { page } ) => {
		await page.goto( `/?p=${ postId }#/payments` );
		await page.waitForSelector( '.af-portal-nav', { state: 'visible' } );

		// Should see payments heading
		const heading = page.getByRole( 'heading', { name: /Payments/i } );
		await expect( heading ).toBeVisible( { timeout: 10000 } );
	} );

	test( 'should navigate between views via nav links', async ( { page } ) => {
		await page.goto( `/?p=${ postId }` );
		await page.waitForSelector( '.af-portal-nav', { state: 'visible' } );

		// Click Payments nav link
		await page
			.locator( '.af-portal-nav a', { hasText: 'Payments' } )
			.click();
		await expect(
			page.getByRole( 'heading', { name: /Payments/i } )
		).toBeVisible( { timeout: 5000 } );

		// Click Campaigns nav link
		await page
			.locator( '.af-portal-nav a', { hasText: 'Campaigns' } )
			.click();
		await expect(
			page.getByRole( 'heading', { name: /Campaigns/i } )
		).toBeVisible( { timeout: 5000 } );

		// Click New nav link
		await page.locator( '.af-portal-nav a', { hasText: 'New' } ).click();
		await expect(
			page.getByRole( 'heading', { name: /Create Campaign/i } )
		).toBeVisible( { timeout: 5000 } );
	} );
} );
