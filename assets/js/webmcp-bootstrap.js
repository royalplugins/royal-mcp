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

    // True only for this site's own origin with an mcp path segment. Relative
    // URLs resolve against the page; any other origin is left alone.
    function isOwnMcpUrl( url ) {
        var parsed;
        try {
            parsed = new URL( url, window.location.href );
        } catch ( e ) {
            return false;
        }
        if ( parsed.origin !== window.location.origin ) {
            return false;
        }
        var search = parsed.search;
        try {
            search = decodeURIComponent( search );
        } catch ( e ) {
            // Keep the raw query when it is not valid percent-encoding.
        }
        return /(?:^|\/)mcp(?:\/|\?|&|$)/.test( parsed.pathname ) || /(?:^|\/)mcp(?:\/|&|$)/.test( search );
    }

    window.fetch = function ( input, init ) {
        var url = '';
        var isRequest = typeof Request !== 'undefined' && input instanceof Request;
        if ( typeof input === 'string' ) {
            url = input;
        } else if ( isRequest ) {
            url = input.url;
        } else if ( input && typeof input.href === 'string' ) {
            url = input.href;
        }

        // Only touch our /mcp endpoint. Anything else (theme fetches, other
        // plugins, third-party APIs) passes through untouched.
        if ( url && isOwnMcpUrl( url ) ) {
            // A copy, so the caller's own options object never carries the nonce elsewhere.
            init = Object.assign( {}, init || {} );
            // Headers given in init replace a Request's own headers, so start
            // from the Request's headers and lay init's headers over them.
            var headers = new Headers( isRequest ? input.headers : undefined );
            if ( init.headers ) {
                new Headers( init.headers ).forEach( function ( value, name ) {
                    headers.set( name, value );
                } );
            }
            if ( ! headers.has( 'X-WP-Nonce' ) ) {
                headers.set( 'X-WP-Nonce', nonce );
            }
            init.headers = headers;
        }

        return originalFetch( input, init );
    };
} )();
