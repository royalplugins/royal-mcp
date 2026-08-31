=== Royal MCP – Secure AI Connector for Claude, ChatGPT & any LLM via MCP ===
Contributors: royalpluginsteam
Donate link: https://www.royalplugins.com
Tags: mcp, ai, claude, chatgpt, elementor
Requires at least: 5.8
Tested up to: 7.1
Stable tag: 1.4.45
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Preview-On-WordPress-Playground: yes

200+ MCP tools. OAuth 2.0. Connect Claude, ChatGPT, Gemini, Cursor & any MCP agent to your WordPress site. 100% self-hosted.

== Description ==

**The most complete WordPress MCP server — 200+ tools, OAuth 2.0, and nothing leaves your site.**

Royal MCP gives Claude, ChatGPT, Google Gemini, Perplexity, DeepSeek, Mistral, and every other MCP-compatible AI structured access to your WordPress site: 85 WordPress core tools plus 121 integration tools that auto-load for WooCommerce, Elementor, Divi, ACF, Yoast SEO, UpdraftPlus, WPForms, Solid Security, Contact Form 7, MonsterInsights, W3 Total Cache, Duplicator, BuddyPress, and more.

= Connect Claude to WordPress =

Royal MCP connects your WordPress site directly to Claude Code, Claude Desktop, or Claude.ai. Setup takes a minute or less: install the connector, authorize, and start your chat.

https://youtu.be/pf-mdRnXezM

**Edit Elementor with Claude**

https://youtu.be/HsEIoDz9WmY

