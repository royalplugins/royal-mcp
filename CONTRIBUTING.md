# Contributing to Royal MCP

Royal MCP is open-source under GPLv2. We're a small commercial team, and this repo exists so users can report bugs, request features, and read the source, and so plugin developers can connect their plugins to Royal MCP. Here's how the different types of contribution work in practice.

## What we're most looking for

- **Bug reports with a reproducer.** Fast-track. Usually confirmed within 48 hours and fixed in the next patch.
- **Feature suggestions.** Welcome and read carefully. Logged for consideration; see below on how that's handled.
- **Integrations from plugin developers.** If you build a WordPress plugin, you can add its tools to Royal MCP yourself, or work with us on it. See below.

## Integrate your plugin

Royal MCP works best alongside the plugins people already run. If you'd like AI clients to work with yours, there are two ways in.

### Build it yourself

Two filters let any plugin add its own tools to Royal MCP, with no change to Royal MCP and no pull request:

- `royal_mcp_tools` adds your tool definitions (name, description, input schema, annotations) to the list every connected AI client sees.
- `royal_mcp_execute_tool_external` runs your tool when it's called and returns the result, or a `WP_Error` for a tool error.

Royal MCP handles sign-in, rate limits, the activity log and its safety switches. Your tools follow read-only mode, drafts-only mode and per-connection access levels: any tool not marked `readOnlyHint: true` counts as a change and is refused while those are on. Your callback checks the user's capabilities itself.

The full guide, with a complete example plugin and the rules every tool follows, is at **[Add your plugin's tools to Royal MCP](https://royalplugins.com/support/royal-mcp/add-your-plugin-tools/)**.

### Partner with us

Want your plugin listed among Royal MCP's integrations, or would you rather we build the integration with you? Email **[devs@royalplugins.com](mailto:devs@royalplugins.com)** with your plugin and what you'd like AI clients to do with it.

- We review every request, and agree the scope with you before anything is built.
- Integrations we build ship in Royal MCP and are listed in the integrations table in the [README](README.md).
- Tools you add through the filters live in your plugin, and you maintain and support them.
- We can't promise a timeline for every request.

You can also earn from the plugins you recommend: the [Royal Plugins affiliate program](https://royalplugins.com/affiliates/) pays 30% on first-year sales and 15% on renewals.

## Release cadence

This GitHub repository is updated on **release boundaries**, not continuously. Between releases the WordPress.org SVN tree and our internal working copy are the source of truth, so `main` here can lag the latest published version by a few weeks. Check the version badge at the top of the [README](README.md) to see what `main` is currently synced to.

## Reporting bugs

[Open an issue](https://github.com/royalplugins/royal-mcp/issues/new/choose) using the Bug Report template. The form asks for:

- Royal MCP version (Plugins → Installed Plugins, or the plugin header). Please include the exact patch level, not just "latest".
- WordPress version, PHP version
- AI client (Claude Desktop, Claude.ai web, ChatGPT, Cursor, etc.) and how you connected
- Exact error message and reproduction steps
- Relevant Royal MCP → Activity Log entries around the failure, if any. An empty log after a reproduced failure tells us the request never reached WordPress, which is diagnostic on its own.

## Feature suggestions

Open an issue using the Feature Suggestion template. A few things to set expectations honestly:

- **We log every suggestion.** Nothing gets ignored.
- **Suggestions are considered against current priorities**, not on a fixed timeline. Individual suggestions rarely get an immediate "yes, shipping in X" response, because that pattern isn't sustainable and leads to public slippage when priorities shift.
- **We won't quote ship dates in issue threads.** When something ships, it appears in the changelog and a comment on the issue closes the loop.
- **Plugin-specific integrations** (adapters for individual third-party plugins) are evaluated case-by-case based on install base and maintenance cost. If you're the developer of that plugin, see [Integrate your plugin](#integrate-your-plugin): you can add the tools yourself today.

## Pull requests

We're not accepting pull requests.

To contribute:

- **Bug reports with a reproducer** — usually confirmed within 48 hours and fixed in the next patch.
- **Feature suggestions** — logged, read carefully, weighed against the roadmap.
- **Integrations** — build them in your own plugin with the filters above, or email devs@royalplugins.com.

## Questions and support

GitHub issues are for bugs and feature suggestions. For everything else:

- **Usage questions and setup help:** [royalplugins.com/support/royal-mcp/](https://royalplugins.com/support/royal-mcp/)
- **WordPress.org support forum:** [wordpress.org/support/plugin/royal-mcp/](https://wordpress.org/support/plugin/royal-mcp/)
- **Integration and partnership questions:** [devs@royalplugins.com](mailto:devs@royalplugins.com)
- **Security issues:** report them privately through [royalplugins.com/contact](https://royalplugins.com/contact/), not in a public issue.

Issues opened as "how do I..." questions will usually be redirected to one of the above.
