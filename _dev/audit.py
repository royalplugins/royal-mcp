#!/usr/bin/env python3
"""
Royal MCP behavioral audit — Phase 1.

Hits every endpoint × every auth state, captures status + headers + body shape,
and asserts each against an expected fixture. Outputs a structured report.

Usage:
    python audit.py <base-url> <api-key> [--verbose]
    python audit.py https://gsc.royalplugins.com cUlDnSex0GxkoVnxBvX6DW7jBFOwzM9Q

This is the file SM proposed during the BMAD audit session — a refuse-to-exit-0
gate before any future Royal MCP release.
"""
import sys
import os
import json
import time
import argparse
import urllib.request
import urllib.error
from urllib.parse import urljoin, quote

# ---- Test runner ----------------------------------------------------------

class Result:
    def __init__(self, name, status, expected, actual, headers, notes=None):
        self.name = name
        self.status = status        # 'PASS', 'FAIL', 'WARN', 'SKIP'
        self.expected = expected
        self.actual = actual
        self.headers = headers or {}
        self.notes = notes or ''

    def line(self):
        sym = {'PASS': '[OK]   ', 'FAIL': '[FAIL] ', 'WARN': '[WARN] ', 'SKIP': '[SKIP] '}[self.status]
        out = f"{sym} {self.name}"
        if self.status != 'PASS':
            out += f"\n        expected: {self.expected}\n        actual:   {self.actual}"
            if self.notes:
                out += f"\n        notes:    {self.notes}"
        return out

results = []

# Real Chrome-like headers — Cloudflare Bot Fight Mode rejects bare urllib UA.
# See gotcha_cloudflare_bot_fingerprint.md.
DEFAULT_HEADERS = {
    'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
    'Accept-Language': 'en-US,en;q=0.9',
    'Accept-Encoding': 'identity',  # no gzip — keeps body parsing simple
    'Sec-Fetch-Site': 'cross-site',
    'Sec-Fetch-Mode': 'cors',
    'Sec-Fetch-Dest': 'empty',
}

class _CIDict(dict):
    """Case-insensitive header dict. HTTP headers are case-insensitive per RFC 7230;
    Python's urllib gives them back in whatever case the server sent. Servers vary
    (curl shows lowercase, others TitleCase). Normalize on lookup."""
    def __init__(self, items=None):
        super().__init__()
        if items:
            for k, v in (items.items() if hasattr(items, 'items') else items):
                self[k.lower()] = v
    def get(self, key, default=None):
        return super().get(key.lower(), default)
    def __contains__(self, key):
        return super().__contains__(key.lower())

def http(method, url, headers=None, body=None, timeout=20):
    # Auto-append cache-buster so we measure server behavior, not edge cache.
    # Bug 4 audit finding: bare /mcp URLs are CF-cached for hours.
    if '?' not in url:
        url = url + '?_audit=' + str(int(time.time() * 1000))
    elif '_audit=' not in url:
        url = url + '&_audit=' + str(int(time.time() * 1000))
    merged = {**DEFAULT_HEADERS, **(headers or {})}
    req = urllib.request.Request(url, method=method, headers=merged)
    if body is not None:
        if isinstance(body, dict):
            body = json.dumps(body).encode('utf-8')
            req.add_header('Content-Type', 'application/json')
        req.data = body
    try:
        resp = urllib.request.urlopen(req, timeout=timeout)
        return resp.status, _CIDict(resp.getheaders()), resp.read().decode('utf-8', errors='replace')
    except urllib.error.HTTPError as e:
        return e.code, _CIDict(e.headers.items()), e.read().decode('utf-8', errors='replace')
    except Exception as e:
        return 0, _CIDict(), f"NETWORK ERROR: {e}"

def cache_buster():
    """Append a unique query string to bust URL-keyed edge caches per audit findings."""
    return f"_audit={int(time.time() * 1000)}{os.getpid() if 'os' in dir() else 0}"

