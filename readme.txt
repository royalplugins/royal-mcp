=== Royal MCP – Secure AI Connector for Claude, ChatGPT & Gemini ===
Contributors: royalpluginsteam
Donate link: https://www.royalplugins.com
Tags: mcp, ai, claude, chatgpt, elementor
Requires at least: 5.8
Tested up to: 7.0
Stable tag: 1.4.33
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Preview-On-WordPress-Playground: yes

Security-first MCP server. Connect Claude, ChatGPT & Gemini to WordPress with API key auth, rate limiting, audit logs, and Elementor tools.

== Description ==

https://youtu.be/pf-mdRnXezM

https://youtu.be/6P7TU1Tva3k

Royal MCP is a security-first Model Context Protocol (MCP) server for WordPress. It gives AI platforms like Claude, ChatGPT, and Google Gemini structured access to your WordPress content — with authentication, rate limiting, and audit logging that most MCP implementations skip entirely.

**First-time setup walkthrough (with videos):** [royalplugins.com/support/royal-mcp/connecting-to-claude/](https://royalplugins.com/support/royal-mcp/connecting-to-claude/)

According to [recent security research](https://mcpplaygroundonline.com/blog/mcp-server-security-complete-guide-2026), 41% of public MCP servers have no authentication and respond to tool calls without any credentials. Royal MCP takes the opposite approach: every MCP session requires an API key, every request is rate-limited, and every interaction is logged.

= Why Security Matters for MCP =

MCP gives AI agents the ability to read, create, update, and delete your WordPress content. Without proper authentication, anyone who discovers your MCP endpoint can:

* Read all your posts, pages, and media
* Create or delete content
* Access user data and plugin information
* Overwhelm your server with rapid-fire requests

Royal MCP prevents all of this with API key authentication on session initialization, timing-safe key comparison, per-IP rate limiting (60 requests/minute), and a full activity log of every MCP interaction.

= Free, Self-Hosted, Fully Featured =

Royal MCP is fully featured in its free, GPL-licensed release. There is no Pro version &mdash; all tools ship in the wp.org plugin, and updates go through the standard WordPress plugin updater.

Your credentials stay on your server. Royal MCP runs entirely inside WordPress: API keys, OAuth tokens, and session state all live in your own database. Royal MCP makes no outbound connections to Royal Plugins&rsquo; own servers &mdash; no license check, no telemetry, no traffic beacon. If you prefer to keep AI inference local too, Ollama and LM Studio are first-class platforms alongside Claude, ChatGPT, and Gemini.

= 67 Core Tools + 60 Integration Tools =

**WordPress Core (67 tools):**

* Posts - create, read, update, delete, search, count (any registered public post type, featured images supported)
* Pages - full CRUD with parent page support
* Post Types - discover all registered public post types on the site
* Post Revisions - list revision history and roll a post back to any prior version
* Media - browse, upload from URL or base64, update alt text/caption/title/description, set as featured image, delete
* Comments - create, read, delete; full moderation suite (list pending, approve, mark spam, trash)
* Users - display names and roles (emails and usernames are not exposed)
* Categories & Tags & Custom Taxonomies - create, update (rename/re-slug/edit/move), delete, assign, count, discover all registered taxonomies
* Term Meta - read, update, delete (most useful for term-level SEO meta - titles, descriptions, focus keywords stored against categories and tags)
* Menus - list menus, list menu items, create / update / delete / reorder menu items
* Post Meta - read, update, delete custom fields (works with ACF, MetaBox, JetEngine, Pods, CPT UI)
* SEO Meta - read and write Yoast SEO or Rank Math title/description/focus keyword/robots/OG fields (auto-detects active SEO plugin)
* Site Info - site name, description, WordPress version, timezone
* Plugins & Themes - list installed plugins and themes with active status
* Theme Appearance - get active theme, read/write theme mods (gated by admin toggle + allowlist), read/write Custom CSS
* Search - full-text content search across post types
* Permalink Structure - read and update permalink settings (gated by admin toggle)
* Options - read allowlisted core options, read full plugin settings by slug (sensitive keys redacted), and write to allowlisted options when an admin enables it

= Plugin Integrations (Conditional) =

Royal MCP automatically detects compatible plugins and adds specialized MCP tools. No configuration needed — if the plugin is active, the tools appear.

**WooCommerce Integration (26 tools):**
When WooCommerce is active, AI agents can manage your store end-to-end:

* Browse and search products by category, status, or type
* Create and update simple and variable products with prices, SKUs, stock levels
* Manage variable products — list, get, create, update, delete, and batch-update product variations
* Manage global attributes (`pa_*` taxonomies) — list registered attributes, list attribute terms, register new attributes, assign attributes to a product as variation axes
* Manage coupons — list, search by code, get, create, update, delete (trash or permanent), and bulk-purge trash; supports all standard WC coupon fields (discount type, expiry, usage limits, product/category restrictions, email allowlists)
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

**ForgeCache Integration (3 tools):**
When ForgeCache is active, AI agents can manage your page cache:

* Clear the entire cache, or purge a specific URL
* View cache statistics — hit rate, file count, total size

**Royal Ledger Integration (4 tools):**
When Royal Ledger is active, AI agents can review your software costs and license data:

* List recurring software costs and renewal dates
* Get cost summaries grouped by month, vendor, or category
* List stored license keys (key VALUES are never exposed — only masked previews; decryption requires logging into wp-admin)

**Royal Links Integration (3 tools):**
When Royal Links is active, AI agents can manage your branded short links:

* List existing links with click counts and target URLs
* Create new branded short links
* Get click statistics for any link

**Advanced Custom Fields Integration (4 tools):**
When ACF (free or Pro) is active, AI agents can read and write ACF fields with the field-type-aware formatting the ACF UI uses — instead of the raw serialized values WordPress meta returns:

* Read a single ACF field, formatted per its Return Format setting (hydrated post objects, parsed repeater rows, image arrays, etc.)
* Read every ACF field on a post in one call, with name/label/type/value bundled — the most efficient way for an AI to discover what fields exist and read them all
* Update an ACF field with type-aware value handling (scalar for text/number, array for repeaters and flex content, post ID for relationships, attachment ID for images)
* Enumerate ACF field groups on the site, optionally filtered by post type — for AI-driven discovery of available custom fields before reading/writing

**Elementor Integration (7 tools):**
When Elementor (free or Pro) is active, AI agents can clone and customize existing Elementor pages without trying to generate page-builder JSON from scratch:

* Clone an existing Elementor page with a new title and fresh element IDs (so the duplicate opens in the editor without ID collisions)
* Bulk-replace text across heading, text-editor, button, image-box, icon-box, icon-list, testimonial, tabs, accordion, toggle, star-rating, call-to-action, and flip-box widgets
* Swap image URLs across image, image-box, background_image, and gallery widget settings
* Get a compact outline of any page (section/container hierarchy, widget types, text snippets) so Claude can reason over a full page in a few KB instead of the raw JSON
* List saved templates from the Elementor template library and import templates from JSON
* Atomic widgets (Elementor 4.0+ Editor V4 elements) pass through opaque — we never decode atomic schemas because Elementor itself may shift them. Widget-level creation from scratch is intentionally out of scope; the design commitment is to work from an existing-known-good source.

= Royal MCP and the WordPress Core Abilities API =

WordPress 6.9 shipped the Abilities API in November 2025 — a primitive that lets plugins register typed capabilities AI agents can call. Core ships three default abilities (site info, user info, environment info) and the `wordpress/mcp-adapter` package bridges abilities to the MCP protocol.

Royal MCP is a complete, production-ready MCP server that predates the official adapter. It runs the full Streamable HTTP transport, enforces API key authentication on every request, ships OAuth 2.0 for Claude Desktop's native connector flow, rate-limits per-IP, redacts sensitive data, and logs every interaction. Out of the box it includes 67 tools for WordPress core operations plus 60 integration tools that auto-load when WooCommerce, GuardPress, SiteVault, ForgeCache, Royal Ledger, Royal Links, Elementor, or Advanced Custom Fields (ACF) is active.

= Supported AI Platforms =

* **Claude (Anthropic)** - Full MCP support via Claude Desktop, Claude Code, and VS Code
* **OpenAI / ChatGPT** - GPT-5.5, GPT-5, GPT-5 Mini, o3
* **Google Gemini** - Gemini 3.5 Flash, 3.1 Flash-Lite
* **Groq** - Llama 3.3, Llama 3.1, GPT-OSS
* **Azure OpenAI** - Azure-hosted OpenAI deployments
* **AWS Bedrock** - Claude, Llama, Titan models
* **Ollama / LM Studio** - Local self-hosted models (no external data transmission)
* **Custom MCP Servers** - Connect to any MCP-compatible endpoint

= Compatible Clients & Frameworks =

<!-- compliance: technical-context -->
Royal MCP works with any MCP-compliant client, IDE, or AI agent framework — no per-tool configuration required. Each entry below describes the specific integration path Royal MCP provides for that target, so customers can answer "will this work with the tool I already use?":

* **Desktop AI apps** - Claude Desktop (native MCP connector via OAuth 2.0), ChatGPT Desktop, Gemini Advanced.
* **AI code IDEs** - Claude Code, VS Code (with MCP extension), Cursor, Windsurf, Continue, Cline, Zed, JetBrains AI Assistant.
* **API testing tools** - Postman, Bruno, Insomnia (use the API key in the `X-Royal-MCP-API-Key` header).
* **Custom field plugins** - Advanced Custom Fields (ACF) has dedicated `acf_*` tools that return values formatted per each field's Return Format setting (the same way the ACF UI shows them). MetaBox, JetEngine, Pods, CPT UI, and Custom Field Suite are supported through the `wp_get_post_meta` / `wp_update_post_meta` tools, so AI agents can populate custom fields just like a human editor.
* **Page builders** - Elementor has dedicated tools for clone-and-customize workflows (clone a page, find/replace text, swap images, get an outline, import templates) - see the Tools list. Widget-level creation from scratch is intentionally out of scope. Divi, Beaver Builder, Bricks, Gutenberg, Spectra, and Stackable store standard post content that is readable and writable by AI; page-builder-specific JSON storage is opaque unless covered by a dedicated tool.
* **Multilingual** - WPML, Polylang, TranslatePress, qTranslate. Translated posts appear as separate posts and can be read or written via the standard post tools.
* **AI agent frameworks** - LangChain, AutoGen, CrewAI, LlamaIndex, Haystack - any MCP-compatible framework can call Royal MCP's tools.
* **AI app platforms** - Anthropic Console, OpenAI Playground, Google AI Studio, Vertex AI, Azure AI Studio, Amazon Bedrock Console.

= MCP Spec Compliance =

Royal MCP implements the [MCP 2025-11-25 Streamable HTTP transport specification](https://modelcontextprotocol.io/specification/2025-11-25/basic/transports#streamable-http):

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
7. New to MCP? Follow the step-by-step connection walkthrough (with videos) at [royalplugins.com/support/royal-mcp/connecting-to-claude/](https://royalplugins.com/support/royal-mcp/connecting-to-claude/)

Full setup guides for each platform are available at [royalplugins.com/support/royal-mcp/](https://royalplugins.com/support/royal-mcp/).

== Frequently Asked Questions ==

= What is MCP and why does my WordPress site need it? =

Model Context Protocol (MCP) is an open standard created by Anthropic that lets AI assistants interact with external data sources. Without MCP, AI tools like Claude or ChatGPT can only work with content you copy and paste into them. With Royal MCP installed, these AI platforms can directly read your WordPress posts, create new content, manage your WooCommerce products, check your security status, and trigger backups — all through a structured, authenticated protocol.

= How is Royal MCP different from other WordPress MCP plugins? =

Security. Most MCP plugins — and 41% of all public MCP servers — have no authentication at all. Royal MCP requires an API key for every session, rate-limits requests to prevent abuse, logs every interaction for audit purposes, and filters sensitive data (emails, PHP version, admin credentials) from responses. We built this plugin with the same security standards we apply to GuardPress, our WordPress security plugin used on thousands of sites.

= Does Royal MCP duplicate what WordPress core now does? =

No. WordPress 6.9 added the Abilities API — a primitive for registering AI-callable functions — and the `wordpress/mcp-adapter` package bridges abilities to the MCP protocol. Royal MCP is a full MCP server with the security layer, connector flows, and plugin integrations that the bare primitive does not include: enforced API key auth, OAuth 2.0 for Claude Desktop, per-IP rate limiting, audit logging, sensitive-data redaction, 67 ready-to-use WordPress core tools, and 60 integration tools that auto-load for WooCommerce, GuardPress, SiteVault, ForgeCache, Royal Ledger, Royal Links, Elementor, and Advanced Custom Fields.

= Does Royal MCP work with WooCommerce? =

Yes. When WooCommerce is active, Royal MCP automatically adds 26 MCP tools spanning product management (simple and variable, including variation CRUD and global attribute management), full coupon management (list/get/create/update/delete + bulk trash purge), order management (view, update status), customer data, and store statistics. No additional configuration is needed — the tools appear automatically in the MCP tools list.

= Can AI assistants configure my plugins for me? =

Yes, with safety controls. Royal MCP exposes two tools for plugin configuration:

* `wp_get_plugin_settings` lets AI read any plugin's stored settings by slug. Sensitive values (API keys, secrets, tokens, passwords, license keys, OAuth credentials) are automatically replaced with `[REDACTED]` before they leave your server, so AI assistants can understand a plugin's configuration without ever seeing stored credentials.

* `wp_update_option` lets AI write to WordPress options, but only after passing three security gates:
    1. The site admin must enable the "Allow AI to write WordPress options" toggle on the Royal MCP settings page (off by default)
    2. The option name must be in a runtime allowlist. The default allowlist is intentionally tiny — `blogname`, `blogdescription`, `posts_per_page`, `date_format`, `time_format`. Plugin authors opt their own settings in via the `royal_mcp_writable_options` filter.
    3. A hard denylist permanently blocks writes to sensitive option names (siteurl, home, license keys, secrets, salts, etc.) regardless of the allowlist or the toggle.

Plugin authors can opt in their settings with one line: `add_filter('royal_mcp_writable_options', fn($opts) => array_merge($opts, ['my_plugin_settings']));`

= How do I connect Claude Desktop to WordPress? =

Install Royal MCP, go to Royal MCP → Settings, and copy your API key and MCP server URL. In Claude Desktop, add a new MCP server configuration with the URL and include the `X-Royal-MCP-API-Key` header with your API key. Full step-by-step guide at [royalplugins.com/support/royal-mcp/](https://royalplugins.com/support/royal-mcp/). If the connection fails, see the next FAQ.

= The connector won't connect — where do I start? =

About 90% of "can't connect" / "OAuth failed" / "tools missing" issues resolve in a basic 4-step pass before any host-specific fix is needed. In order: (1) update Royal MCP to the latest version (every recent release fixes meaningful OAuth edge cases), (2) run a conflict test — deactivate all other plugins, switch to a default theme like Twenty Twenty-Five, and purge every cache layer (any cache plugin, your host's server-level cache, Cloudflare/CDN, and browser cache), (3) wipe stale OAuth state — use the Reset OAuth State button in Royal MCP → Settings if you're on 1.4.17 or newer, or run the four `DELETE` SQL queries documented in our support article, (4) check Royal MCP → Activity Logs for the most recent `oauth:` row, which records exactly which validation rule fired. Full walk-through with copy-pasteable commands at [royalplugins.com/support/royal-mcp/troubleshooting-start-here.html](https://royalplugins.com/support/royal-mcp/troubleshooting-start-here.html). Only proceed to host-specific fixes (Cloudflare AI Bots toggle, SiteGround `/.well-known/` static files, edge-cache exclusions) after the four basics are ruled out — most "advanced infrastructure" tickets we receive actually resolve in those four steps.

= I restored my WordPress database from backup and Claude can't reconnect. How do I fix this? =

When you restore from backup, the OAuth client credentials Claude was holding no longer match anything on the WordPress side, so Claude's connector ends up with a stale token that no Royal MCP installation will accept. The fix in Royal MCP 1.4.17+ is one click: go to **Royal MCP → Settings** and click the **Reset OAuth State** button. This wipes all stale OAuth clients, issued access/refresh tokens, and pending authorization codes. Then in Claude, delete the existing connector entirely, wait 30 seconds, and re-add it from scratch — the full OAuth flow runs fresh against the cleaned-up state and the connection works. On 1.4.16 or older the same effect can be achieved by running four `DELETE` SQL queries documented at [royalplugins.com/support/royal-mcp/troubleshooting-start-here.html](https://royalplugins.com/support/royal-mcp/troubleshooting-start-here.html). The plugin's settings, API key, and Activity Log are not affected by Reset OAuth State — only the OAuth handshake state.

= Claude says "Couldn't register with sign-in service" or "Session not found" — what's wrong? =

Both messages (plus "no tools available" in Claude.ai after connecting) usually mean one of Royal MCP's OAuth or sessions database tables is physically missing. The fix is to update Royal MCP to 1.4.29 or newer — the new runtime healer detects missing tables and recreates them automatically on the next pageload, with no deactivate/reactivate required. After updating, delete the existing Royal MCP connector in Claude, wait 30 seconds, then re-add it fresh. If you can't update yet and need to recover immediately, the manual workaround is `wp option delete royal_mcp_db_version` followed by loading any wp-admin page. Full symptom diagnostic (phpMyAdmin / WP-CLI), the auto-heal explanation, and the manual recovery walkthrough are at [royalplugins.com/support/royal-mcp/oauth-tables-missing.html](https://royalplugins.com/support/royal-mcp/oauth-tables-missing.html).

= I'm auditing my install and can't find the OAuth endpoints under `/wp-json/royal-mcp/v1/`. Where are they? =

By design, Royal MCP's OAuth endpoints (`/register`, `/token`, `/authorize`) are registered as **top-level WordPress rewrite rules at the site root**, not as REST API routes under `/wp-json/royal-mcp/v1/`. This is required by the OAuth 2.0 specification (RFC 6749) and the MCP discovery specs (RFC 8414 and RFC 9728), which mandate predictable site-root paths so OAuth-discovery-aware clients can find them without per-plugin configuration. If you're auditing rewrite rules instead of REST routes, you can see ours via `wp rewrite list | grep royal_mcp_oauth` from WP-CLI. The `/wp-json/royal-mcp/v1/` namespace contains the JSON-RPC tool endpoint at `/mcp` plus supporting REST routes (`/posts`, `/pages`, `/site`, etc.) — but not the OAuth handshake endpoints themselves. Both routing layers are normal and both need to be reachable for the connector to work end-to-end.

= Is my content safe? =

Royal MCP is designed with defense in depth. API key authentication is required for all MCP sessions. Rate limiting prevents abuse (60 requests per minute per IP). Activity logging records every tool call. Sensitive data is filtered — user emails, usernames, admin email, PHP version, and stored credentials inside plugin settings (api keys, secrets, tokens, passwords) are never exposed through MCP. Comment creation respects your WordPress moderation settings. Post meta values are sanitized before storage. Option writes are disabled by default and gated by three independent checks (admin toggle, allowlist, hard denylist) when enabled. The plugin itself starts disabled by default — nothing is accessible until you explicitly enable it.

= Can I use local AI models instead of cloud services? =

Yes. Royal MCP supports Ollama and LM Studio for fully local AI inference. When using local models, no data leaves your server — the AI model runs on your own hardware and communicates with WordPress through the MCP protocol on localhost.

= What happens if I uninstall Royal MCP? =

Royal MCP performs a clean uninstall. All plugin options, database tables (activity logs), transients, and user meta are removed. No orphaned data is left behind.

= Does Royal MCP work with Claude Code, VS Code, Cursor, Windsurf, or other AI IDEs? =

Yes. Any MCP-compliant client can connect to Royal MCP. Configure your IDE or client with the MCP server URL (`https://yoursite.com/wp-json/royal-mcp/v1/mcp`) and the API key (sent in the `X-Royal-MCP-API-Key` header). Claude Desktop additionally supports the native "Add Connector" OAuth 2.0 flow, which Royal MCP handles via Dynamic Client Registration (RFC 7591) — no manual API key management required on that path. The same OAuth flow works in any client that follows the MCP 2025-11-25 spec.

= Does Royal MCP work with custom fields, ACF, MetaBox, JetEngine, Pods, or CPT UI? =

Yes. Royal MCP exposes WordPress's standard `wp_get_post_meta`, `wp_update_post_meta`, and `wp_delete_post_meta` tools, which read and write any custom field — including Advanced Custom Fields (ACF), MetaBox, JetEngine, Pods, CPT UI, and Custom Field Suite. AI agents can populate ACF fields, set repeater rows, update flexible content blocks, and read computed fields just like a human editor working in the WordPress admin.

= Will Royal MCP slow down my WordPress site? =

No. The MCP endpoint is a REST route that runs only when an authenticated AI client makes a request — it does not run on visitor-facing pages, frontend templates, or admin screens (except its own settings page). The activity log uses a single indexed database table and writes asynchronously after the response is sent. Rate limiting (60 requests/minute per IP) prevents accidental overload.

= Does Royal MCP work on WordPress multisite networks? =

Yes, on a per-site basis. Each site in a multisite network has its own API key, its own activity log, and its own settings. AI clients connect to a specific site's MCP endpoint — Royal MCP does not bridge requests between sites in the network.

= Can I limit which posts, pages, or post types AI can access? =

Yes. The `wp_get_posts` and `wp_create_post` tools accept a `post_type` parameter and validate it against registered public post types, so private or internal post types are not exposed. Plugin authors can disable specific tools entirely with the `royal_mcp_disabled_tools` filter, or scope the option-write allowlist with `royal_mcp_writable_options`. WordPress's standard capability checks also apply to every tool call.

= Does Royal MCP work with WPML, Polylang, or TranslatePress for multilingual content? =

<!-- compliance: technical-context -->
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

= 1.4.33 =
* Feature: `wp_create_post`, `wp_update_post`, `wp_create_page`, and `wp_update_page` accept a new `date` parameter (ISO 8601, site timezone). Combine with `status="future"` to schedule; use alone on the update tools to backdate. Past-dated `future` publishes immediately with the given timestamp, matching wp-admin behavior. Both `post_date` and `post_date_gmt` are derived from the same parsed timestamp so they never disagree; the update tools set `edit_date=true` internally so the change takes effect.
* Feature: `wp_create_post` and `wp_create_page` status enum expanded from `["publish", "draft"]` to `["publish", "draft", "future", "pending", "private"]`. The additional statuses are standard WordPress statuses handled natively by `wp_insert_post`; no extra caller work required.
* Feature: New `royal_mcp_tool_called` action hook fires after every MCP tool invocation with `(tool_name, status, error_message)`. Ecosystem plugins can subscribe for classification, dashboarding, or forwarding without depending on the internal logger.
* Feature: Activity Log page surfaces a pointer to the free Royal AI Firewall plugin (wp.org) for site owners tracking HTTP-layer AI bot traffic outside of MCP tool calls. When Royal AI Firewall is detected on the site, the pointer swaps to a direct dashboard link.

= 1.4.32 =
* Feature: `wp_search` now accepts optional `snippet` (int, max 1000 chars) and `per_page` (default 20, max 100) parameters. When `snippet` is set, each result row includes the matched post&rsquo;s `slug` and a content excerpt windowed around the first occurrence of the search term &mdash; lets AI drivers skip a follow-up `wp_get_page` per result on multi-page audits. Snippet extraction strips HTML and registered shortcodes and is multibyte-safe. Strictly additive; existing callers without the new parameters see no behavior change.
* Feature: `wc_get_orders` now accepts a `page` parameter for stores with more than `per_page` orders. **Response shape change:** the tool now returns `{orders, page, per_page, total, total_pages}` instead of a bare array. AI drivers should iterate `page` until `page >= total_pages`. Pre-1.4.32, orders beyond the first 100 were unreachable.
* Docs: general readme cleanup and updates.

= 1.4.31 =
* Hardening: `wp_delete_post` capability check now runs before the post-existence lookup. Pre-1.4.31, a Subscriber-tier OAuth Bearer calling `wp_delete_post` with a non-existent post ID received "Post not found." rather than a permission error &mdash; effectively a post-ID enumeration surface (the response distinguished "exists but you can't delete" from "doesn't exist"). 1.4.31 inverts the order: unauthorized callers now receive a permission error regardless of whether the target post exists. Same defense-in-depth pattern as the six integration cap-order fixes shipped in 1.4.30.
* Hardening: `wp_get_post_meta` now requires the `edit_post` capability for underscore-prefixed (protected) meta keys, matching WordPress core&rsquo;s `is_protected_meta()` convention. Pre-1.4.31, a Subscriber-tier OAuth Bearer could read underscore-prefixed post meta on public posts (Yoast SEO `_yoast_wpseo_*`, `_edit_lock`, `_wp_attached_file`, ACF internal fields, custom plugin meta) because the broader `read_post` cap returned true for public content. The non-underscore (developer-visible) meta path keeps the existing `read_post` gate so legitimate public-meta reads continue to work for low-privilege users. Empty-key &ldquo;return all meta&rdquo; requests also require `edit_post` since the response would otherwise expose protected keys.
* Hardening: `wp_update_post`, `wp_update_page`, `wp_update_media`, and `wp_update_term` now treat empty-string text fields as "preserve existing value" rather than "blank the field." Pre-1.4.31, an AI driver that template-filled an optional text argument with `""` instead of omitting it would silently destroy the existing post body, title, excerpt, caption, alt text, term name, or term description. Field omission already preserved existing values via PHP&rsquo;s `isset()` gate; this extends the same protection to the empty-string case. To explicitly clear a text field, edit through the WP admin.
* Ergonomics: Every tool that identifies a single post now accepts either `id` or `post_id`. Pre-1.4.31, `wp_get_post` / `wp_update_post` / `wp_delete_post` required `id` while `wp_get_post_meta` / `wp_update_post_meta` / `wp_get_seo_meta` / `wp_update_seo_meta` / `wp_get_post_revisions` / `wp_add_post_terms` required `post_id` &mdash; an AI driver that called a tool with the wrong-named argument received an InputValidationError. Both names are now accepted on every post-identifying tool (pages and media included; comments, terms, and users keep their separate ID domains). No schema changes; existing callers continue to work unchanged.
* UX: Royal Plugins Founders Bundle banner tweaks on the Royal MCP settings page.
* UX: New wp.org review-request banner on the Royal MCP settings page with a direct CTA to leave a review. Dismissable per plugin version &mdash; appears once on each plugin update, no time-based or pageload re-prompts.

= 1.4.30 =
* New: `elementor_add_widget` MCP tool &mdash; the first structural-write Elementor tool. Programmatically drop widgets or containers into an existing Elementor page. Dual-surface design: the raw path accepts any widget type registered with Elementor (or an Editor V4 atomic prefix) plus a full Elementor settings object; the curated path covers the 11 highest-frequency widget types (container, heading, text-editor, button, image, image-box, icon-box, icon-list, video, divider, spacer) with flat parameters that the tool expands into the canonical settings object internally, saving tokens on every call. Container widgets can include nested children inline (one call drops a parent container with N child widgets, recursive). Atomic widgets (Editor V4) pass through opaquely via the raw path since their JSON schema is not publicly documented. Curated `video` detects host and routes YouTube, Vimeo, and Dailymotion URLs to the correct internal Elementor field. Curated `icon-list` builds the repeater shape with auto-generated item IDs. Cap-checked via `edit_post` per the existing Elementor-tool pattern (1.4.26 hardening still applies). Pre-1.4.30 the Elementor tools covered clone-and-customize (1.4.19) and read (`elementor_get_page_outline`); they did not let an agent build a page widget by widget. 1.4.30 closes that gap with the smallest possible surface.
* Hardening: `elementor_add_widget` rejects unknown `widget_type` slugs at the boundary rather than serializing them into `_elementor_data` (where Elementor would render them as silent empty placeholders). Validates against Elementor&rsquo;s widget registry, allows Editor V4 atomic prefixes (`a-*` / `e-*`) opaquely, and fails open if the registry is unreachable so a transient autoloader miss can&rsquo;t block writes that would otherwise succeed. Catches typos (`headng`, `text-edtior`) at the API call instead of after an agent thinks the page was built.
* Hardening: Capability check order in six integration tool wrappers (GuardPress, SiteVault, ForgeCache, Royal Ledger, ACF, Royal Links). Pre-1.4.30 the &ldquo;integration is not active&rdquo; check fired before the capability check, so a Subscriber-tier OAuth Bearer calling an integration tool on a site where that integration was inactive would receive the &ldquo;X is not active&rdquo; error message &mdash; effectively a presence-probe surface that let unauthorized callers enumerate which integrations were installed. 1.4.30 inverts the order: an unauthorized caller now receives a permission error first regardless of whether the integration is present. For four of the six wrappers the existing umbrella cap (`manage_options` for GuardPress / SiteVault / Royal Ledger) was already correct and only needed reordering; ACF and Royal Links gained a new `edit_posts` umbrella check above their per-handler caps. Per-handler object-level checks (`read_post`, `edit_post`, `manage_options`) remain in place &mdash; no semantic change for authorized callers.

= 1.4.29 =
* Fix: Restore the runtime DB-migration retry semantic that regressed in 1.4.27. On a subset of wp.org auto-update installs (LiteSpeed-fronted hosts with opcache, plus any environment where the autoloader transiently failed during the file-swap), 1.4.27&rsquo;s `maybe_upgrade_db()` could mark the schema version as up-to-date even when the new sessions table and OAuth tables hadn&rsquo;t actually been created. The latched state silently broke OAuth registration (`/register` returned 500 with "Failed to persist client registration. The OAuth tables may be missing") and MCP session persistence (`Mcp-Session-Id` couldn&rsquo;t be looked up on the next request, returning 404 "Session not found"). 1.4.29 restores the success-tracking &mdash; `db_version` only advances when every required migration actually ran &mdash; and adds a force-load fallback so a transient autoloader miss can&rsquo;t latch the install. Affected customers heal automatically on the 1.4.29 update; if any install is still stuck after updating, a single deactivate + reactivate also creates the tables.
* Fix: Defensive self-heal on `/register`. If the OAuth client registration handler hits the "tables may be missing" error path, the plugin now attempts to create the missing tables once and retry the insert before returning the 500 to the calling MCP client. Belt-and-suspenders for any install that still updates with the autoloader race fired.
* Fix: `maybe_upgrade_db()` no longer trusts the `royal_mcp_db_version` option alone &mdash; it now also verifies that the OAuth-clients and sessions tables physically exist before short-circuiting the migration. Closes a recovery gap where an install whose tables had been dropped externally (or by an uninstall that left the version option behind) could not self-heal via the runtime migration, even after a deactivate + reactivate cycle. Thanks to @rula99 for the wp.org forum report and root-cause analysis.
* Fix: `uninstall.php` now also deletes the `royal_mcp_db_version` option. Pre-1.4.29, uninstall dropped all tables and cleared settings but left the version option in place, so a subsequent reinstall on the same WP install would see the option matching the new plugin version and skip table re-creation, leaving the install in a stuck state. Uninstall now leaves a fully clean slate. Thanks to @rula99.

= 1.4.28 =
* Compatibility: Authorization-header API key fallback. Pre-1.4.28, if an MCP client sent its static API key via the universal `Authorization: Bearer <key>` HTTP header, Royal MCP routed the value entirely into OAuth-token validation, failed (since an API key is not an OAuth token), and returned 401 &mdash; even though the same key worked when sent via the Royal-MCP-specific `X-Royal-MCP-API-Key` header. This broke connection with several modern MCP clients (Apify&rsquo;s newly-launched MCP connectors, n8n, Make.com, anything that follows the universal HTTP convention for bearer credentials). 1.4.28 adds a strict-additive fallback: after OAuth-token validation fails, the same Bearer value is tried as an API key before returning the 401. The security perimeter is unchanged &mdash; API keys were already accepted as bearer credentials via a different header name; this just accepts the universal convention every modern MCP client uses. The `X-Royal-MCP-API-Key` header continues to work for backward compatibility.
* Feature: Yoast / Rank Math `wp_get_seo_meta` and `wp_update_seo_meta` tools now read and write the post URL slug (the &ldquo;Slug&rdquo; field shown in Yoast&rsquo;s and Rank Math&rsquo;s post editors). Pre-1.4.28, AI agents could write SEO title, meta description, focus keyword, robots, and OG fields but had to fall back to `wp_update_post` for the slug &mdash; an extra tool call and a workflow break. Now a single `wp_update_seo_meta` call covers the whole SEO setup. The slug is a WordPress-native field (post_name), so it works regardless of whether Yoast or Rank Math is installed. Slug updates route through `wp_update_post()` so WordPress&rsquo;s slug-uniqueness logic runs (appends -2, -3, etc on collision) and downstream `save_post` hooks fire normally. The actually-saved slug is returned in the response so the caller can confirm whether WordPress modified the requested value. Requires `edit_post` capability on the target post (the same gate the rest of the tool already enforces). Thanks to @KKNORR-TC for the request (GH issue #34).

= 1.4.27 =
* Reliability: MCP session state moved off WordPress transients onto a dedicated `wp_royal_mcp_sessions` table, fixing 404 "Session not found" errors on sites with object-cache drop-ins (some LiteSpeed-based managed hosts, SpeedyCache, etc).
* Cleanup: Removed ~130 lines of orphan admin-AJAX code (`royal_mcp_get_platform_fields` / `render_platform_fields`) that no UI path still called.
* Compliance: Replaced an SEO-plugin enumeration in the description with a generic capability sentence.

= 1.4.26 =
* Security: Per-tool WordPress capability checks added across all content, user, term, comment, and integration tools. Pre-1.4.26, an OAuth Bearer token from a low-privileged role (Subscriber, Contributor) could invoke admin-only operations &mdash; create/update/delete content, enumerate users, read private posts and post meta, manage WooCommerce records, trigger backups, read security audit logs. The API-key path was unaffected (runs as admin per 1.4.6). Status filters on `wp_get_posts` / `wp_get_comments` converted from denylist to positive allowlist (unknown statuses fail closed). Reported by Alessandro Greco (Aleff). Recommended for all users.

= 1.4.25 =
* UX: MCP Server URL promoted to the top of General Settings as the canonical inbound URL for every client. Previously labeled "Claude Connector Settings &mdash; FOR CLAUDE.AI" which hid it from ChatGPT/Cursor/Gemini setup paths.
* UX: New in-product "MCP Client Setup Guides" accordion covering Claude.ai, ChatGPT, Claude Desktop, and Cursor.
* UX: "AI Platforms" renamed to "Outbound AI Provider Configuration" with a disambiguation banner so customers stop mistaking the outbound provider list for inbound MCP setup.
* UX: Cloudflare warning moved to General Settings (applies to all clients, not just Claude).
* UX: Legacy REST API Base URL and manual OAuth Client ID / Secret demoted into a collapsible "Advanced" subsection.
* Fix: Universal admin icon alignment, visible keyboard focus ring on all settings-page buttons, improved helper-text contrast.

= 1.4.24 =
* New: Advanced Custom Fields integration &mdash; 4 tools (`acf_get_field`, `acf_get_fields`, `acf_update_field`, `acf_get_field_groups`). Returns values per each field's Return Format setting (hydrated post objects, parsed repeater rows, image arrays) instead of raw serialized data. Auto-registers when ACF (free or Pro) is active.
* Fix: `wc_create_product` now respects the `type` argument and creates the matching WooCommerce product class (Simple, Variable, Grouped, External). Pre-1.4.24 it silently returned Simple for every type, breaking the variable-product workflow. Bug had been present since the WooCommerce integration shipped in 1.4.10.
* Doc: readme.txt Description and Installation section now point to the first-time setup walkthrough. AI Platforms screen shows a contextual notice on the Claude card to disambiguate inbound vs outbound setup.

= 1.4.23 =
* Fix: AI Platforms model dropdowns refreshed across all five LLM providers (Claude, OpenAI, Gemini, Groq, Bedrock) &mdash; retired models removed, current production lineups added, defaults rotated to vendor-recommended replacements. Pre-1.4.23 customers picking retired models hit 404 on Test Connection or upstream errors at runtime. Existing installs with a working stored model are unaffected.

= 1.4.22 =
* Fix: AI Platforms &rarr; Test Connection on Claude now uses the model selected in the dropdown (was hardcoded to a deprecated model that always returned 404). Dropdowns refreshed to current lineups.
* Fix: Manually-configured OAuth Client ID and Client Secret can now be cleared through the UI; Reset OAuth State extended to wipe them too.
* Fix: OAuth root rewrite rules now match both bare and trailing-slash variants &mdash; closes a hijack vector where membership plugins / theme templates could intercept the trailing-slash form.
* New: Admin notice detects when the web server returns a 301 trailing-slash redirect on POST `/register` (host-side canonicalization that breaks OAuth registration since clients don't follow 301 on POST).
* New: `.well-known/` self-check now also detects when a membership plugin or theme template intercepts the discovery endpoint with an HTML page.

= 1.4.21 =
* Fix: Gutenberg block content via `wp_create_page` / `wp_update_page` / `wp_create_post` / `wp_update_post` no longer mangles the block JSON comment (broke WP 7.0's per-block Custom CSS). Two compounding bugs: a pre-filter `wp_kses_post()` HTML-encoded block delimiters, and `wp_insert_post()`'s internal `wp_unslash` stripped literal backslashes inside escape sequences. Round-trip is now byte-for-byte preserved on WP 6.x and 7.0. Reported by @danielkleinert (royalplugins/royal-mcp#15).

= 1.4.20 =
* Fix: WooCommerce order tools no longer hang on HPOS stores when a `shop_order_refund` appears in the result set. The order formatters expected a `WC_Order` and choked on `WC_Order_Refund`, surfacing as -32001 timeout. Fixed across `wc_get_orders`, `get_store_stats`, `wc_get_order`, `wc_update_order_status`. Thanks to @ober37 (royalplugins/royal-mcp#20, #21).

= 1.4.19 =
* New: Six Elementor tools for clone-and-customize workflows: `elementor_clone_page`, `elementor_replace_text`, `elementor_replace_image`, `elementor_get_page_outline`, `elementor_list_local_templates`, `elementor_import_template`. Auto-register when Elementor is active. Atomic widgets (Editor V4) pass through opaque. Capability-gated. Tested against a real Elementor Pro 4.0.4 page with 74 widgets / 9 containers.
* New: Admin notice detects stale static `.well-known/oauth-authorization-server` files left from a pre-1.4.0 host-support workaround &mdash; they advertise old `/wp-json/royal-mcp/v1/` paths and silently break Claude.ai connections.
* Doc: Page-builder line in readme softened to describe Elementor handling explicitly.

= 1.4.18 =
* Fix: `/wp-json/royal-mcp/v1/mcp` GET handler is now User-Agent-aware. Anthropic's post-OAuth session probe (UA `Claude-User`) gets HTTP 200 + `text/event-stream`; other authenticated GETs continue to receive 405 with `Allow: POST, DELETE, OPTIONS` (preserves the 1.4.12 mcp-remote retry-storm fix).
* Fix: `wp_update_menu_item` and `wp_reorder_menu_items` no longer destroy non-empty existing fields. Pre-1.4.18 these passed partial args to `wp_update_nav_menu_item()`, which merged unspecified fields with empty defaults &mdash; wiping titles, URLs, parent_id, target on every item touched (royalplugins/royal-mcp#14).
* Doc: New FAQ entries &mdash; DB-restore recovery via Reset OAuth State (#12), OAuth endpoints are top-level rewrite rules not REST routes, "where do I start" troubleshooting checklist.

= 1.4.17 =
* Fix: Authorization codes moved off WordPress transients onto a dedicated `wp_royal_mcp_oauth_auth_codes` table with atomic single-row consume. On stacks with multiple object-cache layers (LiteSpeed + SpeedyCache reproducer), the transient backend was silently evicting auth codes in the ~2s `/authorize` &rarr; `/token` window, breaking OAuth with `invalid_grant`.
* New: "Reset OAuth State" admin button &mdash; one-click wipe of all registered clients, tokens, and pending auth codes. Recorded in Activity Logs as `oauth:reset`. Settings/API key/Activity Log unaffected.
* New: MCP `tools/call` requests write a structured Activity Log entry on every invocation (action `tools/call:<tool_name>`). Argument keys are logged; values are not.
* Fix: Activity Log "View Details" modal now renders Request/Response JSON instead of `[object Object]`.
* Fix: Plugin admin CSS/JS now use `ROYAL_MCP_VERSION . filemtime($file)` cache-busting, so intra-version asset patches stop serving stale on Cloudflare-fronted installs.

= 1.4.16 =
* New: OAuth flow now writes structured Activity Log entries on every `/token`, `/register`, or `/authorize` failure (error code, description, HTTP status, public `client_id` / `grant_type` / `response_type`). Auth codes, PKCE verifiers, client secrets, and tokens are excluded from the payload. Pre-1.4.16 OAuth failures exited silently &mdash; support required `WP_DEBUG_LOG` + source patches.

= 1.4.15 =
* Fix: Regenerate API Key button no longer silently no-ops (sanitize order was checking the existing readonly value before the regenerate flag).
* Fix: New API keys are 32-char lowercase hex instead of mixed-case alphanumeric, eliminating O/0, I/l/1, o/0 visual-ambiguity transcription errors. Existing keys keep working. Same 128-bit entropy.
* Fix: MCP sessions now use a sliding 24-hour TTL with refresh-on-access (was fixed 1h), eliminating the Claude Desktop thundering-herd reconnect loop.
* Fix: All `/wp-json/royal-mcp/*` responses now send `Cache-Control: no-store, no-cache, must-revalidate, private` on every response. Closes a leak where URL-keyed edge caches could serve an auth-error response to subsequent authenticated requests &mdash; or cache an authenticated 200 and serve it to unauthenticated ones.
* Fix: Invalid API key now returns HTTP 401 with `WWW-Authenticate: Bearer` (per RFC 7235) instead of 403, so RFC 9728-aware MCP clients trigger OAuth discovery on the response.

= 1.4.14 =
* Fix: Unauthenticated GET to the MCP endpoint now returns HTTP 401 + `WWW-Authenticate: Bearer resource_metadata="..."` instead of 405, restoring the spec-correct OAuth discovery path for Claude.ai web and ChatGPT MCP connectors (RFC 9728). Authenticated GET continues to return 405 (preserving 1.4.12 mcp-remote fix). Resolves a WP.org forum report against 1.4.13.
* New: Self-check detects when the host blocks `/.well-known/oauth-authorization-server` (some managed hosts reserve the path prefix at nginx for ACME SSL) and surfaces a dismissible admin notice with the manual fix link.

= 1.4.13 =
* Fix: OAuth endpoint responses (`/register`, `/token`, `/authorize`) now send `Cache-Control: no-store` by default. Previously, aggressive edge caches could cache a 405 from a stale GET probe and serve it to subsequent valid POSTs, breaking Claude.ai's OAuth flow.
* New: 10 WooCommerce variation and attribute MCP tools (CRUD + batch + attribute-term management). Parent product price/stock cache synced via `WC_Product_Variable::sync()` after every mutation. Contributed by @ober37.
* New: 7 WooCommerce coupon management MCP tools (full CRUD + trash/purge). Every operation validates the post type is `shop_coupon`. Contributed by @ober37.

= 1.4.12 =
* Fix: MCP `protocolVersion` bumped from `2025-03-26` to `2025-11-25` &mdash; current Claude Desktop builds were silently rejecting the entire tool list when the server replied with the older date. Thanks to @ober37.
* Fix: `handle_get_stream()` now returns HTTP 405 with `Allow: POST, DELETE, OPTIONS` instead of an immediately-closed SSE stream, ending the `mcp-remote` retry storm that dropped MCP sessions.
* Enhancement: `wp_get_taxonomies` returns a `slug` field alias for the taxonomy identifier; `wp_get_term_meta` returns a structured response (`{term_id, key, value}` or `{term_id, meta}`) matching the rest of the term-meta tool family.

= 1.4.11 =
* New: `wp_update_term`, `wp_get_term_meta`, `wp_update_term_meta`, `wp_delete_term_meta`, `wp_get_taxonomies`. Most useful for editing tag/category SEO meta.
* Enhancement: `wp_create_term`, `wp_delete_term`, `wp_add_post_terms` accept any registered taxonomy (was hardcoded to `category` and `post_tag`).
* Enhancement: `wp_create_term` accepts optional `slug`; `wp_create_post` / `wp_update_post` accept `post_author` user ID.

= 1.4.10 =
* New: Royal Ledger integration (4 tools), ForgeCache integration (3 tools), Royal Links integration (3 tools). Auto-load when each host plugin is active.
* New: SEO meta tools (`wp_get_seo_meta`, `wp_update_seo_meta`) auto-detect the active SEO plugin and read/write title, description, focus keyword, robots, OG fields.
* New: Permalink structure tools and post revision tools (read history + revert).

= 1.4.9 =
* New: Theme appearance tools (active theme, theme mods, custom CSS read/write). Writes gated by an admin toggle (off by default) and a new `royal_mcp_writable_theme_mods` allowlist filter.
* New: Menu item CRUD (create/update/delete/reorder); comment moderation (pending list, approve, spam, trash). Capability-gated.

= 1.4.8 =
* Fix: Custom connector setup in Claude no longer fails with "Unknown client_id" on sites that were updated from a pre-1.4.0 build without ever being deactivated/reactivated. The OAuth tables are now created on plugin upgrade, not just on first activation.
* Fix: Dynamic Client Registration (`POST /register`) now returns a real 500 with the underlying database error if the write fails, instead of returning a fake 201 with a client_id that was never persisted.

= 1.4.7 =
* New: `wp_get_plugin_settings` &mdash; returns all wp_options matching a plugin slug with sensitive keys ([REDACTED]). Lets AI read plugin config without seeing credentials.
* New: `wp_update_option` &mdash; gated by an admin toggle (off by default), the `royal_mcp_writable_options` filter, and a hard denylist for sensitive option names.
* Security: `wp_get_option` redacts sensitive keys; outbound HTTP timeouts reduced to 10s.
* Listing: Refreshed plugin directory banners and tags.

= 1.4.6 =
* New: `wp_upload_media_from_url` (SSRF-hardened), `wp_upload_media` (base64), `wp_set_featured_image`, `wp_update_media`.
* Enhancement: `wp_create_post` / `wp_update_post` accept `featured_media` attachment ID.
* Enhancement: API-key authenticated requests now run as administrator so capability checks succeed (matches the trust level of the admin-only-accessible key).

= 1.4.5 =
* New: WordPress Playground live preview — click "Live Preview" on the plugin listing to try the Royal MCP settings page and activity log in a browser sandbox with demo API key and sample log entries pre-seeded.
* New: Video walkthrough embedded on the plugin listing page.

= 1.4.4 =
* New: Custom post type support &mdash; `wp_get_posts` / `wp_create_post` accept `post_type`. New `wp_get_post_types` tool discovers all registered public post types.

= 1.4.3 =
* Security: Fixed broken access control on MCP REST API endpoints &mdash; all tool calls now require authenticated API key or OAuth Bearer; Origin header dropped as a security control. Reported by Alexis Lafontaine via Patchstack.

= 1.4.2 =
* Security: Authentication enforced on every MCP request (not just session init). Sessions bound to authenticated credentials. Auth required on GET stream and DELETE session endpoints too.

= 1.4.1 =
* Fix: Resolved fatal error during activation on WordPress 7.0 RC ("Class Token_Store not found") &mdash; fully-qualified namespace references for WP 7.0 compatibility.

= 1.4.0 =
* New: OAuth 2.0 authorization server &mdash; Claude Desktop's "Add Connector" works natively. Dynamic Client Registration (RFC 7591), PKCE-secured authorization code flow per MCP spec (2025-03-26), token refresh with rotation, WordPress login consent screen, discovery at `/.well-known/oauth-authorization-server`.
* Security: Access tokens stored as SHA-256 hashes. Authorization codes single-use with 10-minute expiry. PKCE (S256) required. Redirect URIs must be localhost or HTTPS.

= 1.3.0 =
* New: WooCommerce integration (9 tools), GuardPress integration (7 tools), SiteVault integration (6 tools). All auto-detected.
* Security: MCP endpoint requires API key (`X-Royal-MCP-API-Key` header). Rate limiting (60 req/min per IP). Timing-safe `hash_equals()` comparison. Removed `admin_email`, `php_version`, `user_login`, `user_email` from response payloads.

= 1.2.3 =
* Security: SSRF protection &mdash; outbound URLs validated against private/reserved IP ranges. Text domain renamed `wp-royal-mcp` &rarr; `royal-mcp`. Menu slugs updated for WP.org compliance. Tested up to WP 7.0.

= 1.2.2 =
* Added: Documentation link on the Plugins page; documentation banner on the settings page.

= 1.2.1 =
* Fixed: Claude Connector setup guide link displaying raw HTML.

= 1.2.0 =
* Security: Origin header validation against DNS rebinding. Session ID format validation. MCP 2025-03-26 Streamable HTTP spec compliance. Added `royal_mcp_allowed_origins` filter.

= 1.1.0 =
* Added multi-platform AI support (Claude, OpenAI, Gemini, Groq, Azure, Bedrock); Claude Desktop MCP connector; activity logging; connection testing.

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.4.33 =
Adds scheduling / backdating support to the four post + page write tools via a new `date` parameter, expands the create-tool status enum with `future`/`pending`/`private`, exposes a `royal_mcp_tool_called` action hook for ecosystem extensions, and adds a pointer on the Activity Log page to the free Royal AI Firewall plugin for HTTP-layer AI bot visibility.

= 1.4.32 =
Adds snippet excerpts to `wp_search` (token saver for multi-page audits) and pagination to `wc_get_orders` (stores beyond 100 orders are now reachable). Note: `wc_get_orders` response shape changes from a bare array to `{orders, page, per_page, total, total_pages}` &mdash; AI driver tooling that iterated the bare array will need to read `result.orders`.

= 1.4.31 =
Security hardening + AI-driver ergonomics. Closes two Subscriber-tier OAuth Bearer gaps and protects against AI drivers that template-fill empty-string text arguments. Every post-identifying tool now accepts either `id` or `post_id`. Settings page also gets a new wp.org review-request banner and minor Founders Bundle banner tweaks.

= 1.4.30 =
Adds `elementor_add_widget`, the first structural-write Elementor tool &mdash; agents can now build pages widget by widget, not just clone and customize. Eleven curated shortcuts (container, heading, text, button, image, image-box, icon-box, icon-list, video, divider, spacer) reduce token cost on common operations; raw passthrough handles the long tail and atomic widgets. Also closes a Subscriber-tier capability-ordering gap in six integration tool wrappers (GuardPress, SiteVault, ForgeCache, Royal Ledger, ACF, Royal Links) &mdash; unauthorized OAuth Bearer callers no longer learn which integrations are active on the site via the &ldquo;not active&rdquo; error path.

= 1.4.29 =
Urgent regression fix. On a subset of 1.4.27 installs the runtime DB migration could silently mark itself complete without actually creating the new sessions table, breaking OAuth registration and session persistence (the very symptoms 1.4.27 was supposed to fix). 1.4.29 restores the original retry semantic plus a one-time self-heal on the /register failure path so affected installs recover automatically on update. Also closes two adjacent recovery gaps surfaced by @rula99 on the wp.org forum &mdash; `maybe_upgrade_db()` now verifies tables physically exist before short-circuiting, and uninstall clears the version option so future reinstalls can&rsquo;t latch into the same stuck state. No customer action required; recommended for everyone on 1.4.27.

= 1.4.28 =
Compatibility + feature release. Adds Authorization-header API key support so MCP clients that send their key via the universal `Authorization: Bearer` header (Apify, n8n, Make.com, etc.) connect on first try. Extends `wp_get_seo_meta` and `wp_update_seo_meta` to cover the URL slug so AI agents can prepare the full SEO surface in one tool call. No customer action required; both changes are strictly additive.

= 1.4.27 =
Reliability patch &mdash; MCP session state moved off WordPress transients onto a dedicated table. Fixes "Session not found" errors on hosts with an active WordPress object cache drop-in. No customer action required; the new table is created automatically on update.

= 1.4.26 =
Security patch — per-tool WordPress capability checks across the OAuth tool surface. Pre-1.4.26, tokens issued to Subscriber/Contributor roles could invoke admin-only operations. The API-key path was unaffected. Reported by Alessandro Greco (Aleff). Recommended for all users.

= 1.4.25 =
Recommended update. Settings page UX pass: the MCP Server URL is now surfaced prominently in General Settings as the canonical URL for every MCP client (Claude.ai, ChatGPT, Claude Desktop, Cursor), instead of being tucked into a card labeled "FOR CLAUDE.AI" that hid it from non-Claude users. New in-product setup guides for Claude.ai, ChatGPT, Claude Desktop, and Cursor. "AI Platforms" section renamed and clarified as outbound-only configuration. Universal icon alignment fix across every button on the settings page, including the previously-invisible icon on the Add Provider button.

= 1.4.24 =
Recommended update. Adds Advanced Custom Fields integration (four `acf_*` tools that return ACF-formatted values instead of raw postmeta). Fixes `wc_create_product` ignoring the `type` argument and always creating simple products — the variable-product workflow end-to-end (create variable product -> create variations) was broken since the integration first shipped in 1.4.10. Adds setup-guide pointers in the wp.org listing and on the AI Platforms admin screen so new users can find the Connecting Claude walkthrough without having to discover the marketing site first.

= 1.4.23 =
Strongly recommended update. AI Platforms model dropdowns are now verified-current across Claude, OpenAI, Gemini, Groq, and AWS Bedrock — every retired or near-term-deprecating model is removed, current production models are added, and defaults are rotated to vendor-recommended replacements. Fixes Test Connection 404s and prevents runtime failures from picking models the vendor no longer serves. Verified against each vendor's official deprecation page on release day.

= 1.4.22 =
Strongly recommended update. Fixes AI Platforms → Test Connection on Claude (was returning 404 for every customer regardless of dropdown choice or API key validity), restores the ability to clear manually-configured OAuth Client ID/Secret through the UI, and widens OAuth root rewrite rules to also match trailing-slash variants so membership plugins can't hijack discovery requests. Adds two new self-check admin notices (host-side 301 on /register; membership plugin serving HTML on /.well-known/).

= 1.4.21 =
Recommended update for WordPress 7.0: Gutenberg blocks created or updated via `wp_create_page`, `wp_update_page`, `wp_create_post`, and `wp_update_post` no longer corrupt escape sequences (`\n`, `&`, backslashes) inside block JSON. Surfaced on WP 7.0's new per-block Custom CSS feature.

= 1.4.17 =
Critical fix where OAuth fails with "Authorization code invalid" — auth codes now use a dedicated DB table with atomic consume, unaffected by object-cache eviction (LiteSpeed + SpeedyCache reproducer). Also adds a Reset OAuth State button and Activity Log entries for MCP tool calls.

= 1.4.16 =
Recommended update: OAuth /token, /register, and /authorize failures now write to Royal MCP > Activity Logs with the exact error code, description, and HTTP status. Pre-1.4.16 these exited silently and required wp-config debug constants to diagnose. No breaking changes.

= 1.4.15 =
Critical update: four customer-affecting bugs fixed. (1) API key Regenerate button being silently overridden — clicking did nothing pre-1.4.15. (2) New keys switched to lowercase hex to eliminate uppercase/lowercase character ambiguity in monospace admin fonts. (3) Fixed 1-hour MCP session TTL replaced with sliding 24-hour window so active Claude Desktop sessions stop dying mid-day. (4) MCP endpoint responses (including unauth 401s) now send `Cache-Control: no-store` — pre-1.4.15 these were missing the header that 1.4.13 added to OAuth endpoints, leaving the MCP endpoint vulnerable to the same edge-cache poisoning. Existing keys keep working.

= 1.4.14 =
Recommended update: fixes Claude.ai web connector / ChatGPT MCP connector failing with "Couldn't reach the MCP server" — unauthenticated GET to the MCP endpoint now returns 401 + WWW-Authenticate so OAuth discovery (RFC 9728) starts correctly. Also adds an admin notice that detects when your host blocks `/.well-known/oauth-authorization-server` (SiteGround / o2switch / Hostinger nginx intercept) and links to the manual fix. Authenticated GET still returns 405 — Claude Desktop / mcp-remote unaffected. No breaking changes.

= 1.4.13 =
Recommended update: fixes OAuth endpoint cache poisoning that broke the Claude.ai web connector on hosts with aggressive edge caches. Adds 17 new WooCommerce tools — variable product and attribute management plus full coupon CRUD. No breaking changes.

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
