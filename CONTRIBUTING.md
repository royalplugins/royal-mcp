# Contributing to Royal MCP

Royal MCP is open-source under GPLv2. We're a small commercial team, and this repo exists so users can report bugs, request features, and read the source. Here's how the different types of contribution work in practice.

## What we're most looking for

- **Bug reports with a reproducer.** Fast-track. Usually confirmed within 48 hours and fixed in the next patch.
- **Feature suggestions.** Welcome and read carefully. Logged for consideration; see below on how that's handled.

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
- **Plugin-specific integrations** (adapters for individual third-party plugins) are evaluated case-by-case based on install base and maintenance cost. We tend to prefer generic primitives that work across whole plugin categories rather than one-off adapters.

## Pull requests

We're not accepting pull requests.

To contribute:

- **Bug reports with a reproducer** — usually confirmed within 48 hours and fixed in the next patch.
- **Feature suggestions** — logged, read carefully, weighed against the roadmap.

## Questions and support

GitHub issues are for bugs and feature suggestions. For everything else:

- **Usage questions and setup help:** [royalplugins.com/support/royal-mcp/](https://royalplugins.com/support/royal-mcp/)
- **WordPress.org support forum:** [wordpress.org/support/plugin/royal-mcp/](https://wordpress.org/support/plugin/royal-mcp/)

Issues opened as "how do I..." questions will usually be redirected to one of the above.