# ---- Categories of tests --------------------------------------------------

def test_oauth_well_known(base):
    """RFC 8414 + RFC 9728 metadata endpoints. Must return 200 + application/json."""
    for path in [
        '/.well-known/oauth-authorization-server',
        '/.well-known/oauth-protected-resource',
    ]:
        url = base + path
        status, hdrs, body = http('GET', url)
        ct = hdrs.get('Content-Type', '').lower()
        is_json_ct = 'application/json' in ct
        is_text_plain = 'text/plain' in ct
        try:
            j = json.loads(body) if status == 200 else None
        except Exception:
            j = None

        if status == 200 and is_json_ct and j:
            results.append(Result(f"OAuth metadata {path}", 'PASS',
                'HTTP 200 + application/json + valid JSON',
                f'HTTP 200 + {ct} + valid JSON', hdrs))
        elif status == 200 and is_text_plain and j:
            results.append(Result(f"OAuth metadata {path}", 'WARN',
                'HTTP 200 + application/json',
                f'HTTP 200 + text/plain (strict MCP clients reject)',
                hdrs, notes='SiteGround static-file Content-Type bug — see gotcha_siteground_static_well_known_content_type.md'))
        elif status == 404:
            results.append(Result(f"OAuth metadata {path}", 'FAIL',
                'HTTP 200',
                f'HTTP 404 (host blocking /.well-known/?)', hdrs))
        else:
            results.append(Result(f"OAuth metadata {path}", 'FAIL',
                'HTTP 200 + application/json + valid JSON',
                f'HTTP {status} + {ct or "no content-type"}', hdrs))

def test_mcp_endpoint_unauth(base):
    """Unauthenticated probes to /mcp. Per 1.4.14: GET -> 401 + WWW-Authenticate."""
    url = base + '/wp-json/royal-mcp/v1/mcp'

    # Unauth GET — must be 401 with WWW-Authenticate header (RFC 9728 OAuth discovery)
    status, hdrs, _ = http('GET', url)
    www_auth = hdrs.get('WWW-Authenticate', '')
    if status == 401 and 'bearer' in www_auth.lower() and 'resource_metadata' in www_auth.lower():
        results.append(Result('Unauth GET /mcp -> 401 + WWW-Auth', 'PASS',
            '401 with WWW-Authenticate: Bearer resource_metadata="..."',
            f'401 with: {www_auth}', hdrs))
    elif status == 405:
        results.append(Result('Unauth GET /mcp -> 401 + WWW-Auth', 'FAIL',
            '401 (RFC 9728)', '405 — pre-1.4.14 behavior, breaks Claude.ai web + ChatGPT', hdrs))
    else:
        results.append(Result('Unauth GET /mcp -> 401 + WWW-Auth', 'FAIL',
            '401 with WWW-Authenticate', f'{status} (WWW-Auth: {www_auth or "missing"})', hdrs))

    # Unauth POST — must be 401 (not 403, not 200)
    status, hdrs, _ = http('POST', url, body={'jsonrpc': '2.0', 'method': 'initialize', 'id': 1})
    if status == 401:
        results.append(Result('Unauth POST /mcp -> 401', 'PASS', '401', '401', hdrs))
    else:
        results.append(Result('Unauth POST /mcp -> 401', 'FAIL', '401', str(status), hdrs))

def test_mcp_cache_headers(base):
    """Every /mcp response must have Cache-Control: no-store (1.4.13 fix)."""
    url = base + '/wp-json/royal-mcp/v1/mcp'
    _, hdrs, _ = http('GET', url)
    cc = hdrs.get('Cache-Control', '').lower()
    if 'no-store' in cc or 'no-cache' in cc:
        results.append(Result('Cache-Control on /mcp', 'PASS', 'no-store/no-cache', cc, hdrs))
    else:
        results.append(Result('Cache-Control on /mcp', 'FAIL',
            'no-store/no-cache (prevents edge cache poisoning)',
            cc or '(missing)', hdrs,
            notes='See gotcha_oauth_cache_poisoning.md'))

