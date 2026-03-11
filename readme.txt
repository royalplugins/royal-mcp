=== Royal MCP ===
Contributors: royalpluginsteam
Donate link: https://www.royalplugins.com
Tags: mcp, ai, api, claude, openai
Requires at least: 5.8
Tested up to: 6.9
Stable tag: 1.2.2
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect AI platforms to your WordPress site using Model Context Protocol (MCP) for seamless content access.

== Description ==

Royal MCP enables AI platforms like Claude, OpenAI, and Google Gemini to securely interact with your WordPress content through the Model Context Protocol (MCP).

**Key Features:**

* **Multi-Platform Support** - Connect Claude, OpenAI, Google Gemini, Mistral, Perplexity, Groq, and more
* **REST API Access** - Expose posts, pages, media, and users to AI platforms
* **Secure Authentication** - API key authentication protects your endpoints
* **Activity Logging** - Track all AI interactions with your site
* **Claude Desktop Integration** - Native MCP connector for Claude Desktop app

**Supported AI Platforms:**

* Claude (Anthropic)
* OpenAI (GPT-4, GPT-3.5)
* Google Gemini
* Mistral AI
* Perplexity
* Groq
* Cohere
* Together AI
* DeepSeek
* And more...

**API Endpoints:**

* `/wp-json/royal-mcp/v1/posts` - Access posts
* `/wp-json/royal-mcp/v1/pages` - Access pages
* `/wp-json/royal-mcp/v1/media` - Access media library
* `/wp-json/royal-mcp/v1/users` - Access user data (public info only)

== External Services ==

This plugin connects to third-party AI services to enable AI platforms to interact with your WordPress content. **No data is transmitted until you explicitly configure and enable a platform connection.**

**What data is sent:** Your WordPress content (posts, pages, media metadata) as requested by the connected AI platform.

**When data is sent:** Only when you have configured a platform with API credentials AND enabled that platform connection AND the AI platform makes a request.

**Supported services and their policies:**

* **Anthropic Claude** - Used for Claude AI integration
  [Terms of Service](https://www.anthropic.com/legal/consumer-terms) | [Privacy Policy](https://www.anthropic.com/legal/privacy)

* **OpenAI** - Used for ChatGPT/GPT-4 integration
  [Terms of Use](https://openai.com/policies/terms-of-use) | [Privacy Policy](https://openai.com/policies/privacy-policy)

* **Google Gemini** - Used for Gemini AI integration
  [Terms of Service](https://ai.google.dev/terms) | [Privacy Policy](https://policies.google.com/privacy)

* **Groq** - Used for Groq LPU inference
  [Terms of Service](https://groq.com/terms-of-use/) | [Privacy Policy](https://groq.com/privacy-policy/)

* **Microsoft Azure OpenAI** - Used for Azure-hosted OpenAI models
  [Terms of Service](https://azure.microsoft.com/en-us/support/legal/) | [Privacy Policy](https://privacy.microsoft.com/en-us/privacystatement)

* **AWS Bedrock** - Used for AWS-hosted AI models
  [Terms of Service](https://aws.amazon.com/service-terms/) | [Privacy Policy](https://aws.amazon.com/privacy/)

* **Ollama / LM Studio** - Local self-hosted models (no external data transmission)

* **Custom MCP Servers** - User-configured servers (data sent to user-specified endpoints only)

== Installation ==

1. Upload the `royal-mcp` folder to `/wp-content/plugins/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to Royal MCP → Settings to configure
4. Add your AI platform(s) and enter API keys
5. Copy your WordPress API key and REST URL for use in AI clients

== Frequently Asked Questions ==

= What is MCP? =

Model Context Protocol (MCP) is a standard for connecting AI assistants to external data sources. It allows AI platforms to securely access your WordPress content.

= Is my data secure? =

Yes. All API endpoints require authentication via API key. Activity logging tracks all requests. No data is sent to external servers without your explicit configuration.

= Which AI platforms are supported? =

Claude, OpenAI, Google Gemini, Mistral, Perplexity, Groq, Cohere, Together AI, DeepSeek, and any platform supporting REST API connections.

= Does this work with Claude Desktop? =

Yes! Royal MCP includes native Claude Desktop MCP connector settings for easy integration.

== Screenshots ==

1. Main settings page with plugin overview
2. AI platform settings and API key configuration
3. Activity log showing API requests
4. Claude Desktop MCP connector configuration
5. Claude MCP connection verification

== Changelog ==

= 1.2.3 =
* Fixed: Text domain changed from 'wp-royal-mcp' to 'royal-mcp' to match plugin slug
* Fixed: Menu slugs updated from 'wp-royal-mcp' to 'royal-mcp' for WP.org compliance
* Fixed: Installation instructions updated to reference correct folder name
* Improved: REST API permission callbacks now include explanatory comments for reviewers
* Improved: Custom MCP server placeholder text clarified

= 1.2.2 =
* Added: Documentation link on Plugins page (Settings | Documentation)
* Added: Documentation banner on settings page with link to support docs

= 1.2.1 =
* Fixed: Claude Connector setup guide link displaying raw HTML instead of clickable link

= 1.2.0 =
* Security: Added Origin header validation to prevent DNS rebinding attacks
* Security: Added session ID format validation (ASCII visible characters only)
* Improved: MCP 2025-03-26 spec compliance for Streamable HTTP transport
* Improved: Proper Accept header validation on POST requests
* Improved: Session management with proper 400/404 responses per MCP spec
* Improved: GET stream handler with proper SSE headers
* Improved: DELETE session handler for explicit session termination
* Fixed: Platform model selector not retaining saved value in admin settings
* Added: Filter hook `royal_mcp_allowed_origins` for custom origin allowlist

= 1.1.0 =
* Added multi-platform AI support
* Added Claude Desktop MCP connector
* Added activity logging
* Improved admin interface
* Added connection testing

= 1.0.0 =
* Initial release

== Upgrade Notice ==

= 1.2.3 =
WordPress.org compliance fixes: text domain, menu slugs, and documentation improvements.

= 1.2.2 =
Added documentation links for easier access to setup guides and support.

= 1.2.0 =
Security hardening and MCP spec compliance improvements. Recommended update for all users.

= 1.1.0 =
Major update with multi-platform support and Claude Desktop integration.
