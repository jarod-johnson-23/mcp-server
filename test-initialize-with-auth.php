<?php
/**
 * Test script to simulate Claude Code's initialize call with OAuth
 *
 * This will help us see what response Claude Code receives
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
		'note' => 'Please authenticate with Claude Code first to generate a token',
	], JSON_PRETTY_PRINT);
	exit;
}

$access_token = $token_data['access_token'];

echo json_encode([
	'test_info' => 'Testing initialize call with OAuth Bearer token',
	'token_user_id' => $token_data['user_id'],
	'token_expires_at' => $token_data['expires_at'],
], JSON_PRETTY_PRINT) . "\n\n";

// Now make a REST API request to initialize endpoint
$request = new WP_REST_Request('POST', '/mcp/v1/mcp');
$request->set_header('Content-Type', 'application/json');
$request->set_header('Authorization', 'Bearer ' . $access_token);

// Set initialize parameters
$request->set_param('jsonrpc', '2.0');
$request->set_param('id', 1);
$request->set_param('method', 'initialize');
$request->set_param('params', [
	'protocolVersion' => '2024-11-05',
	'capabilities' => new stdClass(),
	'clientInfo' => [
		'name' => 'test-client',
		'version' => '1.0.0'
	]
]);

// Simulate user authentication
wp_set_current_user($token_data['user_id']);

// Process through REST API
$server = rest_get_server();
$response = $server->dispatch($request);

$data = $response->get_data();

echo json_encode([
	'success' => true,
	'response_status' => $response->get_status(),
	'response_headers' => $response->get_headers(),
	'full_response' => $data,
	'has_result' => isset($data['result']),
	'has_capabilities' => isset($data['result']['capabilities']),
	'capabilities' => $data['result']['capabilities'] ?? null,
	'tools_capability' => $data['result']['capabilities']['tools'] ?? null,
	'resources_capability' => $data['result']['capabilities']['resources'] ?? null,
], JSON_PRETTY_PRINT);
