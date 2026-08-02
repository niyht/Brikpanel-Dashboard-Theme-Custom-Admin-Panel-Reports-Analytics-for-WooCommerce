<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Bot / crawler filtering for BrikPanel's storefront analytics (3.2.30).
 *
 * Every storefront counter BrikPanel keeps — daily visitors, page views,
 * product views, both add-to-cart counters, checkout visits, live visitors
 * and cart abandonment capture — funnels through brikpanel_is_bot_request()
 * before it writes anything. This file is that single decision point.
 *
 * Why it moved here: the previous implementation lived inside the Live
 * Visitors module and matched a short list of tokens (bot, crawler, spider,
 * a handful of named crawlers). That caught Googlebot but let a large slice
 * of real crawler traffic through — Google-InspectionTool, GoogleOther,
 * Google-Shopping, headless Chrome, curl, python-requests and most AI
 * crawlers carry no "bot" token in their user agent at all. On stores that
 * expose WooCommerce's `?add-to-cart=` links in archive markup those
 * crawlers trigger a real server-side add-to-cart event per product they
 * follow, which is how a single crawl turns into thousands of recorded
 * add-to-carts and an inflated visitor count.
 *
 * Three layers, cheapest first:
 *   1. Built-in user-agent token list (filterable).
 *   2. Merchant-supplied user-agent fragments and IP / CIDR ranges, set in
 *      WooCommerce ▸ Settings ▸ BrikPanel ▸ Analytics.
 *   3. The `brikpanel_is_bot_request` filter for anything code-level.
 *
 * This is a load-shedding and data-quality heuristic, not a security
 * boundary: a client that spoofs a browser user agent is indistinguishable
 * from a browser at this layer. The cookieless-client throttle in the
 * add-to-cart counters is the backstop for that case.
 */

/**
 * Built-in user-agent fragments treated as automated traffic.
 *
 * Matched case-insensitively as substrings against the raw user agent, so
 * "storebot-google" also covers "Storebot-Google/1.0". Grouped by family to
 * keep the list reviewable.
 *
 * @return string[]
 */
