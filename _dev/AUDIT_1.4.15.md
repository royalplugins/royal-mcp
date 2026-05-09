# Royal MCP — Behavioral Audit Report (1.4.15)

**Date:** 2026-05-09
**Trigger:** Customer report from Mark @ thegin.uk on SiteGround surfaced 4 distinct bugs in 1.4.14. Pattern of "every release fixes the bug one customer reported but ships with others undetected" warranted a comprehensive audit.
**Outcome:** 5 bugs fixed in 1.4.15; behavioral test harness committed as `_dev/audit.py`; pre-ship gate enforces 0 FAILs before release.

---

## Why this audit happened

Royal MCP shipped 4 patch releases in ~6 weeks (1.4.12 → 1.4.13 → 1.4.14 → 1.4.15). Each fixed something a customer reported. Each shipped with other bugs that nobody had hit yet. The pattern was visible in support history:

- 1.4.12 fixed mcp-remote 405 retry storms
- 1.4.13 fixed OAuth endpoint cache poisoning + added 17 WC tools
- 1.4.14 fixed Claude.ai web GET probe (RFC 9728 401+WWW-Authenticate)
- **1.4.15 fixes 4 customer-impacting bugs that pre-date all of those**, found because we finally went looking systematically

That last part is the indicator: when "discovery" is happening on customers instead of in CI, every release introduces a new "lessons learned" gotcha file rather than reducing the cumulative bug surface.

## Scope of the audit

The audit covered **the behavioral surface** — what an external HTTP client sees when hitting every Royal MCP endpoint × every auth state × every method. It deliberately did NOT re-run static checks (we already have `security-scanner.py` and `audit-plugin.py` for that — they catch code patterns, not behavioral gaps).

Coverage:

| Surface | Tested | How |
|---------|--------|------|
| OAuth metadata (`/.well-known/oauth-*`) | ✓ | `test_oauth_well_known()` — checks 200 + JSON Content-Type |
| OAuth `/register` | ✓ | `test_oauth_register()` — POSTs and verifies client_id returned |
| MCP `/wp-json/royal-mcp/v1/mcp` GET unauth | ✓ | Asserts 401 + `WWW-Authenticate: Bearer resource_metadata="..."` |
| MCP /mcp GET auth | ✓ | Asserts 405 + `Allow: POST, DELETE, OPTIONS` |
| MCP /mcp POST initialize | ✓ | Asserts 200 + `Mcp-Session-Id` header |
| MCP /mcp POST tools/list | ✓ | Asserts 200 + ≥30 tools |
| MCP /mcp session reuse | ✓ | Same session ID across multiple POSTs |
| MCP /mcp DELETE cleanup | ✓ | 200/204 |
| MCP /mcp invalid key | ✓ | 401 (was 403 pre-fix) |
| Cache-Control on every response | ✓ | Asserts `no-store` on every Royal MCP response |
| Legacy `/sse`, `/messages` | partial | Confirmed not 5xx |
| REST_Controller `/site`, `/posts` | partial | Auth + unauth |
| Admin AJAX (`royal_mcp_*`) | ✗ | Skipped — requires admin cookie |
| Settings page regenerate flow | ✗ | Skipped — requires admin login |
| OAuth `/authorize` HTML flow | ✗ | Skipped — interactive |
| OAuth `/token` full flow | ✗ | Skipped — needs prior authorization code |

The `*` skipped tests are tracked as Phase 2 work — they need either Playwright or a logged-in cookie jar.

## Findings (each one was real, each one's now fixed)

### Bug 1 — Regenerate button silently overridden (CUSTOMER REPORT)

**Reproduce:** Click Regenerate on the API key. Observe key did not change.
**Root cause:** Settings form submits the readonly `api_key` field's existing value alongside the regenerate flag. `Settings_Page::sanitize_settings()` checked `api_key` first (truthy), took that branch, ignored the regenerate flag. **Has been broken since 1.0.**
**Fix:** Flip precedence — `regenerate_api_key` checked first, then `api_key`, then fallback. 5 lines.
**Test added:** Manual (regenerate test requires admin cookie). Phase 2 will automate via Playwright.

### Bug 2 — Mixed-case API keys cause silent transcription errors (CUSTOMER REPORT)

