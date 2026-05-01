=== Royal MCP – Secure AI Connector for Claude, ChatGPT & Gemini ===
Contributors: royalpluginsteam
Donate link: https://www.royalplugins.com
Tags: mcp, ai, claude, chatgpt, mcp-server
Requires at least: 5.8
Tested up to: 7.0
Stable tag: 1.4.12
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Preview-On-WordPress-Playground: yes

The security-first MCP server for WordPress. Connect Claude, ChatGPT, and Gemini with API key auth, rate limiting, and activity logging.

== Description ==

https://youtu.be/8Wbr0ReLpok

Royal MCP is a security-first Model Context Protocol (MCP) server for WordPress. It gives AI platforms like Claude, ChatGPT, and Google Gemini structured access to your WordPress content — with authentication, rate limiting, and audit logging that most MCP implementations skip entirely.

According to [recent security research](https://mcpplaygroundonline.com/blog/mcp-server-security-complete-guide-2026), 41% of public MCP servers have no authentication and respond to tool calls without any credentials. Royal MCP takes the opposite approach: every MCP session requires an API key, every request is rate-limited, and every interaction is logged.

= Why Security Matters for MCP =

MCP gives AI agents the ability to read, create, update, and delete your WordPress content. Without proper authentication, anyone who discovers your MCP endpoint can:

* Read all your posts, pages, and media
* Create or delete content
* Access user data and plugin information
* Overwhelm your server with rapid-fire requests

Royal MCP prevents all of this with API key authentication on session initialization, timing-safe key comparison, per-IP rate limiting (60 requests/minute), and a full activity log of every MCP interaction.

= 41+ MCP Tools Built In =

**WordPress Core (41 tools):**

* Posts — create, read, update, delete, search, count (featured images supported)
* Pages — full CRUD with parent page support
* Media — browse, upload from URL or base64, update alt text and metadata, set as featured image, delete
* Comments — create (respects moderation settings), read, delete
* Users — display names and roles (emails and usernames are not exposed)
* Categories & Tags — create, assign, delete, count
* Menus — list menus and menu items
* Post Meta — read, update, delete custom fields
* Site Info — site name, description, WordPress version, timezone
* Plugins & Themes — list installed plugins and themes with active status
* Search — full-text content search across post types
* Options — read allowlisted core options, read full plugin settings by slug (sensitive keys redacted), and write to allowlisted options when an admin enables it

= Plugin Integrations (Conditional) =

Royal MCP automatically detects compatible plugins and adds specialized MCP tools. No configuration needed — if the plugin is active, the tools appear.

**WooCommerce Integration (9 tools):**
When WooCommerce is active, AI agents can manage your store:

* Browse and search products by category, status, or type
* Create and update products with prices, SKUs, stock levels
* View orders, order details, and update order status
* List customers with order count and total spent
* Get store statistics — revenue, order count, average order value by period

**GuardPress Integration (7 tools):**
When GuardPress is active, AI agents can monitor your site security:

* Get current security score and grade with factor breakdown
* View security statistics — failed logins, blocked IPs, alerts
* Run vulnerability scans and review results
* List blocked IP addresses and failed login attempts
* Browse the security audit log filtered by severity

**SiteVault Integration (6 tools):**
When SiteVault is active, AI agents can manage your backups:

* List available backups filtered by status or type
* Trigger new backups (full, database, files, plugins, themes)
* Check backup progress in real time
* View backup statistics — total size, last backup, counts
* List and review backup schedules

= Royal MCP and the WordPress Core Abilities API =

WordPress 6.9 shipped the Abilities API in November 2025 — a primitive that lets plugins register typed capabilities AI agents can call. Core ships three default abilities (site info, user info, environment info) and the `wordpress/mcp-adapter` package bridges abilities to the MCP protocol.

Royal MCP is a complete, production-ready MCP server that predates the official adapter. It runs the full Streamable HTTP transport, enforces API key authentication on every request, ships OAuth 2.0 for Claude Desktop's native connector flow, rate-limits per-IP, redacts sensitive data, and logs every interaction. Out of the box it includes 41+ tools for WordPress core operations plus integrations for WooCommerce, GuardPress, and SiteVault.

= Supported AI Platforms =

* **Claude (Anthropic)** — Full MCP support via Claude Desktop, Claude Code, and VS Code
* **OpenAI / ChatGPT** — GPT-4o, GPT-4 Turbo, GPT-3.5 Turbo
* **Google Gemini** — Gemini 1.5 Pro, 1.5 Flash
* **Groq** — Llama 3.3, Mixtral, Gemma 2
* **Azure OpenAI** — Azure-hosted OpenAI deployments
* **AWS Bedrock** — Claude, Llama, Titan models
* **Ollama / LM Studio** — Local self-hosted models (no external data transmission)
* **Custom MCP Servers** — Connect to any MCP-compatible endpoint

= Compatible Clients & Frameworks =

Royal MCP works with any MCP-compliant client, IDE, or AI agent framework — no per-tool configuration required:

* **Desktop AI apps** — Claude Desktop (native MCP connector via OAuth 2.0), ChatGPT Desktop, Gemini Advanced.
* **AI code IDEs** — Claude Code, VS Code (with MCP extension), Cursor, Windsurf, Continue, Cline, Zed, JetBrains AI Assistant.
* **API testing tools** — Postman, Bruno, Insomnia (use the API key in the `X-Royal-MCP-API-Key` header).
* **Custom field plugins** — Advanced Custom Fields (ACF), MetaBox, JetEngine, Pods, CPT UI, Custom Field Suite. The `wp_get_post_meta` / `wp_update_post_meta` tools read and write any custom field, so AI agents can populate ACF fields just like a human editor.
* **Page builders** — Elementor, Divi, Beaver Builder, Bricks, Gutenberg, Spectra, Stackable. Post content stored by builders is fully readable and writable by AI.
* **Multilingual** — WPML, Polylang, TranslatePress, qTranslate. Translated posts appear as separate posts and can be read or written via the standard post tools.
* **AI agent frameworks** — LangChain, AutoGen, CrewAI, LlamaIndex, Haystack — any MCP-compatible framework can call Royal MCP's tools.
* **AI app platforms** — Anthropic Console, OpenAI Playground, Google AI Studio, Vertex AI, Azure AI Studio, Amazon Bedrock Console.

= MCP Spec Compliance =

Royal MCP implements the [MCP 2025-03-26 Streamable HTTP transport specification](https://modelcontextprotocol.io/specification/2025-03-26/basic/transports#streamable-http):

* Single `/mcp` endpoint for all JSON-RPC communication
* POST for client messages, GET for server-sent events, DELETE for session termination
* Cryptographically secure session IDs with transient-based storage
* Origin header validation to prevent DNS rebinding attacks
* Proper CORS handling for browser-based MCP clients

== External Services ==

This plugin connects to third-party AI services to enable AI platforms to interact with your WordPress content. **No data is transmitted until you explicitly configure and enable a platform connection.**

**What data is sent:** Your WordPress content (posts, pages, media metadata) as requested by the connected AI platform through authenticated MCP tool calls.

**When data is sent:** Only when you have configured a platform with API credentials AND enabled that platform connection AND the AI platform makes an authenticated request.

**Supported services and their policies:**

* **Anthropic Claude** — Used for Claude AI integration
  [Terms of Service](https://www.anthropic.com/legal/consumer-terms) | [Privacy Policy](https://www.anthropic.com/legal/privacy)

* **OpenAI** — Used for ChatGPT/GPT-4 integration
  [Terms of Use](https://openai.com/policies/terms-of-use) | [Privacy Policy](https://openai.com/policies/privacy-policy)

* **Google Gemini** — Used for Gemini AI integration
  [Terms of Service](https://ai.google.dev/terms) | [Privacy Policy](https://policies.google.com/privacy)

* **Groq** — Used for Groq LPU inference
  [Terms of Service](https://groq.com/terms-of-use/) | [Privacy Policy](https://groq.com/privacy-policy/)

* **Microsoft Azure OpenAI** — Used for Azure-hosted OpenAI models
  [Terms of Service](https://azure.microsoft.com/en-us/support/legal/) | [Privacy Policy](https://privacy.microsoft.com/en-us/privacystatement)

* **AWS Bedrock** — Used for AWS-hosted AI models
  [Terms of Service](https://aws.amazon.com/service-terms/) | [Privacy Policy](https://aws.amazon.com/privacy/)

* **Ollama / LM Studio** — Local self-hosted models (no external data transmission)

* **Custom MCP Servers** — User-configured servers (data sent to user-specified endpoints only)

== Installation ==

1. Upload the `royal-mcp` folder to `/wp-content/plugins/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to Royal MCP → Settings to configure
4. Copy your API key — you will need this to authenticate MCP connections
5. Add your AI platform(s) and enter their API keys
6. In your AI client (Claude Desktop, VS Code, etc.), configure the MCP server URL and API key

Full setup guides for each platform are available at [royalplugins.com/support/royal-mcp/](https://royalplugins.com/support/royal-mcp/).

== Frequently Asked Questions ==

= What is MCP and why does my WordPress site need it? =

Model Context Protocol (MCP) is an open standard created by Anthropic that lets AI assistants interact with external data sources. Without MCP, AI tools like Claude or ChatGPT can only work with content you copy and paste into them. With Royal MCP installed, these AI platforms can directly read your WordPress posts, create new content, manage your WooCommerce products, check your security status, and trigger backups — all through a structured, authenticated protocol.

= How is Royal MCP different from other WordPress MCP plugins? =

Security. Most MCP plugins — and 41% of all public MCP servers — have no authentication at all. Royal MCP requires an API key for every session, rate-limits requests to prevent abuse, logs every interaction for audit purposes, and filters sensitive data (emails, PHP version, admin credentials) from responses. We built this plugin with the same security standards we apply to GuardPress, our WordPress security plugin used on thousands of sites.

= Does Royal MCP duplicate what WordPress core now does? =

No. WordPress 6.9 added the Abilities API — a primitive for registering AI-callable functions — and the `wordpress/mcp-adapter` package bridges abilities to the MCP protocol. Royal MCP is a full MCP server with the security layer, connector flows, and plugin integrations that the bare primitive does not include: enforced API key auth, OAuth 2.0 for Claude Desktop, per-IP rate limiting, audit logging, sensitive-data redaction, and 41+ ready-to-use tools spanning posts, pages, media, comments, users, options, and WooCommerce/GuardPress/SiteVault.

= Does Royal MCP work with WooCommerce? =

Yes. When WooCommerce is active, Royal MCP automatically adds 9 additional MCP tools for product management (create, update, search), order management (view, update status), customer data, and store statistics. No additional configuration is needed — the tools appear automatically in the MCP tools list.

= Can AI assistants configure my plugins for me? =

Yes, with safety controls. Royal MCP exposes two tools for plugin configuration:

* `wp_get_plugin_settings` lets AI read any plugin's stored settings by slug. Sensitive values (API keys, secrets, tokens, passwords, license keys, OAuth credentials) are automatically replaced with `[REDACTED]` before they leave your server, so AI assistants can understand a plugin's configuration without ever seeing stored credentials.

* `wp_update_option` lets AI write to WordPress options, but only after passing three security gates:
    1. The site admin must enable the "Allow AI to write WordPress options" toggle on the Royal MCP settings page (off by default)
    2. The option name must be in a runtime allowlist. The default allowlist is intentionally tiny — `blogname`, `blogdescription`, `posts_per_page`, `date_format`, `time_format`. Plugin authors opt their own settings in via the `royal_mcp_writable_options` filter.
    3. A hard denylist permanently blocks writes to sensitive option names (siteurl, home, license keys, secrets, salts, etc.) regardless of the allowlist or the toggle.

Plugin authors can opt in their settings with one line: `add_filter('royal_mcp_writable_options', fn($opts) => array_merge($opts, ['my_plugin_settings']));`

= How do I connect Claude Desktop to WordPress? =

Install Royal MCP, go to Royal MCP → Settings, and copy your API key and MCP server URL. In Claude Desktop, add a new MCP server configuration with the URL and include the `X-Royal-MCP-API-Key` header with your API key. Full step-by-step guide at [royalplugins.com/support/royal-mcp/](https://royalplugins.com/support/royal-mcp/).

= Is my content safe? =

Royal MCP is designed with defense in depth. API key authentication is required for all MCP sessions. Rate limiting prevents abuse (60 requests per minute per IP). Activity logging records every tool call. Sensitive data is filtered — user emails, usernames, admin email, PHP version, and stored credentials inside plugin settings (api keys, secrets, tokens, passwords) are never exposed through MCP. Comment creation respects your WordPress moderation settings. Post meta values are sanitized before storage. Option writes are disabled by default and gated by three independent checks (admin toggle, allowlist, hard denylist) when enabled. The plugin itself starts disabled by default — nothing is accessible until you explicitly enable it.

= Can I use local AI models instead of cloud services? =

Yes. Royal MCP supports Ollama and LM Studio for fully local AI inference. When using local models, no data leaves your server — the AI model runs on your own hardware and communicates with WordPress through the MCP protocol on localhost.

= What happens if I uninstall Royal MCP? =

Royal MCP performs a clean uninstall. All plugin options, database tables (activity logs), transients, and user meta are removed. No orphaned data is left behind.

= Does Royal MCP work with Claude Code, VS Code, Cursor, Windsurf, or other AI IDEs? =

Yes. Any MCP-compliant client can connect to Royal MCP. Configure your IDE or client with the MCP server URL (`https://yoursite.com/wp-json/royal-mcp/v1/mcp`) and the API key (sent in the `X-Royal-MCP-API-Key` header). Claude Desktop additionally supports the native "Add Connector" OAuth 2.0 flow, which Royal MCP handles via Dynamic Client Registration (RFC 7591) — no manual API key management required on that path. The same OAuth flow works in any client that follows MCP 2025-03-26 spec.

= Does Royal MCP work with custom fields, ACF, MetaBox, JetEngine, Pods, or CPT UI? =

Yes. Royal MCP exposes WordPress's standard `wp_get_post_meta`, `wp_update_post_meta`, and `wp_delete_post_meta` tools, which read and write any custom field — including Advanced Custom Fields (ACF), MetaBox, JetEngine, Pods, CPT UI, and Custom Field Suite. AI agents can populate ACF fields, set repeater rows, update flexible content blocks, and read computed fields just like a human editor working in the WordPress admin.

= Will Royal MCP slow down my WordPress site? =

No. The MCP endpoint is a REST route that runs only when an authenticated AI client makes a request — it does not run on visitor-facing pages, frontend templates, or admin screens (except its own settings page). The activity log uses a single indexed database table and writes asynchronously after the response is sent. Rate limiting (60 requests/minute per IP) prevents accidental overload.

= Does Royal MCP work on WordPress multisite networks? =

Yes, on a per-site basis. Each site in a multisite network has its own API key, its own activity log, and its own settings. AI clients connect to a specific site's MCP endpoint — Royal MCP does not bridge requests between sites in the network.

= Can I limit which posts, pages, or post types AI can access? =

Yes. The `wp_get_posts` and `wp_create_post` tools accept a `post_type` parameter and validate it against registered public post types, so private or internal post types are not exposed. Plugin authors can disable specific tools entirely with the `royal_mcp_disabled_tools` filter, or scope the option-write allowlist with `royal_mcp_writable_options`. WordPress's standard capability checks also apply to every tool call.

= Does Royal MCP work with WPML, Polylang, or TranslatePress for multilingual content? =

Yes. Translated posts appear as separate WordPress posts (each with its own ID and language meta) and are readable or writable via the standard `wp_get_posts`, `wp_create_post`, and `wp_update_post` tools. AI agents can list posts in a specific language by filtering on the language meta key, or translate a post and write the corresponding translation by ID.

= How do I monitor what AI is doing on my site? =

Every authenticated MCP request is logged to the Royal MCP activity log with timestamp, client IP, tool name, parameters (sensitive values redacted), and response status. The log is filterable by time range, client, tool, or status code, and exportable to CSV. The log page refreshes via AJAX so you can watch active sessions in real time.

== Screenshots ==

1. Main settings page with API key and platform overview
2. AI platform configuration with connection testing
3. Activity log showing authenticated MCP requests
4. Claude Desktop MCP connector setup
5. WooCommerce product management via Claude
6. OAuth consent screen for Claude Desktop connector

== Changelog ==

= 1.4.12 =
* Fix: MCP `protocolVersion` bumped from `2025-03-26` to `2025-11-25`. Current Claude Desktop builds send `protocolVersion: 2025-11-25` in their `initialize` handshake; when the server responded with the older date, Claude Desktop silently rejected the entire tool list (no error, tools simply did not appear in the connector). All existing installs should update to restore Claude Desktop compatibility. Thanks to @ober37 for the report and patch.
* Fix: `handle_get_stream()` now returns HTTP 405 with `Allow: POST, DELETE, OPTIONS` instead of an immediately-closed SSE stream. The previous behaviour caused `mcp-remote` (the standard bridge between Claude Desktop and HTTP MCP servers) to treat the closed stream as a dropped connection and rapidly retry, hitting rate limits and dropping the entire MCP session. Returning 405 stops the retry loop and keeps the connection stable. Thanks again to @ober37.
* Enhancement: `wp_get_taxonomies` now returns a `slug` field on each entry as a clearer alias for the taxonomy identifier. WordPress's `WP_Taxonomy` object uses `name` for the slug for historical reasons, which often confuses AI agents that expect a `slug` field on something called a "taxonomy". Both `slug` and `name` are populated and contain the same value; existing callers that read `name` continue to work.
* Enhancement: `wp_get_term_meta` returns a structured response — `{term_id, key, value}` when reading a single key, or `{term_id, meta: {...}}` when reading all meta for a term. Pre-1.4.12 the tool returned the raw scalar value (or raw associative array), inconsistent with `wp_update_term_meta` / `wp_delete_term_meta` which already returned structured arrays. AI agents now see the same shape across the term-meta tool family.

= 1.4.11 =
* New: `wp_update_term` — rename, re-slug, edit description, or change the parent of any term in any taxonomy. Resolves a long-standing gap where AI agents could create and delete terms but not edit them.
* New: `wp_get_term_meta`, `wp_update_term_meta`, `wp_delete_term_meta` — read/write term meta. Most useful for editing tag/category SEO meta stored by Yoast SEO (`_yoast_wpseo_title`, `_yoast_wpseo_metadesc`), Rank Math (`rank_math_title`, `rank_math_description`), or AIOSEO (`_aioseo_title`, `_aioseo_description`).
* New: `wp_get_taxonomies` — discover all registered public taxonomies (built-in plus custom taxonomies registered by themes/plugins like `product_cat`, `brand`, etc.). Returns slug, label, hierarchical flag, and which post types the taxonomy applies to.
* Enhancement: `wp_create_term`, `wp_delete_term`, and `wp_add_post_terms` now accept any registered taxonomy, not just `category` and `post_tag`. The hardcoded enum has been replaced with runtime `taxonomy_exists()` validation. WooCommerce, EDD, custom-taxonomy, and post-type-specific term workflows now work directly.
* Enhancement: `wp_create_term` accepts an optional `slug` parameter for deterministic URL slugs.
* Enhancement: `wp_create_post` and `wp_update_post` accept a `post_author` user ID. Defaults to the authenticated MCP user (admin). Validates that the user exists before mutating the post.

= 1.4.10 =
* New: Royal Ledger integration (4 tools) — `rl_get_costs`, `rl_create_cost`, `rl_get_renewals`, `rl_get_keys`. Auto-loads when Royal Ledger is active. License key VALUES are never exposed through MCP — only masked previews are returned (decryption requires logging into wp-admin).
* New: ForgeCache integration (3 tools) — `fc_clear_cache`, `fc_get_cache_stats`, `fc_purge_url`. Auto-loads when ForgeCache is active.
* New: Royal Links integration (3 tools) — `rlinks_get_links`, `rlinks_create_link`, `rlinks_get_link_stats`. Auto-loads when Royal Links is active.
* New: SEO meta tools — `wp_get_seo_meta`, `wp_update_seo_meta`. Auto-detects Yoast SEO or Rank Math and reads/writes the active plugin's title, description, focus keyword, robots, and OG fields. Requires `edit_post` capability.
* New: Permalink structure tools — `wp_get_permalink_structure`, `wp_update_permalink_structure`. Update is gated by the existing "Allow AI to write WordPress options" toggle and `manage_options` capability.
* New: Post revision tools — `wp_get_post_revisions`, `wp_restore_revision`. Returns revision history with author, date, word count, and lets AI roll a post back to a previous version when the user asks ("revert this post to yesterday's version").

= 1.4.9 =
* New: Theme appearance tools — `wp_get_active_theme`, `wp_get_theme_mods`, `wp_update_theme_mod`, `wp_get_custom_css`, `wp_update_custom_css`. Theme mod writes are gated by a new "Allow AI to modify theme appearance" admin toggle (off by default) plus a new `royal_mcp_writable_theme_mods` allowlist filter (default empty, opt-in only). Custom CSS writes pass through `wp_kses_post` so script tags are stripped, and require the `unfiltered_html` capability.
* New: Menu item CRUD — `wp_create_menu_item`, `wp_update_menu_item`, `wp_delete_menu_item`, `wp_reorder_menu_items`. AI agents can build and reorganize navigation menus directly. All four require the `edit_theme_options` capability.
* New: Comment moderation — `wp_get_pending_comments`, `wp_approve_comment`, `wp_spam_comment`, `wp_trash_comment`. Closes the gap between the existing comment create/delete tools. All four require the `moderate_comments` capability. Author email addresses are redacted in `wp_get_pending_comments` output.
* Filter: New `royal_mcp_writable_theme_mods` filter for theme/plugin authors to opt their customizer settings into the AI-writable allowlist.

= 1.4.8 =
* Fix: Custom connector setup in Claude no longer fails with "Unknown client_id" on sites that were updated from a pre-1.4.0 build without ever being deactivated/reactivated. The OAuth tables are now created on plugin upgrade, not just on first activation.
* Fix: Dynamic Client Registration (`POST /register`) now returns a real 500 with the underlying database error if the write fails, instead of returning a fake 201 with a client_id that was never persisted.

= 1.4.7 =
* Tags: refreshed readme tags for better WordPress.org discoverability — replaced low-usage multi-word phrases with `mcp`, `ai`, `claude`, `chatgpt`, `mcp-server`.
* New: Royal Plugins Founders Bundle banner on the Royal MCP Settings and Activity Log screens. Banner is per-user dismissable and only renders on Royal MCP admin pages.
* New: wp_get_plugin_settings tool — returns all wp_options that match a plugin slug, with sensitive keys (api_key, secret, token, password, salt, license_key, etc.) replaced with [REDACTED] before return. Lets AI agents read plugin configuration without ever seeing stored credentials.
* New: wp_update_option tool — writes a WordPress option, gated by three security checks: (1) a new admin toggle "Allow AI to write WordPress options" (off by default), (2) a runtime allowlist extensible via the royal_mcp_writable_options filter, and (3) a hard denylist for sensitive option names that overrides the allowlist. Default writable list is intentionally tiny (blogname, blogdescription, posts_per_page, date_format, time_format) — plugin authors opt their settings in via filter.
* New: Filter `royal_mcp_writable_options` for plugin authors to declare which of their settings AI agents may write. Receives an array of option names; return the merged array.
* Security: wp_get_option now redacts sensitive keys from returned values for parity with wp_get_plugin_settings.
* Security: Reduced outbound HTTP timeouts in the MCP client (30s → 10s) and platform connection tester (15s → 10s) to align with Royal Plugins HTTP guidelines and avoid blocking the request thread on slow upstream services.
* Listing: Refreshed the WordPress.org plugin directory banners. Subtitle and feature line are larger and more legible, the brand icon (crown + connected nodes) replaces the placeholder atom, and the wordmark spacing is tightened. SVG sources are now versioned for future updates.

= 1.4.6 =
* New: wp_upload_media_from_url — download an image from a public HTTPS URL and add it to the media library (SSRF-hardened: private IP ranges blocked, HTTPS required, 20 MB cap, scriptable formats rejected).
* New: wp_upload_media — upload an image from base64-encoded bytes for AI-generated or pasted images.
* New: wp_set_featured_image — set or replace a post's featured image by attachment ID or by image URL in a single call (pass media_id=0 to remove).
* New: wp_update_media — update alt text, caption, title, and description on existing attachments for better SEO and accessibility.
* Enhancement: wp_create_post and wp_update_post now accept a featured_media attachment ID in their schemas.
* Enhancement: API-key authenticated requests now run as a site administrator so capability checks (upload_files, edit_post, etc.) succeed. The API key is stored in admin-only settings, so this matches the trust level of the key itself.

= 1.4.5 =
* New: WordPress Playground live preview — click "Live Preview" on the plugin listing to try the Royal MCP settings page and activity log in a browser sandbox with demo API key and sample log entries pre-seeded.
* New: Video walkthrough embedded on the plugin listing page.

= 1.4.4 =
* Feature: Custom post type support — wp_get_posts and wp_create_post now accept a post_type parameter
* Feature: New wp_get_post_types tool discovers all registered public post types on the site
* Enhancement: wp_get_post and wp_get_posts responses now include the post type field
* Enhancement: Post type validation ensures only public post types can be queried or created

= 1.4.3 =
* Security: Fixed broken access control on MCP REST API endpoints (reported by Alexis Lafontaine via Patchstack)
* Security: All MCP tool calls now require authenticated API key or OAuth Bearer token
* Security: Removed reliance on Origin header as a security control

= 1.4.2 =
* Security: Enforce authentication on every MCP request, not just session initialization
* Security: Bind MCP sessions to authenticated credentials to prevent session hijacking
* Security: Add authentication to GET stream and DELETE session endpoints

= 1.4.1 =
* Fix: Resolved fatal error during activation on WordPress 7.0 RC ("Class Token_Store not found")
* Fix: Fully qualified namespace references for WP 7.0 compatibility
* Tested: WordPress 7.0 RC2 compatibility verified

= 1.4.0 =
* New: OAuth 2.0 authorization server — Claude Desktop's "Add Connector" flow now works natively
* New: Dynamic Client Registration (RFC 7591) for seamless MCP client onboarding
* New: PKCE-secured authorization code flow per MCP spec (2025-03-26)
* New: Token refresh with automatic rotation for long-lived sessions
* New: WordPress login integration — consent screen after authentication
* New: Metadata discovery endpoint at /.well-known/oauth-authorization-server
* New: Daily cleanup of expired OAuth tokens via scheduled event
* Improved: MCP endpoint now accepts both Bearer tokens and API key authentication
* Improved: CORS headers include Authorization for OAuth-based clients
* Security: Access tokens stored as SHA-256 hashes (never stored in plain text)
* Security: Authorization codes are single-use with 10-minute expiry
* Security: PKCE (S256) required for all authorization requests
* Security: Redirect URI validation enforces localhost or HTTPS only

= 1.3.0 =
* New: WooCommerce integration — 9 MCP tools for products, orders, customers, and store stats (auto-detected)
* New: GuardPress integration — 7 MCP tools for security score, scans, firewall logs, and audit trail (auto-detected)
* New: SiteVault integration — 6 MCP tools for backup management, scheduling, and progress tracking (auto-detected)
* Security: MCP endpoint now requires API key authentication via X-Royal-MCP-API-Key header
* Security: Added rate limiting (60 requests/minute per IP) to prevent abuse and accidental DoS
* Security: API key comparison uses timing-safe hash_equals() to prevent timing attacks
* Security: Sanitized wp_update_post_meta values before storage
* Security: Comments created via MCP now respect WordPress moderation settings
* Security: Removed admin_email and php_version from wp_get_site_info response
* Security: Removed user_login and user_email from wp_get_users/wp_get_user responses
* Improved: CORS headers include X-Royal-MCP-API-Key for cross-origin MCP clients

= 1.2.3 =
* Security: Added SSRF protection — validates all outbound URLs against private/reserved IP ranges
* Fixed: Text domain changed from 'wp-royal-mcp' to 'royal-mcp' to match plugin slug
* Fixed: Menu slugs updated for WP.org compliance
* Improved: REST API permission callbacks include explanatory comments for reviewers
* Compatibility: Tested up to WordPress 7.0

= 1.2.2 =
* Added: Documentation link on Plugins page (Settings | Documentation)
* Added: Documentation banner on settings page

= 1.2.1 =
* Fixed: Claude Connector setup guide link displaying raw HTML

= 1.2.0 =
* Security: Origin header validation to prevent DNS rebinding attacks
* Security: Session ID format validation (ASCII visible characters only)
* Improved: MCP 2025-03-26 Streamable HTTP spec compliance
* Added: Filter hook `royal_mcp_allowed_origins` for custom origin allowlist

= 1.1.0 =
* Added multi-platform AI support (Claude, OpenAI, Gemini, Groq, Azure, Bedrock)
* Added Claude Desktop MCP connector
* Added activity logging
* Added connection testing

= 1.0.0 =
* Initial release

== Upgrade Notice ==

= 1.4.12 =
Recommended update: fixes Claude Desktop tool-list silent failure after recent Claude Desktop updates, and an mcp-remote reconnection loop that could drop the MCP session. Also adds slug alias on wp_get_taxonomies and a structured response on wp_get_term_meta. No breaking changes.

= 1.4.11 =
Adds wp_update_term, wp_get/update/delete_term_meta, and wp_get_taxonomies tools — covering tag/category renaming and SEO-plugin term meta (Yoast, Rank Math, AIOSEO). Existing term tools now accept any taxonomy. wp_create_post and wp_update_post accept a post_author user ID. No breaking changes.

= 1.4.10 =
Adds 16 new MCP tools: Royal Ledger, ForgeCache, and Royal Links ecosystem integrations (auto-load when each host plugin is active), SEO meta (Yoast or Rank Math auto-routed), permalink structure read/update, and post revision history + restore. No breaking changes.

= 1.4.9 =
Adds 13 new MCP tools across three groups: theme appearance (5), menu item CRUD (4), and comment moderation (4). Theme writes are gated by a new admin toggle plus an opt-in allowlist filter, mirroring the 1.4.7 wp_update_option safety pattern. No breaking changes.

= 1.4.8 =
Fixes a setup failure that hit users who updated from a pre-1.4.0 build: the Claude custom connector flow returned "Unknown client_id" because the OAuth tables were never created on update. Recommended for anyone who has not been able to add Royal MCP as a Claude connector.

= 1.4.7 =
New: AI assistants can now read plugin settings (sensitive keys redacted) and write to allowlisted WordPress options when enabled. New "Allow AI to write WordPress options" toggle is OFF by default; turn it on under Royal MCP > Settings to opt in.

= 1.3.0 =
Major security and feature update. MCP endpoint now requires API key authentication. Added WooCommerce, GuardPress, and SiteVault integrations (22 new tools). Rate limiting added. Recommended update for all users.

= 1.2.3 =
Security: SSRF protection for outbound requests. WordPress.org compliance fixes.

= 1.2.0 =
Security hardening and MCP spec compliance improvements. Recommended update for all users.
