<div align="center">

# Royal MCP

**Connect AI platforms to your WordPress site using Model Context Protocol (MCP)**

[![WordPress](https://img.shields.io/badge/WordPress-5.8+-21759B?style=flat-square&logo=wordpress)](https://wordpress.org/plugins/royal-mcp/)
[![PHP](https://img.shields.io/badge/PHP-7.4+-777BB4?style=flat-square&logo=php)](https://www.php.net/)
[![License](https://img.shields.io/badge/License-GPLv2-blue?style=flat-square)](https://www.gnu.org/licenses/gpl-2.0.html)
[![Version](https://img.shields.io/badge/Version-1.4.10-C9A227?style=flat-square)](https://wordpress.org/plugins/royal-mcp/)

[Download on WordPress.org](https://wordpress.org/plugins/royal-mcp/) · [Documentation](https://royalplugins.com/support/royal-mcp/) · [Royal Plugins](https://royalplugins.com)

</div>

---

Royal MCP enables AI platforms like Claude, OpenAI, and Google Gemini to securely interact with your WordPress content through the [Model Context Protocol (MCP)](https://modelcontextprotocol.io/). Expose your posts, pages, media, and users to LLMs with secure API key authentication and full activity logging.

## Supported AI Platforms

| Platform | Status |
|----------|--------|
| Claude (Anthropic) | Native MCP connector for Claude Desktop |
| OpenAI (GPT-4, GPT-4o) | REST API access |
| Google Gemini | REST API access |
| Mistral | REST API access |
| Perplexity | REST API access |
| Groq | REST API access |

## Features

- **Multi-Platform Support** — Connect Claude, OpenAI, Google Gemini, Mistral, Perplexity, Groq, and more
- **REST API Access** — Expose posts, pages, media, and users to AI platforms
- **Secure Authentication** — API key authentication protects your endpoints
- **Activity Logging** — Track all AI interactions with your site
- **Claude Desktop Integration** — Native MCP connector for the Claude Desktop app
- **Zero Config** — Install, generate an API key, connect your AI platform

## Quick Start

1. Install from [WordPress.org](https://wordpress.org/plugins/royal-mcp/) or upload the zip
2. Go to **Settings > Royal MCP** in your WordPress admin
3. Generate an API key
4. Add the connection details to your AI platform

### Claude Desktop Configuration

Add this to your Claude Desktop `claude_desktop_config.json`:

```json
{
  "mcpServers": {
    "my-wordpress-site": {
      "command": "npx",
      "args": ["-y", "@modelcontextprotocol/server-wordpress"],
      "env": {
        "WP_URL": "https://yoursite.com",
        "WP_API_KEY": "your-generated-api-key"
      }
    }
  }
}
```

## Requirements

- WordPress 5.8+
- PHP 7.4+

## More Royal Plugins

- [GuardPress](https://royalplugins.com/guardpress/) — WordPress security hardening
- [SiteVault](https://royalplugins.com/sitevault/) — WordPress backups and migration
- [ForgeCache](https://royalplugins.com/forgecache/) — Caching and performance
- [FormForge](https://royalplugins.com/formforge/) — Form builder with PDF generation
- [SEObolt](https://royalplugins.com/seobolt/) — SEO toolkit for WordPress

## Free WordPress Tools

- [SERP Preview](https://royalplugins.com/tools/serp-preview/) — Preview Google search result snippets
- [Schema Validator](https://royalplugins.com/tools/schema-validator/) — Validate structured data markup
- [Meta Tag Checker](https://royalplugins.com/tools/meta-tag-checker/) — Analyze page meta tags
- [HTTP Headers Checker](https://royalplugins.com/tools/http-headers-checker/) — Inspect HTTP response headers

## Disclaimer

Royal MCP is provided as-is without warranty of any kind. API keys protect your endpoints — guard them like any other credential. Users are responsible for the content, commands, and actions AI platforms are allowed to perform on their WordPress site.

## License

Royal MCP is licensed under the [GPLv2 or later](https://www.gnu.org/licenses/gpl-2.0.html).

---
<p align="center">
  <strong>Built by <a href="https://royalplugins.com">Royal Plugins</a></strong><br/>
  Lightweight, security-first WordPress plugins.<br/>
  © 2026 Royal Plugins. All rights reserved.
</p>
