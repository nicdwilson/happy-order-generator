// @ts-check
const { defineConfig } = require( '@playwright/test' );

/**
 * Under QIT the site URL is provided as QIT_SITE_URL. Locally it is wp-env's
 * development site (http://localhost:8890, admin / password).
 */
const baseURL = process.env.QIT_SITE_URL || process.env.WP_BASE_URL || 'http://localhost:8890';

module.exports = defineConfig( {
	testDir: './tests',
	// Specs share one WordPress site and its options, so run them in order.
	workers: 1,
	fullyParallel: false,
	retries: process.env.CI ? 1 : 0,
	timeout: 120_000,
	reporter: [
		[ 'list' ],
		[ 'playwright-ctrf-json-reporter', { outputDir: 'results', outputFile: 'ctrf.json' } ],
		[ 'blob', { outputDir: 'results/blob' } ],
	],
	use: {
		baseURL,
		trace: 'retain-on-failure',
		screenshot: 'only-on-failure',
	},
} );
