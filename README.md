# MCP Server for WordPress

[![Commit activity](https://img.shields.io/github/commit-activity/m/mcp-wp/mcp-server)](https://github.com/mcp-wp/mcp-server/pulse/monthly)
[![Code Coverage](https://codecov.io/gh/mcp-wp/mcp-server/branch/main/graph/badge.svg)](https://codecov.io/gh/mcp-wp/mcp-server)
[![License](https://img.shields.io/github/license/mcp-wp/mcp-server)](https://github.com/mcp-wp/mcp-server/blob/main/LICENSE)

[Model Context Protocol](https://modelcontextprotocol.io/) server using the WordPress REST API.

Try it by installing and activating the latest nightly build on your own WordPress website:

[![Download latest nightly build](https://img.shields.io/badge/Download%20latest%20nightly-24282D?style=for-the-badge&logo=Files&logoColor=ffffff)](https://mcp-wp.github.io/mcp-server/mcp.zip)

## Description

This WordPress plugin implements the [Streamable HTTP transport](https://modelcontextprotocol.io/specification/2025-03-26/basic/transports#streamable-http) from the MCP specification (protocol version 2025-03-26).

Under the hood it uses the [`logiscape/mcp-sdk-php`](https://github.com/logiscape/mcp-sdk-php) package to set up a fully functioning MCP server. Then, this functionality is exposed through a new `wp-json/mcp/v1/mcp` REST API route in WordPress.

### Implementation Status

**Supported Features:**
- ✅ POST-based JSON-RPC message handling
- ✅ Session management via `Mcp-Session-Id` header and cookies
- ✅ WordPress REST API tools (198+ endpoints exposed)
- ✅ Basic Auth via WordPress Application Passwords
- ✅ MCP protocol version 2025-03-26

**Not Supported:**
- ❌ Server-Sent Events (SSE) for bidirectional streaming
- ❌ GET requests (returns HTTP 405 per spec)
- ❌ OAuth authentication (uses Basic Auth instead)

This is a **POST-only** implementation suitable for clients that support Streamable HTTP without requiring SSE.

## Usage

### With Claude Code

1. **Install the plugin** on your WordPress site
2. **Create an Application Password:**
   - Go to Users → Your Profile in WordPress admin
   - Scroll to "Application Passwords"
   - Create a new password and copy it
3. **Add the MCP server to Claude Code:**
   ```bash
   claude mcp add --transport http wordpress \
     https://your-site.com/wp-json/mcp/v1/mcp \
     --header "Authorization: Basic $(echo -n 'username:app_password' | base64)"
   ```
4. **Start Claude Code** and use `/mcp` to verify connection

### With WP-CLI

This plugin works best in companion with the [WP-CLI AI command](https://github.com/mcp-wp/ai-command):

1. Run `wp plugin install --activate https://github.com/mcp-wp/mcp-server/archive/refs/heads/main.zip`
2. Run `wp plugin install --activate ai-services`
3. Run `wp package install mcp-wp/ai-command:dev-main`
4. Run `wp mcp server add "mysite" "https://example.com/wp-json/mcp/v1/mcp"`
5. Run `wp ai "Greet my friend Pascal"` or so

Note: The WP-CLI command also works on a local WordPress installation without this plugin.
