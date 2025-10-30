<?php
/**
 * Test script to see what initialize returns through the REST API
 *
 * Upload this to your WordPress site and access it via:
 * https://your-site.com/wp-content/plugins/mcp-server/test-initialize.php
 */

// Load WordPress
require_once __DIR__ . '/../../../wp-load.php';

header('Content-Type: application/json');

try {
	// Simulate what the REST API does
	$_SERVER['REQUEST_METHOD'] = 'POST';

	// Create a proper initialize request
	$initialize_request = array(
		'jsonrpc' => '2.0',
		'id' => 1,
		'method' => 'initialize',
		'params' => array(
			'protocolVersion' => '2024-11-05',
			'capabilities' => new stdClass(),
			'clientInfo' => array(
				'name' => 'test-client',
				'version' => '1.0.0'
			)
		)
	);

	// Make a REST request to the MCP endpoint
	$request = new WP_REST_Request('POST', '/mcp/v1/mcp');
	$request->set_header('Content-Type', 'application/json');

	// Set all the parameters
	foreach ($initialize_request as $key => $value) {
		$request->set_param($key, $value);
	}

	// Process through REST API
	$server = rest_get_server();
	$response = $server->dispatch($request);

	$data = $response->get_data();

	echo json_encode([
		'success' => true,
		'rest_response_status' => $response->get_status(),
		'rest_response_headers' => $response->get_headers(),
		'initialize_response' => $data,
		'result_structure' => isset($data['result']) ? array_keys((array)$data['result']) : 'no result key',
		'capabilities_in_result' => isset($data['result']['capabilities']) ? $data['result']['capabilities'] : 'no capabilities',
	], JSON_PRETTY_PRINT);

} catch (Exception $e) {
	http_response_code(500);
	echo json_encode([
		'success' => false,
		'error' => $e->getMessage(),
		'trace' => $e->getTraceAsString(),
	], JSON_PRETTY_PRINT);
}
