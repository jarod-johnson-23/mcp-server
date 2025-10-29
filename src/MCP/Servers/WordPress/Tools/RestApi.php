<?php

namespace McpWp\MCP\Servers\WordPress\Tools;

use McpWp\MCP\Server;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use WP_REST_Request;
use WP_REST_Response;

/**
 * REST API tools class.
 *
 * @phpstan-import-type ToolDefinition from Server
 * @phpstan-import-type ToolInputSchema from Server
 * @phpstan-type ArgumentSchema array{description?: string, type?: string, required?: bool}
 */
readonly class RestApi {
	private LoggerInterface $logger;

	/**
	 * Constructor.
	 *
	 * @param LoggerInterface|null $logger Logger.
	 */
	public function __construct( ?LoggerInterface $logger = null ) {
		$this->logger = $logger ?? new NullLogger();
	}

	/**
	 * Returns a list of tools for all REST API routes.
	 *
	 * @throws \Exception
	 *
	 * @return array<int, ToolDefinition> Tools.
	 */
    public function get_tools( array $opts = [] ): array {
		$server     = rest_get_server();
		$routes     = $server->get_routes();
		$namespaces = $server->get_namespaces();
		$tools      = [];

		foreach ( $routes as $route => $handlers ) {
			// Do not include namespace routes in the response.
			if ( in_array( ltrim( $route, '/' ), $namespaces, true ) ) {
				continue;
			}

			// Optional allowlist of namespaces: only include routes whose namespace is in the allowlist.
			if ( isset( $opts['namespaces'] ) && is_array( $opts['namespaces'] ) && ! empty( $opts['namespaces'] ) ) {
				$trimmed = ltrim( $route, '/' );
				$parts   = explode( '/', $trimmed, 3 );
				$ns_part = $parts[0] ?? '';
				$ver_part = $parts[1] ?? '';
				$route_namespace = '' !== $ver_part ? $ns_part . '/' . $ver_part : $ns_part;

				if ( ! in_array( $route_namespace, $opts['namespaces'], true ) ) {
					continue;
				}
			}

			/**
			 * @param array{methods: array<string, mixed>, accept_json: bool, accept_raw: bool, show_in_index: bool, args: array, callback: array, permission_callback?: array} $handler
			 */
			foreach ( $handlers as $handler ) {

				/**
				 * Methods for this route, e.g. 'GET', 'POST', 'PUT', etc.
				 *
				 * @var string[] $methods
				 */
				$methods = array_keys( $handler['methods'] );

				// If WP_REST_Server::EDITABLE is used, keeo only POST but not PUT or PATCH.
				if ( in_array( 'POST', $methods, true ) ) {
					$methods = array_diff( $methods, [ 'PUT', 'PATCH' ] );
				}

				foreach ( $methods as $method ) {
					$title = '';

					if (
						in_array(
							"$method $route",
							[
								'GET/',
								'POST /batch/v1',
							],
							true
						)
					) {
						continue;
					}

					if (
						is_array( $handler['callback'] ) &&
						isset( $handler['callback'][0] ) &&
						$handler['callback'][0] instanceof \WP_REST_Controller
					) {
						$controller = $handler['callback'][0];
						$schema     = $controller->get_public_item_schema();
						if ( isset( $schema['title'] ) ) {
							$title = $schema['title'];
						}
					}

					if ( isset( $handler['permission_callback'] ) && is_callable( $handler['permission_callback'] ) ) {
						$has_required_parameter = (bool) preg_match_all( '/\(?P<(\w+)>/', $route );

						if ( ! $has_required_parameter ) {
							/**
							 * Permission callback result.
							 *
							 * @var bool|\WP_Error $result
							 */
							$result = call_user_func( $handler['permission_callback'], new WP_REST_Request() );
							if ( true !== $result ) {
								continue;
							}
						}
					}

					$information = new RouteInformation(
						$route,
						$method,
						$title,
					);

					// Autosaves or revisions controller could come up multiple times, skip in that case.
					if ( array_key_exists( $information->get_name(), $tools ) ) {
						continue;
					}

					$tool = [
						'name'        => $information->get_name(),
						'description' => $information->get_description(),
						'inputSchema' => $this->args_to_schema( $handler['args'] ),
						'annotations' => [
							// A human-readable title for the tool.
							'title'           => null, // TODO: Add titles.
							// If true, the tool does not modify its environment.
							'readOnlyHint'    => 'GET' === $method,
							// This property is meaningful only when `readOnlyHint == false`
							'idempotentHint'  => 'GET' === $method,
							// Whether the tool may perform destructive updates to its environment.
							// This property is meaningful only when `readOnlyHint == false`
							'destructiveHint' => 'DELETE' === $method,
						],
						'callback'    => function ( $params ) use ( $route, $method ) {
							return json_encode( $this->rest_callable( $route, $method, $params ), JSON_THROW_ON_ERROR );
						},
					];

					$tools[ $information->get_name() ] = $tool;
				}
			}
		}

		return array_values( $tools );
	}

	/**
	 * REST route tool callback.
	 *
	 * @throws \JsonException
	 *
	 * @param string $route Route
	 * @param string $method HTTP method.
	 * @param array<string, mixed> $params Route params.
	 * @return array<string, mixed> REST response data.
	 */
	private function rest_callable( string $route, string $method, array $params ): array {
		$server = rest_get_server();

		preg_match_all( '/\(?P<(\w+)>/', $route, $matches );

		foreach ( $matches[1] as $match ) {
			if ( array_key_exists( $match, $params ) ) {
				$route = (string) preg_replace(
					'/(\(\?P<' . $match . '>.*?\))/',
					// @phpstan-ignore cast.string
					(string) $params[ $match ],
					$route,
					1
				);
				unset( $params[ $match ] );
			}
		}

		// Fix incorrect meta inputs.
		if ( isset( $params['meta'] ) ) {
			if ( false === $params['meta'] || '' === $params['meta'] || [] === $params['meta'] ) {
				unset( $params['meta'] );
			}
		}

		$this->logger->debug( "$method $route with input: " . json_encode( $params, JSON_THROW_ON_ERROR ) );

		$request = new WP_REST_Request( $method, $route );
		$request->set_body_params( $params );

		/**
		 * REST API response.
		 *
		 * @var WP_REST_Response $response
		 */
		$response = $server->dispatch( $request );

		/**
		 * Response data.
		 *
		 * @phpstan-var array<string, mixed> $data
		 */
		$data = $server->response_to_data( $response, false ); // @phpstan-ignore varTag.type

		// Reduce amount of data that is returned.
		unset( $data['_links'], $data['_embedded'] );

		foreach ( $data as &$item ) {
			if ( is_array( $item ) ) {
				unset( $item['_links'], $item['_embedded'] );
			}
		}

		return $data;
	}

	/**
	 * @throws \Exception
	 *
	 * @param array<string, mixed> $args REST API route arguments.
	 * @return array<string, mixed> Normalized schema.
	 *
	 * @phpstan-param array<string, ArgumentSchema> $args REST API route arguments.
	 * @phpstan-return ToolInputSchema
	 */
	private function args_to_schema( array $args = [] ): array {
		$schema   = [];
		$required = [];

		if ( empty( $args ) ) {
			return [
				'type'       => 'object',
				'properties' => [],
				'required'   => [],
			];
		}

		foreach ( $args as $title => $arg ) {
			if ( ! is_array( $arg ) ) {
				$schema[ $title ] = [ 'type' => 'string' ];
				continue;
			}

			$description = $arg['description'] ?? $title;
			$type        = $this->sanitize_type( $arg['type'] ?? 'string' );

			$property = [
				'type'        => $type,
				'description' => $description,
			];

			// Preserve enum and default if present.
			if ( isset( $arg['enum'] ) ) {
				$property['enum'] = $arg['enum'];
			}
			if ( array_key_exists( 'default', $arg ) ) {
				$property['default'] = $arg['default'];
			}

			// Handle array items typing defensively.
			if ( 'array' === $type && isset( $arg['items'] ) && is_array( $arg['items'] ) ) {
				$item_type = 'string';
				if ( isset( $arg['items']['type'] ) ) {
					$item_type = $this->sanitize_type( $arg['items']['type'] );
				}
				$property['items'] = [ 'type' => $item_type ];
			}

			$schema[ $title ] = $property;

			if ( isset( $arg['required'] ) && true === $arg['required'] ) {
				$required[] = $title;
			}
		}

		return [
			'type'       => 'object',
			'properties' => $schema,
			'required'   => $required,
		];
	}

	/**
	 * Normalize a type from REST API schema to MCP PHP SDK JSON schema.
	 *
	 * @param mixed $type Type.
	 * @return string Normalized type.
	 * @throws \Exception
	 */
	private function sanitize_type( $type ): string {
		// Coerce and normalize to supported JSON Schema primitives.
		$normalize = static function ( string $t ): string {
			$t = strtolower( trim( $t ) );
			if ( 'int' === $t ) {
				return 'integer';
			}
			if ( 'bool' === $t ) {
				return 'boolean';
			}
			if ( 'float' === $t || 'double' === $t ) {
				return 'number';
			}
			// Accept already-valid primitives
			if ( in_array( $t, [ 'string', 'integer', 'number', 'boolean', 'array', 'object' ], true ) ) {
				return $t;
			}
			// Everything else becomes string (mixed, scalar, callable, null, unknown, etc.)
			return 'string';
		};

		// Handle non-array types quickly.
		if ( ! is_array( $type ) ) {
			if ( empty( $type ) ) {
				return 'string';
			}
			return $normalize( (string) $type );
		}

		// If array can be either list of allowed types or associative with 'type'.
		if ( array_key_exists( 'type', $type ) ) {
			return $this->sanitize_type( $type['type'] );
		}

		// List of possible types: prefer string, integer, number, boolean, array, object.
		$preferred = [ 'string', 'integer', 'number', 'boolean', 'array', 'object' ];
		foreach ( $preferred as $p ) {
			if ( in_array( $p, $type, true ) ) {
				return $p;
			}
		}

		// Fallback
		return 'string';
	}
}
