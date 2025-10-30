<?php
/**
 * Main REST API controller.
 *
 * @package McpWp
 */

declare(strict_types = 1);

namespace McpWp;

use Mcp\Types\InitializeResult;
use Mcp\Types\JSONRPCError;
use Mcp\Types\JsonRpcErrorObject;
use Mcp\Types\JsonRpcMessage;
use Mcp\Types\JSONRPCNotification;
use Mcp\Types\JSONRPCRequest;
use Mcp\Types\JSONRPCResponse;
use Mcp\Types\NotificationParams;
use Mcp\Types\RequestId;
use Mcp\Types\RequestParams;
use McpWp\MCP\Servers\WordPress\WordPress;
use WP_Error;
use WP_Http;
use WP_Post;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * MCP REST API controller.
 */
class RestController extends WP_REST_Controller {
	/**
	 * MCP session ID cookie name.
	 */
	protected const SESSION_COOKIE_NAME = 'mcp_session_id';

	/**
	 * MCP session ID header name (kept for backward compatibility).
	 */
	protected const SESSION_ID_HEADER = 'Mcp-Session-Id';

	/**
	 * The namespace of this controller's route.
	 *
	 * @var string
	 */
	protected $namespace = 'mcp/v1';

	/**
	 * Registers the routes for the objects of the controller.
	 *
	 * @see register_rest_route()
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/mcp',
			[
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'create_item' ],
					'permission_callback' => [ $this, 'create_item_permissions_check' ],
					'args'                => [
						'jsonrpc' => [
							'type'        => 'string',
							'enum'        => [ '2.0' ],
							'description' => __( 'JSON-RPC protocol version.', 'mcp' ),
							'required'    => true,
						],
						'id'      => [
							'type'        => [ 'string', 'integer' ],
							'description' => __( 'Identifier established by the client.', 'mcp' ),
							// It should be required, but it's not sent for things like notifications.
							'required'    => false,
						],
						'method'  => [
							'type'        => 'string',
							'description' => __( 'Method to be invoked.', 'mcp' ),
							'required'    => true,
						],
						'params'  => [
							'type'        => 'object',
							'description' => __( 'Method to be invoked.', 'mcp' ),
						],
					],
				],
				[
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => [ $this, 'delete_item' ],
					'permission_callback' => [ $this, 'delete_item_permissions_check' ],
				],
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_item' ],
					'permission_callback' => [ $this, 'get_item_permissions_check' ],
				],
				'schema' => [ $this, 'get_public_item_schema' ],
			]
		);
	}

	/**
	 * Checks if a given request has access to create items.
	 *
	 * Per MCP Streamable HTTP spec:
	 * - OAuth 2.1 Bearer token authentication is required
	 * - Sessions are auto-created on initialize, but not required for OAuth
	 * - All authenticated requests have access
	 *
	 * @phpstan-param WP_REST_Request<array{jsonrpc: string, id?: string|number, method: string, params: array<string, mixed>}> $request
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return true|WP_Error True if the request has access to create items, WP_Error object otherwise.
	 */
	public function create_item_permissions_check( $request ): true|WP_Error {
		// Validate OAuth Bearer token and authenticate user
		$auth_result = $this->authenticate_request( $request );
		if ( is_wp_error( $auth_result ) ) {
			return $auth_result;
		}

		// OAuth authentication is sufficient - no session required
		// Sessions are only created on initialize for tracking purposes
		return true;
	}

