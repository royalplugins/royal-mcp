# Royal MCP `/wp-json/royal-mcp/v1/mcp` Endpoint Behavior Contract

**Last verified:** 2026-05-09 (Royal MCP 1.4.15)

> ⚠️ **Read this before changing any HTTP response code on the MCP endpoint.**
> We've burned ourselves twice now (1.4.12 fix broke 1.4.13 web-connector flow).
> The matrix below is the ground truth — any code change that violates a
> non-greyed-out cell in the "expected" column is a regression.

---

## Why this doc exists

The MCP Streamable HTTP transport spec, RFC 9728 (Protected Resource Metadata),
and the realities of multiple MCP clients (Claude.ai web, Claude Desktop via
mcp-remote, ChatGPT, Cursor, Cline, etc.) place overlapping demands on the
single `/mcp` endpoint. Different clients probe in different ways, and small
response-code changes can silently break one client while fixing another.

Every prior shipped fix to this endpoint has had unintended consequences for at
least one client. The matrix is the spec for how every method × auth-state
combo MUST respond — derived from the MCP spec, RFC 9728, and observed client
behavior.

---

## Authoritative sources

1. **MCP Streamable HTTP transport spec** —
   https://modelcontextprotocol.io/specification/2025-06-18/basic/transports
   - GET on the MCP endpoint **MUST** either return `Content-Type: text/event-stream`
     OR HTTP 405 Method Not Allowed. Both are spec-compliant.
   - POST is the primary delivery mechanism for client → server JSON-RPC.

2. **RFC 9728 — OAuth 2.0 Protected Resource Metadata** —
   https://datatracker.ietf.org/doc/rfc9728/
   - Unauthenticated requests to a protected resource **MUST** return 401 with
     `WWW-Authenticate: Bearer resource_metadata="<URL>"` so the client can
     discover the OAuth authorization server.

3. **mcp-remote source** (Claude Desktop bridge) —
   https://github.com/geelen/mcp-remote
   - On 401 + `WWW-Authenticate` → reads header, starts OAuth discovery.
   - On 405 → triggers fallback to the deprecated HTTP+SSE transport. (This is
     why 1.4.12 returning 405 stopped its retry storm — mcp-remote handled the
     405 cleanly instead of treating a closed SSE stream as a dropped
     connection.)

4. **Anthropic MCP connector docs** —
   https://platform.claude.com/docs/en/agents-and-tools/mcp-connector
   - Claude Code/web first checks RFC 9728 metadata, then RFC 8414. MCP
     endpoints **must** send 401 + `WWW-Authenticate: Bearer` on unauth
     requests for OAuth flow to start.

---

## The contract

### Resolution of the spec tension

The MCP spec says GET → `text/event-stream` OR 405. RFC 9728 says unauth
requests → 401 + WWW-Authenticate. These don't conflict — they apply at
different layers:

1. **Authentication check goes FIRST.** If the request lacks credentials, return
   401 + `WWW-Authenticate: Bearer resource_metadata="..."` regardless of HTTP
   method.
2. **Method dispatch goes SECOND.** Only authenticated requests reach the
   method-specific logic, and only there does the GET → 405 (no SSE) rule
   apply.

This ordering is what every spec-compliant implementation does. Royal MCP
1.4.13 had it backwards (method check before auth check on GET only); 1.4.14
fixes the order.

### Test matrix

