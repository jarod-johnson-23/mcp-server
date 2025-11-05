<?php
/**
 * Configuration file for MCP WordPress Server.
 *
 * @package McpWp
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return [
	/**
	 * TinyPNG API Configuration
	 *
	 * Get your API key from: https://tinypng.com/developers
	 * Free tier includes 500 compressions per month
	 */
	'tinypng' => [
		'api_key' => 'DnF81Lx3qpvl951MScVKtbRKbRzKn4wJ', // Replace with your actual API key
		'enabled' => true, // Set to false to disable compression
	],
];