**Reproduce:** Generate key. Observe characters at certain positions look identical between O/0, I/l/1, o/0 in monospace admin fonts. Customer transcribes wrong character into config, hits "Invalid API key", can't tell why.
**Root cause:** `wp_generate_password(32, false)` produces 32-char mixed-case alphanumeric. Several letter pairs are visually identical in common admin fonts.
**Fix:** Switch to `bin2hex(random_bytes(16))` — 32-char lowercase hex. Same 128-bit entropy. Existing keys keep working.
**Test added:** Format check — newly-generated keys must be `[a-f0-9]{32}`.

### Bug 3 — 1-hour fixed session TTL with no sliding refresh (CUSTOMER REPORT)

**Reproduce:** Open Claude Desktop. Use it. Walk away for 60 minutes. Try to use it. Multiple `mcp-remote` npx processes spawn, "Session not found or expired" errors loop.
**Root cause:** `Server::store_session()` set `HOUR_IN_SECONDS` TTL with no refresh on `is_valid_session()`. Active sessions died regardless of activity.
**Fix:** Bump TTL to `DAY_IN_SECONDS`. Refresh transient on every `is_valid_session()` hit (sliding window). Active sessions live until 24h true idle. 10 lines.
**Test added:** Phase 2 — needs to simulate >1hr idle, slow.

### Bug 4 — MCP endpoint responses missing Cache-Control: no-store (AUDIT-DISCOVERED)

**Reproduce:** Fire `GET /wp-json/royal-mcp/v1/mcp` from any client. Response gets cached at edge (Cloudflare in our reproduction). Subsequent requests with the same URL get the cached 401 regardless of headers. Adding any query string (`?cb=X`) busts the cache.
**Root cause:** 1.4.13 added `Cache-Control: no-store` to OAuth endpoints (`/register`, `/token`, `/authorize`) via `OAuth\Server::json_response()` defaults. The MCP endpoint has its OWN `MCP\Server::json_response()` helper which never got the same treatment. `validate_auth`'s 401 response constructed `WP_REST_Response` directly, bypassing both helpers, and set zero cache headers. **REST_Controller routes (`/site`, `/posts`, `/pages`, etc.) had the same gap.**
**Fix:** Three layers of defense:
1. `MCP\Server::json_response()` now sets `Cache-Control: no-store, no-cache, must-revalidate, private` + `Pragma: no-cache`
2. `validate_auth`, `validate_bearer_token`, `validate_api_key_value`, and `handle_get_stream`'s 405 each set the same headers explicitly
3. Global `rest_post_dispatch` filter in main plugin file applies the headers to ALL `/royal-mcp/*` namespace responses as a backstop

**Test added:** `test_cache_control_on_all_responses()` checks every endpoint × every auth state.

**This was almost certainly Mark @ thegin.uk's "worked yesterday, fails today"** — his SiteGround caching layer captured an unauth 401 from his initial setup, then served it back even after he set the API key correctly. Same root cause as the o2switch / Cloudflare APO cache poisoning we shipped 1.4.13 to fix, just on a different endpoint we missed.

### Bug 5 — Invalid API key returned 403 instead of 401 (AUDIT-DISCOVERED)

**Reproduce:** Send a wrong API key. Get 403. RFC 7235-aware MCP clients (Claude.ai web, ChatGPT) treat 403 as "auth succeeded but no permission" and don't trigger their OAuth discovery fallback. So a misconfigured key blocks the OAuth flow that should kick in.
**Root cause:** `validate_api_key_value()` returned 403 on key mismatch.
**Fix:** Return 401 + `WWW-Authenticate: Bearer error="invalid_token", resource_metadata="..."`. RFC 7235-correct + MCP-spec-correct. 4-line change.
**Test added:** `test_mcp_invalid_key()` asserts 401.

## What still needs investigation (not blocking 1.4.15)

- **Authenticated REST_Controller routes return 200 without our cache headers in the wild** — covered now by global filter, but worth verifying CF actually drops cache after the next purge
- **OAuth `/authorize` HTML response not behaviorally tested** — needs Playwright + headed browser
- **Admin AJAX endpoints not tested** — `royal_mcp_get_platform_fields` and `royal_mcp_test_connection` need a logged-in admin cookie
- **OAuth `/token` flow not end-to-end tested** — needs a chained test that goes through `/register` → `/authorize` → `/token`
- **No load testing** — 109 tools registered, no benchmark on response time at scale
- **No concurrency testing** — multiple sessions, race conditions, transient overwrite scenarios

These go on the 1.4.16+ punch list.

## State machines (for future reference)

The four state machines that need to stay coherent across releases. Documented here so the next person who touches Royal MCP doesn't have to rediscover them.

### 1. OAuth flow