function brikpanel_bot_ua_tokens() {
    $tokens = [
        // Generic self-identifying tokens.
        'bot', 'crawler', 'crawling', 'spider', 'scraper', 'archiver', 'indexer',
        'fetcher', 'validator', 'monitor', 'uptime', 'preview', 'analyzer',

        // Google. Only Googlebot / Storebot / AdsBot carry a "bot" token; the
        // rest of Google's fleet does not, and Storebot-Google is the one that
        // walks the add-to-cart and checkout flow to verify prices.
        'storebot-google', 'google-inspectiontool', 'googleother', 'google-extended',
        'google-shopping', 'google-read-aloud', 'google-site-verification',
        'apis-google', 'feedfetcher-google', 'googleweblight', 'google favicon',
        'googleproducer', 'mediapartners', 'chrome-lighthouse', 'lighthouse',
        'google-cloudvertexbot', 'google-safety', 'google-pagerenderer',

        // Other search engines.
        'slurp', 'yandex', 'baiduspider', 'baidu', 'sogou', 'exabot', 'seznam',
        'bingpreview', 'msnbot', 'duckduckbot', 'qwantify', 'coccoc', 'naver',

        // Apple.
        'applebot',

        // Social / messaging link unfurlers.
        'facebookexternalhit', 'facebookcatalog', 'meta-externalagent',
        'meta-externalfetcher', 'twitterbot', 'linkedinbot', 'slackbot',
        'slack-imgproxy', 'discordbot', 'telegrambot', 'whatsapp', 'skypeuripreview',
        'redditbot', 'pinterest', 'tumblr', 'vkshare', 'embedly', 'quora link preview',
        'flipboard', 'nuzzel', 'outbrain', 'snapchat',

        // SEO / marketing suites.
        'ahrefs', 'semrush', 'mj12', 'majestic', 'dotbot', 'rogerbot', 'blexbot',
        'petalbot', 'dataforseo', 'screaming frog', 'sitebulb', 'seokicks',
        'serpstatbot', 'linkdexbot', 'spyfu', 'similarweb', 'netcraft', 'domcop',
        'zoominfo', 'barkrowler', 'megaindex', 'seostar',

        // AI / LLM crawlers.
        'gptbot', 'chatgpt-user', 'oai-searchbot', 'claudebot', 'claude-web',
        'anthropic-ai', 'perplexitybot', 'perplexity-user', 'ccbot', 'bytespider',
        'amazonbot', 'youbot', 'diffbot', 'omgili', 'timpibot', 'imagesift',
        'cohere-ai', 'ai2bot', 'firecrawl', 'scrapy',

        // Uptime / performance / security scanners.
        'pingdom', 'uptimerobot', 'statuscake', 'newrelic', 'site24x7',
        'datadog', 'gtmetrix', 'webpagetest', 'phantomas', 'zgrab', 'masscan',
        'nmap', 'nuclei', 'wpscan', 'sucuri', 'detectify', 'qualys',

        // Headless browsers and HTTP libraries. A real shopper never sends
        // these; anything that does is a script.
        'headlesschrome', 'phantomjs', 'puppeteer', 'playwright', 'selenium',
        'curl/', 'wget', 'libwww-perl', 'lwp-', 'python-requests',
        'python-urllib', 'aiohttp', 'httpx', 'go-http-client', 'okhttp',
        'java/', 'apache-httpclient', 'guzzlehttp', 'axios/', 'node-fetch',
        'restsharp', 'postmanruntime', 'insomnia', 'httpie',
        'ruby', 'php/', 'dart/', 'winhttp', 'http_request2', 'zabbix',

        // WordPress / WooCommerce internal callers.
        'wordpress/', 'woocommerce/', 'wp-android', 'wp-iphone', 'jetpack',
    ];

    /**
     * Filter the built-in list of user-agent fragments treated as bots.
     *
     * Fragments are matched case-insensitively as substrings. Merchants can
     * add their own from the Analytics settings screen without touching this;
     * use the filter for code-level control (for example to *remove* a token
     * that clashes with a legitimate client on your store).
     *
     * @param string[] $tokens Lower-case user-agent fragments.
     */
    return (array) apply_filters( 'brikpanel_bot_ua_tokens', $tokens );
}

/**
 * User-agent fragments that must never be treated as bots, checked before
 * the token list.
 *
 * The generic "bot" token is matched as a substring so it covers Googlebot,
 * AdsBot-Google, Storebot-Google and the long tail of crawlers that name
 * themselves *bot. That substring also lands inside a few real device names
 * — CUBOT is a phone brand — and a false positive here silently discards a
 * real shopper's data, which is worse than letting one crawler through.
 *
 * @return string[]
 */
function brikpanel_bot_ua_allowlist() {
    /**
     * Filter the user-agent fragments exempted from bot detection.
     *
     * @param string[] $allow Lower-case fragments; a match wins over every token.
     */
    return (array) apply_filters( 'brikpanel_bot_ua_allowlist', [ 'cubot' ] );
}

/**
 * Merchant-supplied user-agent fragments from the Analytics settings screen.
 *
 * Stored as free text, one fragment per line. Parsed defensively: blank
 * lines dropped, everything lower-cased, capped so a pathological paste
 * cannot turn every page view into a thousand string comparisons.
 *
 * @return string[]
 */
function brikpanel_custom_bot_ua_tokens() {
    $raw = (string) get_option( 'brikpanel_excluded_user_agents', '' );
    if ( '' === trim( $raw ) ) {
        return [];
    }

    $tokens = [];
    foreach ( preg_split( '/[\r\n]+/', $raw ) as $line ) {
        $line = strtolower( trim( $line ) );
        if ( '' !== $line ) {
            $tokens[] = $line;
        }
    }

    return array_slice( array_unique( $tokens ), 0, 200 );
}

/**
 * Merchant-supplied IP addresses / ranges from the Analytics settings screen.
 *
 * Accepts one entry per line: a plain IPv4 / IPv6 address, or CIDR notation
 * (`66.249.64.0/19`). Useful for office traffic and for crawlers that hide
 * behind a browser user agent but come from a known range.
 *
 * @return string[]
 */
