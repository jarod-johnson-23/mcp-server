<?php

namespace McpWp\MCP;

use InvalidArgumentException;
use Mcp\Server\NotificationOptions;
use Mcp\Server\Server as McpServer;
use Mcp\Shared\ErrorData;
use Mcp\Shared\McpError;
use Mcp\Shared\Version;
use Mcp\Types\CallToolResult;
use Mcp\Types\Implementation;
use Mcp\Types\InitializeResult;
use Mcp\Types\JSONRPCError;
use Mcp\Types\JsonRpcErrorObject;
use Mcp\Types\JsonRpcMessage;
use Mcp\Types\JSONRPCNotification;
use Mcp\Types\JSONRPCRequest;
use Mcp\Types\JSONRPCResponse;
use Mcp\Types\ListResourcesResult;
use Mcp\Types\ListResourceTemplatesResult;
use Mcp\Types\ListToolsResult;
use Mcp\Types\ReadResourceResult;
use Mcp\Types\RequestId;
use Mcp\Types\RequestParams;
use Mcp\Types\Resource;
use Mcp\Types\ResourceTemplate;
use Mcp\Types\Result;
use Mcp\Types\TextContent;
use Mcp\Types\TextResourceContents;
use Mcp\Types\Tool;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * @phpstan-type ToolInputSchema array{type: string, properties: array<string, mixed>, required: string[]}
 * @phpstan-type ToolDefinition array{name: string, description?: string, callback: callable, inputSchema: ToolInputSchema}
 */
class Server {
	/**
	 * @var array<string, array{tool: Tool, callback: callable}>
	 */
	private array $tools = [];

	/**
	 * @var Array<Resource>
	 */
	private array $resources = [];

	/**
	 * @var Array<ResourceTemplate>
	 */
	private array $resource_templates = [];

	protected McpServer $mcp_server;

	protected LoggerInterface $logger;

	public function __construct( private readonly string $name, ?LoggerInterface $logger = null ) {
		$this->logger = $logger ?? new NullLogger();

		$this->mcp_server = new McpServer( $name, $this->logger );

		$this->mcp_server->registerHandler(
			'initialize',
			[ $this, 'initialize' ]
		);

		$this->mcp_server->registerHandler(
			'tools/list',
			[ $this, 'list_tools' ]
		);

		$this->mcp_server->registerHandler(
			'tools/call',
			[ $this, 'call_tool' ]
		);

		$this->mcp_server->registerHandler(
			'resources/list',
			[ $this, 'list_resources' ]
		);

		$this->mcp_server->registerHandler(
			'resources/read',
			[ $this, 'read_resources' ]
		);

		$this->mcp_server->registerHandler(
			'resources/templates/list',
			[ $this, 'list_resource_templates' ]
		);

		$this->mcp_server->registerNotificationHandler(
			'notifications/initialized',
			[ $this, 'do_nothing' ]
		);
	}

	public function do_nothing(): void {
		// Do nothing.
	}

	/**
	 * Registers a new MCP tool.
	 *
	 * @param ToolDefinition $tool_definition Tool definition.
	 * @return void
	 */
	public function register_tool( array $tool_definition ): void {
		$name     = $tool_definition['name'];
		$callable = $tool_definition['callback'];

		if ( strlen( $name ) > 64 ) {
			if ( 1 !== preg_match( '/^[a-zA-Z0-9_-]{1,64}$/', $name ) ) {
				throw new InvalidArgumentException( "Tool names should match pattern '^[a-zA-Z0-9_-]{1,64}$'. Received: '$name'." );
			}
		}

		if ( array_key_exists( $name, $this->tools ) ) {
			throw new InvalidArgumentException( "Tool $name is already registered" );
		}

		foreach ( $tool_definition['inputSchema']['properties'] as $property => $schema ) {
			// Anthropic has strict requirements for property keys.
			if ( 1 !== preg_match( '/^[a-zA-Z0-9_-]{1,64}$/', $property ) ) {
				throw new InvalidArgumentException( "Property keys should match pattern '^[a-zA-Z0-9_-]{1,64}$'. Received: '$property' (tool: $name)." );
			}
		}

		$this->tools[ $name ] = [
			'tool'     => Tool::fromArray( $tool_definition ),
			'callback' => $callable,
		];
	}

