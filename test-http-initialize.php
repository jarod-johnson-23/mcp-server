<?php
/**
 * Test script that makes a real HTTP request to the initialize endpoint
 */

// Load WordPress
require_once __DIR__ . '/../../../wp-load.php';

header('Content-Type: application/json');

// Get the most recent valid OAuth token from the database
global $wpdb;
$token_data = $wpdb->get_row(
	"SELECT * FROM {$wpdb->prefix}mcp_oauth_tokens
	WHERE expires_at > NOW()
	ORDER BY created_at DESC
	LIMIT 1",
	ARRAY_A
);

if (!$token_data) {
	echo json_encode([
		'success' => false,
		'error' => 'No valid OAuth token found in database',
	], JSON_PRETTY_PRINT);
	exit;
}

$access_token = $token_data['access_token'];

// Make a real HTTP request to the initialize endpoint
$url = get_site_url() . '/wp-json/mcp/v1/mcp';

$initialize_request = [
	'jsonrpc' => '2.0',
	'id' => 1,
	'method' => 'initialize',
	'params' => [
		'protocolVersion' => '2024-11-05',
		'capabilities' => new stdClass(),
		'clientInfo' => [
			'name' => 'test-client',
			'version' => '1.0.0'
		]
	]
];

$response = wp_remote_post($url, [
	'headers' => [
		'Content-Type' => 'application/json',
		'Authorization' => 'Bearer ' . $access_token,
	],
	'body' => json_encode($initialize_request),
	'timeout' => 30,
]);

if (is_wp_error($response)) {
	echo json_encode([
		'success' => false,
		'error' => $response->get_error_message(),
	], JSON_PRETTY_PRINT);
	exit;
}

$response_code = wp_remote_retrieve_response_code($response);
$response_body = wp_remote_retrieve_body($response);
$response_data = json_decode($response_body, true);

echo json_encode([
	'test_info' => 'Real HTTP request to initialize endpoint with OAuth',
	'token_info' => [
		'user_id' => $token_data['user_id'],
		'expires_at' => $token_data['expires_at'],
		'token_preview' => substr($access_token, 0, 20) . '...',
	],
	'request_url' => $url,
	'http_status' => $response_code,
	'response_body' => $response_data,
	'has_result' => isset($response_data['result']),
	'has_capabilities' => isset($response_data['result']['capabilities']),
	'capabilities' => $response_data['result']['capabilities'] ?? null,
	'tools_capability' => $response_data['result']['capabilities']['tools'] ?? null,
], JSON_PRETTY_PRINT);