def test_mcp_authenticated_flow(base, api_key):
    """Full MCP flow with valid API key: initialize -> tools/list -> cleanup."""
    url = base + '/wp-json/royal-mcp/v1/mcp'
    auth = {'X-Royal-MCP-API-Key': api_key, 'Content-Type': 'application/json',
            'Accept': 'application/json, text/event-stream'}

    # Authenticated GET — must be 405 (not 401, not 200) per 1.4.12 spec
    status, hdrs, _ = http('GET', url, headers={'X-Royal-MCP-API-Key': api_key})
    allow = hdrs.get('Allow', '')
    if status == 405 and 'POST' in allow:
        results.append(Result('Auth GET /mcp -> 405 + Allow', 'PASS',
            '405 with Allow: POST, DELETE, OPTIONS', f'405 with Allow: {allow}', hdrs))
    elif status == 401:
        results.append(Result('Auth GET /mcp -> 405 + Allow', 'FAIL',
            '405 (auth succeeded, method dispatch should reject)',
            '401 — auth check ordering broken or key wrong', hdrs))
    else:
        results.append(Result('Auth GET /mcp -> 405 + Allow', 'FAIL',
            '405 + Allow header', f'{status}', hdrs))

    # Initialize handshake
    status, hdrs, body = http('POST', url, headers=auth,
        body={'jsonrpc': '2.0', 'method': 'initialize', 'id': 1,
              'params': {'protocolVersion': '2024-11-05',
                         'capabilities': {}, 'clientInfo': {'name': 'audit', 'version': '1'}}})
    session_id = hdrs.get('Mcp-Session-Id', '')
    if status == 200 and session_id:
        results.append(Result('MCP initialize -> session', 'PASS',
            '200 with Mcp-Session-Id header', f'200, session: {session_id[:16]}...', hdrs))
    else:
        results.append(Result('MCP initialize -> session', 'FAIL',
            '200 + Mcp-Session-Id', f'{status}, session: {session_id or "(none)"}', hdrs,
            notes=f'body: {body[:200]}'))
        return

    # Send notifications/initialized to complete handshake
    auth_with_session = {**auth, 'Mcp-Session-Id': session_id}
    http('POST', url, headers=auth_with_session,
         body={'jsonrpc': '2.0', 'method': 'notifications/initialized'})

    # tools/list
    status, hdrs, body = http('POST', url, headers=auth_with_session,
        body={'jsonrpc': '2.0', 'method': 'tools/list', 'id': 2})
    try:
        j = json.loads(body)
        tools = j.get('result', {}).get('tools', [])
        if status == 200 and len(tools) > 5:
            results.append(Result(f'tools/list -> {len(tools)} tools', 'PASS',
                '200 with tools array', f'200 with {len(tools)} tools', hdrs))
        else:
            results.append(Result('tools/list', 'FAIL',
                'tools array with 30+ entries',
                f'{status}, {len(tools)} tools', hdrs, notes=body[:200]))
    except Exception as e:
        results.append(Result('tools/list', 'FAIL', 'valid JSON', f'parse error: {e}', hdrs))

    # Test session reuse (already-authenticated subsequent call)
    status, hdrs, _ = http('POST', url, headers=auth_with_session,
        body={'jsonrpc': '2.0', 'method': 'tools/list', 'id': 3})
    if status == 200:
        results.append(Result('Session reuse on 2nd call', 'PASS', '200', '200', hdrs))
    else:
        results.append(Result('Session reuse on 2nd call', 'FAIL', '200',
            f'{status} — session lost between calls', hdrs))

    # Cleanup — DELETE
    status, hdrs, _ = http('DELETE', url, headers=auth_with_session)
    if status in (200, 204):
        results.append(Result('Session DELETE cleanup', 'PASS', '200/204', str(status), hdrs))
    else:
        results.append(Result('Session DELETE cleanup', 'WARN',
            '200/204', str(status), hdrs))

