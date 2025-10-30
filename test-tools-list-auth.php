<?php
/**
 * Test the tools/list endpoint with authentication
 */

// Load WordPress
require_once __DIR__ . '/../../../wp-load.php';

header('Content-Type: application/json');

echo "=== Testing tools/list Endpoint ===\n\n";

// Get admin user
$user = get_user_by('login', 'admin_Jarod');
if (!$user) {
	echo json_encode(['error' => 'User not found'], JSON_PRETTY_PRINT);
	exit;
}

// Create a test Application Password
$created = WP_Application_Passwords::create_new_application_password(
	$user->ID,
	array('name' => 'MCP Tools Test - ' . gmdate('Y-m-d H:i:s'))
);

if (is_wp_error($created)) {
	echo json_encode(['error' => $created->get_error_message()], JSON_PRETTY_PRINT);
	exit;
}

$raw_password = $created[0];
$password_data = $created[1];
$token = $user->user_login . ':' . $raw_password;

echo "Step 1: Created test token\n";
echo "Token format: Bearer " . $user->user_login . ":*****\n\n";

// Test tools/list endpoint
$url = get_site_url() . '/wp-json/mcp/v1/mcp';

$tools_request = [
	'jsonrpc' => '2.0',
	'id' => 1,
	'method' => 'tools/list',
	'params' => new stdClass()
];

echo "Step 2: Calling tools/list...\n";

$response = wp_remote_post($url, [
	'headers' => [
		'Content-Type' => 'application/json',
		'Authorization' => 'Bearer ' . $token,
	],
	'body' => json_encode($tools_request),
	'timeout' => 30,
]);

if (is_wp_error($response)) {
	echo "✗ Error: " . $response->get_error_message() . "\n";
	exit;
}

$response_code = wp_remote_retrieve_response_code($response);
$response_body = wp_remote_retrieve_body($response);
$response_data = json_decode($response_body, true);

echo "HTTP Status: " . $response_code . "\n\n";

if ($response_code === 200 && isset($response_data['result'])) {
	$tools = $response_data['result']['tools'] ?? [];
	$tools_count = count($tools);

	echo "✓ SUCCESS! tools/list returned " . $tools_count . " tools\n\n";

	if ($tools_count > 0) {
		echo "First 5 tools:\n";
		foreach (array_slice($tools, 0, 5) as $tool) {
			echo "  - " . $tool['name'] . "\n";
			echo "    Description: " . substr($tool['description'], 0, 60) . "...\n";
		}
	} else {
		echo "⚠️  WARNING: No tools found!\n";
		echo "This means the WordPress MCP server isn't exposing any tools.\n";
	}
} else {
	echo "✗ FAILED\n\n";
	echo json_encode($response_data, JSON_PRETTY_PRINT);
}

// Clean up
echo "\n\nStep 3: Cleaning up test password...\n";
WP_Application_Passwords::delete_application_password($user->ID, $password_data['uuid']);
echo "✓ Test password deleted\n";
