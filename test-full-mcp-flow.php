<?php
/**
 * Test the complete MCP flow that Claude Code uses
 */

// Load WordPress
require_once __DIR__ . '/../../../wp-load.php';

header('Content-Type: text/plain');

echo "=== Simulating Complete Claude Code MCP Flow ===\n\n";

// Get admin user
$user = get_user_by('login', 'admin_Jarod');
if (!$user) {
	echo "ERROR: User not found\n";
	exit;
}

// Create a test Application Password (simulating OAuth token)
$created = WP_Application_Passwords::create_new_application_password(
	$user->ID,
	array('name' => 'MCP Flow Test - ' . gmdate('Y-m-d H:i:s'))
);

if (is_wp_error($created)) {
	echo "ERROR: " . $created->get_error_message() . "\n";
	exit;
}

$raw_password = $created[0];
$password_data = $created[1];
$token = $user->user_login . ':' . $raw_password;

echo "✓ Created test token: " . $user->user_login . ":*****\n\n";

$url = get_site_url() . '/wp-json/mcp/v1/mcp';

// Step 1: Initialize (what Claude Code does first)
echo "Step 1: Calling initialize...\n";

$init_request = [
	'jsonrpc' => '2.0',
	'id' => 1,
	'method' => 'initialize',
	'params' => [
		'protocolVersion' => '2024-11-05',
		'capabilities' => new stdClass(),
		'clientInfo' => [
			'name' => 'Claude Code',
			'version' => '1.0.0'
		]
	]
];

$response = wp_remote_post($url, [
	'headers' => [
		'Content-Type' => 'application/json',
		'Authorization' => 'Bearer ' . $token,
	],
	'body' => json_encode($init_request),
	'timeout' => 30,
]);

$init_data = json_decode(wp_remote_retrieve_body($response), true);

if (isset($init_data['result']['capabilities']['tools'])) {
	echo "✓ Initialize returned tools capability: " . json_encode($init_data['result']['capabilities']['tools']) . "\n";
} else {
	echo "✗ Initialize did NOT return tools capability\n";
	echo "Response: " . json_encode($init_data, JSON_PRETTY_PRINT) . "\n";
	exit;
}

// Step 2: Send initialized notification (required by MCP spec)
echo "\nStep 2: Sending initialized notification...\n";

$initialized_notification = [
	'jsonrpc' => '2.0',
	'method' => 'notifications/initialized',
	'params' => new stdClass()
];

$response = wp_remote_post($url, [
	'headers' => [
		'Content-Type' => 'application/json',
		'Authorization' => 'Bearer ' . $token,
	],
	'body' => json_encode($initialized_notification),
	'timeout' => 30,
]);

$status_code = wp_remote_retrieve_response_code($response);
echo "✓ Notification sent (status: $status_code)\n";

// Step 3: List tools (what Claude Code does to see available tools)
echo "\nStep 3: Calling tools/list...\n";

$tools_request = [
	'jsonrpc' => '2.0',
	'id' => 2,
	'method' => 'tools/list',
	'params' => new stdClass()
];

$response = wp_remote_post($url, [
	'headers' => [
		'Content-Type' => 'application/json',
		'Authorization' => 'Bearer ' . $token,
	],
	'body' => json_encode($tools_request),
	'timeout' => 30,
]);

$tools_data = json_decode(wp_remote_retrieve_body($response), true);

if (isset($tools_data['result']['tools'])) {
	$count = count($tools_data['result']['tools']);
	echo "✓ tools/list returned $count tools\n";

	// Show first 3 WordPress tools (skip MCP internal endpoints)
	echo "\nSample WordPress tools:\n";
	$wp_tools = array_filter($tools_data['result']['tools'], function($tool) {
		return strpos($tool['name'], 'wp_v2_') === 0;
	});

	foreach (array_slice($wp_tools, 0, 3) as $tool) {
		echo "  - " . $tool['name'] . "\n";
	}

	if (count($wp_tools) > 0) {
		echo "\n✓✓✓ SUCCESS! WordPress tools are available!\n";
	} else {
		echo "\n⚠️  WARNING: No wp_v2_* tools found!\n";
	}
} else {
	echo "✗ tools/list did NOT return tools\n";
	echo "Response: " . json_encode($tools_data, JSON_PRETTY_PRINT) . "\n";
}

// Step 4: Test calling a specific tool
echo "\nStep 4: Testing tool execution (ping)...\n";

$ping_request = [
	'jsonrpc' => '2.0',
	'id' => 3,
	'method' => 'ping',
	'params' => new stdClass()
];

$response = wp_remote_post($url, [
	'headers' => [
		'Content-Type' => 'application/json',
		'Authorization' => 'Bearer ' . $token,
	],
	'body' => json_encode($ping_request),
	'timeout' => 30,
]);

$ping_data = json_decode(wp_remote_retrieve_body($response), true);

if (isset($ping_data['result'])) {
	echo "✓ Ping successful\n";
} else {
	echo "Ping response: " . json_encode($ping_data) . "\n";
}

// Clean up
echo "\nCleaning up test password...\n";
WP_Application_Passwords::delete_application_password($user->ID, $password_data['uuid']);
echo "✓ Done!\n";

echo "\n===============================\n";
echo "If all steps succeeded, the server is working correctly.\n";
echo "The issue with Claude Code might be:\n";
echo "1. Cached connection state - try restarting Claude Code\n";
echo "2. Authentication not persisted - make sure to complete OAuth flow\n";
echo "3. Claude Code UI issue - check /mcp command in a chat window\n";