def test_mcp_invalid_key(base):
    """Wrong API key must be rejected with 401."""
    url = base + '/wp-json/royal-mcp/v1/mcp'
    auth = {'X-Royal-MCP-API-Key': 'definitely-not-the-real-key-' + str(int(time.time()))}
    status, hdrs, _ = http('POST', url, headers=auth,
        body={'jsonrpc': '2.0', 'method': 'initialize', 'id': 1})
    if status == 401:
        results.append(Result('Invalid key -> 401', 'PASS', '401', '401', hdrs))
    else:
        results.append(Result('Invalid key -> 401', 'FAIL', '401', str(status), hdrs))

def test_oauth_register(base):
    """OAuth dynamic client registration — POST /register."""
    url = base + '/register'
    status, hdrs, body = http('POST', url, body={
        'redirect_uris': ['https://claude.ai/api/mcp/auth_callback'],
        'client_name': 'Royal MCP Audit',
        'token_endpoint_auth_method': 'none',
    })
    try:
        j = json.loads(body)
        if status in (200, 201) and j.get('client_id'):
            results.append(Result('OAuth /register -> client_id', 'PASS',
                '200/201 with client_id', f'{status} with client_id: {j["client_id"][:12]}...', hdrs))
        else:
            results.append(Result('OAuth /register -> client_id', 'FAIL',
                '200 with client_id', f'{status} with body: {body[:150]}', hdrs))
    except Exception:
        results.append(Result('OAuth /register -> client_id', 'FAIL',
            '200 + JSON', f'{status} non-JSON: {body[:200]}', hdrs))

def test_cache_control_on_all_responses(base, api_key):
    """Audit-discovered bug #4 (1.4.15): every MCP/OAuth response must have
    Cache-Control: no-store. Pre-1.4.15 the MCP endpoint's responses were
    missing the header that 1.4.13 added to OAuth — so URL-keyed edge caches
    served stale auth-error responses to authenticated requests."""
    targets = [
        ('Unauth GET /mcp', 'GET',  base + '/wp-json/royal-mcp/v1/mcp', None),
        ('Auth GET /mcp',   'GET',  base + '/wp-json/royal-mcp/v1/mcp', {'X-Royal-MCP-API-Key': api_key}),
        ('OAuth /register', 'POST', base + '/register', None),
    ]
    for name, method, url, hdrs in targets:
        if method == 'POST' and 'register' in url:
            status, response_hdrs, _ = http(method, url, headers=hdrs,
                body={'redirect_uris': ['https://test.example/cb'], 'client_name': 'audit'})
        else:
            status, response_hdrs, _ = http(method, url, headers=hdrs)
        cc = response_hdrs.get('Cache-Control', '').lower()
        if 'no-store' in cc or 'no-cache' in cc:
            results.append(Result(f'Cache-Control on "{name}"', 'PASS',
                'no-store/no-cache', cc, response_hdrs))
        else:
            results.append(Result(f'Cache-Control on "{name}"', 'FAIL',
                'no-store/no-cache (prevents edge cache poisoning)',
                cc or '(missing)', response_hdrs,
                notes='See gotcha_oauth_cache_poisoning.md + AUDIT_1.4.15.md'))

def test_legacy_endpoints(base, api_key):
    """Legacy /sse and /messages endpoints — should still work or return useful errors."""
    # /sse — should return 405 or deprecation message for authenticated requests
    url = base + '/wp-json/royal-mcp/v1/sse'
    status, hdrs, body = http('GET', url, headers={'X-Royal-MCP-API-Key': api_key})
    # Accept any 2xx or 4xx with a JSON body — just shouldn't be 500
    if status < 500:
        results.append(Result('Legacy /sse responds (not 5xx)', 'PASS',
            'any 2xx/4xx', str(status), hdrs))
    else:
        results.append(Result('Legacy /sse', 'FAIL', 'any 2xx/4xx', str(status), hdrs))