function brikpanel_excluded_ip_rules() {
    $raw = (string) get_option( 'brikpanel_excluded_ips', '' );
    if ( '' === trim( $raw ) ) {
        return [];
    }

    $rules = [];
    foreach ( preg_split( '/[\r\n,]+/', $raw ) as $line ) {
        $line = trim( $line );
        if ( '' !== $line ) {
            $rules[] = $line;
        }
    }

    return array_slice( array_unique( $rules ), 0, 200 );
}

/**
 * Current visitor's IP address.
 *
 * Defers to WooCommerce's own resolver when available so proxy handling
 * matches whatever the store is already doing for geolocation and order
 * records, instead of introducing a second, differently-spoofable source.
 *
 * @return string Empty string when the address cannot be determined.
 */
function brikpanel_client_ip() {
    if ( class_exists( 'WC_Geolocation' ) ) {
        $ip = WC_Geolocation::get_ip_address();
        if ( is_string( $ip ) && '' !== $ip ) {
            return $ip;
        }
    }

    return isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';
}

/**
 * Whether an IP address falls inside a CIDR range.
 *
 * Handles IPv4 and IPv6 by comparing the leading $bits bits of the packed
 * binary form, so no 32-bit integer overflow and no family-specific branches
 * beyond the length check.
 *
 * @param string $ip    Address to test.
 * @param string $cidr  Range in `address/bits` form.
 * @return bool
 */
function brikpanel_ip_in_cidr( $ip, $cidr ) {
    if ( false === strpos( $cidr, '/' ) ) {
        return false;
    }

    list( $subnet, $bits ) = explode( '/', $cidr, 2 );
    $bits = (int) $bits;

    $ip_bin     = @inet_pton( trim( $ip ) );
    $subnet_bin = @inet_pton( trim( $subnet ) );

    // Different families (v4 vs v6) never match, and an unparsable address
    // must never be treated as "inside the range".
    if ( false === $ip_bin || false === $subnet_bin || strlen( $ip_bin ) !== strlen( $subnet_bin ) ) {
        return false;
    }

    $max_bits = strlen( $ip_bin ) * 8;
    if ( $bits < 0 || $bits > $max_bits ) {
        return false;
    }
    if ( 0 === $bits ) {
        return true;
    }

    $whole_bytes = intdiv( $bits, 8 );
    if ( $whole_bytes > 0 && substr( $ip_bin, 0, $whole_bytes ) !== substr( $subnet_bin, 0, $whole_bytes ) ) {
        return false;
    }

    $remainder = $bits % 8;
    if ( 0 === $remainder ) {
        return true;
    }

    $mask = ~( ( 1 << ( 8 - $remainder ) ) - 1 ) & 0xFF;

    return ( ord( $ip_bin[ $whole_bytes ] ) & $mask ) === ( ord( $subnet_bin[ $whole_bytes ] ) & $mask );
}

/**
 * Whether the current request should be excluded from storefront analytics.
 *
 * Decided once per request and cached in a static, because the same request
 * can hit several counters (a page view plus a product view plus a live
 * ping) and re-running the token scan for each is pure waste.
 *
 * @return bool True when the request is automated traffic or an excluded IP.
 */