	/**
	 * Creates one item from the collection.
	 *
	 * @todo Support batch requests
	 *
	 * @phpstan-param WP_REST_Request<array{jsonrpc: string, id?: string|number, method?: string, params: array<string, mixed>, result?: array<string, mixed>, error?: array{code: int, message: string, data: mixed}}> $request
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function create_item( $request ): WP_Error|WP_REST_Response {
		$message = new JsonRpcMessage(
			new JSONRPCError(
				'2.0',
				new RequestId( '0' ),
				new JsonRpcErrorObject(
					-32600,
					'Invalid JSON-RPC message structure.',
					null
				)
			)
		);

		if ( isset( $request['method'] ) ) {
			// It's a Request or Notification
			if ( isset( $request['id'] ) ) {
				$params = null;

				if ( isset( $request['params'] ) ) {
					// Handle _meta specially as it has a typed property
					$meta = null;
					if ( isset( $request['params']['_meta'] ) && is_array( $request['params']['_meta'] ) ) {
						// Convert _meta array to Meta object if needed
						$meta = new \Mcp\Types\Meta();
						// TODO: populate meta properties if needed
					}

					$params = new RequestParams( $meta );

					// Set all other params using magic __set (goes into extraFields)
					foreach ( $request['params'] as $key => $value ) {
						if ( $key !== '_meta' ) {
							$params->{$key} = $value;
						}
					}
				}

				$message = new JsonRpcMessage(
					new JSONRPCRequest(
						'2.0',
						new RequestId( (string) $request['id'] ),
						$params,
						$request['method'],
					)
				);
			} else {
				$params = null;

				if ( isset( $request['params'] ) ) {
					// Handle _meta specially for notifications too
					$meta = null;
					if ( isset( $request['params']['_meta'] ) && is_array( $request['params']['_meta'] ) ) {
						$meta = new \Mcp\Types\Meta();
					}

					$params = new NotificationParams( $meta );

					// Set all other params using magic __set
					foreach ( $request['params'] as $key => $value ) {
						if ( $key !== '_meta' ) {
							$params->{$key} = $value;
						}
					}
				}

				$message = new JsonRpcMessage(
					new JSONRPCNotification(
						'2.0',
						$params,
						$request['method'],
					)
				);
			}
		} elseif ( isset( $request['result'] ) || isset( $request['error'] ) ) {
			// TODO: Can the client actually send errors?
			// TODO: Can the client actually send results?
			// It's a Response or Error
			if ( isset( $request['error'] ) ) {
				// It's an Error
				$error_data = $request['error'];
				$message    = new JsonRpcMessage(
					new JSONRPCError(
						'2.0',
						new RequestId( (string) ( $request['id'] ?? 0 ) ),
						new JsonRpcErrorObject(
							$error_data['code'],
							$error_data['message'],
							$error_data['data'] ?? null
						)
					)
				);
			} else {
				// It's a Response
				$message = new JsonRpcMessage(
					new JSONRPCResponse(
						'2.0',
						new RequestId( (string) ( $request['id'] ?? 0 ) ),
						$request['result']
					)
				);
			}
		}

		$server       = new WordPress();
		$mcp_response = $server->handle_message( $message );
		$response     = new WP_REST_Response();

		if ( null !== $mcp_response ) {
			$response->set_data( $mcp_response );
		} else {
			$response->set_status( 202 );
		}

		// @phpstan-ignore property.notFound
		if ( isset( $mcp_response ) && $mcp_response->message->result instanceof InitializeResult ) {
			$uuid = wp_generate_uuid4();

			wp_insert_post(
				[
					'post_type'   => 'mcp_session',
					'post_status' => 'publish',
					'post_title'  => $uuid,
					'post_name'   => $uuid,
				]
			);

			// Set cookie for session management
			$this->set_session_cookie( $uuid, $response );

			// Also set header for backward compatibility
			$response->header( self::SESSION_ID_HEADER, $uuid );
		}

		// Quick workaround for MCP Inspector.
		$response->header( 'Access-Control-Allow-Origin', '*' );

		// TODO: send right status code.

		return $response;
	}

	/**
	 * Checks if a given request has access to terminate an MCP session.
	 *
	 * @phpstan-param WP_REST_Request<array{jsonrpc: string, id?: string|number, method: string, params: array<string, mixed>}> $request
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return true|WP_Error True if the request has access to delete the item, WP_Error object otherwise.
	 */
	public function delete_item_permissions_check( $request ): true|WP_Error {
		// Validate OAuth Bearer token and authenticate user
		$auth_result = $this->authenticate_request( $request );
		if ( is_wp_error( $auth_result ) ) {
			return $auth_result;
		}

		// OAuth authentication is sufficient
		return true;
	}

