<?php
/**
 * Test script to debug MCP capabilities response
 *
 * Upload this to your WordPress site and access it via:
 * https://your-site.com/wp-content/plugins/mcp-server/test-capabilities.php
 */

// Load WordPress
require_once __DIR__ . '/../../../wp-load.php';
require_once __DIR__ . '/vendor/autoload.php';

header('Content-Type: application/json');

try {
	// Create WordPress MCP server instance
	$server = new \McpWp\MCP\Servers\WordPress\WordPress();

	// Call initialize to get capabilities
	$result = $server->initialize();

	// Get the internal MCP server to inspect handlers
	$reflection = new ReflectionObject($server);
	$mcp_server_property = $reflection->getProperty('mcp_server');
	$mcp_server_property->setAccessible(true);
	$mcp_server = $mcp_server_property->getValue($server);

	// Get registered handlers
	$handlers = $mcp_server->getHandlers();

	echo json_encode([
		'success' => true,
		'capabilities' => [
			'tools' => $result->capabilities->tools ?? null,
			'resources' => $result->capabilities->resources ?? null,
			'prompts' => $result->capabilities->prompts ?? null,
			'logging' => $result->capabilities->logging ?? null,
		],
		'registered_handlers' => array_keys($handlers),
		'server_info' => [
			'name' => $result->serverInfo->name,
			'version' => $result->serverInfo->version,
		],
		'protocol_version' => $result->protocolVersion,
	], JSON_PRETTY_PRINT);

} catch (Exception $e) {
	http_response_code(500);
	echo json_encode([
		'success' => false,
		'error' => $e->getMessage(),
		'trace' => $e->getTraceAsString(),
	], JSON_PRETTY_PRINT);
}
