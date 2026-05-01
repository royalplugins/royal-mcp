# Contributing to Royal MCP

Thanks for taking the time to contribute! Royal MCP is open-source under GPLv2, and we welcome bug reports, feature suggestions, and pull requests.

## Release Cadence

This GitHub repository is updated on **release boundaries**, not continuously. Between releases the WordPress.org SVN tree and our internal working copy are the source of truth, so `main` here can lag the latest published version by a few weeks.

If you forked recently and `main` looks behind the [WordPress.org listing](https://wordpress.org/plugins/royal-mcp/), check the version badge at the top of the README before starting work — that is the version `main` is currently synced to.

## Before You Start a PR

1. **Check the WordPress.org changelog** at [wordpress.org/plugins/royal-mcp/#developers](https://wordpress.org/plugins/royal-mcp/#developers) — if your feature is in a newer version than what `main` shows, give us a few days to push the sync before opening the PR
2. **Open an issue first** for anything larger than a bug fix or small enhancement, so we can discuss scope before you invest the time
3. **One concern per PR** — keep diffs focused and reviewable

## Syncing a Stale Fork

If you forked from an older `main` and we have since pushed a sync:

```bash
git remote add upstream https://github.com/royalplugins/royal-mcp.git
git fetch upstream
git rebase upstream/main
# resolve conflicts, then:
git push --force-with-lease origin <your-branch>
```

For deeply diverged branches, opening a fresh branch off the new `main` and cherry-picking your changes is usually cleaner than rebasing.

## Coding Standards

- **PHP:** WordPress Coding Standards. ABSPATH check on line 1 of every PHP file.
- **Security:** Escape all output (`esc_html`, `esc_attr`, `esc_url`), sanitize all input (`sanitize_text_field`, `wp_unslash`), prepare all SQL (`$wpdb->prepare()`).
- **Naming:** Option keys use the `rmcp_` prefix; AJAX/CSS use `rmcp-` / `rmcp_`. Don't mix prefixes.
- **MCP spec:** Follow the [Model Context Protocol 2025-11-25 spec](https://modelcontextprotocol.io/specification/2025-11-25/) for any tool/transport changes.

## Reporting Bugs

[Open an issue](https://github.com/royalplugins/royal-mcp/issues/new) and include:

- Royal MCP version (Settings → Royal MCP, or check the plugin header)
- WordPress version, PHP version
- AI client (Claude Desktop, OpenAI, etc.) and how you connected
- Exact error message or unexpected behaviour, with reproduction steps

## Questions / Support

- **General usage questions:** [royalplugins.com/support/royal-mcp/](https://royalplugins.com/support/royal-mcp/)
- **WordPress.org forum:** [wordpress.org/support/plugin/royal-mcp/](https://wordpress.org/support/plugin/royal-mcp/)
- **Code-level discussion:** GitHub issues on this repo

## License

By submitting a pull request you agree that your contribution is licensed under GPLv2 (the same license as Royal MCP).