| Client | Method | Auth state | Expected response | Why |
|---|---|---|---|---|
| Claude.ai web (URL-only mode) | GET | none | **401 + `WWW-Authenticate: Bearer resource_metadata="..."`** | Probes endpoint to start OAuth discovery (RFC 9728). 405 makes Claude bail with "couldn't reach". |
| Claude.ai web | POST `initialize` | none | **401 + `WWW-Authenticate`** | Same OAuth discovery trigger. Body content irrelevant. |
| Claude.ai web | POST `initialize` | Bearer (valid) | **200 + JSON-RPC result** | Standard MCP initialize response. |
| Claude.ai web | POST | Bearer (invalid) | **401 + `WWW-Authenticate: Bearer error="invalid_token", resource_metadata="..."`** | RFC 6750 §3 — invalid token error response. |
| Claude.ai web | OPTIONS | any | **204 + CORS headers** | Preflight for browser-initiated fetch. |
| Claude Desktop (mcp-remote) | GET | Bearer | **405 + `Allow: POST, DELETE, OPTIONS`** | We don't host SSE. mcp-remote handles 405 by falling back to POST-only mode. **DO NOT change this** — broke once already in pre-1.4.12. |
| Claude Desktop (mcp-remote) | GET | none | **401 + `WWW-Authenticate`** | mcp-remote will start OAuth flow. Rare path (Claude Desktop is configured with token), but spec-correct. |
| Claude Desktop (mcp-remote) | POST | Bearer | **200 + JSON-RPC result** | Primary mode. |
| ChatGPT MCP connector | GET | none | **401 + `WWW-Authenticate`** | Same RFC 9728 flow as Claude.ai. |
| Cursor / Cline / Windsurf | POST | Bearer | **200 + JSON-RPC result** | These clients use POST exclusively for JSON-RPC. No GET probe. |
| Healthcheck / curl probe | GET | none | **401 + `WWW-Authenticate`** | Acceptable — caller learns auth is required. Not a regression. |
| Browser direct visit | GET | none | **401 + `WWW-Authenticate`** | Same. |
| Any client | DELETE | Bearer + valid `Mcp-Session-Id` | **200 / 204** (session terminated) | Per spec §Session Management. |
| Any client | DELETE | none | **401** | Auth-first rule applies. |
| Any client | DELETE | Bearer + missing session header | **400 Bad Request** | Per spec — server requires `Mcp-Session-Id`. |
| Any client | unknown method (PUT, PATCH, etc.) | any | **405 + `Allow: GET, POST, DELETE, OPTIONS`** | WP REST API default; should not change. |

### Negative cases (regressions to avoid)

- ❌ Returning 405 for unauthenticated GET. Breaks Claude.ai web (1.4.13 bug).
- ❌ Returning 200 with an empty/closed SSE stream for GET. Breaks mcp-remote
  (pre-1.4.12 bug — caused retry storm).
- ❌ Returning 401 *without* a `WWW-Authenticate` header. Clients can't
  discover OAuth and treat it as a hard-fail.
- ❌ Returning 200 OK to an unauthenticated request of any method. Defeats
  authentication.
- ❌ Returning 403 instead of 401 for invalid tokens. Spec/RFC 6750 say 401 for
  bad/missing credentials.

### Headers the endpoint MUST send (where applicable)

- **`WWW-Authenticate: Bearer resource_metadata="<home_url>/.well-known/oauth-protected-resource"`**
  on every 401. The `resource_metadata` URL is what tells RFC 9728-aware
  clients where to start OAuth discovery.

- **`Access-Control-Allow-Origin: *`** + **`Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS`**
  + **`Access-Control-Allow-Headers: Content-Type, Accept, Authorization, Mcp-Session-Id, X-Royal-MCP-API-Key`**
  on OPTIONS preflight.

- **`Allow: POST, DELETE, OPTIONS`** on the authenticated 405 (GET when SSE
  unavailable) — tells clients which methods *are* allowed.

---

## Process: how to evolve this contract safely

1. Find the cell in the matrix you're considering changing.
2. Identify the specific client(s) whose behavior depends on that cell. Cite
   their source/docs in the PR.
3. Re-verify against the current MCP spec version (the spec evolves —
   `2025-11-25` is the most recent published version).
4. Update this matrix in the SAME commit as the code change. Bump the
   "Last verified" date.
5. Manually exercise the updated cell with a real client (Claude.ai web is the
   most representative — it does the most aggressive probing).
6. After ship, monitor support-forum traffic for ~7 days for regressions before
   declaring the change stable.

If you're not willing to do step 5, you don't have evidence the change works
and shouldn't ship it.

---

## Per-host compatibility matrix

Different WordPress hosts intercept, cache, or rewrite REST API requests in
ways that break MCP clients silently. This table captures known behaviors.