	public function initialize(): InitializeResult {
		return new InitializeResult(
			capabilities: $this->mcp_server->getCapabilities( new NotificationOptions(), [] ),
			serverInfo: new Implementation(
				$this->name,
				'0.0.1', // TODO: Make dynamic.
			),
			protocolVersion: Version::LATEST_PROTOCOL_VERSION
		);
	}

	// TODO: Implement pagination, see https://spec.modelcontextprotocol.io/specification/2024-11-05/server/utilities/pagination/#response-format
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
	public function list_tools( ?array $params ): ListToolsResult {
		$prepared_tools = [];
		foreach ( $this->tools as $tool ) {
			$prepared_tools[] = $tool['tool'];
		}

		return new ListToolsResult( $prepared_tools );
	}

	public function call_tool( ?array $params ): CallToolResult {
		$tool_name = $params['name'] ?? null;
		$arguments = $params['arguments'] ?? [];

		if ( ! $tool_name ) {
			throw new InvalidArgumentException( 'Tool name is required' );
		}

		$found_tool = null;
		foreach ( $this->tools as $name => $tool ) {
			if ( $name === $tool_name ) {
				$found_tool = $tool;
				break;
			}
		}

		if ( null === $found_tool ) {
			throw new InvalidArgumentException( "Unknown tool: {$tool_name}" );
		}

		$result = call_user_func( $found_tool['callback'], $arguments );

		if ( $result instanceof CallToolResult ) {
			return $result;
		}

		if ( is_wp_error( $result ) ) {
			return new CallToolResult(
				[
					new TextContent(
						$result->get_error_message()
					),
				],
				true
			);
		}

		if ( is_string( $result ) ) {
			$result = [ new TextContent( $result ) ];
		}

		if ( ! is_array( $result ) ) {
			$result = [ $result ];
		}
		return new CallToolResult( $result );
	}

	// TODO: Implement pagination, see https://spec.modelcontextprotocol.io/specification/2024-11-05/server/utilities/pagination/#response-format
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
	public function list_resources(): ListResourcesResult {
		return new ListResourcesResult( $this->resources );
	}

	// TODO: Make dynamic.
	public function read_resources( ?array $params ): ReadResourceResult {
		$uri = $params['uri'] ?? null;

		if ( ! $uri ) {
			throw new InvalidArgumentException( 'Resource URI is required' );
		}

		if ( 'example://greeting' !== $uri ) {
			throw new InvalidArgumentException( "Unknown resource: {$uri}" );
		}

		return new ReadResourceResult(
			[
				new TextResourceContents(
					'Hello from the example MCP server!',
					$uri,
					'text/plain'
				),
			]
		);
	}

	/**
	 * Registers a single resource.
	 *
	 * @param Resource $res Resource
	 * @return void
	 */
	public function register_resource( Resource $res ): void {
		$this->resources[ $res->name ] = $res;
	}

	// TODO: Implement pagination, see https://spec.modelcontextprotocol.io/specification/2024-11-05/server/utilities/pagination/#response-format
    // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
	public function list_resource_templates( ?array $params ): ListResourceTemplatesResult {
		return new ListResourceTemplatesResult( $this->resource_templates );
	}

	/**
	 * Registers a resource template.
	 *
	 * @param ResourceTemplate $resource_template Resource template.
	 * @return void
	 */
	public function register_resource_template( ResourceTemplate $resource_template ): void {
		$this->resource_templates[ $resource_template->name ] = $resource_template;
	}

