<?php
/**
 * Test parameter handling with typed objects
 */

// Load WordPress
require_once __DIR__ . '/../../../wp-load.php';
require_once __DIR__ . '/vendor/autoload.php';

header('Content-Type: text/plain');

echo "=== Testing Parameter Handling ===\n\n";

// Simulate what RestController does
$test_params = [
	'name' => 'get_wp_v2_posts',
	'arguments' => [
		'per_page' => 5,
		'context' => 'view'
	],
	'_meta' => [
		'progressToken' => '123'
	]
];

echo "Step 1: Create RequestParams object (like RestController does)...\n";

// Handle _meta specially
$meta = null;
if (isset($test_params['_meta']) && is_array($test_params['_meta'])) {
	$meta = new \Mcp\Types\Meta();
}

$params = new \Mcp\Types\RequestParams($meta);

// Set other params using magic __set
foreach ($test_params as $key => $value) {
	if ($key !== '_meta') {
		$params->{$key} = $value;
	}
}

echo "✓ RequestParams created successfully\n";
echo "  Type: " . get_class($params) . "\n";
echo "  Has name: " . (isset($params->name) ? 'yes' : 'no') . "\n";
echo "  Has arguments: " . (isset($params->arguments) ? 'yes' : 'no') . "\n\n";

echo "Step 2: Convert to array (like Server.php does)...\n";

if (!is_array($params)) {
	$params_array = json_decode(json_encode($params), true);
	echo "✓ Converted to array\n";
	echo "  Array keys: " . implode(', ', array_keys($params_array)) . "\n";
	echo "  name value: " . $params_array['name'] . "\n";
	echo "  arguments: " . json_encode($params_array['arguments']) . "\n\n";
} else {
	echo "Already an array\n\n";
}

echo "Step 3: Test with actual WordPress MCP server...\n";

$user = get_user_by('login', 'admin_Jarod');
if (!$user) {
	echo "ERROR: User not found\n";
	exit;
}

$created = WP_Application_Passwords::create_new_application_password(
	$user->ID,
	array('name' => 'MCP Param Test - ' . gmdate('Y-m-d H:i:s'))
);

if (is_wp_error($created)) {
	echo "ERROR: " . $created->get_error_message() . "\n";
	exit;
}

$raw_password = $created[0];
$password_data = $created[1];
$token = $user->user_login . ':' . $raw_password;

$url = get_site_url() . '/wp-json/mcp/v1/mcp';

$response = wp_remote_post($url, [
	'headers' => [
		'Content-Type' => 'application/json',
		'Authorization' => 'Bearer ' . $token,
	],
	'body' => json_encode([
		'jsonrpc' => '2.0',
		'id' => 1,
		'method' => 'tools/call',
		'params' => [
			'name' => 'get_wp_v2_users',
			'arguments' => [
				'per_page' => 3,
				'context' => 'view'
			]
		]
	]),
	'timeout' => 30,
]);

$data = json_decode(wp_remote_retrieve_body($response), true);

if (isset($data['result'])) {
	echo "✓ Tool call succeeded!\n";
	if (isset($data['result']['content'])) {
		$content = $data['result']['content'];
		if (is_array($content) && isset($content[0]['text'])) {
			$users = json_decode($content[0]['text'], true);
			if (is_array($users)) {
				echo "  Retrieved " . count($users) . " users\n";
				echo "  First user: " . ($users[0]['name'] ?? 'unknown') . "\n";
			}
		}
	}
} else {
	echo "✗ Tool call failed\n";
	echo "  Error: " . ($data['message'] ?? $data['code'] ?? 'unknown') . "\n";
	echo "  Full response: " . json_encode($data, JSON_PRETTY_PRINT) . "\n";
}

// Clean up
WP_Application_Passwords::delete_application_password($user->ID, $password_data['uuid']);

echo "\n✅ Test complete!\n";