```
[client]                                   [Royal MCP]
   |                                            |
   |-- GET /.well-known/oauth-protected-resource->|  → 200 + JSON metadata
   |-- GET /.well-known/oauth-authorization-server->|  → 200 + JSON metadata
   |                                            |
   |-- POST /register ------------------------->|  → 200 + client_id (no_secret per spec)
   |                                            |
   |-- GET /authorize?... ---------------------->|  → 200 HTML consent screen (admin login)
   |   user clicks "Allow"                     |
   |   browser redirects to client redirect_uri with ?code=
   |                                            |
   |-- POST /token (code + verifier) ---------->|  → 200 + access_token (Cache-Control: no-store)
   |                                            |
   |-- POST /wp-json/royal-mcp/v1/mcp           |
   |   Authorization: Bearer <access_token>    |
   |   { "jsonrpc": "2.0", "method": "..." } -->|  → 200 + JSON-RPC reply
   |                                            |
```

**Cache rule:** Discovery endpoints (`/.well-known/*`) MAY be cached short-term (1hr). Token endpoint MUST be `no-store`. Authorize HTML SHOULD be `no-store, private`.

### 2. Session lifecycle

```
[POST /mcp init]    →  store_session(id, fingerprint) — sets transient with DAY_IN_SECONDS
[POST /mcp method]  →  is_valid_session(id) — refreshes transient TTL (sliding)
[idle <24h]         →  transient still valid
[idle >24h]         →  transient expired, re-init required
[DELETE /mcp]       →  delete_session(id) — explicit cleanup
```

**Pre-1.4.15:** Fixed 1hr TTL, no refresh. Sessions died at exactly minute 60 regardless of activity.

### 3. API key flow

```
[admin opens settings] →  reads royal_mcp_settings.api_key
[admin clicks Save]   →  sanitize_settings() — readonly api_key field re-saves existing value
[admin clicks Regenerate] →  sanitize_settings() — checks regenerate_api_key FIRST, generates new bin2hex(random_bytes(16))
[customer copies key] →  pastes into mcp-remote --header / Claude Desktop config
[client request]      →  validate_auth() reads X-Royal-MCP-API-Key header
                      →  validate_api_key_value() does hash_equals against settings
                      →  401 on mismatch (was 403 pre-1.4.15)
                      →  proceed on match (sets current_user to admin for capability checks)
```

**Critical invariants:**
- Regenerate must check its flag BEFORE the readonly api_key field
- New keys must be visually unambiguous (lowercase hex)
- hash_equals (constant-time) for comparison

### 4. Endpoint dispatch matrix

For `/wp-json/royal-mcp/v1/mcp`:

| Method | Auth | Expected response |
|--------|------|------------------|
| OPTIONS | any | 204 + CORS headers |
| GET | none | 401 + WWW-Authenticate (RFC 9728 OAuth discovery) |
| GET | valid | 405 + Allow: POST, DELETE, OPTIONS |
| POST | none | 401 + WWW-Authenticate |
| POST | invalid | 401 + WWW-Authenticate (was 403 pre-1.4.15) |
| POST | valid | 200 + JSON-RPC reply |
| DELETE | valid + Mcp-Session-Id | 200/204 + cleanup |

**Every response above must have:** `Cache-Control: no-store, no-cache, must-revalidate, private` and `Pragma: no-cache`.

See `MCP_ENDPOINT_BEHAVIOR_MATRIX.md` for the per-MCP-client and per-host compatibility extension of this table.

## Pre-ship gate (Phase 2)

Before any future Royal MCP release ships:

```bash
python _dev/audit.py https://gsc.royalplugins.com $(wp option get royal_mcp_settings | jq -r .api_key)
```

Must exit 0 (zero FAILs). If any FAIL, ship is blocked. The audit is the last gate; security-scanner and audit-plugin run first as static gates.

## What this audit cost vs what it prevented

- **Time:** ~3 hours total (build the script, run, diagnose, fix the 2 audit-discovered bugs, re-run, re-deploy, document)
- **Bugs caught:** 2 (Cache-Control gap + 401 vs 403) that would have shipped silently in 1.4.15 and surfaced as customer reports in 1.4.16
- **Process change:** behavioral fixture testing is now mandatory pre-ship for Royal MCP. Pattern can be replicated for other plugins (forgecache, sitevault) where the failure mode is "the plugin works in isolation but breaks behaviorally with X host / Y client / Z cache layer".

The audit found bugs the static gates couldn't. That's the whole point.
