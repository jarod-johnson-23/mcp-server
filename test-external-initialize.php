<?php
/**
 * Test script that simulates an EXTERNAL request (like Claude Code makes)
 * This uses curl instead of wp_remote_post to test real external HTTP
 */

// Load WordPress
require_once __DIR__ . '/../../../wp-load.php';

header('Content-Type: application/json');

// Get the most recent OAuth token
global $wpdb;
$token_data = $wpdb->get_row(
	"SELECT * FROM {$wpdb->prefix}mcp_oauth_tokens
	WHERE expires_at > NOW()
	ORDER BY created_at DESC
	LIMIT 1",
	ARRAY_A
);

if (!$token_data) {
	echo json_encode(['error' => 'No token found. Please re-authenticate with Claude Code.'], JSON_PRETTY_PRINT);
	exit;
}

// Get the actual user
$user = get_user_by('id', $token_data['user_id']);
if (!$user) {
	echo json_encode(['error' => 'User not found'], JSON_PRETTY_PRINT);
	exit;
}

// Get the WordPress Application Password associated with this OAuth token
// The token field contains the UUID of the app password
$app_passwords = WP_Application_Passwords::get_user_application_passwords($user->ID);
$matching_password = null;

foreach ($app_passwords as $app_password) {
	if ($app_password['uuid'] === $token_data['token']) {
		$matching_password = $app_password;
		break;
	}
}

if (!$matching_password) {
	echo json_encode([
		'error' => 'No matching Application Password found',
		'note' => 'The OAuth token might have been created before we switched to App Passwords',
		'solution' => 'Re-authenticate with Claude Code to get a new token'
	], JSON_PRETTY_PRINT);
	exit;
}

echo "=== Testing External HTTP Request (simulating Claude Code) ===\n\n";
echo "Note: We can't get the actual password from the database (it's hashed)\n";
echo "This test will fail unless we use a freshly created token.\n";
echo "The real test is: Does Claude Code work after re-authenticating?\n\n";

echo "Token info:\n";
echo "- User: " . $user->user_login . "\n";
echo "- App Password Name: " . $matching_password['name'] . "\n";
echo "- App Password UUID: " . $matching_password['uuid'] . "\n";
echo "- Created: " . $matching_password['created'] . "\n\n";

echo "What Claude Code should be sending:\n";
echo "Authorization: Bearer " . $user->user_login . ":<password_from_token_endpoint>\n\n";

echo "For external testing, create a new token manually and test with curl:\n\n";
echo "curl -X POST 'https://cocopah2023dev.wpengine.com/wp-json/mcp/v1/mcp' \\\n";
echo "  -H 'Content-Type: application/json' \\\n";
echo "  -H 'Authorization: Bearer " . $user->user_login . ":<password>' \\\n";
echo "  -d '{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"initialize\",\"params\":{\"protocolVersion\":\"2024-11-05\",\"capabilities\":{},\"clientInfo\":{\"name\":\"test\",\"version\":\"1.0\"}}}'\n";
