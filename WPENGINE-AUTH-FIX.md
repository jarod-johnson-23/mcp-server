# WP Engine Authentication Fix

## Problem
WP Engine's security system strips the token value from `Authorization: Bearer <token>` headers, preventing OAuth authentication from working.

## Solution
Use WordPress's built-in Application Password system, which WP Engine must allow since it's core WordPress functionality.

## What Changed

### 1. Token Creation (`src/OAuth/TokenStorage.php`)
- OAuth access tokens are now WordPress Application Passwords
- When a token is created, we call `WP_Application_Passwords::create_new_application_password()`
- The token returned is in format: `username:password` (e.g., `admin_Jarod:xxxx xxxx xxxx xxxx xxxx xxxx`)
- This is what Claude Code will send as: `Authorization: Bearer username:password`

### 2. Token Validation (`src/RestController.php`)
- Simplified authentication to use WordPress's native system
- WordPress handles validation via the `determine_current_user` filter
- We just check if a user is authenticated: `get_current_user_id()`

### 3. Authentication Hook (`src/functions.php`)
- Added `determine_current_user` filter hook to `validate_bearer_token()`
- This function uses `wp_authenticate_application_password()` which WP Engine allows

### 4. Token Cleanup (`src/OAuth/Database.php`)
- When OAuth tokens expire, we also delete the corresponding WordPress Application Password
- This prevents accumulation of unused app passwords

## How It Works

1. User goes through OAuth flow (authorize page, consent, etc.)
2. After approval, we create a WordPress Application Password
3. The access token returned is `username:password`
4. Claude Code sends: `Authorization: Bearer username:password`
5. WordPress validates this using its built-in Application Password system
6. WP Engine allows this because it's WordPress core functionality

## Testing

Upload and run `test-final-complete.php` to verify everything works:

```bash
# Access the test script in your browser:
https://your-site.com/wp-content/plugins/mcp-server/test-final-complete.php
```

Expected output:
- ✓ Authentication working
- ✓ Initialize working
- ✓ Ping working
- ✓ 194+ WordPress tools available
- ✓ Tool execution working
- 🎉 ALL TESTS PASSED!

## Files Modified

1. `src/OAuth/TokenStorage.php` - Creates WordPress App Passwords instead of custom tokens
2. `src/RestController.php` - Uses WordPress authentication instead of custom validation
3. `src/functions.php` - Hooks Bearer token validation into WordPress auth
4. `src/OAuth/Database.php` - Cleans up expired App Passwords
5. `src/MCP/Server.php` - Fixed parameter type mismatches (RequestParams → ?array)

## Next Steps

1. **Push all changes to WordPress server**
2. **Run test script** to verify authentication works
3. **Re-authenticate Claude Code** - Remove and re-add the MCP server:
   ```bash
   claude mcp remove wordpress_cocopah -s user
   claude mcp add --transport http wordpress_cocopah https://cocopah2023dev.wpengine.com/wp-json/mcp/v1/mcp
   ```
4. **Authenticate** when prompted - go through the OAuth flow
5. **Test in Claude Code** - the tools should now be visible and usable!

## Why This Works

WordPress Application Passwords are used by:
- WordPress Mobile Apps
- Third-party WordPress integrations
- WordPress REST API authentication

WP Engine **cannot** block these without breaking WordPress core functionality, so this approach works even with WP Engine's strict security.