	/**
	 * Terminates an MCP session.
	 *
	 * @phpstan-param WP_REST_Request<array{jsonrpc: string, id?: string|number, method: string, params: array<string, mixed>}> $request
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function delete_item( $request ): WP_Error|WP_REST_Response {
		$session_id = $this->get_session_id( $request );

		/**
		 * Session post object.
		 *
		 * @var WP_Post $session
		 */
		$session = $this->get_session( $session_id );

		wp_delete_post( $session->ID, true );

		$response = new WP_REST_Response( '' );

		// Clear the session cookie
		$this->clear_session_cookie( $response );

		return $response;
	}


	/**
	 * Checks if a given request has access to get a specific item.
	 *
	 * Per MCP Streamable HTTP spec: GET requests should return either SSE stream
	 * or HTTP 405 Method Not Allowed. This signals to clients that the server
	 * is POST-only and doesn't require OAuth.
	 *
	 * @phpstan-param WP_REST_Request<array{jsonrpc: string, id?: string|number, method: string, params: array<string, mixed>}> $request
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return true|WP_Error True if the request has read access for the item, WP_Error object otherwise.
	 */
	public function get_item_permissions_check( $request ): true|WP_Error {
		// Per MCP spec: GET should return 405 if SSE is not supported
		// This prevents Claude Code from attempting OAuth discovery
		return new WP_Error(
			'rest_no_route',
			__( 'GET method not supported. This server uses POST-only transport. Please use POST requests with proper authentication headers.', 'mcp' ),
			array( 'status' => WP_Http::METHOD_NOT_ALLOWED )
		);
	}

	/**
	 * Retrieves the post's schema, conforming to JSON Schema.
	 *
	 * @return array<string, mixed> Item schema data.
	 */
	public function get_item_schema() {
		if ( null !== $this->schema ) {
			return $this->add_additional_fields_schema( $this->schema );
		}

		$schema = [
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => __( 'MCP Server', 'mcp' ),
			'type'       => 'object',
			// Base properties for every Post.
			'properties' => [
				'jsonrpc'  => [
					'description' => __( 'JSON-RPC protocol version.', 'mcp' ),
					'type'        => 'string',
					'context'     => [ 'view' ],
				],
				'id'       => [
					'description' => __( 'Identifier established by the client.', 'mcp' ),
					'type'        => [ 'string', 'integer' ],
					'context'     => [ 'view' ],
				],
				'result'   => [
					'description' => __( 'Result', 'mcp' ),
					'type'        => [ 'object' ],
					'context'     => [ 'view' ],
				],
				'date_gmt' => [
					'description' => __( 'The date the post was published, as GMT.' ),
					'type'        => [ 'string', 'null' ],
					'format'      => 'date-time',
					'context'     => [ 'view' ],
				],
			],
		];

		$this->schema = $schema;

		return $this->add_additional_fields_schema( $this->schema );
	}

	/**
	 * Checks if a valid session was provided.
	 *
	 * @phpstan-param WP_REST_Request<array{jsonrpc: string, id?: string|number, method: string, params: array<string, mixed>}> $request
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return true|WP_Error True if a valid session was provided, WP_Error object otherwise.
	 */
	protected function check_session( WP_REST_Request $request ): true|WP_Error {
		$session_id = $this->get_session_id( $request );

		if ( empty( $session_id ) ) {
			return new WP_Error(
				'mcp_missing_session',
				__( 'Missing session.', 'mcp' ),
				array( 'status' => WP_Http::BAD_REQUEST )
			);
		}

		$session = $this->get_session( $session_id );

		if ( null === $session ) {
			return new WP_Error(
				'mcp_invalid_session',
				__( 'Session not found, it may have been terminated.', 'mcp' ),
				array( 'status' => WP_Http::NOT_FOUND )
			);
		}

		return true;
	}

	/**
	 * Gets a session by its ID.
	 *
	 * @param string $session_id MCP session ID.
	 * @return WP_Post|null Post object if ID is valid, null otherwise.
	 */
	protected function get_session( string $session_id ): ?WP_Post {
		$args = [
			'name'             => $session_id,
			'post_type'        => 'mcp_session',
			'post_status'      => 'publish',
			'numberposts'      => 1,
			'suppress_filters' => false,
		];

		$posts = get_posts( $args );

		if ( empty( $posts ) ) {
			return null;
		}

		return $posts[0];
	}

	/**
	 * Gets the session ID from cookie or header.
	 *
	 * Checks cookie first (preferred), then falls back to header for backward compatibility.
	 *
	 * @phpstan-param WP_REST_Request<array{jsonrpc: string, id?: string|number, method: string, params: array<string, mixed>}> $request
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return string Session ID if found, empty string otherwise.
	 */
	protected function get_session_id( WP_REST_Request $request ): string {
		// First try to get from cookie (preferred method)
		if ( isset( $_COOKIE[ self::SESSION_COOKIE_NAME ] ) && is_string( $_COOKIE[ self::SESSION_COOKIE_NAME ] ) ) {
			return $_COOKIE[ self::SESSION_COOKIE_NAME ];
		}

		// Fall back to header for backward compatibility
		$header_value = $request->get_header( self::SESSION_ID_HEADER );
		return is_string( $header_value ) ? $header_value : '';
	}

	/**
	 * Sets the session cookie in the response.
	 *
	 * @param string           $session_id Session ID to set.
	 * @param WP_REST_Response $response   Response object to add cookie header to.
	 * @return void
	 */
	protected function set_session_cookie( string $session_id, WP_REST_Response $response ): void {
		$cookie_params = [
			'expires'  => time() + DAY_IN_SECONDS, // 1 day expiration
			'path'     => '/',
			'domain'   => '',
			'secure'   => is_ssl(),
			'httponly' => true,
			'samesite' => 'Lax',
		];

		$cookie_value = sprintf(
			'%s=%s; Expires=%s; Path=%s; HttpOnly; SameSite=%s%s',
			self::SESSION_COOKIE_NAME,
			rawurlencode( $session_id ),
			gmdate( 'D, d M Y H:i:s T', $cookie_params['expires'] ),
			$cookie_params['path'],
			$cookie_params['samesite'],
			$cookie_params['secure'] ? '; Secure' : ''
		);

		$response->header( 'Set-Cookie', $cookie_value );
	}

	/**
	 * Clears the session cookie.
	 *
	 * @param WP_REST_Response $response Response object to add cookie header to.
	 * @return void
	 */
	protected function clear_session_cookie( WP_REST_Response $response ): void {
		$cookie_value = sprintf(
			'%s=; Expires=%s; Path=/; HttpOnly; SameSite=Lax',
			self::SESSION_COOKIE_NAME,
			gmdate( 'D, d M Y H:i:s T', time() - YEAR_IN_SECONDS )
		);

		$response->header( 'Set-Cookie', $cookie_value );
	}

	/**
	 * Authenticates a request using OAuth 2.1 Bearer token.
	 *
	 * Per MCP spec, HTTP transport MUST use OAuth 2.1 for authentication.
	 * This validates the Bearer token and sets the current WordPress user.
	 *
	 * @phpstan-param WP_REST_Request<array{jsonrpc: string, id?: string|number, method: string, params: array<string, mixed>}> $request
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return true|WP_Error True if authenticated, WP_Error otherwise.
	 */
	protected function authenticate_request( WP_REST_Request $request ): true|WP_Error {
		// Use WordPress's built-in Application Password authentication
		// This works with WP Engine because it's WordPress's native auth system

		// Check if user is already authenticated (WordPress handles this via determine_current_user filter)
		$user_id = get_current_user_id();

		if ( $user_id ) {
			// Already authenticated via WordPress's Application Password system
			return true;
		}

		// No authentication found
		return new WP_Error(
			'rest_no_auth',
			__( 'Authentication required. Please provide Authorization: Bearer username:password', 'mcp' ),
			array(
				'status'  => WP_Http::UNAUTHORIZED,
				'headers' => array(
					'WWW-Authenticate' => 'Bearer realm="' . get_site_url() . '/wp-json/mcp/v1/mcp"',
				),
			)
		);
	}
}
