<?php
/**
 * Test script to see what tools/list returns
 *
 * Upload this to your WordPress site and access it via:
 * https://your-site.com/wp-content/plugins/mcp-server/test-tools-list.php
 */

// Load WordPress
require_once __DIR__ . '/../../../wp-load.php';
require_once __DIR__ . '/vendor/autoload.php';

header('Content-Type: application/json');

try {
	// Create WordPress MCP server instance
	$server = new \McpWp\MCP\Servers\WordPress\WordPress();

	// Get the internal MCP server to inspect registered tools
	$reflection = new ReflectionObject($server);
	$tools_property = $reflection->getProperty('tools');
	$tools_property->setAccessible(true);
	$tools = $tools_property->getValue($server);

	// Call list_tools to see what gets returned
	$list_tools_result = $server->list_tools();

	echo json_encode([
		'success' => true,
		'tools_count' => count($tools),
		'list_tools_result_type' => get_class($list_tools_result),
		'list_tools_result_tools_count' => isset($list_tools_result->tools) ? count($list_tools_result->tools) : 'no tools property',
		'first_5_tool_names' => array_slice(array_keys($tools), 0, 5),
		'sample_tool' => array_values($tools)[0] ?? null,
	], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

} catch (Exception $e) {
	http_response_code(500);
	echo json_encode([
		'success' => false,
		'error' => $e->getMessage(),
		'trace' => $e->getTraceAsString(),
	], JSON_PRETTY_PRINT);
}
