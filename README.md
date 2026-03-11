<div align="center">

# Royal MCP

**Connect AI platforms to your WordPress site using Model Context Protocol (MCP)**

[![WordPress](https://img.shields.io/badge/WordPress-5.8+-21759B?style=flat-square&logo=wordpress)](https://wordpress.org/plugins/royal-mcp/)
[![PHP](https://img.shields.io/badge/PHP-7.4+-777BB4?style=flat-square&logo=php)](https://www.php.net/)
[![License](https://img.shields.io/badge/License-GPLv2-blue?style=flat-square)](https://www.gnu.org/licenses/gpl-2.0.html)
[![Version](https://img.shields.io/badge/Version-1.2.2-C9A227?style=flat-square)](https://wordpress.org/plugins/royal-mcp/)

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
    "wordpress": {
      "url": "https://yoursite.com/wp-json/royal-mcp/v1/mcp",
      "headers": {
        "X-API-Key": "your-api-key-here"
      }
    }
  }
}
```

## Requirements

- WordPress 5.8+
- PHP 7.4+
- HTTPS recommended for secure API communication

## Installation

### From WordPress.org (Recommended)
1. Go to **Plugins > Add New** in your WordPress admin
2. Search for "Royal MCP"
3. Click **Install Now**, then **Activate**

### Manual Installation
1. Download the latest release from [WordPress.org](https://wordpress.org/plugins/royal-mcp/)
2. Upload to `/wp-content/plugins/royal-mcp/`
3. Activate through the **Plugins** menu

## Documentation

Full documentation is available at [royalplugins.com/support/royal-mcp](https://royalplugins.com/support/royal-mcp/).

## About Royal Plugins

Royal MCP is built by [Royal Plugins](https://royalplugins.com) — lightweight, security-first WordPress plugins built with clean code. Every plugin undergoes multi-engine security analysis before release.

Check out our other plugins:
- [GuardPress](https://royalplugins.com/guardpress/) — WordPress security hardening
- [SEObolt](https://seobolt.io) — Technical SEO toolkit
- [SiteVault](https://royalplugins.com/sitevault/) — Automated backups
- [ForgeCache](https://royalplugins.com/forgecache/) — Speed optimization

## License

Royal MCP is licensed under the [GPLv2 or later](https://www.gnu.org/licenses/gpl-2.0.html).