	/**
	 * Processes an incoming message from the client.
	 *
	 * @param JsonRpcMessage $message
	 * @return JsonRpcMessage|void|null
	 */
	public function handle_message( JsonRpcMessage $message ) {
		$this->logger->debug( 'Received message: ' . json_encode( $message ) );

		$inner_message = $message->message;

		try {
			if ( $inner_message instanceof JSONRPCRequest ) {
				// It's a request
				return $this->process_request( $inner_message );
			}

			if ( $inner_message instanceof JSONRPCNotification ) {
				// It's a notification
				$this->process_notification( $inner_message );
				return null;
			}

			// Server does not expect responses from client; ignore or log
			$this->logger->warning( 'Received unexpected message type: ' . get_class( $inner_message ) );
		} catch ( McpError $e ) {
			if ( $inner_message instanceof JSONRPCRequest ) {
				return $this->send_error( $inner_message->id, $e->error );
			}
		} catch ( \Exception $e ) {
			$this->logger->error( 'Error handling message: ' . $e->getMessage() );
			if ( $inner_message instanceof JSONRPCRequest ) {
				// Code -32603 is Internal error as per JSON-RPC spec
				return $this->send_error(
					$inner_message->id,
					new ErrorData(
						-32603,
						$e->getMessage()
					)
				);
			}
		}
	}

	/**
	 * Processes a JSONRPCRequest message.
	 */
	private function process_request( JSONRPCRequest $request ): JsonRpcMessage {
		$method   = $request->method;
		$handlers = $this->mcp_server->getHandlers();
		$handler  = $handlers[ $method ] ?? null;

		if ( null === $handler ) {
			throw new McpError(
				new ErrorData(
					-32601, // Method not found
					"Method not found: {$method}"
				)
			);
		}

		// Get params - may already be an array from RestController, or could be an object
		$params = $request->params ?? null;
		if ( $params !== null && ! is_array( $params ) ) {
			// Convert object to array recursively (for backwards compatibility)
			$params = json_decode( json_encode( $params ), true );
		}

		$result = $handler( $params );

		if ( ! $result instanceof Result ) {
			$result = new Result();
		}

		return $this->send_response( $request->id, $result );
	}

	/**
	 * Processes a JSONRPCNotification message.
	 */
	private function process_notification( JSONRPCNotification $notification ): void {
		$method   = $notification->method;
		$handlers = $this->mcp_server->getNotificationHandlers();
		$handler  = $handlers[ $method ] ?? null;

		if ( null !== $handler ) {
			// Get params - may already be an array from RestController, or could be an object
			$params = $notification->params ?? null;
			if ( $params !== null && ! is_array( $params ) ) {
				// Convert object to array recursively (for backwards compatibility)
				$params = json_decode( json_encode( $params ), true );
			}
			$handler( $params );
		} else {
			$this->logger->warning( "No handler registered for notification method: $method" );
		}
	}

	/**
	 * Sends a response to a request.
	 *
	 * @param RequestId $id The request ID to respond to.
	 * @param Result    $result The result object.
	 */
	private function send_response( RequestId $id, Result $result ): JsonRpcMessage {
		// Create a JSONRPCResponse object and wrap in JsonRpcMessage
		$response = new JSONRPCResponse(
			'2.0',
			$id,
			$result
		);
		$response->validate();

		return new JsonRpcMessage( $response );
	}


	/**
	 * Sends an error response to a request.
	 *
	 * @param RequestId $id The request ID to respond to.
	 * @param ErrorData $error The error data.
	 */
	private function send_error( RequestId $id, ErrorData $error ): JsonRpcMessage {
		$error_object = new JsonRpcErrorObject(
			$error->code,
			$error->message,
			$error->data ?? null
		);

		$response = new JSONRPCError(
			'2.0',
			$id,
			$error_object
		);
		$response->validate();

		return new JsonRpcMessage( $response );
	}
}
