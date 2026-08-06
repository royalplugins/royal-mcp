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

We accept PRs, but with more structure than a typical open-source project because we're a small team shipping a commercial product on a fixed cadence:

1. **Open an issue first and wait for maintainer sign-off on scope before writing code.** Unsolicited PRs (even well-crafted ones) may be closed without review if the underlying feature isn't something we're planning to ship.
2. **One concern per PR.** Keep diffs focused.
3. **Bug-fix PRs with a clear reproducer** are the easiest to get merged.
4. **Feature PRs** are harder. The scoping conversation on the issue matters more than the code.
5. **Merging your PR doesn't create a fast-track relationship for future requests.** We appreciate the contribution, but every feature suggestion goes through the same evaluation regardless of who filed it.

Not because we don't value the work. We do. But making the process explicit up front is fairer than an implicit expectation that breaks later.

## Coding standards

- **PHP:** WordPress Coding Standards. `if ( ! defined( 'ABSPATH' ) ) exit;` on line 1 of every PHP file.
- **Security:** Escape all output (`esc_html`, `esc_attr`, `esc_url`), sanitize all input (`sanitize_text_field`, `wp_unslash`), prepare all SQL (`$wpdb->prepare()`).
- **Naming:** Option keys use the `rmcp_` prefix; AJAX/CSS use `rmcp-` / `rmcp_`. Don't mix prefixes.
- **MCP spec:** Follow the [Model Context Protocol 2025-11-25 spec](https://modelcontextprotocol.io/specification/2025-11-25/) for any tool/transport changes.
- **Versioning:** Maintainers handle version bumps. Don't touch the version header, `ROYAL_MCP_VERSION` constant, `Stable tag:`, or `readme.txt` changelog in a PR.

## Questions and support

GitHub issues are for bugs and feature suggestions. For everything else:

- **Usage questions and setup help:** [royalplugins.com/support/royal-mcp/](https://royalplugins.com/support/royal-mcp/)
- **WordPress.org support forum:** [wordpress.org/support/plugin/royal-mcp/](https://wordpress.org/support/plugin/royal-mcp/)

Issues opened as "how do I..." questions will usually be redirected to one of the above.

## License

By submitting a pull request you agree that your contribution is licensed under GPLv2 (the same license as Royal MCP).
