<!-- Thanks for contributing to Royal MCP! Please fill in the relevant sections. -->

## Summary

<!-- 1-3 sentences describing what this PR does and why. -->

## Changes

<!-- Bulleted list of significant code changes. -->
-
-

## Testing

<!--
How you tested. Please include:
- MCP client(s) used (Claude Desktop, mcp-remote, Cursor, Postman, etc.)
- WordPress version
- PHP version
- Any plugins that were active for integration tests (WooCommerce, GuardPress, etc.)
-->

## Security checklist

<!-- For PRs that touch tool definitions, REST routes, $_GET/$_POST handling, or DB queries. Tick each as confirmed; strike out items that don't apply. -->

- [ ] `if ( ! defined( 'ABSPATH' ) ) exit;` on line 1 of every new PHP file
- [ ] All superglobals (`$_GET`, `$_POST`, `$_REQUEST`, `$_SERVER`) wrapped in `wp_unslash()` + an appropriate `sanitize_*()` function
- [ ] All output escaped — `esc_html`, `esc_attr`, `esc_url`, or `wp_kses_post` for HTML
- [ ] All SQL uses `$wpdb->prepare()` with placeholders (`%d`, `%s`, `%i`)
- [ ] Capability checks (`current_user_can(...)`) on any state-changing action
- [ ] New REST routes have a real `permission_callback` (not `__return_true`)

## MCP tool checklist

<!-- For PRs that add or modify MCP tools. -->

- [ ] New tool registered in `tools/list` response in `includes/MCP/Server.php` (or via `royal_mcp_tools` filter for integrations)
- [ ] Tool input schemas use `new \stdClass()` (not `[]`) for empty `properties` — PHP arrays serialize to JSON `[]` and break Claude Desktop schema validation for **all** tools
- [ ] Tool name uses the correct prefix: `wp_*` for WordPress core, `<plugin>_*` for integrations (e.g. `wc_`, `gp_`, `sv_`, `fc_`, `rl_`, `rlinks_`)
- [ ] Cross-resource ownership validated before mutation (e.g. variation belongs to product before update/delete)
- [ ] Tested against Claude Desktop and `tools/list` returns no schema errors

## Versioning

> **Maintainers handle versioning** — please do not bump the version line, the version constant, or the `Stable tag:` in this PR.

- [ ] I have not modified `royal-mcp.php` `Version:` header
- [ ] I have not modified `ROYAL_MCP_VERSION` constant
- [ ] I have not modified `readme.txt` `Stable tag:` or added a changelog entry

## Related issues

<!-- Closes #XX, fixes #YY. -->