function brikpanel_is_bot_request() {
    static $decision = null;
    if ( null !== $decision ) {
        return $decision;
    }

    $ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? strtolower( (string) $_SERVER['HTTP_USER_AGENT'] ) : '';

    // No user agent at all: never a real browser.
    $is_bot = ( '' === trim( $ua ) );

    // An allowlisted device name exempts the request from the built-in token
    // list only. The merchant's own exclusions below still apply: someone who
    // adds their office IP means it regardless of what they browse from.
    $allowlisted = false;
    if ( ! $is_bot ) {
        foreach ( brikpanel_bot_ua_allowlist() as $allowed ) {
            if ( '' !== $allowed && false !== strpos( $ua, strtolower( $allowed ) ) ) {
                $allowlisted = true;
                break;
            }
        }
    }

    if ( ! $is_bot && ! $allowlisted ) {
        foreach ( brikpanel_bot_ua_tokens() as $token ) {
            if ( '' !== $token && false !== strpos( $ua, strtolower( $token ) ) ) {
                $is_bot = true;
                break;
            }
        }
    }

    if ( ! $is_bot ) {
        foreach ( brikpanel_custom_bot_ua_tokens() as $token ) {
            if ( false !== strpos( $ua, $token ) ) {
                $is_bot = true;
                break;
            }
        }
    }

    if ( ! $is_bot ) {
        $rules = brikpanel_excluded_ip_rules();
        if ( ! empty( $rules ) ) {
            $ip = brikpanel_client_ip();
            if ( '' !== $ip ) {
                foreach ( $rules as $rule ) {
                    if ( false !== strpos( $rule, '/' ) ) {
                        if ( brikpanel_ip_in_cidr( $ip, $rule ) ) {
                            $is_bot = true;
                            break;
                        }
                    } elseif ( 0 === strcasecmp( trim( $rule ), $ip ) ) {
                        $is_bot = true;
                        break;
                    }
                }
            }
        }
    }

    /**
     * Filter the final bot decision for the current request.
     *
     * @param bool   $is_bot Whether BrikPanel will skip analytics for this request.
     * @param string $ua     Lower-cased user agent (empty when not sent).
     */
    $decision = (bool) apply_filters( 'brikpanel_is_bot_request', $is_bot, $ua );

    return $decision;
}

/**
 * Back-compat alias.
 *
 * Kept because every tracker in the plugin calls this name, and third-party
 * code may too. New code should call brikpanel_is_bot_request().
 *
 * @return bool
 */
function _brikpanel_is_bot_ua() {
    return brikpanel_is_bot_request();
}

/**
 * One-per-day gate for clients that do not keep cookies.
 *
 * The add-to-cart counters cap a normal shopper at one recorded event per
 * day using a cookie. A crawler discards cookies, so before this gate every
 * single `?add-to-cart=` URL it followed counted as another add-to-cart —
 * which is what lets one crawl outweigh a month of real shoppers even when
 * its user agent is unknown to the token list above.
 *
 * This applies the same daily cap to cookieless clients, keyed on a hashed
 * address + user agent instead of a cookie.
 *
 * Real shoppers are barely touched by it. A returning visitor carries
 * cookies, so the gate never runs for them at all; only a brand-new visitor
 * whose very first request to the store is the add-to-cart itself arrives
 * cookieless, and that one is counted. Including the user agent in the key
 * is what keeps two different shoppers behind one office IP from cancelling
 * each other out — they browse from different devices, a crawler does not.
 *
 * @param string $bucket Namespace so separate counters do not share a gate.
 * @return bool True when the caller may record; false when already recorded today.
 */
function brikpanel_cookieless_daily_gate( $bucket ) {
    // Any cookie at all means the client keeps state; the caller's own
    // cookie check already governs it.
    //
    // Read the raw request header rather than $_COOKIE: WooCommerce starts a
    // customer session during add-to-cart and writes the new cookie straight
    // into $_COOKIE so the rest of the request can see it, which would make
    // every client look like it had sent cookies. $_SERVER['HTTP_COOKIE'] is
    // what the client actually sent and is never rewritten.
    $sent_cookies = isset( $_SERVER['HTTP_COOKIE'] ) ? trim( (string) $_SERVER['HTTP_COOKIE'] ) : '';
    if ( '' !== $sent_cookies ) {
        return true;
    }

    $ip = brikpanel_client_ip();
    if ( '' === $ip ) {
        // Unidentifiable and cookieless: nothing to rate-limit against, and
        // counting it would be the exact inflation this gate exists to stop.
        return false;
    }

    $ua  = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';
    $key = 'bp_cg_' . substr( hash_hmac( 'sha256', $bucket . '|' . $ip . '|' . $ua, wp_salt( 'brikpanel_bot_gate' ) ), 0, 24 );
    if ( get_transient( $key ) ) {
        return false;
    }

    $seconds_until_midnight = strtotime( 'tomorrow', current_time( 'timestamp' ) ) - current_time( 'timestamp' );
    set_transient( $key, 1, max( 60, $seconds_until_midnight ) );

    return true;
}