**First-time setup walkthrough:** [royalplugins.com/support/royal-mcp/connecting-to-claude/](https://royalplugins.com/support/royal-mcp/connecting-to-claude/)

= Connect ChatGPT to WordPress =

ChatGPT on the web, desktop, and iOS supports MCP servers natively. Add Royal MCP in ChatGPT's Plugins panel, authorize once, and ChatGPT can read your posts, publish drafts, update product prices, moderate comments, and audit SEO across your site — all through ordinary conversation.

= Works with every MCP-compatible AI client =

Royal MCP is not vendor-locked. Claude/Anthropic, ChatGPT/OpenAI, Gemini/Google, Grok/xAI, Llama/Meta, Mistral, DeepSeek, Qwen/Alibaba, Cohere, and Perplexity all work through MCP-compatible clients like Cursor, Windsurf, Cline, Continue, Zed, JetBrains AI Assistant, OpenCode, Warp, Ollama, and LM Studio, all connecting through the same endpoint. Switch AI vendors without rewriting a single connection.

= How Royal MCP handles authorization =

Royal MCP speaks full OAuth 2.0 with PKCE and Dynamic Client Registration (RFC 7591) for Claude Desktop, Claude Code, ChatGPT web, and every modern MCP client. Sessions expire, refresh automatically, and can be revoked globally with one button in wp-admin. Clients that don't speak OAuth get timing-safe API-key auth, per-IP rate limits (60 requests per minute), and the same activity log for every tool call.

= Where do my credentials go? =

Nowhere. Your AI client authenticates straight to your WordPress site, and every API key, OAuth token, session, and audit-log entry stays inside your own database. There's no hosted server sitting between your chat and your site, and no license check or telemetry reaching out on activation. Ollama and LM Studio are first-class platforms alongside Claude, ChatGPT, and Gemini if you want to keep AI inference local too.

= Can I undo what the AI does? =

Yes. Every MCP client (Claude Desktop, ChatGPT, and the rest) asks you to approve or deny each destructive tool call by default, until you flip that setting in your connector. On top of that, Royal MCP captures a reverse-state snapshot before every destructive write and hands back a 72-hour undo token. One mcp_undo_last_operation call reverses the change — whether Claude deleted a post, replaced text on an Elementor page, updated a WooCommerce product, or reordered menu items. New posts and pages start as drafts, so nothing the AI writes appears on your live site until you approve publishing. Every tool call also lands in an activity log you can review from wp-admin.

= Does Royal MCP work with the WordPress Abilities API? =

Yes. Royal MCP surfaces every AI-callable operation through one endpoint, from three sources: the 85 native tools Royal MCP ships, the 121 integration tools that auto-load when WooCommerce, Elementor, Divi, ACF, Yoast SEO, UpdraftPlus, WPForms, Solid Security, Contact Form 7, MonsterInsights, W3 Total Cache, Duplicator, BuddyPress, and other supported plugins activate, and every ability any plugin registers through WordPress 6.9's Abilities API. Your AI sees them all as MCP tools — one connector, no per-plugin setup, no per-vendor rewrite.

= See what AI agents do to your site =

The free [Royal AI Firewall](https://wordpress.org/plugins/royal-ai-firewall/) companion shows every AI agent hitting your site at the HTTP layer (training crawlers, retrieval bots, AI search engines), not just the ones connected through Royal MCP. Install both for a unified view across MCP tool calls and HTTP-layer bot hits.

= 85 Core Tools + 121 Integration Tools =

**WordPress Core (85 tools):**

* Posts - create, read, update, delete, search, count (any registered public post type, featured images supported)
* Pages - full CRUD with parent page support
* Post Types - discover all registered public post types on the site
* Post Revisions - list revision history and roll a post back to any prior version
* Media - browse, upload from URL or base64, update alt text/caption/title/description, set as featured image, delete
* Comments - create, read, delete; full moderation suite (list pending, approve, mark spam, trash)
* Users - display names and roles (emails and usernames are not exposed)
* Categories, Tags & Custom Taxonomies - full CRUD, assign, count, discover registered taxonomies
* Term Meta - read/update/delete (most useful for term-level SEO fields on categories and tags)
* Menus - create menus, list, and full CRUD + reorder on menu items
* Publish and Promote - publish a draft and insert into a menu in one call
* Preview Links - generate a shareable draft-preview URL without a WP login
* Post Meta - read/update/delete custom fields (works with ACF, MetaBox, JetEngine, Pods, CPT UI)
* SEO Meta - read/write Yoast, Rank Math, AIOSEO, or SEObolt title/description/focus keyword/robots/OG (auto-detects active plugin)
* SEO Audit - fetch a post's rendered HTML and report title, meta description, canonical, viewport, OG, Twitter Card
* Widgets - list, list sidebars, update content (writes gated by theme-appearance admin toggle)
* Find and Replace - literal replace with dry-run preview, expected-count safety, read-after-write verification
* Undo - reverse the previous delete or reorder within 72 hours via mcp_undo_last_operation
* Site Info + Site Status - name, WP/PHP versions, active theme + plugins, cron activity — for AI-driven pre-write validation
* Error Log + Cron Schedule - read recent PHP errors and scheduled WP cron events
* Connection Health - MCP session diagnostic (route, auth method, session ID, plugin version)
* Plugins & Themes - list installed with active status
* Theme Appearance - get theme, read/write theme mods (gated), read/write Custom CSS
* Search + Permalink Structure - full-text content search; read/write permalinks (gated)
* Options - read allowlisted core options, read plugin settings (sensitive keys redacted), write to allowlisted options when enabled

= Plugin Integrations (Conditional) =

Royal MCP automatically detects compatible plugins and adds specialized MCP tools. No configuration needed — if the plugin is active, the tools appear.

**WooCommerce Integration (29 tools):**
When WooCommerce is active, AI agents can manage your store end-to-end:

* Products — browse, search, create/update simple + variable products with prices, SKUs, stock levels
* Variations — list, get, create, update, delete, batch-update
* Global attributes (`pa_*`) — list attributes and terms, register new, assign to products as variation axes
* Coupons — full CRUD plus bulk-trash purge, with all standard WC fields (discount type, expiry, usage limits, product/category restrictions, email allowlists)
* Orders — view, create, update status, attach order notes (B2B, wholesale, phone-order workflows)
* Customers + store statistics — order counts, revenue, average order value by period

**Elementor Integration (11 tools):**
When Elementor (free or Pro) is active, AI agents can clone and customize existing pages without trying to generate page-builder JSON from scratch:

* Clone a page with fresh element IDs so the duplicate opens in the editor without collisions
* Bulk-replace text across heading, text-editor, button, image-box, icon-box, testimonial, tabs, accordion, and 6+ other widget types (plus image alt/title on gallery widgets)
* Swap image URLs across image, image-box, background, and gallery widget settings
* Get a compact page outline (element IDs, widget types, text snippets) — a few KB instead of raw JSON
* Read full settings for a single widget/container by ID for precise agent editing
* Import templates from JSON, list saved templates, insert templates at any position on any page
* Rebuild post_content from Elementor data on pages with damaged core content (restores WP search visibility)
* Every write returns a 72-hour undo token. Atomic widgets (V4) pass through opaque — widget-level creation from scratch is intentionally out of scope

**Divi Integration (9 tools):**
When Divi (Divi 4 shortcode format or Divi 5 block format) is active, AI agents get safety-first tools for reading and editing Divi content without corrupting builder state:

* Detect a page's Divi format from postmeta — aged pages read correctly even after theme updates
* Get a compact page outline (section/row/module hierarchy with text snippets) — a few KB instead of raw markup
* Validate a Divi shortcode or block string before writing to catch malformed structure
* List Divi Library layouts (with category filter) and read a full layout by ID
* Find-and-replace text with dry-run preview, expected-count safety check, active-editor-session detection
* Clone a Divi 4 or Divi 5 page as a new draft, regenerating D5 clientIds so the duplicate opens without collisions (72-hour undo token)
* Swap an image URL across every image-bearing Divi element on a post, dual-format aware (72-hour undo token)
* Apply a Divi Library entry to a target page in merge or replace mode (72-hour undo token)

**Advanced Custom Fields Integration (4 tools):**
When ACF (free or Pro) is active, AI agents can read and write ACF fields with the field-type-aware formatting the ACF UI uses — instead of the raw serialized values WordPress meta returns:

* Read a single ACF field, formatted per its Return Format setting (hydrated post objects, parsed repeater rows, image arrays, etc.)
* Read every ACF field on a post in one call, with name/label/type/value bundled — the most efficient way for an AI to discover what fields exist and read them all
* Update an ACF field with type-aware value handling (scalar for text/number, array for repeaters and flex content, post ID for relationships, attachment ID for images)
* Enumerate ACF field groups on the site, optionally filtered by post type — for AI-driven discovery of available custom fields before reading/writing

**Redirection Integration (4 tools):**
When John Godley's Redirection plugin is active, AI agents can manage 301 / 302 / 307 redirects:

* List redirects with group + URL-substring filters
* Create new redirects (source, target, status code, regex, group, title)
* Update existing redirects (target, status, enabled state)
* List redirect groups

**Also auto-detected:**

* **Yoast SEO Integration (5 tools)** — read/write Yoast meta (raw + resolved), capture JSON-LD schema graph, list indexed internal links, list Premium redirects
* **UpdraftPlus Integration (4 tools)** — list backups, read status, trigger async backups with entity filtering, read schedule
* **WPForms Integration (4 tools)** — list forms, read form schema, (Pro) list submissions, (Pro) read single submission
* **Solid Security Integration (4 tools)** — read security status, list currently locked-out IPs, read the security event log, add an IP to the ban list
* **Contact Form 7 Integration (3 tools)** — list forms, read a single form's parsed field schema, list submissions (via Flamingo add-on)
* **MonsterInsights Integration (4 tools)** — read the analytics overview, top pages, traffic sources, and top Search Console queries
* **W3 Total Cache Integration (3 tools)** — read cache config across every module, purge cache (all / by URL / by post), read usage statistics
* **Duplicator Integration (3 tools)** — list migration packages, read per-package status, get the installer URL for a completed package
* **BuddyPress Integration (4 tools)** — list community members, read a single member profile, list groups, read the activity feed

* **Royal AI Firewall Integration (6 tools)** — review AI bot traffic, dashboard stats, and set allow/block/challenge policies per bot signature
* **GuardPress Integration (7 tools)** — review your security score, run vulnerability scans, inspect failed logins and blocked IPs
* **SiteVault Integration (6 tools)** — list backups, trigger new ones, check progress, review schedules
* **ForgeCache Integration (3 tools)** — purge and inspect your page cache
* **Royal Ledger Integration (4 tools)** — review recurring software costs and masked license entries
* **Royal Links Integration (3 tools)** — manage branded short links and click stats

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
* **Page builders** - Elementor and Divi have dedicated tools for safe clone-and-customize workflows (Elementor: clone a page, find/replace text, swap images, get an outline, import templates; Divi: format detection, layout validation, page outline, library read, find/replace with builder-format awareness) - see the Tools list. Widget-level creation from scratch is intentionally out of scope. Beaver Builder, Bricks, Gutenberg, Spectra, and Stackable store standard post content that is readable and writable by AI; page-builder-specific JSON storage is opaque unless covered by a dedicated tool.
* **Multilingual** - WPML, Polylang, TranslatePress: translated posts appear as separate posts and can be read/written via the standard post tools.
* **AI agent frameworks** - Any MCP-compatible framework (LangChain, AutoGen, CrewAI, LlamaIndex, Haystack, etc.).

= MCP Spec Compliance =

Royal MCP implements the [MCP 2025-11-25 Streamable HTTP transport specification](https://modelcontextprotocol.io/specification/2025-11-25/basic/transports#streamable-http):

* Single `/mcp` endpoint for all JSON-RPC communication
* POST for client messages, GET for server-sent events, DELETE for session termination
* Cryptographically secure session IDs with transient-based storage
* Origin header validation to prevent DNS rebinding attacks
* Proper CORS handling for browser-based MCP clients

== External Services ==

Royal MCP makes no outbound calls of its own — no telemetry, no license check, no update ping. The MCP protocol is inbound: your AI client authenticates and calls your site, and any data the AI sees is sent under whatever terms you've agreed to with that AI provider.

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

Security. Most MCP plugins (and 41% of all public MCP servers) have no authentication at all. Royal MCP requires an API key for every session, rate-limits requests to prevent abuse, logs every interaction for audit purposes, and filters sensitive data (emails, PHP version, admin credentials) from responses. We built this plugin with the same security standards we apply to GuardPress, our WordPress security plugin used on thousands of sites.

= Does Royal MCP duplicate what WordPress core now does? =

No. WordPress 6.9 added the Abilities API (a primitive for registering AI-callable functions), and the `wordpress/mcp-adapter` package bridges abilities to the MCP protocol. Royal MCP is a full MCP server with the security layer, connector flows, and plugin integrations the bare primitive does not include: enforced API key auth, OAuth 2.0 for Claude Desktop, per-IP rate limiting, audit logging, sensitive-data redaction, 85 ready-to-use WordPress core tools, and 121 integration tools for WooCommerce, GuardPress, Royal AI Firewall, SiteVault, ForgeCache, Royal Ledger, Royal Links, Elementor, Divi, ACF, Yoast SEO, UpdraftPlus, WPForms, Redirection, Solid Security, Contact Form 7, MonsterInsights, W3 Total Cache, Duplicator, and BuddyPress.

= Does Royal MCP work with WooCommerce? =

Yes. When WooCommerce is active, Royal MCP automatically adds 29 MCP tools spanning product management (simple and variable, including variation CRUD and global attribute management), full coupon management (list/get/create/update/delete + bulk trash purge), order management (view, create, update, add notes, update status), customer data, and store statistics. No additional configuration is needed — the tools appear automatically in the MCP tools list.

= Can AI assistants configure my plugins for me? =

Yes, with safety controls. Royal MCP exposes two tools for plugin configuration:

* `wp_get_plugin_settings` lets AI read any plugin's stored settings by slug. Sensitive values (API keys, secrets, tokens, passwords, license keys, OAuth credentials) are automatically replaced with `[REDACTED]` before they leave your server, so AI assistants can understand a plugin's configuration without ever seeing stored credentials.

* `wp_update_option` lets AI write to WordPress options, but only after passing three security gates:
    1. The site admin must enable the "Allow AI to write WordPress options" toggle on the Royal MCP settings page (off by default)
    2. The option name must be in a runtime allowlist. The default allowlist is intentionally tiny — `blogname`, `blogdescription`, `posts_per_page`, `date_format`, `time_format`, `show_on_front`, `page_on_front`. Plugin authors opt their own settings in via the `royal_mcp_writable_options` filter.
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

= 1.4.45 =
* Fix: Plugin constant definitions no longer emit PHP notices when Royal MCP is loaded alongside a wrapper plugin that pre-defines the same constants.
* New: Solid Security integration adds four tools — read the security status summary, list currently locked-out IPs, read the security event log, and add an IP to the ban list.
* New: Contact Form 7 integration adds three tools — list forms, read a single form's parsed field schema, and list submissions when the Flamingo add-on is active.
* New: MonsterInsights integration adds four tools — read the analytics overview, top pages, traffic sources, and top Google Search Console queries.
* New: W3 Total Cache integration adds three tools — read cache configuration across every module, purge cache (all, by URL, or by post), and read usage statistics.
* New: Duplicator integration adds three tools — list migration packages, read per-package status, and get the installer URL for a completed package.
* New: BuddyPress integration adds four tools — list community members, read a single member profile, list groups, and read the activity feed.
* New: MCP server identity now advertises the WordPress Site Icon so connector cards show per-site branding.
* Enhancement: WooCommerce coupon and product read responses now include ISO 8601 date variants and additional catalog and subscription fields.
* Enhancement: Help page CSS and admin links now work correctly when the plugin is vendored inside a wrapper plugin.
* Fix: WooCommerce order tools now register correctly with ChatGPT.

= 1.4.44.1 =
* Fix: WooCommerce order tools now register correctly with ChatGPT.

= 1.4.44 =
* New: Yoast SEO integration adds five tools — read the full Yoast meta surface, capture the JSON-LD schema graph, list indexed internal links, list Premium redirects, and update Yoast title/description/focus keyword with a 72-hour undo token.
* New: UpdraftPlus integration adds four tools — list local backup history, read per-backup status, trigger a new backup, and read the current backup schedule.
* New: WPForms integration adds four tools — list forms, read a single form's field schema, and (Premium) list submissions plus read a single submission.
* Fix: Envelope helper's full-JSON-mirror path no longer throws on non-array input during defensive test paths.
* Fix: Post meta, SEO meta, Yoast meta, and featured image edits made through Royal MCP tools now trigger the standard WordPress post-save event so page-cache plugins and SEO indexable rebuilders pick up the change automatically.
* Fix: Divi tools now return a clean not-active error on sites where Divi is not installed.
* Fix: divi_validate_layout accepts raw_content on sites without Divi installed so cross-site validation workflows work without the plugin being present.
* Fix: Meta writes now bump the post modification timestamp so sitemap regenerators, feed cache invalidators, and staleness detectors keyed on post_modified fire correctly.
* Fix: wp_get_post, wp_get_page, wp_get_posts, and wp_get_pages return featured_media (attachment ID) and featured_media_url in the response envelope.
* Fix: wp_set_featured_image persists the alt_text argument on both the media_id and image_url input paths.
* Fix: mcp_undo_last_operation documentation names yoast_update_meta in the supported-tools list.
* Fix: Tier-gated tools return a uniform {state: "unavailable", reason, tier, message} envelope so AI callers cannot misread a tier gate as an empty query result.
* Fix: yoast_get_meta returns Open Graph, Twitter, canonical, and breadcrumb fields as both the stored template and the resolved value Yoast renders (featured image fallback, permalink fallback, post title fallback).
* Fix: yoast_get_meta surfaces analysis_pending so callers can distinguish a post that has never been scored from a post with a real low content_score or linkdex.
* Fix: yoast_get_schema removes empty author references from the JSON-LD graph so posts with unresolvable authors no longer produce invalid Article markup.
* Fix: yoast_get_internal_links returns link_index_status (indexed, index_pending, no_links) plus a content_anchor_count cross-check so callers can distinguish a post with no links from a post the link index has not processed yet.
* Fix: updraftplus_trigger_backup dispatches asynchronously via WP-Cron and honors the entities argument (a caller requesting entities:["plugins"] gets a plugins-only backup).
* Fix: fc_purge_url purges the homepage, category and tag archives, paginated pages, and other URLs that do not resolve to a single post or page.
* Fix: WPForms form schema returns choices as an indexed list with label/value/key, required as a boolean, timestamps as ISO 8601 strings alongside epoch integers, and form listing paginates deterministically on same-second creations.
* New: In-plugin Help page with per-client setup guides (Claude Desktop, claude.ai Web, Claude Code CLI, ChatGPT, Cursor, VS Code, Continue), a three-rung quick-fix troubleshooting ladder, and a copy-ready diagnostic-info block for support requests.
* New: Newsletter signup link in the Royal MCP admin header and Support tab.
* Enhancement: Cross-sell notice bar above every Royal MCP admin page.
* Enhancement: Every integration's tools now appear in the MCP tool list on connect (WooCommerce, Elementor, Divi, ACF, Yoast, UpdraftPlus, WPForms, SiteVault, ForgeCache, GuardPress, Royal AI Firewall, Royal Ledger, Royal Links, Redirection) so activating a supported plugin no longer requires reconnecting the AI client, and every tool schema now passes strict-validator MCP clients (VS Code, ChatGPT strict mode) after fixing meta-update, WooCommerce order, Elementor widget, ACF field, option, theme-mod, and widget-update surfaces.

= 1.4.43 =
* New: Divi 4 and Divi 5 page tools in Free — clone a page, swap images, and import a library template, all with 72-hour undo tokens.
* New: Elementor library-to-page bridge (`elementor_apply_template_to_page`) plus tools to repair pages where the Elementor render is missing (single and bulk).
* New: SiteVault backup tools now work with the free SiteVault plugin from WordPress.org in addition to SiteVault Pro.
* Enhancement: Every destructive write emits a 72-hour undo token in the response text; responses now surface actionable URLs, IDs, and counts.
* Enhancement: `elementor_replace_text` adds dry-run preview, expected-count guard, image alt/title walking, and optional media-library sync.
* Fix: Tool schemas conform to strict JSON Schema so VS Code and ChatGPT Plugins accept the tools at load time.
* Fix: `elementor_clone_page` populates page content so cloned pages stay visible to WordPress core search.
* Fix: OAuth discovery adds a filter to override the metadata URL for hosts intercepting `.well-known/`.

= 1.4.42 =
* Enhancement: Write-verification helper now detects pre-write input mangling for tools that require strict input fidelity.
* Enhancement: Tool argument sanitizers now preserve %XX escape sequences so URLs, patterns, and templated inputs survive round-trip.
* Fix: wp_update_permalink_structure preserves permalink tokens like %category% and %author% when writing the structure.
* Fix: Hardens redaction of sensitive data across plugin/option/meta read tools and debug log responses.

= 1.4.41 =
* New: Six Divi builder tools for safer AI-driven Divi editing (get page format, outline, validate layout, list local templates, library get, replace text).
* New: wp_publish_and_promote publishes a draft and inserts it into a menu in one call.
* New: wp_create_menu creates a WordPress navigation menu with optional theme-location assignment.
* New: wp_create_preview_link generates a shareable draft-preview URL for one preview session.
* Enhancement: wp_get_post_revisions returns per-revision content_length for quick change-size scanning.
* Enhancement: Destructive write tools now warn when literal escape sequences appear in replacement text, so operators catch double-escaping before it lands.
* Enhancement: OAuth discovery self-check now detects Sucuri / CloudProxy edge blocks and links to a Sucuri-specific fix guide.
* Enhancement: A "Re-check now" button on host-blocked admin notices re-probes OAuth discovery in-place during troubleshooting.
* Enhancement: OAuth discovery cache shortens automatically on WAF-fronted sites for faster feedback after configuration changes.

= 1.4.40 =
* New: Every write tool re-reads modified fields after the write and returns the actual saved values.
* New: Undo tokens now cover wp_delete_post, wp_delete_term, and wp_delete_menu_item, restoring deleted objects within 72 hours via mcp_undo_last_operation.
* New: wp_update_post preserves the WooCommerce product_type when generic post updates would otherwise reset it to Simple.
* New: wc_get_order returns fee_lines, shipping_lines, and the raw payment method ID for full write-verification.
* New: wp_get_seo_meta and wp_update_seo_meta now support AIOSEO and SEObolt alongside Yoast and Rank Math.
* New: wp_get_widgets returns widget instance content and parsed block markup for before/after diffs.
* New: OAuth endpoint paths (authorize/token/register) are filterable via royal_mcp_oauth_rewrite_paths.
* New: Admin notice detects when a published page shadows an OAuth endpoint.
* Enhancement: wp_get_posts + wp_count_posts accept non-public post types when the caller has edit capability.
* Enhancement: Option writes require opt-in to the read allowlist first; admin_email + default_role + mailserver_* permanently denylisted.
* Enhancement: JSON-RPC envelope hardening re-forces "2.0" against edge-layer transformations.
* Enhancement: Royal MCP Pro promotion refreshed for post-launch with a green Upgrade to Pro link in the sidebar.
* Fix: elementor_replace_text is now multi-byte and case-insensitive.
* Fix: Uninstalling the free plugin no longer removes data used by Royal MCP Pro when Pro is installed on the same site.
* Fix: seo_audit_meta_tags now counts characters instead of bytes so titles and descriptions containing non-ASCII content are measured correctly.

= 1.4.39 =
* New: Free plugin refuses activation when Royal MCP Pro is already active.
* New: OAuth session-length setting lets site owners choose how long AI sessions stay connected before requiring re-authorization.
* New: Revoke all active AI sessions from Settings → OAuth.
* New: wp_replace_in_post and wp_replace_in_page tools for literal find/replace inside post content with dry-run, expected-count safety, and read-after-write verification.
* New: Settings-page section to allowlist third-party plugin options for `wp_update_option` without writing filter code.
* New: Widget tools — list widget instances (optionally filtered by sidebar), list sidebars, and update widget content; writes gated by the theme-appearance admin toggle.
* New: wp_reorder_menu_items now returns an undo token that mcp_undo_last_operation can consume within 72 hours to restore the prior order.
* New: mcp_undo_last_operation tool consumes an undo token and reverses the operation that generated it.
* New: seo_audit_meta_tags fetches a post's actual rendered HTML and reports title, meta description, canonical, viewport, Open Graph and Twitter Card tags — catches conflicts that only appear in served output.
* New: WooCommerce order write tools — wc_create_order, wc_update_order, wc_add_order_note — for B2B, wholesale, phone orders, and support-note trails.
* Fix: `elementor_replace_text` now walks Blockquote widget fields (author name and quote content).
* Enhancement: OAuth sessions now default to 24 hours.
* Enhancement: Admin auto-detects BitNinja WebShield interference with OAuth discovery and shows targeted host-support instructions.
* Enhancement: Additional /authorize, /token, and /register diagnostics in Activity Log.
* Enhancement: Readme highlights Royal AI Firewall as the companion plugin for HTTP-layer AI bot visibility alongside Royal MCP.

= 1.4.38 =
* Feature: All tools register as WordPress abilities on WP 6.9+, accessible via the WP Abilities REST API and the WordPress MCP Adapter alongside the native MCP endpoint.
* Feature: Redirection plugin integration adds four tools for listing, creating, updating redirects and listing groups.
* Feature: Google Site Kit GA4 configuration accessible to AI agents with sensitive keys redacted.
* Feature: `royal_mcp_connection_health` tool now returns active page-builder versions (Divi, Elementor, WordPress core) so agents can plan multi-step operations without a probe call.
* Feature: New `.mcpb` bundle for one-click Claude Desktop install.
* Feature: Founding Members waitlist notification for the upcoming Royal MCP Pro release.

= 1.4.37 =
* Feature: Six new Royal AI Firewall tools return dashboard stats, recent bot hits, per-bot policies, policy updates, daily rollups, and a block-all-AI-bots action.
* Feature: New Royal Tools admin page lists every free Royal Plugins family member with one-click install links.
* Feature: New `royal_mcp_connection_health` diagnostic tool returns route, auth method, session ID, and version details for any authenticated caller.
* Feature: New `elementor_get_widget_settings` tool reads full settings for a single Elementor widget, container, section, or column by ID.
* Feature: Coexistence detection surfaces a routing hint on Elementor tool descriptions when Elementor's own MCP module is also active.
* Feature: Custom top header bar renders on every Royal MCP admin page with View Docs and Support buttons.
* Enhancement: Lightweight admin footer shows Royal Plugins family links plus the current plugin version marker.
* Enhancement: `wp_update_post` and `wp_update_page` now accept `menu_order`, `post_parent`, `password`, `comment_status`, `ping_status`, `excerpt`, and `post_author` fields and return actual stored values so silent-drop by WordPress is surfaced rather than hidden.
* Enhancement: New `royal_mcp_tool_context` hook fires alongside the legacy `royal_mcp_tool_called` action with an enriched payload for downstream firewall integrations.
* Fix: `wp_get_seo_meta` now correctly reports noindex state on Rank Math sites, and `wp_update_seo_meta` responses reflect actual stored values rather than requested values.

= 1.4.36 =
* Feature: New `wp_get_site_status` tool returns WordPress, PHP, MySQL, plugin, theme, and environment info in a single call.
* Feature: New `wp_get_error_log_tail` tool returns the last lines of the debug log with optional keyword filtering.
* Feature: New `wp_get_cron_schedule` tool lists every scheduled event with next-run time and overdue flag.
* Fix: HTML preserved in post meta, term meta, term descriptions, comments, order notes, post and page excerpts, and coupon descriptions.
* Feature: New `royal_mcp_meta_value_sanitizer` filter lets sites customize per-key sanitization.
* Feature: Admin notice detects when Imunify360 is intercepting the OAuth discovery endpoints and links to a fix article.
* Feature: Admin notice detects when the site uses Plain permalinks and links directly to Settings -> Permalinks.
* Enhancement: Refined descriptions across several read tools.
* Enhancement: List responses on `wp_get_terms` and `wc_get_orders` include a `total_count` field.

= 1.4.35 =
* Fix: OAuth-authenticated MCP sessions remain valid across access-token rotation.

= 1.4.34 =
* Feature: `wp_update_post_meta` accepts any JSON type (string, number, boolean, array, object).
* Feature: New `wp_add_post_meta` tool adds a meta row without overwriting existing values under the same key.
* Feature: New `wp_get_terms` tool lists terms in any registered taxonomy with pagination.
* Security: Meta-write tools reject strings that look like PHP-serialized payloads at the schema boundary.

= 1.4.33 =
* Feature: `wp_create_post`, `wp_update_post`, `wp_create_page`, and `wp_update_page` accept a `date` parameter for scheduling and backdating.
* Feature: Create-post and create-page status enum expanded to include `future`, `pending`, and `private`.
* Feature: New `royal_mcp_tool_called` action hook fires after every MCP tool invocation with `(tool_name, status, error_message)`.
* Feature: Activity Log page surfaces a pointer to the free Royal AI Firewall plugin for HTTP-layer AI bot visibility.

= 1.4.32 =
* Feature: `wp_search` accepts optional `snippet` and `per_page` parameters for excerpted results.
* Feature: `wc_get_orders` accepts a `page` parameter and returns `{orders, page, per_page, total, total_pages}`.
* Docs: General readme cleanup and updates.

= 1.4.31 =
* Hardening: `wp_delete_post` capability check runs before the post-existence lookup.
* Hardening: `wp_get_post_meta` requires `edit_post` capability for protected (underscore-prefixed) meta keys.
* Hardening: Empty-string text fields on update tools preserve the existing value instead of blanking it.
* Enhancement: Every post-identifying tool accepts either `id` or `post_id`.
* UX: New wp.org review-request banner on the settings page, dismissable per plugin version.

= 1.4.30 =
* Feature: New `elementor_add_widget` tool builds Elementor pages widget by widget, with curated shortcuts for the 11 most common widget types and raw passthrough for the long tail.
* Hardening: `elementor_add_widget` rejects unknown widget-type slugs at the boundary.
* Hardening: Capability check order tightened in six integration tool wrappers.

= 1.4.29 =
* Fix: Runtime DB migration reliably creates the sessions and OAuth tables on all installs.
* Fix: `/register` self-heals when the OAuth tables are missing.
* Fix: `uninstall.php` also removes the `royal_mcp_db_version` option for a fully clean slate on reinstall.

= 1.4.28 =
* Feature: MCP clients can send their API key via the standard `Authorization: Bearer` header in addition to the existing `X-Royal-MCP-API-Key` header.
* Feature: `wp_get_seo_meta` and `wp_update_seo_meta` cover the post URL slug alongside the existing SEO fields.

= 1.4.27 =
* Reliability: MCP session state moved onto a dedicated `wp_royal_mcp_sessions` table.
* Cleanup: Removed unused admin-AJAX handlers.
* Compliance: Reworded a section of the plugin description.

= 1.4.26 =
* Security: Per-tool WordPress capability checks added across all content, user, term, comment, and integration tools, with status filters converted to positive allowlists.

= 1.4.25 =
* UX: MCP Server URL surfaced at the top of General Settings as the canonical inbound URL for every client.
* Feature: New in-product MCP Client Setup Guides accordion covering Claude.ai, ChatGPT, Claude Desktop, and Cursor.
* UX: Outbound AI Provider Configuration renamed and separated from inbound MCP setup.
* UX: Legacy REST API Base URL and manual OAuth Client ID / Secret moved into an Advanced subsection.
* Enhancement: Universal admin icon alignment, keyboard focus rings on settings-page buttons, and improved helper-text contrast.

= 1.4.24 =
* Feature: Advanced Custom Fields integration -- 4 tools (`acf_get_field`, `acf_get_fields`, `acf_update_field`, `acf_get_field_groups`).
* Fix: `wc_create_product` respects the `type` argument and creates the matching WooCommerce product class (Simple, Variable, Grouped, External).
* Docs: Description and Installation sections point to the first-time setup walkthrough.

= 1.4.23 =
* Fix: AI Platforms model dropdowns refreshed across Claude, OpenAI, Gemini, Groq, and Bedrock.

= 1.4.22 =
* Fix: AI Platforms Test Connection on Claude uses the model selected in the dropdown.
* Fix: Manually-configured OAuth Client ID and Secret can be cleared through the UI.
* Hardening: OAuth root rewrite rules match both bare and trailing-slash variants.
* Feature: Admin notice detects host-side 301 redirects on POST `/register`.
* Feature: `.well-known/` self-check detects when a plugin or theme intercepts the discovery endpoint with an HTML page.

= 1.4.21 =
* Fix: Gutenberg block content round-trips byte-for-byte through the post-write tools.

= 1.4.20 =
* Fix: WooCommerce order tools handle refund records without hanging.

= 1.4.19 =
* Feature: Six Elementor clone-and-customize tools (`elementor_clone_page`, `elementor_replace_text`, `elementor_replace_image`, `elementor_get_page_outline`, `elementor_list_local_templates`, `elementor_import_template`).
* Feature: Admin notice detects stale static `.well-known/oauth-authorization-server` files.
* Docs: Elementor handling described explicitly in the page-builder section.

= 1.4.18 =
* Fix: Authenticated GET on the MCP endpoint is User-Agent-aware for the Anthropic session probe.
* Fix: `wp_update_menu_item` and `wp_reorder_menu_items` preserve fields that were not included in the update.
* Docs: New FAQ entries for DB-restore recovery, OAuth endpoint locations, and troubleshooting.

= 1.4.17 =
* Fix: Authorization codes moved onto a dedicated `wp_royal_mcp_oauth_auth_codes` table with atomic single-row consume.
* Feature: New "Reset OAuth State" admin button clears registered clients, tokens, and pending auth codes in one click.
* Feature: MCP `tools/call` requests write a structured Activity Log entry on every invocation (argument keys logged, values excluded).
* Fix: Activity Log "View Details" modal renders Request/Response JSON.
* Enhancement: Plugin admin CSS/JS use content-hash cache-busting.

= 1.4.16 =
* Feature: OAuth flow writes structured Activity Log entries on every `/token`, `/register`, or `/authorize` failure (auth codes, PKCE verifiers, secrets, and tokens are excluded).

= 1.4.15 =
* Fix: Regenerate API Key button reliably issues a new key.
* Enhancement: New API keys are 32-char lowercase hex to eliminate visual-ambiguity transcription errors; existing keys keep working.
* Enhancement: MCP sessions use a sliding 24-hour TTL with refresh-on-access.
* Hardening: All `/wp-json/royal-mcp/*` responses send `Cache-Control: no-store, no-cache, must-revalidate, private`.
* Hardening: Invalid API key returns HTTP 401 with `WWW-Authenticate: Bearer` per RFC 7235.

= 1.4.14 =
* Fix: Unauthenticated GET on the MCP endpoint returns HTTP 401 with `WWW-Authenticate: Bearer resource_metadata="..."` per RFC 9728 so web-based MCP clients trigger OAuth discovery correctly.
* Feature: Self-check detects when the host blocks `/.well-known/oauth-authorization-server` and surfaces a dismissible admin notice with the manual fix.

= 1.4.13 =
* Hardening: OAuth endpoint responses (`/register`, `/token`, `/authorize`) send `Cache-Control: no-store` by default.
* Feature: 10 WooCommerce variation and attribute tools covering CRUD, batch operations, and attribute-term management.
* Feature: 7 WooCommerce coupon management tools with full CRUD plus trash and purge.

= 1.4.12 =
* Enhancement: MCP `protocolVersion` bumped to `2025-11-25` to match current Claude Desktop builds.
* Fix: MCP GET stream returns HTTP 405 with `Allow: POST, DELETE, OPTIONS`.
* Enhancement: `wp_get_taxonomies` returns a `slug` field alias; `wp_get_term_meta` returns a structured response matching the rest of the term-meta family.

= 1.4.11 =
* Feature: New tools -- `wp_update_term`, `wp_get_term_meta`, `wp_update_term_meta`, `wp_delete_term_meta`, `wp_get_taxonomies`.
* Enhancement: `wp_create_term`, `wp_delete_term`, and `wp_add_post_terms` accept any registered taxonomy.
* Enhancement: `wp_create_term` accepts an optional `slug`; `wp_create_post` and `wp_update_post` accept a `post_author` user ID.

= 1.4.10 =
* Feature: Royal Ledger integration (4 tools), ForgeCache integration (3 tools), and Royal Links integration (3 tools), each auto-loading when the host plugin is active.
* Feature: SEO meta tools (`wp_get_seo_meta`, `wp_update_seo_meta`) auto-detect the active SEO plugin and read/write title, description, focus keyword, robots, and OG fields.
* Feature: Permalink structure tools and post revision tools (read history and revert).

= 1.4.9 =
* Feature: Theme appearance tools (active theme, theme mods, custom CSS read/write) gated by an admin toggle and a `royal_mcp_writable_theme_mods` allowlist filter.
* Feature: Menu item CRUD (create, update, delete, reorder) and comment moderation (pending list, approve, spam, trash).

= 1.4.8 =
* Fix: Custom connector setup succeeds on sites updated from an early build without a deactivate/reactivate cycle.
* Fix: Dynamic Client Registration (`POST /register`) returns a real 500 with the underlying error when the DB write fails.

= 1.4.7 =
* Feature: New `wp_get_plugin_settings` tool returns wp_options matching a plugin slug with sensitive keys redacted.
* Feature: New `wp_update_option` tool gated by an admin toggle (off by default), the `royal_mcp_writable_options` filter, and a hard denylist for sensitive option names.
* Security: `wp_get_option` redacts sensitive keys; outbound HTTP timeouts reduced to 10 seconds.
* Docs: Refreshed plugin directory banners and tags.

= 1.4.6 =
* Feature: New tools -- `wp_upload_media_from_url` (SSRF-hardened), `wp_upload_media` (base64), `wp_set_featured_image`, and `wp_update_media`.
* Enhancement: `wp_create_post` and `wp_update_post` accept `featured_media` attachment ID.
* Enhancement: API-key authenticated requests run with administrator capability to match the trust level of the admin-only-accessible key.

= 1.4.5 =
* Feature: WordPress Playground live preview available from the plugin listing.
* Feature: Video walkthrough embedded on the plugin listing page.

= 1.4.4 =
* Feature: Custom post type support -- `wp_get_posts` and `wp_create_post` accept `post_type`, and a new `wp_get_post_types` tool discovers all registered public post types.

= 1.4.3 =
* Security: Access control on MCP REST API endpoints -- all tool calls require an authenticated API key or OAuth Bearer.

= 1.4.2 =
* Security: Authentication enforced on every MCP request, with sessions bound to authenticated credentials.

= 1.4.1 =
* Fix: Resolved fatal error during activation on WordPress 7.0.

= 1.4.0 =
* Feature: OAuth 2.0 authorization server with Dynamic Client Registration (RFC 7591), PKCE-secured authorization code flow, token refresh with rotation, WordPress login consent screen, and discovery at `/.well-known/oauth-authorization-server`.
* Security: Access tokens stored as SHA-256 hashes; authorization codes single-use with 10-minute expiry; PKCE (S256) required; redirect URIs restricted to localhost or HTTPS.

= 1.3.0 =
* Feature: WooCommerce integration (9 tools), GuardPress integration (7 tools), and SiteVault integration (6 tools).
* Security: MCP endpoint requires API key (`X-Royal-MCP-API-Key` header) with rate limiting at 60 requests per minute per IP and timing-safe comparison.

= 1.2.3 =
* Security: SSRF protection for outbound URLs; text domain renamed to `royal-mcp`; menu slugs updated for wp.org compliance.

= 1.2.2 =
* Feature: Documentation link on the Plugins page and documentation banner on the settings page.

= 1.2.1 =
* Fix: Claude Connector setup guide link renders correctly.

= 1.2.0 =
* Security: Origin header validation against DNS rebinding, session ID format validation, MCP 2025-03-26 Streamable HTTP spec compliance, and new `royal_mcp_allowed_origins` filter.

= 1.1.0 =
* Feature: Multi-platform AI support (Claude, OpenAI, Gemini, Groq, Azure, Bedrock), Claude Desktop MCP connector, activity logging, and connection testing.

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.4.37 =
Adds six Royal AI Firewall tools, a Royal Tools admin page with one-click install links to the free Royal Plugins family, a connection-health diagnostic tool, an Elementor widget-settings read tool, and expands wp_update_post / wp_update_page with menu_order and other missing fields plus real read-after-write response shape.

= 1.4.36 =
Adds three diagnostic tools (site status, error-log tail, cron schedule), preserves HTML across several write tools, and adds admin notices for two common environment issues that block OAuth discovery.

= 1.4.33 =
Adds scheduling and backdating to the post and page write tools, expands the create-status enum, and exposes a new action hook for ecosystem extensions.

= 1.4.32 =
Adds snippet excerpts to `wp_search` and pagination to `wc_get_orders`. Note: `wc_get_orders` response shape changes from a bare array to `{orders, page, per_page, total, total_pages}`.

= 1.4.31 =
Security hardening and ergonomic improvements for post-identifying tools. Recommended for all users.

= 1.4.30 =
Adds the first structural-write Elementor tool plus capability-order hardening across six integration wrappers. Recommended for all users.

= 1.4.29 =
Reliability fix for the runtime DB migration. Recommended for anyone on 1.4.27.

= 1.4.28 =
Adds `Authorization: Bearer` header support for API keys and covers the post URL slug in the SEO meta tools. Both changes are strictly additive.

= 1.4.27 =
Reliability patch: MCP session state moved onto a dedicated table. No customer action required.

= 1.4.26 =
Security patch: per-tool capability checks across the OAuth tool surface. Recommended for all users.

= 1.4.25 =
Recommended update. Settings page UX pass and new in-product setup guides for Claude.ai, ChatGPT, Claude Desktop, and Cursor.

= 1.4.24 =
Recommended update. Adds Advanced Custom Fields integration and enables variable-product creation in WooCommerce.

= 1.4.23 =
Strongly recommended update. AI Platforms model dropdowns refreshed across every provider.

= 1.4.22 =
Recommended update. Fixes Test Connection on Claude, restores clearing of manual OAuth credentials, and adds two new self-check admin notices.

= 1.4.21 =
Recommended update for WordPress 7.0: preserves escape sequences inside Gutenberg block content on the post and page write tools.

= 1.4.17 =
Critical fix for OAuth authorization codes. Also adds a Reset OAuth State button and Activity Log entries for MCP tool calls.

= 1.4.16 =
Recommended update: OAuth failures now write to Activity Logs with the exact error code, description, and HTTP status.

= 1.4.15 =
Critical update: four fixes to the API key flow, session TTL, and cache headers. Existing keys keep working.

= 1.4.14 =
Recommended update: unauthenticated GET on the MCP endpoint returns 401 with `WWW-Authenticate` so web-based MCP clients trigger OAuth discovery correctly.

= 1.4.13 =
Recommended update: OAuth endpoint caching hardened plus 17 new WooCommerce tools (variable products, attributes, coupon CRUD).

= 1.4.12 =
Recommended update: fixes tool-list silent failure on Claude Desktop and adds a slug alias on `wp_get_taxonomies`.

= 1.4.11 =
Adds `wp_update_term`, the term-meta tools, and `wp_get_taxonomies`, with existing term tools accepting any registered taxonomy.

= 1.4.10 =
Adds 16 new tools spanning Royal Ledger, ForgeCache, and Royal Links integrations, SEO meta, permalink structure, and post revision history plus restore.

= 1.4.9 =
Adds 13 new tools across theme appearance, menu item CRUD, and comment moderation, with theme writes gated by an admin toggle and opt-in allowlist filter.

= 1.4.8 =
Fixes a setup failure on sites updated from an early build. Recommended for anyone unable to add Royal MCP as a Claude connector.

= 1.4.7 =
Adds plugin-settings read (sensitive keys redacted) and allowlisted options write. New "Allow AI to write WordPress options" toggle is OFF by default.

= 1.3.0 =
Major security and feature update. Recommended for all users.

= 1.2.3 =
Security: SSRF protection for outbound requests plus wp.org compliance fixes.

= 1.2.0 =
Security hardening and MCP spec compliance improvements. Recommended for all users.
