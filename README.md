<div align="center">

# Royal MCP

**Security-first MCP server for WordPress.** Connect Claude, ChatGPT, and Gemini to your WordPress site with API key + OAuth 2.1 authentication, full activity logging, and capability-gated access.

[![WordPress](https://img.shields.io/badge/WordPress-5.8+-21759B?style=flat-square&logo=wordpress)](https://wordpress.org/plugins/royal-mcp/)
[![PHP](https://img.shields.io/badge/PHP-7.4+-777BB4?style=flat-square&logo=php)](https://www.php.net/)
[![License](https://img.shields.io/badge/License-GPLv2-blue?style=flat-square)](https://www.gnu.org/licenses/gpl-2.0.html)
[![Version](https://img.shields.io/badge/Version-1.4.22-C9A227?style=flat-square)](https://wordpress.org/plugins/royal-mcp/)

[Download on WordPress.org](https://wordpress.org/plugins/royal-mcp/) · [Documentation](https://royalplugins.com/support/royal-mcp/) · [Royal Plugins](https://royalplugins.com)

</div>

---

A WordPress plugin that exposes your site as a [Model Context Protocol](https://modelcontextprotocol.io/) server. AI agents — Claude.ai web, Claude Desktop, ChatGPT, custom clients — can read and write posts, pages, media, users, menus, WooCommerce orders, and Elementor pages, with every call going through capability gating, rate limiting, and an audit log. Distributed via the official WordPress.org plugin directory.

## Quick facts

| | |
|---|---|
| **Auth** | API key (`X-Royal-MCP-API-Key`) **or** OAuth 2.1 with PKCE + Dynamic Client Registration (RFC 7591) |
| **Transport** | MCP 2025-11-25 Streamable HTTP (single `/mcp` endpoint, POST/GET/DELETE) |
| **Tool count** | Up to 122 (67 WordPress core + 55 conditional plugin integrations) |
| **Rate limit** | 60 req/min per IP (configurable) |
| **Session model** | Sliding 24h TTL with refresh-on-access |
| **Activity log** | Every tool call logged (tool name + arg keys; argument values are never recorded) |
| **Distribution** | [wp.org plugin directory](https://wordpress.org/plugins/royal-mcp/) + GitHub releases + auto-update via WP admin |
| **Tested** | PHP 7.4 → 8.3, WordPress 5.8 → 7.0 |
| **License** | GPLv2+ |

## Capabilities

### WordPress core (67 tools, always available)

- **Content** — Posts, pages, custom post types (full CRUD + revisions + featured images)
- **Taxonomies** — Categories, tags, custom taxonomies, term meta, post-term linking
- **Media** — Browse, upload (URL or base64), update metadata, delete
- **Comments** — Create, moderate (approve / spam / trash)
- **Users** — Read display names + roles (emails and usernames are not exposed)
- **Menus** — Read, create items, reorder, update with destructive-write guardrails
- **Theme** — Custom CSS, theme mods, active theme detection
- **Site** — Permalink structure, options (allowlisted), site info
- **Search** — Cross-content search by query
- **SEO** — Yoast / Rank Math / AIOSEO meta read/write where the plugin is active

### Plugin integrations (55 tools, conditional)

Auto-register only when the integrated plugin is active.

| Plugin | Tools | What's covered |
|---|---|---|
| WooCommerce | 26 | Products, variations, attributes, coupons, orders, customers, store stats |
| GuardPress | 7 | Security score, failed logins, blocked IPs, vulnerability scans, audit log |
| SiteVault | 6 | Trigger backups, monitor progress, list schedules |
| Royal Ledger | 4 | Software costs, renewal dates, license keys (values never exposed) |
| Royal Links | 3 | Branded short links, click stats |
| ForgeCache | 3 | Cache stats, clear cache, purge URL |
| **Elementor** (new in 1.4.19) | **6** | **Clone-and-customize workflow: clone pages, replace text, swap images, get outline, list templates, import templates** |

## What we don't do

Explicit scope boundaries — the integration model is "narrow tools that work reliably," not "expose every API surface."

- **No widget-level Elementor generation from scratch.** Atomic widgets (Editor V4) pass through opaque; we never decode atomic schemas because Elementor itself may shift them.
- **No Beaver Builder / Divi / Bricks page-builder JSON writes.** Standard post content is readable and writable; page-builder-specific JSON storage is opaque unless covered by a dedicated tool.
- **No theme builder template creation** (Elementor or otherwise).
- **No core file modifications** — Royal MCP never writes to `wp-content/themes`, `wp-includes`, or `wp-admin`.
- **No plugin installation or upgrades via MCP.** Discovery yes; install/activate/deactivate no.
- **No raw SQL.** Queries go through `WP_Query` and `$wpdb->prepare()` only.

## Connect

### Install

1. Install from [WordPress.org](https://wordpress.org/plugins/royal-mcp/) (recommended — auto-updates via WP admin) or upload the GitHub release zip.
2. **Royal MCP → Settings** → click *Generate API Key*.
3. Pick a client below.

### Claude.ai web (OAuth — recommended)

Easiest path — no config file edits, no API key in your client.

1. In Claude.ai → **Settings → Connectors → Add Custom Connector**.
2. URL: `https://yoursite.com/wp-json/royal-mcp/v1/mcp`
3. Approve the OAuth consent screen when prompted. Claude.ai handles dynamic client registration + PKCE flow against your site.

### Claude Desktop (OAuth via mcp-remote)

```json
{
  "mcpServers": {
    "my-wordpress": {
      "command": "npx",
      "args": ["-y", "mcp-remote", "https://yoursite.com/wp-json/royal-mcp/v1/mcp"]
    }
  }
}
```

Config path: `~/Library/Application Support/Claude/claude_desktop_config.json` (macOS) or `%APPDATA%\Claude\claude_desktop_config.json` (Windows).

### Claude Desktop (API key)

Skip OAuth and authenticate via header:

```json
{
  "mcpServers": {
    "my-wordpress": {
      "command": "npx",
      "args": [
        "-y", "mcp-remote",
        "https://yoursite.com/wp-json/royal-mcp/v1/mcp",
        "--header", "X-Royal-MCP-API-Key:YOUR_API_KEY"
      ]
    }
  }
}
```

### ChatGPT

ChatGPT's custom MCP connector takes the same URL as Claude.ai web. Follow ChatGPT's connector flow and paste `https://yoursite.com/wp-json/royal-mcp/v1/mcp`.

### Raw HTTP (custom clients)

```bash
# 1. Initialize a session. -i prints headers so you can grab Mcp-Session-Id.
curl -i -X POST https://yoursite.com/wp-json/royal-mcp/v1/mcp \
  -H "X-Royal-MCP-API-Key: YOUR_KEY" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json, text/event-stream" \
  -d '{
    "jsonrpc": "2.0",
    "method": "initialize",
    "id": 1,
    "params": {
      "protocolVersion": "2025-11-25",
      "capabilities": {},
      "clientInfo": {"name": "my-app", "version": "1.0"}
    }
  }'

# 2. List available tools using the session id from the response header.
curl -X POST https://yoursite.com/wp-json/royal-mcp/v1/mcp \
  -H "X-Royal-MCP-API-Key: YOUR_KEY" \
  -H "Mcp-Session-Id: <session_id>" \
  -H "Content-Type: application/json" \
  -d '{"jsonrpc": "2.0", "method": "tools/list", "id": 2}'
```

## Security model

| Layer | What it does |
|---|---|
| **API key** | 32-char hex, timing-safe comparison. Sent via `X-Royal-MCP-API-Key` header. Regenerate from admin without server restart. |
| **OAuth 2.1** | RFC 7591 Dynamic Client Registration, RFC 8414 metadata, PKCE S256 required, refresh tokens supported. No implicit grant. No client_credentials grant. |
| **Capability gating** | Every tool checks WordPress capabilities. `edit_posts` for create/update, `manage_options` for site settings, `edit_post` per-post for individual operations. |
| **Rate limiting** | 60 requests/minute per IP, sliding window. |
| **Session model** | Sliding 24h TTL with refresh-on-access. Cryptographically secure 32-byte session IDs. |
| **Activity log** | Every tool call writes a row to a database log. Records: tool name, argument keys, IP, User-Agent, errors. **Never** records argument values (they may contain customer data). |
| **OAuth state recovery** | One-click *Reset OAuth State* admin button wipes all clients + tokens + auth codes, without affecting your API key or settings. |
| **Discovery** | `.well-known/oauth-authorization-server` and `.well-known/oauth-protected-resource` served at site root per RFC 8414 + RFC 9728. |

Full security architecture: [royalplugins.com/support/royal-mcp/](https://royalplugins.com/support/royal-mcp/)

## Project status

- **Active maintenance** — releases roughly weekly. See [releases](https://github.com/royalplugins/royal-mcp/releases) for changelog.
- **MCP spec compliance** — implements the [Streamable HTTP transport (2025-11-25)](https://modelcontextprotocol.io/specification/2025-11-25/basic/transports#streamable-http).
- **Issues** — [github.com/royalplugins/royal-mcp/issues](https://github.com/royalplugins/royal-mcp/issues). Customer-impact issues are typically acknowledged within 24h and triaged with version targets.
- **Contributing** — PRs welcome. The plugin source is committed to both this repo and the wp.org SVN trunk; releases are coordinated through the wp.org review process.

## Related projects

- [GuardPress](https://royalplugins.com/guardpress/) — WordPress security hardening
- [SiteVault](https://royalplugins.com/sitevault/) — WordPress backups and migration
- [ForgeCache](https://royalplugins.com/forgecache/) — Caching and performance
- [FormForge](https://royalplugins.com/formforge/) — Form builder with PDF generation
- [SEObolt](https://royalplugins.com/seobolt/) — SEO toolkit for WordPress

## License

GPLv2 or later — see [LICENSE](LICENSE) or the [GNU site](https://www.gnu.org/licenses/gpl-2.0.html).

Royal MCP is provided as-is. API keys protect your endpoints; guard them like any other credential. You are responsible for the content, commands, and actions any AI platform is allowed to perform on your WordPress site.

---

<p align="center">
  <strong>Built by <a href="https://royalplugins.com">Royal Plugins</a></strong><br/>
  Lightweight, security-first WordPress plugins.<br/>
  © 2026 Royal Plugins.
</p>
