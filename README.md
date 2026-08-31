<div align="center">

# Royal MCP

**Security-first MCP server for WordPress.** Connect Claude, ChatGPT, and Gemini to your WordPress site with API key + OAuth 2.1 authentication, full activity logging, and capability-gated access.

[![WordPress](https://img.shields.io/badge/WordPress-5.8+-21759B?style=flat-square&logo=wordpress)](https://wordpress.org/plugins/royal-mcp/)
[![PHP](https://img.shields.io/badge/PHP-7.4+-777BB4?style=flat-square&logo=php)](https://www.php.net/)
[![License](https://img.shields.io/badge/License-GPLv2-blue?style=flat-square)](https://www.gnu.org/licenses/gpl-2.0.html)
[![Version](https://img.shields.io/badge/Version-1.4.45-C9A227?style=flat-square)](https://wordpress.org/plugins/royal-mcp/)

[Download on WordPress.org](https://wordpress.org/plugins/royal-mcp/) · [Documentation](https://royalplugins.com/support/royal-mcp/) · [Royal Plugins](https://royalplugins.com)

</div>

---

A WordPress plugin that exposes your site as a [Model Context Protocol](https://modelcontextprotocol.io/) server. AI agents — Claude.ai web, Claude Desktop, ChatGPT, custom clients — can read and write posts, pages, media, users, menus, WooCommerce orders, and Elementor pages, with every call going through capability gating, rate limiting, and an audit log. Distributed via the official WordPress.org plugin directory.

## Quick facts

| | |
|---|---|
| **Auth** | API key (`X-Royal-MCP-API-Key`) **or** OAuth 2.1 with PKCE + Dynamic Client Registration (RFC 7591) |
| **Transport** | MCP 2025-11-25 Streamable HTTP (single `/mcp` endpoint, POST/GET/DELETE) |
| **Tool count** | Up to 206 (85 WordPress core + 121 conditional plugin integrations) |
| **Abilities API** | WP 6.9+ — every tool also registers as a WordPress ability, reachable via WP core REST at `/wp-json/wp-abilities/v1/abilities/{name}/run` and via the WordPress MCP Adapter's named `royal-mcp-server` |
| **Rate limit** | 60 req/min per IP (configurable) |
| **Session model** | Sliding 24h TTL with refresh-on-access |
| **Activity log** | Every tool call logged (tool name + arg keys; argument values are never recorded) |
| **Distribution** | [wp.org plugin directory](https://wordpress.org/plugins/royal-mcp/) + GitHub releases + auto-update via WP admin |
| **Tested** | PHP 7.4 → 8.3, WordPress 5.8 → 7.0 |
| **License** | GPLv2+ |

## Capabilities

### WordPress core (85 tools, always available)

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
- **Diagnostics** — Site status (WP/PHP/MySQL/plugins/themes/cron in one call), PHP error-log tail, WP cron schedule, and MCP `royal_mcp_connection_health` (returns route, auth method, session ID, plugin version, and active page-builder versions for Divi + Elementor + Gutenberg)

### Plugin integrations (121 tools, conditional)

Auto-register only when the integrated plugin is active.

| Plugin | Tools | What's covered |
|---|---|---|
| WooCommerce | 29 | Products, variations, attributes, coupons, orders (create/update/notes), customers, store stats |
| Elementor | 11 | Clone pages, replace text, swap images, get outline, read single element, list templates, import templates, add widget, rebuild post_content |
| Divi | 9 | Format detection (D4 shortcode vs D5 block), page outline, layout validation, library list + get, find/replace with builder-format awareness, clone, image swap, library apply |
| GuardPress | 7 | Security score, failed logins, blocked IPs, vulnerability scans, audit log |
| SiteVault | 6 | Trigger backups, monitor progress, list schedules |
| Royal AI Firewall | 6 | Dashboard stats, recent bot hits, per-bot policies (allow / block / challenge), daily rollups |
| Yoast SEO | 5 | Read/write Yoast meta (raw + resolved), capture JSON-LD schema graph, list indexed internal links, list Premium redirects |
| Redirection | 4 | List redirects with group + URL-substring filters, create + update redirects (301 / 302 / 307 / regex / groups), list redirect groups |
| Royal Ledger | 4 | Software costs, renewal dates, license keys (values never exposed) |
| Advanced Custom Fields | 4 | Read/write ACF fields with each field's Return Format respected (hydrated post objects, parsed repeater rows, image arrays); enumerate field groups |
| UpdraftPlus | 4 | List backup history, read per-backup status, trigger async backups with entity filtering, read schedule |
| WPForms | 4 | List forms, read a single form's parsed field schema, (Pro) list submissions, (Pro) read single submission |
| Solid Security | 4 | Read security status, list currently locked-out IPs, read the security event log, add an IP to the ban list |
| MonsterInsights | 4 | Read the analytics overview, top pages, traffic sources, and top Google Search Console queries |
| BuddyPress | 4 | List community members, read a single member profile, list groups, read the activity feed (same detection covers BuddyBoss Platform) |
| Contact Form 7 | 3 | List forms, read a single form's parsed field schema, list submissions (via Flamingo add-on) |
| W3 Total Cache | 3 | Read cache configuration across every module, purge cache (all / by URL / by post), read usage statistics |
| Duplicator | 3 | List migration packages, read per-package status, get the installer URL for a completed package |
| Royal Links | 3 | Branded short links, click stats |
| ForgeCache | 3 | Cache stats, clear cache, purge URL |
| Google Site Kit | 1 | Read authenticated Site Kit report data |

## WordPress Abilities API bridge (WP 6.9+)

WordPress 6.9 shipped the [Abilities API](https://developer.wordpress.org/plugins/abilities-api/) — a primitive that lets plugins register typed capabilities AI agents can call. As of 1.4.38, every Royal MCP tool also registers as a WordPress ability, giving you three ways to reach the same handlers:

1. **Native** — Royal MCP's `/wp-json/royal-mcp/v1/mcp` Streamable HTTP endpoint (unchanged, always available).
2. **WP MCP Adapter** — if the [`wordpress/mcp-adapter`](https://github.com/WordPress/mcp-adapter) package is installed, Royal MCP registers a named `royal-mcp-server` on the `mcp_adapter_init` hook alongside adapter's default server.
3. **WP core REST** — direct ability invocation at `/wp-json/wp-abilities/v1/abilities/{name}/run` for callers that prefer the core WP endpoint.

Same handlers, three transports, one set of per-tool capability gates. Bridge can be disabled with the `royal_mcp_abilities_registration_enabled` option (default: on).

## What we don't do

Explicit scope boundaries — the integration model is "narrow tools that work reliably," not "expose every API surface."

- **No widget-level Elementor generation from scratch.** Atomic widgets (Editor V4) pass through opaque; we never decode atomic schemas because Elementor itself may shift them.
- **No Beaver Builder / Bricks page-builder JSON writes.** Standard post content is readable and writable; page-builder-specific JSON storage is opaque unless covered by a dedicated tool. (Elementor and Divi have dedicated tools — see the integration table above.)
- **No theme builder template creation** (Elementor or otherwise).
- **No core file modifications** — Royal MCP never writes to `wp-content/themes`, `wp-includes`, or `wp-admin`.
- **No plugin installation or upgrades via MCP.** Discovery yes; install/activate/deactivate no.
- **No raw SQL.** Queries go through `WP_Query` and `$wpdb->prepare()` only.

## Royal MCP Pro (paid)

The Free plugin is fully featured for individual site owners. Royal MCP Pro extends it for agencies and multi-site operators:

- **Divi Pro suite** — page clone, image swap, template import, full library CRUD, D4→D5 Migrator, global preset bulk-apply (8 tools)
- **Elementor Pro depth** — additional Elementor tools beyond the Free integration
- **Universal audit log** — every AI operation logged with attribution + export
- **72-hour undo on every write** — every destructive Pro tool returns an undo token; reverse any operation within the window
- **License-gated updates** through the standard WordPress updater — no runtime dependencies on external license servers

[Learn more at royalplugins.com/royal-mcp-pro](https://royalplugins.com/royal-mcp-pro/)

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

### Claude Desktop (.mcpb one-click bundle — new in 1.4.38)

Easiest Claude Desktop path — no `mcp-remote`, no npx, no config-file editing.

1. Download `royal-mcp-1.4.38.mcpb` from the [latest GitHub release](https://github.com/royalplugins/royal-mcp/releases/latest).
2. Double-click the `.mcpb` file — Claude Desktop opens the install prompt.
3. Enter your site URL + API key when prompted. Connection is live.

The bundle ships a zero-dependency stdio-to-HTTPS bridge in Node ≥18, which Claude Desktop already includes. See [Claude Desktop MCP Bundles](https://modelcontextprotocol.io/) for the .mcpb spec.

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
- **Source access** — This repo mirrors the plugin's wp.org SVN trunk for transparency. Report bugs and feature requests in [Issues](https://github.com/royalplugins/royal-mcp/issues). Releases ship through the wp.org review pipeline.

## Further reading

- **[Editing Elementor with Claude: The Four Workflows That Work Today](https://royalplugins.com/blog/editing-elementor-with-claude-four-workflows/)** — the four real architectures for AI-editing Elementor pages and when to reach for which. User-facing version with prompts, examples, and a video demo of the clone-and-customize flow.
- **[Editing Elementor with Claude: 4 MCP Architectures](https://royalplugins.hashnode.dev/editing-elementor-with-claude-mcp-architectures)** — engineer's cut on Hashnode. The PHP under the hood of `elementor_clone_page`, the six tool signatures, and the HTTP-Basic-vs-OAuth-2.1 auth-model tradeoff.

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
