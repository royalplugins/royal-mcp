<div align="center">

# Royal MCP

**Run your WordPress site from Claude, ChatGPT or any AI app: write and edit content, manage your store, fix SEO and undo any change. Works alongside Elementor's and WooCommerce's own MCP tools. 100% self-hosted.**

[![WordPress](https://img.shields.io/badge/WordPress-5.8+-21759B?style=flat-square&logo=wordpress)](https://wordpress.org/plugins/royal-mcp/)
[![PHP](https://img.shields.io/badge/PHP-7.4+-777BB4?style=flat-square&logo=php)](https://www.php.net/)
[![License](https://img.shields.io/badge/License-GPLv2-blue?style=flat-square)](https://www.gnu.org/licenses/gpl-2.0.html)
[![Version](https://img.shields.io/badge/Version-1.5.12-C9A227?style=flat-square)](https://wordpress.org/plugins/royal-mcp/)

[Download on WordPress.org](https://wordpress.org/plugins/royal-mcp/) · [Documentation](https://royalplugins.com/support/royal-mcp/) · [Royal MCP Pro](https://royalplugins.com/royal-mcp-pro/) · [Royal Plugins](https://royalplugins.com)

</div>

---

Royal MCP is an open-source WordPress plugin that turns your site into a [Model Context Protocol](https://modelcontextprotocol.io/) server. Connect it once, and your AI app can read and change your site the way you would from wp-admin: posts, pages, media, menus, your WooCommerce store, Elementor and Divi pages, SEO, backups and security. Every call runs as a WordPress user, is checked against that user's capabilities, and lands in an activity log, and every destructive change can be undone.

Nothing is relayed through a third-party service. Your API keys, sign-in tokens and logs stay in your own WordPress database.

## What you can do with it

Ask in plain language. These all work on the free plugin:

| Ask your AI to... | What happens |
|---|---|
| *"Draft a post about our spring sale, use this image as the featured image and write the SEO title and description."* | Creates a draft, uploads the image to your media library, sets the featured image and writes the meta for Yoast, Rank Math, AIOSEO or SEOPress. Nothing goes live until you publish. |
| *"Find the pages that still mention our old phone number and update them."* | Searches your content and replaces the text page by page, with an undo token for each change. |
| *"Which products are low on stock? Create a 15% coupon for orders over $100."* | Reads products filtered by stock status and creates the WooCommerce coupon. |
| *"Clone the Services page as a new landing page, change the headline and swap the hero image."* | Clones the Elementor page and edits the copy and image in place, keeping the layout intact. |
| *"Show me this month's top pages and search queries."* | Reads the overview, top pages, traffic sources and Search Console queries from MonsterInsights. |
| *"Take a backup, then check for vulnerable plugins and failed logins this week."* | Starts a backup (SiteVault or UpdraftPlus) and reads scan results and login activity (GuardPress, Wordfence or Solid Security). |
| *"The site feels slow. What's wrong?"* | Reads site status, the PHP error log and the cron schedule, and purges the cache once the issue is fixed (ForgeCache, LiteSpeed Cache or W3 Total Cache). |
| *"Undo that last change."* | Reverses the last destructive write from its stored snapshot, within 72 hours. |

## Built to work alongside other MCP tools

Royal MCP is designed to run next to the MCP tools other plugins ship, so your AI can reach for the best tool for each part of a job.

- **Elementor.** When Elementor's own MCP is switched on, Royal MCP detects it and tells your AI that Elementor's builder is there for designing new layouts. Royal MCP's Elementor tools keep handling edits to the pages you already have: text and image swaps, widgets, cloning and templates.
- **WooCommerce.** WooCommerce's own MCP abilities keep working. Royal MCP adds the rest of the store: coupons, variations, attributes, customers and store stats.
- **WordPress Abilities API.** Royal MCP's tools also register as WordPress abilities. With the [MCP Adapter](https://github.com/WordPress/mcp-adapter) installed they are served on Royal MCP's own adapter server, `royal-mcp-server`, with the same permission checks, switches and logging as the native endpoint.

Royal MCP answers on its own endpoint and never switches off another plugin's tools, so together they cover more of your site than any one of them alone.

## Safety

- **Your AI app asks first.** Claude, ChatGPT and other clients ask you to approve each destructive tool call by default.
- **Drafts first.** New posts and pages start as drafts.
- **Read-only and drafts-only modes.** One switch refuses every tool that changes the site, or every tool that would publish.
- **An access level per connection.** Give each connected AI app Full, Read-only or Drafts-only access from the Connected Clients screen, and revoke any of them.
- **Recent Changes with undo.** Every destructive write stores a snapshot first. The Recent Changes screen shows the before, after and current values, and one `mcp_undo_last_operation` call reverses a change within 72 hours.
- **Activity log.** Every tool call is logged with the tool name, argument keys, IP and user agent. Argument values are never stored.
- **Dry runs.** High-impact writes such as site options and permalink structure can be previewed before they are applied.

## Capabilities

270 tools: 113 core tools that are always available, plus 157 integration tools that load only when the matching plugin is active.

### WordPress core (113 tools)

- **Content** - Posts, pages and custom post types (create, read, update, delete, clone as a draft, revisions, featured images)
- **Taxonomies** - Categories, tags, custom taxonomies, term meta, post-term linking
- **Media** - Browse; upload images, PDFs and other allowed files (by URL, base64 or a file handed over by ChatGPT); convert, resize and compress images on upload; find a file already in the library by address or content and reuse it instead of uploading a copy; update metadata; delete
- **Blocks** - Read a post's block tree; insert, replace, move or delete single blocks; replace text across blocks; read and edit synced patterns; read global styles and Site Editor templates
- **Comments** - Create and moderate (approve, spam, trash)
- **Users** - Display names, roles, registration dates and any capabilities granted beyond a user's roles. Emails and usernames are not exposed unless a site owner opts in with the `royal_mcp_user_fields` filter
- **Menus** - Read, create items, reorder, update
- **Theme** - Custom CSS, theme mods, active theme
- **Site** - Permalink structure, allowlisted options, site info
- **Search** - Search across content
- **SEO** - Meta read and write for Yoast, Rank Math, AIOSEO, SEOPress and SEObolt
- **Safety** - Read-only mode, drafts-only mode, per-connection access levels, Recent Changes and 72-hour undo
- **Plugins and themes** - Every installed plugin, including must-use plugins and drop-ins, with versions, active status and last-modified dates; installed themes
- **Diagnostics** - Site status (WordPress, PHP, MySQL, plugins, themes and cron in one call), PHP error-log tail, cron schedule, and `royal_mcp_connection_health`

### Plugin integrations (157 tools)

| Plugin | Tools | What's covered |
|---|---|---|
| WooCommerce | 29 | Products, variations, attributes, coupons, orders (create, update, notes), customers, store stats; paged lists with totals; product filters by ID, SKU and stock status; order filters by date |
| Elementor | 14 | Clone pages, replace text, swap images, page outline, widget settings, add widgets, templates (list, import, apply), widget schema, dynamic tags, rebuild post content |
| Divi | 9 | Format detection (Divi 4 or Divi 5), page outline, layout validation, library list and read, find and replace, clone, image swap, apply a library item |
| Wordfence | 8 | Security status, firewall mode and scan findings, blocked IPs, block or unblock an IP, failed logins, blocked requests, start a scan |
| GuardPress | 7 | Security score, failed logins, blocked IPs, vulnerability scans, audit log |
| SiteVault | 6 | Start backups, monitor progress, list backups and schedules |
| Royal AI Firewall | 6 | Dashboard stats, recent bot hits, per-bot policies (allow, block, challenge), daily rollups |
| TablePress | 6 | List, read, create, copy and export tables, change cells, with undo |
| Yoast SEO | 5 | Meta read and write, JSON-LD schema graph, indexed internal links, Premium redirects |
| Code Snippets | 5 | List and read snippets (credentials in code are masked), recent deactivations, deactivate a snippet, edit its name, description and tags |
| Redirection | 4 | List, create and update redirects; list groups |
| Royal Ledger | 4 | Software costs, renewal dates, license keys (values never exposed) |
| Advanced Custom Fields | 4 | Read and write fields with each field's Return Format respected; list field groups |
| UpdraftPlus | 4 | Backup history, per-backup status, start a backup, schedule |
| WPForms | 4 | Forms, a form's fields, entries (WPForms Pro) |
| Solid Security | 4 | Security status, locked-out IPs, security log, ban an IP |
| MonsterInsights | 4 | Analytics overview, top pages, traffic sources, top Search Console queries |
| BuddyPress | 4 | Members, a member profile, groups, activity feed (BuddyBoss Platform too) |
| LiteSpeed Cache | 4 | Cache status and settings, purge everything or specific pages |
| ForgeCache | 4 | Cache stats, clear cache, purge a URL, real-user Core Web Vitals |
| Contact Form 7 | 3 | Forms, a form's fields, submissions (with Flamingo) |
| W3 Total Cache | 3 | Cache configuration, purge (all, by URL or by post), usage statistics |
| Duplicator | 3 | Migration packages, package status, installer URL |
| Complianz | 7 | Consent setup status, cookies, services, banner texts and legal documents; change banner texts and cookie descriptions, with undo |
| WPCode | 3 | Read only: snippets, a snippet (credentials masked), its status and last error |
| Royal Links | 3 | Branded short links, click stats |

Not seeing your plugin? The core tools still cover posts on any custom post type, any plugin's custom fields through post meta, and any plugin's comments. Request an integration on the [WordPress.org support forum](https://wordpress.org/support/plugin/royal-mcp/). If you build that plugin, you can [add its tools to Royal MCP yourself](https://royalplugins.com/support/royal-mcp/add-your-plugin-tools/) or [partner with us](CONTRIBUTING.md#integrate-your-plugin).

## Connect

### Install

1. Install from [WordPress.org](https://wordpress.org/plugins/royal-mcp/) (recommended, updates through wp-admin) or upload the zip from the [latest GitHub release](https://github.com/royalplugins/royal-mcp/releases/latest).
2. Go to **Royal MCP → Settings**, switch Royal MCP on and copy the **MCP Server URL**. For clients that use an API key, generate one on the same screen.
3. Pick a client below.

Your MCP Server URL is `https://yoursite.com/wp-json/royal-mcp/v1/mcp`.

### Claude (claude.ai, Claude Desktop) - sign in with OAuth

1. **Settings → Connectors → Add custom connector**.
2. Paste your MCP Server URL.
3. Approve the sign-in screen on your site. No API key needed.

### ChatGPT

Add a custom connector with your MCP Server URL and follow the sign-in flow.

### Claude Desktop with an API key

```json
{
  "mcpServers": {
    "my-wordpress": {
      "command": "npx",
      "args": [
        "-y", "mcp-remote",
        "https://yoursite.com/wp-json/royal-mcp/v1/mcp",
        "--header", "Authorization:${AUTH_HEADER}"
      ],
      "env": {
        "AUTH_HEADER": "Bearer YOUR_API_KEY"
      }
    }
  }
}
```

Config file: `~/Library/Application Support/Claude/claude_desktop_config.json` (macOS) or `%APPDATA%\Claude\claude_desktop_config.json` (Windows).

### Cursor, VS Code, Claude Code and other MCP clients

Add an HTTP MCP server with your MCP Server URL and an `Authorization` header set to `Bearer YOUR_API_KEY`, or use OAuth where the client supports it.

### Raw HTTP

```bash
# 1. Start a session. -i prints the headers so you can copy Mcp-Session-Id.
curl -i -X POST https://yoursite.com/wp-json/royal-mcp/v1/mcp \
  -H "Authorization: Bearer YOUR_API_KEY" \
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

# 2. List the tools, using the session id from the response headers.
curl -X POST https://yoursite.com/wp-json/royal-mcp/v1/mcp \
  -H "Authorization: Bearer YOUR_API_KEY" \
  -H "Mcp-Session-Id: <session_id>" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json, text/event-stream" \
  -d '{"jsonrpc": "2.0", "method": "tools/list", "id": 2}'
```

The older `X-Royal-MCP-API-Key` header still works in this release but is deprecated. Send the key as `Authorization: Bearer` instead.

## Security model

| Layer | What it does |
|---|---|
| **API key** | 32 hex characters from a cryptographically secure generator, stored only as a SHA-256 hash and compared in constant time. Sent as `Authorization: Bearer`. Rotate it from wp-admin or with `wp royal-mcp rotate-api-key`. |
| **OAuth 2.1** | Dynamic Client Registration (RFC 7591), authorization server metadata (RFC 8414), PKCE S256 required, refresh tokens. No implicit grant, no client-credentials grant. |
| **Capability checks** | Every tool checks the WordPress capability it needs, for example `edit_posts` to create or update content, `edit_post` for a specific post and `manage_options` for site settings. |
| **Rate limiting** | Per IP, per minute: 60 requests without credentials, 300 with. Calls through the WordPress Abilities API and other MCP servers: 300 per minute per user. |
| **Sessions** | Cryptographically random session IDs with a sliding 24-hour lifetime. |
| **Activity log** | One row per tool call: tool name, argument keys, IP, user agent, errors. Argument values are never stored. |
| **Connected Clients** | See every signed-in AI app, set its access level, and revoke one or all. A one-click reset clears OAuth clients and tokens without touching your API key or settings. |
| **Discovery** | `.well-known/oauth-authorization-server` and `.well-known/oauth-protected-resource` at the site root (RFC 8414, RFC 9728). |

Found a security issue? Report it privately through [royalplugins.com/contact](https://royalplugins.com/contact/), not in a public issue.

## Royal MCP Pro

**Royal MCP gives one site owner a safe AI connection. [Royal MCP Pro](https://royalplugins.com/royal-mcp-pro/) is for running AI across real client work:** 350+ tools, bulk operations across many pages and products, deeper Elementor, Divi, WooCommerce and ACF authoring, cross-plugin workflows that run a whole job in one call, and a full audit trail.

Pro is one plugin that includes everything in Royal MCP. When Pro is active, you run Pro on its own.

| | Royal MCP (free) | Royal MCP Pro |
|---|:---:|:---:|
| **Tools** | 270 | **350+** |
| Connect Claude, ChatGPT, Cursor and any MCP app (OAuth or API key) | ✅ | ✅ |
| Read-only and drafts-only modes, per-connection access levels | ✅ | ✅ |
| Recent Changes and 72-hour undo on destructive writes | ✅ | ✅ Pro tools too |
| Activity log of every tool call | ✅ | ✅ |
| **Universal audit log** with filters, per-row undo, CSV export and 90-day retention | — | ✅ |
| **Custom Endpoint Profiles**: choose which tools each AI connection can call | — | ✅ |
| **Bulk find and replace** across posts, with a two-step commit | — | ✅ |
| **Elementor**: clone, edit text and images, add widgets, apply templates | ✅ 14 tools | ✅ |
| **Elementor Pro depth**: widget, container and section CRUD by ID, Theme Builder and display conditions, bulk widget settings, global variables and classes | — | ✅ +14 tools |
| **WooCommerce**: products, variations, attributes, coupons, orders, customers, stats | ✅ 29 tools | ✅ |
| **WooCommerce bulk**: prices, stock, categories, order status, deletion with confirmation, grouped products, bulk coupons | — | ✅ +12 tools |
| **Divi**: outline, validation, find and replace, clone, image swap | ✅ 9 tools | ✅ |
| **Divi Pro**: library create, update and delete, Divi 4 to Divi 5 migrator, global preset bulk-apply | — | ✅ |
| **ACF**: read and write field values | ✅ 4 tools | ✅ |
| **ACF Setup Suite**: field groups, fields, JSON import and export, options pages, post types and blocks | — | ✅ +15 tools |
| **SEO**: meta read and write per post | ✅ | ✅ |
| **SEO Agency Suite**: site-wide SEO audit and bulk meta writers | — | ✅ |
| **Cross-plugin workflows**: launch-readiness check, monthly maintenance report, theme-swap asset migration, SEO launch in one call with rollback | — | ✅ |
| **FormForge Pro** entries, analytics and form creation | — | ✅ |
| **Royal Affiliate Pro** affiliates, referrals, campaigns and payouts | — | ✅ +25 tools |
| Sites | Unlimited | 1, 5 or 100 |
| Updates | WordPress.org | Direct from Royal Plugins |
| Support | WordPress.org forum and GitHub Issues | Email support |
| Price | Free forever | From $99/year · [30-day money-back guarantee](https://royalplugins.com/refund/) |

**[See Royal MCP Pro →](https://royalplugins.com/royal-mcp-pro/)** · [Pricing](https://royalplugins.com/royal-mcp-pro/pricing/) · [Full Free vs Pro comparison](https://royalplugins.com/royal-mcp-pro/free-vs-pro/)

## Scope

What Royal MCP deliberately does not do:

- **No core file changes.** It never writes to `wp-content/themes`, `wp-includes` or `wp-admin`.
- **No plugin installs or upgrades.** It can list plugins, not install, activate or update them.
- **No raw SQL.** Every query goes through WordPress APIs and prepared statements.
- **No Elementor Theme Builder templates in the free plugin.** Theme Builder work is in Royal MCP Pro.
- **No Beaver Builder or Bricks layout JSON writes.** Their standard post content is readable and writable; builder-specific layout data is left alone unless a dedicated tool covers it.

## More from Royal Plugins

Royal MCP works with the rest of the Royal Plugins family. Each one adds its own tools to Royal MCP when it's active.

| Plugin | What it does |
|---|---|
| [Royal MCP Pro](https://royalplugins.com/royal-mcp-pro/) | 350+ tools for agencies: bulk operations, deep builder and store authoring, audit log |
| [GuardPress](https://royalplugins.com/guardpress/) | Security: firewall, malware scanner, 2FA, login protection, activity log |
| [SiteVault](https://royalplugins.com/sitevault/) | Automated backups, staging, encryption, migration ([free version](https://wordpress.org/plugins/sitevault-backup-restore-migration/)) |
| [ForgeCache](https://royalplugins.com/forgecache/) | Page caching, CSS and JS optimization, lazy loading, CDN, Core Web Vitals |
| [SEObolt](https://royalplugins.com/seobolt/) | SEO audit, rank tracking, schema, redirects, image SEO |
| [FormForge](https://royalplugins.com/formforge/) | Form builder with conditional logic, payments, quizzes and PDF export |
| [Royal Affiliates](https://royalplugins.com/royal-affiliates/) | Run your own affiliate program on WooCommerce |
| [Royal Links](https://royalplugins.com/royal-links/) | Branded links with geo-targeting, A/B tests and click analytics ([free version](https://wordpress.org/plugins/royal-links/)) |
| [Royal AI Firewall](https://royalplugins.com/royal-ai-firewall/) | Free: see and control the AI bots that visit your site |
| [Royal SMTP](https://royalplugins.com/royal-smtp/) | Free: reliable email delivery over SMTP |
| [Royal Access](https://royalplugins.com/royal-access/) | Free: role and permission management |

### Earn with Royal Plugins

Recommend Royal MCP Pro and the rest of the family to your audience and earn **30% on every first-year sale and 15% on every renewal**, with a 60-day cookie and monthly payouts.

**[Join the Royal Plugins affiliate program →](https://royalplugins.com/affiliates/)**

## Further reading

- **[Connect Claude to WordPress in 5 Minutes](https://royalplugins.com/blog/connect-claude-to-wordpress-in-5-minutes/)** - the quickest way to get connected, with a video and step-by-step setup.
- **[Editing Elementor with Claude: 4 Workflows That Work](https://royalplugins.com/blog/editing-elementor-with-claude-four-workflows/)** - the four ways to edit Elementor pages with AI and when to use each, with prompts, examples and a video demo.
- **[Translate WordPress with Claude + DeepL MCP](https://royalplugins.com/blog/translate-wordpress-with-claude-and-deepl-mcp/)** - Royal MCP and another MCP server working together on a real job.
- **[WordPress's MCP Era: Distribution and Runtime Servers Now Coexist](https://royalplugins.com/blog/wordpress-mcp-era/)** - why several MCP tools on one site work better together.
- **[Claude Skills for WordPress](https://royalplugins.com/blog/claude-skills-for-wordpress/)** - turn the prompts you repeat into reusable workflows.

## License

GPLv2 or later. See [LICENSE](LICENSE) or the [GNU site](https://www.gnu.org/licenses/gpl-2.0.html).

Royal MCP is provided as-is. API keys protect your endpoint; guard them like any other credential. You are responsible for the content, commands and actions any AI platform is allowed to perform on your WordPress site.

---

<p align="center">
  <strong>Built by <a href="https://royalplugins.com">Royal Plugins</a></strong><br/>
  Lightweight, security-first WordPress plugins.<br/>
  © 2026 Royal Plugins.
</p>