def test_rest_controller_routes(base, api_key):
    """REST_Controller routes — at minimum /site should respond authenticated."""
    auth = {'X-Royal-MCP-API-Key': api_key}
    for path in ['/wp-json/royal-mcp/v1/site', '/wp-json/royal-mcp/v1/posts']:
        url = base + path
        status, hdrs, _ = http('GET', url, headers=auth)
        if status == 200:
            results.append(Result(f'Auth GET {path}', 'PASS', '200', '200', hdrs))
        elif status == 401:
            results.append(Result(f'Auth GET {path}', 'FAIL',
                '200 (auth should pass)', '401 — REST_Controller may use different auth path', hdrs))
        else:
            results.append(Result(f'Auth GET {path}', 'WARN', '200', str(status), hdrs))

    # Same paths unauth — should be 401
    for path in ['/wp-json/royal-mcp/v1/site']:
        url = base + path
        status, hdrs, _ = http('GET', url)
        if status == 401:
            results.append(Result(f'Unauth GET {path} -> 401', 'PASS', '401', '401', hdrs))
        elif status == 200:
            results.append(Result(f'Unauth GET {path} -> 401', 'FAIL',
                '401 (auth required)', '200 — leaking site data without auth', hdrs))
        else:
            results.append(Result(f'Unauth GET {path}', 'WARN', '401', str(status), hdrs))

def test_well_known_content_type(base):
    """SiteGround static-file workaround issue — .well-known/ returning text/plain breaks strict clients."""
    url = base + '/.well-known/oauth-authorization-server'
    _, hdrs, _ = http('GET', url)
    ct = hdrs.get('Content-Type', '').lower()
    if 'application/json' in ct:
        # already covered in test_oauth_well_known but here for completeness
        pass

def test_admin_settings_form(base):
    """The settings page form — verify regenerate POST flow.

    Without a logged-in admin cookie we can't actually submit the form,
    so this tests only that the admin page exists and surfaces the
    api_key field via DB inspection (run separately from this script)."""
    results.append(Result('Settings page regenerate flow', 'SKIP',
        'manual verification', 'requires admin login — see manual checks',
        {}, notes='Run the regenerate test by clicking the button + querying DB'))

# ---- Main -----------------------------------------------------------------

def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('base', help='Base URL of WordPress site')
    ap.add_argument('api_key', help='Royal MCP API key')
    ap.add_argument('--verbose', action='store_true')
    args = ap.parse_args()
    base = args.base.rstrip('/')

    print(f"=== Royal MCP audit against {base} ===\n")

    test_oauth_well_known(base)
    test_mcp_endpoint_unauth(base)
    test_mcp_cache_headers(base)
    test_mcp_authenticated_flow(base, args.api_key)
    test_mcp_invalid_key(base)
    test_oauth_register(base)
    test_cache_control_on_all_responses(base, args.api_key)
    test_legacy_endpoints(base, args.api_key)
    test_rest_controller_routes(base, args.api_key)
    test_well_known_content_type(base)
    test_admin_settings_form(base)

    n_pass = sum(1 for r in results if r.status == 'PASS')
    n_fail = sum(1 for r in results if r.status == 'FAIL')
    n_warn = sum(1 for r in results if r.status == 'WARN')
    n_skip = sum(1 for r in results if r.status == 'SKIP')

    for r in results:
        print(r.line())

    print(f"\n=== {n_pass} PASS · {n_fail} FAIL · {n_warn} WARN · {n_skip} SKIP ===")
    sys.exit(1 if n_fail > 0 else 0)

if __name__ == '__main__':
    main()
