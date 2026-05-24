/**
 * ESLint flat config for Simple WP Helpdesk JS assets.
 *
 * Extends @wordpress/scripts defaults. WordPress packages are resolved as
 * externals at build time (webpack); ESLint doesn't see them as installed
 * node_modules, so we turn off the import/* rules for @wordpress/* specifiers.
 */

const wpPlugin = require( '@wordpress/eslint-plugin' );

/** @type {import('eslint').Linter.FlatConfig[]} */
module.exports = [
	// Global ignores.
	{
		ignores: [
			'**/build/**',
			'**/node_modules/**',
			'**/vendor/**',
			'simple-wp-helpdesk/assets/dist/**',
		],
	},

	// Base recommended config from @wordpress/eslint-plugin.
	...wpPlugin.configs.recommended,

	// Project-level overrides for all plugin assets.
	{
		files: [ 'simple-wp-helpdesk/assets/src/**/*.{js,jsx}' ],
		rules: {
			// @wordpress/* packages are WP externals — not in node_modules.
			'import/no-extraneous-dependencies': 'off',
			'import/no-unresolved': [
				'error',
				{ ignore: [ '^@wordpress/' ] },
			],
		},
	},

	// Silence pre-existing issues in toast/index.js (legacy, pre-dates ESLint gate).
	{
		files: [ 'simple-wp-helpdesk/assets/src/toast/**/*.js' ],
		rules: {
			'jsdoc/empty-tags': 'off',
			'jsdoc/check-line-alignment': 'off',
			'prettier/prettier': 'off',
			'prefer-const': 'off',
			'no-undef': 'off',
		},
	},
];
