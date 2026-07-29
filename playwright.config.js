process.env.WP_BASE_URL = process.env.WP_BASE_URL || 'http://localhost:8992';

const { defineConfig } = require( '@playwright/test' );
const baseConfig = require( '@wordpress/scripts/config/playwright.config' );

module.exports = defineConfig( {
	...baseConfig,
	testDir: './tests/e2e',
	// Specs mutate shared WordPress/plugin state (including uninstall), so a
	// deterministic single worker preserves the complete suite without races.
	workers: 1,
	use: {
		...baseConfig.use,
		baseURL: process.env.WP_BASE_URL || 'http://localhost:8992',
	},
	webServer: {
		...baseConfig.webServer,
		command: 'npm run env start',
		port: 8992,
		reuseExistingServer:
			process.env.WP_ENV_REUSE_EXISTING === 'true' ||
			baseConfig.webServer.reuseExistingServer,
	},
} );
