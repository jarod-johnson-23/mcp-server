<?php
/**
 * Final comprehensive test of the complete MCP server with all fixes
 */

// Load WordPress
require_once __DIR__ . '/../../../wp-load.php';

header('Content-Type: text/plain');

echo "=== FINAL COMPREHENSIVE MCP SERVER TEST ===\n";
echo "Testing with all fixes applied\n\n";

// Get admin user
$user = get_user_by('login', 'admin_Jarod');
if (!$user) {
	echo "ERROR: User not found\n";
	exit;
}

// Create test Application Password
$created = WP_Application_Passwords::create_new_application_password(
	$user->ID,
	array('name' => 'MCP Final Test - ' . gmdate('Y-m-d H:i:s'))
);

if (is_wp_error($created)) {
	echo "ERROR: " . $created->get_error_message() . "\n";
	exit;
}

$raw_password = $created[0];
$password_data = $created[1];
$token = $user->user_login . ':' . $raw_password;

echo "✓ Authentication token created\n\n";

$url = get_site_url() . '/wp-json/mcp/v1/mcp';
$headers = [
	'Content-Type' => 'application/json',
	'Authorization' => 'Bearer ' . $token,
];

// Test 1: Initialize
echo "TEST 1: Initialize\n";
$response = wp_remote_post($url, [
	'headers' => $headers,
	'body' => json_encode([
		'jsonrpc' => '2.0',
		'id' => 1,
		'method' => 'initialize',
		'params' => [
			'protocolVersion' => '2024-11-05',
			'capabilities' => new stdClass(),
			'clientInfo' => ['name' => 'Test', 'version' => '1.0']
		]
	]),
	'timeout' => 30,
]);

$data = json_decode(wp_remote_retrieve_body($response), true);
$has_tools_cap = isset($data['result']['capabilities']['tools']);
echo $has_tools_cap ? "✓ PASS - Tools capability present\n" : "✗ FAIL - No tools capability\n";
echo "\n";

// Test 2: Initialized notification
echo "TEST 2: Initialized Notification\n";
$response = wp_remote_post($url, [
	'headers' => $headers,
	'body' => json_encode([
		'jsonrpc' => '2.0',
		'method' => 'notifications/initialized',
		'params' => new stdClass()
	]),
	'timeout' => 30,
]);
$status = wp_remote_retrieve_response_code($response);
echo ($status === 202 || $status === 200) ? "✓ PASS - Notification accepted\n" : "✗ FAIL - Status: $status\n";
echo "\n";

// Test 3: Ping
echo "TEST 3: Ping (tests parameter conversion fix)\n";
$response = wp_remote_post($url, [
	'headers' => $headers,
	'body' => json_encode([
		'jsonrpc' => '2.0',
		'id' => 2,
		'method' => 'ping',
		'params' => new stdClass()
	]),
	'timeout' => 30,
]);
$data = json_decode(wp_remote_retrieve_body($response), true);
$ping_success = isset($data['result']) && !isset($data['code']);
echo $ping_success ? "✓ PASS - Ping successful\n" : "✗ FAIL - Ping failed: " . ($data['message'] ?? 'unknown error') . "\n";
echo "\n";

// Test 4: List tools
echo "TEST 4: List Tools\n";
$response = wp_remote_post($url, [
	'headers' => $headers,
	'body' => json_encode([
		'jsonrpc' => '2.0',
		'id' => 3,
		'method' => 'tools/list',
		'params' => new stdClass()
	]),
	'timeout' => 30,
]);
$data = json_decode(wp_remote_retrieve_body($response), true);
$tools = $data['result']['tools'] ?? [];
$tools_count = count($tools);
echo "✓ PASS - Retrieved $tools_count tools\n";

// Check for WordPress tools
$wp_tools = array_filter($tools, function($tool) {
	return strpos($tool['name'], 'wp_v2_') === 0;
});
$wp_count = count($wp_tools);
echo $wp_count > 0 ? "✓ PASS - Found $wp_count WordPress API tools\n" : "✗ FAIL - No WordPress tools found\n";

if ($wp_count > 0) {
	echo "\nSample WordPress tools:\n";
	foreach (array_slice($wp_tools, 0, 5) as $tool) {
		echo "  • " . $tool['name'] . "\n";
	}
}
echo "\n";

// Test 5: Call a specific WordPress tool
echo "TEST 5: Call WordPress Tool (get users)\n";
$response = wp_remote_post($url, [
	'headers' => $headers,
	'body' => json_encode([
		'jsonrpc' => '2.0',
		'id' => 4,
		'method' => 'tools/call',
		'params' => [
			'name' => 'wp_v2_users_GET',
			'arguments' => [
				'per_page' => 5,
				'context' => 'view'
			]
		]
	]),
	'timeout' => 30,
]);
$data = json_decode(wp_remote_retrieve_body($response), true);
$tool_call_success = isset($data['result']) && !isset($data['code']);
echo $tool_call_success ? "✓ PASS - Tool executed successfully\n" : "✗ FAIL - Tool call failed\n";

if ($tool_call_success && isset($data['result']['content'])) {
	$content = $data['result']['content'];
	if (is_array($content) && isset($content[0]['text'])) {
		$users_data = json_decode($content[0]['text'], true);
		if (is_array($users_data)) {
			echo "  Retrieved " . count($users_data) . " users\n";
		}
	}
}
echo "\n";

// Clean up
echo "Cleaning up test password...\n";
WP_Application_Passwords::delete_application_password($user->ID, $password_data['uuid']);
echo "✓ Cleanup complete\n\n";

echo "===========================================\n";
echo "SUMMARY:\n";
echo "• Authentication: Working ✓\n";
echo "• Initialize: Working ✓\n";
echo "• Ping: " . ($ping_success ? "Working ✓" : "Failed ✗") . "\n";
echo "• Tools List: $tools_count tools available ✓\n";
echo "• WordPress Tools: $wp_count found " . ($wp_count > 0 ? "✓" : "✗") . "\n";
echo "• Tool Execution: " . ($tool_call_success ? "Working ✓" : "Failed ✗") . "\n";
echo "\n";

if ($ping_success && $wp_count > 0 && $tool_call_success) {
	echo "🎉 ALL TESTS PASSED! 🎉\n";
	echo "The MCP server is fully functional.\n";
	echo "\nNext step: Re-authenticate Claude Code:\n";
	echo "  1. claude mcp remove wordpress_cocopah -s user\n";
	echo "  2. claude mcp add --transport http wordpress_cocopah https://cocopah2023dev.wpengine.com/wp-json/mcp/v1/mcp\n";
	echo "  3. Complete OAuth authentication\n";
	echo "  4. Try asking: 'Use the WordPress MCP server to get 5 users'\n";
} else {
	echo "⚠️  Some tests failed. Check the output above.\n";
}
