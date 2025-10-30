<?php
/**
 * Test WordPress Application Password authentication
 * This should work with WP Engine since it's WordPress's native auth system
 */

// Load WordPress
require_once __DIR__ . '/../../../wp-load.php';

header('Content-Type: application/json');

echo "=== Testing WordPress Application Password System ===\n\n";

// Get admin user
$user = get_user_by('login', 'admin_Jarod');
if (!$user) {
	echo json_encode(['error' => 'User not found'], JSON_PRETTY_PRINT);
	exit;
}

echo "Step 1: Creating test Application Password...\n";

// Create a test Application Password
$created = WP_Application_Passwords::create_new_application_password(
	$user->ID,
	array('name' => 'MCP Test - ' . gmdate('Y-m-d H:i:s'))
);

if (is_wp_error($created)) {
	echo json_encode(['error' => $created->get_error_message()], JSON_PRETTY_PRINT);
	exit;
}

$raw_password = $created[0]; // The actual password
$password_data = $created[1]; // The database entry

echo "✓ Application Password created\n";
echo "  UUID: " . $password_data['uuid'] . "\n";
echo "  Password: " . $raw_password . "\n\n";

// Format the token as Claude Code will send it
$token = $user->user_login . ':' . $raw_password;

echo "Step 2: Testing authentication with Bearer token...\n";
echo "Token format: Bearer " . $user->user_login . ":*****\n\n";

// Test the initialize endpoint with this token
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
		'Authorization' => 'Bearer ' . $token,
	],
	'body' => json_encode($initialize_request),
	'timeout' => 30,
]);

if (is_wp_error($response)) {
	echo "✗ HTTP Error: " . $response->get_error_message() . "\n";
	exit;
}

$response_code = wp_remote_retrieve_response_code($response);
$response_body = wp_remote_retrieve_body($response);
$response_data = json_decode($response_body, true);

echo "Step 3: Response received\n";
echo "HTTP Status: " . $response_code . "\n\n";

if ($response_code === 200 && isset($response_data['result'])) {
	echo "✓ SUCCESS! Authentication worked!\n\n";
	echo "Response structure:\n";
	echo json_encode([
		'jsonrpc' => $response_data['jsonrpc'] ?? null,
		'id' => $response_data['id'] ?? null,
		'result' => [
			'serverInfo' => $response_data['result']['serverInfo'] ?? null,
			'protocolVersion' => $response_data['result']['protocolVersion'] ?? null,
			'capabilities' => $response_data['result']['capabilities'] ?? null,
		]
	], JSON_PRETTY_PRINT);
} else {
	echo "✗ FAILED\n\n";
	echo json_encode($response_data, JSON_PRETTY_PRINT);
}

// Clean up - delete the test app password
echo "\n\nStep 4: Cleaning up test password...\n";
WP_Application_Passwords::delete_application_password($user->ID, $password_data['uuid']);
echo "✓ Test password deleted\n";
