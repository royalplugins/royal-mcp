/**
 * Royal MCP — WebMCP bootstrap shim.
 *
 * Injects an X-WP-Nonce header into every same-origin fetch that targets the
 * Royal MCP endpoint at /mcp. The Cloudflare WebMCP bridge posts to /mcp with
 * cookies but does not send a WordPress nonce, so without this shim every
 * bridge-driven request would be rejected as a CSRF risk. Enqueued only when
 * an admin has flipped the "Enable browser agents (WebMCP)" toggle AND the
 * current visitor is logged in — anonymous page loads never see this script.
 */
( function () {
    if ( typeof window === 'undefined' || typeof window.fetch !== 'function' ) {
        return;
    }
    var config = window.royalMcpWebMcp || {};
    var nonce = config.nonce;
    if ( ! nonce ) {
        return;
    }

    var originalFetch = window.fetch.bind( window );

    window.fetch = function ( input, init ) {
        var url = '';
        if ( typeof input === 'string' ) {
            url = input;
        } else if ( input && typeof input.url === 'string' ) {
            url = input.url;
        }

        // Only touch our /mcp endpoint. Anything else (theme fetches, other
        // plugins, third-party APIs) passes through untouched.
        if ( url && /(?:^|\/)mcp(?:\/|\?|$)/.test( url ) ) {
            init = init || {};
            var headers = new Headers( init.headers || {} );
            if ( ! headers.has( 'X-WP-Nonce' ) ) {
                headers.set( 'X-WP-Nonce', nonce );
            }
            init.headers = headers;
        }

        return originalFetch( input, init );
    };
} )();