| Host | Known issue | Royal MCP fix | Customer-side action |
|------|-------------|----------------|----------------------|
| **SiteGround** | nginx blocks `/.well-known/` paths at server level (returns 404 before WP sees the request). Static workaround files served as `text/plain` rather than `application/json`. | Admin notice (`Well_Known_Notice` class) detects the block and surfaces manual fix. 1.4.15 candidate: notice for the Content-Type variant. | Place static `.well-known/` files OR add `<FilesMatch>` block in `.htaccess`. SiteGround support ticket for nginx Content-Type override. |
| **o2switch (PowerBoost)** | Aggressive edge cache poisons OAuth endpoint responses — caches a 405 from a stale GET probe and serves it to subsequent valid POSTs. | 1.4.13 added `Cache-Control: no-store` to OAuth endpoints. 1.4.15 extended to MCP endpoint + REST_Controller routes (audit-discovered gap). | URL exclusion for `/wp-json/royal-mcp/*` in PowerBoost cache rules if `no-store` is ignored. |
| **Hostinger** | Some configs intercept `/.well-known/` similar to SiteGround. | Same as SiteGround — admin notice. | Same — static file workaround. |
| **Cloudflare** (any underlying host) | Bot Fight Mode rejects scripted scanner requests. Edge cache may URL-key responses that lack `Cache-Control: no-store`. | 1.4.15 ships global `rest_post_dispatch` filter forcing `no-store` on every `/royal-mcp/*` response. | Page Rules: bypass cache for `/wp-json/royal-mcp/*`. Disable Bot Fight Mode for the MCP path if false-positives. |
| **Cloudflare APO** (Automatic Platform Optimization) | Caches WP responses including REST API. | Same `no-store` filter — APO honors it. | Disable APO if responses still cached after 1.4.15. |
| **LiteSpeed (web server)** | Default cache configuration may cache REST endpoints. | Same `no-store` filter. | LiteSpeed Cache plugin: exclude `/wp-json/royal-mcp/` from cache rules. |
| **Plain Apache + mod_security** | Some default rule sets reject Authorization header on certain paths. | None — server-side issue. | `SecRuleRemoveById <id>` for the offending rule. |

If a customer reports "MCP works in Claude Desktop but not Claude.ai web (or
vice versa)", the smoking gun is almost always one of the rows above —
specifically the cells where a host's edge cache or path-rewrite layer affects
GET /wp-json/royal-mcp/v1/mcp differently than POST.

---

## Per-MCP-client compatibility matrix

Different MCP clients probe the endpoint in different ways. A change that
fixes one can silently break another. Test against ALL of these before
shipping.

| Client | Transport | First probe | Auth method | Failure mode if broken |
|--------|-----------|-------------|-------------|------------------------|
| **Claude.ai web** (web-based connector) | Streamable HTTP | `GET /.well-known/oauth-protected-resource` (RFC 9728), then `GET /mcp` for OAuth discovery | OAuth 2.0 Bearer token | "Couldn't reach the MCP server" — silently fails when 401+WWW-Authenticate is missing or has wrong format |
| **Claude Desktop** (via mcp-remote npx bridge) | Streamable HTTP after fallback negotiation | `GET /mcp` for SSE — falls back to POST-only on 405 | OAuth flow OR `--header X-Royal-MCP-API-Key` | Retry storm if 405 missing `Allow` header. Multiple npx processes if session expires. |
| **ChatGPT** (custom MCP connector) | Streamable HTTP | RFC 9728 discovery (same as Claude.ai web) | OAuth 2.0 Bearer token | Similar to Claude.ai web — fails on missing WWW-Authenticate |
| **Cursor** | Streamable HTTP | POST initialize directly | API key in header (or OAuth) | "Connection failed" with no detail. Less robust than Claude clients. |
| **Cline** (VS Code) | Streamable HTTP | POST initialize | API key | Same as Cursor |
| **Custom mcp-remote** (`--header` mode) | Streamable HTTP, no OAuth | POST initialize | Whatever header the user puts in `--header` flag | Works as long as auth header reaches the server. Bypasses OAuth entirely — useful for hosts where OAuth keeps failing. |
| **Direct curl / programmatic** (Royal Plugins audit script, custom integrations) | Streamable HTTP | Whatever the script sends | API key OR Bearer token | Tests can be run against this — see `_dev/audit.py` |

**Audit invariant:** any change to MCP response codes, headers, or auth flow
must be re-tested against AT LEAST Claude.ai web AND Claude Desktop +
mcp-remote. These two clients have the most divergent probing patterns; if
a change works for both, it'll usually work for the others.

---

## Cross-references

- `AUDIT_1.4.15.md` — what the 2026-05-09 audit found and how each was fixed.
- `audit.py` — runnable behavioral test suite. Pre-ship gate.
- `pre-ship.sh` — wraps security-scanner, audit-plugin, and the behavioral
  audit into a single refuse-to-exit-0 gate before any release.
- `gotcha_oauth_cache_poisoning.md` (memory) — the OAuth-side instance of the
  same root cause that bug 4 found on the MCP side.
- `gotcha_royal_mcp_endpoint_response_codes.md` (memory) — the 1.4.12/1.4.13
  whack-a-mole that motivated this matrix existing in the first place.
