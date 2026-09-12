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
 * Whether the current request is a speculative fetch rather than a person
 * opening the page.
 *
 * Browsers and link-preload plugins request URLs ahead of the click so the
 * next navigation feels instant. Nobody is looking at the result, and for a
 * WooCommerce store it is worse than a wasted hit: the archive templates
 * render "Add to cart" as plain `?add-to-cart=123` links, and WooCommerce
 * fills the cart for real when a preloader follows one. Left uncounted for,
 * a single preloader walking a category page produces one add-to-cart per
 * product listed on it, which is how a handful of products end up with
 * near-identical cart totals that outrank their own page views.
 *
 * Covers every mechanism in current use: the Speculation Rules API and
 * Chrome/Safari link prefetch (`Sec-Purpose`), the older Chrome/Edge and
 * preload-plugin convention (`Purpose` / `X-Purpose`), and Firefox
 * (`X-Moz: prefetch`).
 *
 * IMPORTANT — only ever use this to decide whether to RECORD something, never
 * to decide whether to PRINT something. A prefetched response is not thrown
 * away: the browser serves that exact HTML when the visitor then clicks the
 * link, and a page cache may store it for everyone who follows. Markup left
 * out here disappears from the page the visitor actually sees, which would
 * turn "do not count prefetches" into "stop counting altogether". Callers that
 * emit markup ask brikpanel_is_bot_request( false ) instead.
 *
 * @return bool True when the request was issued speculatively.
 */
function brikpanel_is_speculative_request() {
    static $speculative = null;
    if ( null !== $speculative ) {
        return $speculative;
    }

    $speculative = false;

    foreach ( [ 'HTTP_SEC_PURPOSE', 'HTTP_PURPOSE', 'HTTP_X_PURPOSE', 'HTTP_X_MOZ' ] as $header ) {
        if ( ! isset( $_SERVER[ $header ] ) ) {
            continue;
        }
        $value = strtolower( (string) $_SERVER[ $header ] );
        if ( false !== strpos( $value, 'prefetch' )
            || false !== strpos( $value, 'prerender' )
            || false !== strpos( $value, 'preview' ) ) {
            $speculative = true;
            break;
        }
    }

    return $speculative;
}

/**
 * Whether the current request should be excluded from storefront analytics.
 *
 * Decided once per request and cached in a static, because the same request
 * can hit several counters (a page view plus a product view plus a live
 * ping) and re-running the token scan for each is pure waste.
 *
 * @param bool $count_speculative_as_bot Whether a prefetch/prerender counts as
 *        a bot. True (the default) is right for anything that RECORDS. Callers
 *        that decide whether to EMIT markup must pass false — see the warning
 *        on brikpanel_is_speculative_request().
 * @return bool True when the request is automated traffic or an excluded IP.
 */
function brikpanel_is_bot_request( $count_speculative_as_bot = true ) {
    // One slot per question, because the two answers genuinely differ and each
    // is asked several times per request. The scan itself runs only once: the
    // speculation overlay is applied on top of the cached scan result below.
    static $decisions = [];
    static $scan      = null;

    $slot = $count_speculative_as_bot ? 'with_speculative' : 'without_speculative';
    if ( isset( $decisions[ $slot ] ) ) {
        return $decisions[ $slot ];
    }

    if ( null !== $scan ) {
        $ua     = $scan['ua'];
        $is_bot = $scan['is_bot'];
    } else {
        $ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? strtolower( (string) $_SERVER['HTTP_USER_AGENT'] ) : '';

        // No user agent at all: never a real browser.
        $is_bot = ( '' === trim( $ua ) );

        $is_bot = brikpanel_scan_request_for_bot( $ua, $is_bot );
        $scan   = [ 'ua' => $ua, 'is_bot' => $is_bot ];
    }

    // A speculative fetch carries a perfectly ordinary browser user agent, so
    // it has to be caught by intent rather than by name. The device allowlist
    // inside the scan deliberately does not rescue it: the merchant allowlists
    // devices they browse from, not requests nobody made.
    if ( ! $is_bot && $count_speculative_as_bot && brikpanel_is_speculative_request() ) {
        $is_bot = true;
    }

    /**
     * Filter the final bot decision for the current request.
     *
     * @param bool   $is_bot Whether BrikPanel will skip analytics for this request.
     * @param string $ua     Lower-cased user agent (empty when not sent).
     * @param bool   $count_speculative_as_bot Which of the two questions is being
     *        asked — see the function docblock.
     */
    // Publish the unfiltered answer before running the filter, so a callback
    // that asks this function again (directly, or via any tracker it touches)
    // gets that answer instead of recursing forever.
    $decisions[ $slot ] = $is_bot;

    $decisions[ $slot ] = (bool) apply_filters(
        'brikpanel_is_bot_request',
        $is_bot,
        $ua,
        $count_speculative_as_bot
    );

    return $decisions[ $slot ];
}

/**
 * The speculation-independent half of the bot decision: user agent tokens,
 * the merchant's device allowlist and their excluded IP rules.
 *
 * Split out so it runs at most once per request even when both variants of
 * brikpanel_is_bot_request() are asked.
 *
 * @param string $ua     Lower-cased user agent.
 * @param bool   $is_bot Decision so far.
 * @return bool
 */
function brikpanel_scan_request_for_bot( $ua, $is_bot ) {
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

    return $is_bot;
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
 * Passive client identity used to key the daily lock below.
 *
 * The user agent on its own collides too readily to be the whole key: two
 * people in one office share an exit address and, on a managed fleet, often
 * the exact same browser build. Accept-Language and Accept-Encoding are
 * stable for a given browser and differ between real devices, so folding
 * them in separates those two people without asking the shopper for anything
 * or storing an identifier of our own.
 *
 * Deliberately weak as a fingerprint. It exists to rate-limit a loop that
 * repeats itself, not to recognise a person across visits, and nothing here
 * is ever stored: it only reaches a salted hash that expires at midnight.
 *
 * @since 3.3.1
 *
 * @return string
 */
function brikpanel_client_fingerprint() {
    $parts = [];

    foreach ( [ 'HTTP_USER_AGENT', 'HTTP_ACCEPT_LANGUAGE', 'HTTP_ACCEPT_ENCODING' ] as $header ) {
        $parts[] = isset( $_SERVER[ $header ] ) ? (string) $_SERVER[ $header ] : '';
    }

    /**
     * Filters the passive identity used to key BrikPanel's daily counter locks.
     *
     * Narrow it on a store whose visitors sit behind one address with identical
     * browsers; widen it if a crawler is caught rotating one of these headers.
     *
     * @since 3.3.1
     *
     * @param string   $fingerprint Joined header values.
     * @param string[] $parts       The individual header values.
     */
    return (string) apply_filters( 'brikpanel_client_fingerprint', implode( '|', $parts ), $parts );
}

/**
 * One-shot daily lock for a client whose own memory cannot be trusted.
 *
 * The counters cap a visitor at one recorded event per day using something the
 * client carries: a cookie for the store-wide figures, the WooCommerce session
 * for the per-product card. That holds right up until the client arrives with
 * no memory at all — and then there is no cap, because the thing being asked
 * has just been born and answers "no, never seen this" every time.
 *
 * That is not a rare edge. WooCommerce 10.3+ destroys a guest session the
 * moment the cart goes empty (WC_Session_Handler::destroy_session_if_empty()),
 * so a script that adds a product and removes it again is handed a brand-new,
 * memoryless session on its next turn, and on every turn after that. A client
 * that simply discards its cookie jar between turns lands in the same place.
 * Measured on one store: 13,053 recorded add-to-carts in a month against a
 * real baseline of 4-7 a day.
 *
 * So when the client keeps no memory, the server keeps one for it: a salted
 * hash of its address plus its passive identity, held until midnight. Note
 * what this does NOT do — it does not drop a first event. A real shopper's
 * first add of the day is not a repeat, so it is recorded exactly as before;
 * only the second identical one from a client that claims to have forgotten
 * the first is refused.
 *
 * Not a security boundary. A client that rotates its address defeats it, the
 * same way a spoofed user agent defeats the token list above. It is a
 * data-quality cap.
 *
 * @since 3.3.1
 *
 * @param string $bucket Namespace so separate counters do not share a lock.
 * @return bool True when the caller may record; false when already recorded today.
 */
function brikpanel_client_daily_lock( $bucket ) {
    $ip = brikpanel_client_ip();
    if ( '' === $ip ) {
        // Unidentifiable and memoryless: nothing to rate-limit against, and
        // counting it would be the exact inflation this lock exists to stop.
        return false;
    }

    $key = 'bp_cd_' . substr(
        hash_hmac(
            'sha256',
            $bucket . '|' . $ip . '|' . brikpanel_client_fingerprint(),
            wp_salt( 'brikpanel_bot_gate' )
        ),
        0,
        24
    );

    // Transients ride a persistent object cache when the store has one, so
    // this is one Redis round trip there and one autoload=no option row where
    // there is none. Either way it expires at midnight and WordPress's daily
    // delete_expired_transients() sweeps the row.
    //
    // Read-then-write, so two requests that arrive inside the same millisecond
    // can both see a miss and both be counted. Measured rather than assumed:
    // 10 simultaneous requests from one identity produced 1, 30 produced 2, 60
    // produced 2, 120 produced 1 — the window is one database round trip wide,
    // so piling on more concurrency does not widen it and an attacker cannot
    // multiply the count by parallelising. The ceiling is 2 where it should be
    // 1, against the thousands this gate exists to stop.
    //
    // Closing it needs an atomic claim. wp_cache_add() is one on Redis and
    // Memcached, but add_option() is not — WordPress writes it as
    // INSERT ... ON DUPLICATE KEY UPDATE, so the loser of the race is never
    // told it lost — and hand-rolling INSERT IGNORE against wp_options means
    // owning the autoload value (which changed representation in 6.6) and the
    // option-cache coherence that add_option() normally handles. That is a
    // larger risk than the two it would save, and it cannot be verified on a
    // host with no persistent object cache to race against.
    if ( get_transient( $key ) ) {
        return false;
    }

    $seconds_until_midnight = strtotime( 'tomorrow', current_time( 'timestamp' ) ) - current_time( 'timestamp' );
    set_transient( $key, 1, max( 60, $seconds_until_midnight ) );

    return true;
}

/**
 * Whether a browser id has the shape this plugin mints.
 *
 * Both minters (the cart-abandonment module and the live-visitors module)
 * write uniqid( 'bp_', true ): `bp_`, thirteen lowercase hex digits, one or
 * two decimal digits, a dot and eight decimal digits. Nothing else ever sets
 * the cookie (it is HttpOnly, so page scripts cannot), which means a value of
 * any other shape was typed by the client, not issued to it. An endpoint that
 * adopted it would be keying its dedupe and its rate limit on a string the
 * caller picks fresh for every request.
 *
 * @since 3.3.2
 *
 * @param mixed $value Raw cookie value.
 * @return bool
 */
function brikpanel_visitor_id_is_valid( $value ) {
    return is_string( $value ) && 1 === preg_match( '/^bp_[0-9a-f]{13}[0-9]{1,2}\.[0-9]{8}$/D', $value );
}

/**
 * The browser id the current request carries, or '' when it sent none or
 * sent one this plugin never issued. Treating a foreign value as "no cookie"
 * is what makes every reader mint a fresh id instead of adopting the
 * client's.
 *
 * @since 3.3.2
 *
 * @return string
 */
function brikpanel_visitor_id_from_cookie() {
    if ( ! isset( $_COOKIE['brikpanel_vid'] ) ) {
        return '';
    }
    $raw = substr( sanitize_text_field( wp_unslash( $_COOKIE['brikpanel_vid'] ) ), 0, 64 );

    return brikpanel_visitor_id_is_valid( $raw ) ? $raw : '';
}

/**
 * Transient key for a short burst lock on the client's passive identity.
 *
 * Companion to brikpanel_client_daily_lock() for endpoints that already keep
 * a per-browser bucket: that bucket is keyed on a cookie the client chooses,
 * so a client that rotates the cookie is never in the same bucket twice. This
 * key ignores the cookie and is built the way the daily lock builds its own:
 * address plus the passive request headers, HMAC-ed with the site salt, never
 * stored raw.
 *
 * Same limit as every other address-keyed brake here: the address comes from
 * brikpanel_client_ip(), which trusts the proxy headers WooCommerce trusts,
 * so a direct-connect client that forges them still rotates its way through.
 * The rotation this closes is the cookie one.
 *
 * @since 3.3.2
 *
 * @param string $bucket Namespace so separate endpoints do not share a lock.
 * @return string Transient name, or '' when the client cannot be identified.
 */
function brikpanel_client_bucket_key( $bucket ) {
    $ip = brikpanel_client_ip();
    if ( '' === $ip ) {
        return '';
    }

    return 'bp_cb_' . substr(
        hash_hmac(
            'sha256',
            $bucket . '|' . $ip . '|' . brikpanel_client_fingerprint(),
            wp_salt( 'brikpanel_bot_gate' )
        ),
        0,
        24
    );
}

/**
 * One-per-day gate for clients that send no cookies at all.
 *
 * Superseded by brikpanel_client_daily_lock() and kept because third-party
 * code may call it. Every BrikPanel counter now calls the lock directly.
 *
 * The reason it was not enough on its own is the early return below: holding
 * cookies was read as proof that the client remembers yesterday, and it is
 * not. A script that browses a product page, adds to cart with the cookies it
 * just picked up, then starts over with an empty jar sends cookies on every
 * request that matters and still remembers nothing between turns.
 *
 * @since 3.2.30
 *
 * @param string $bucket Namespace so separate counters do not share a gate.
 * @return bool True when the caller may record; false when already recorded today.
 */
function brikpanel_cookieless_daily_gate( $bucket ) {
    // Read the raw request header rather than $_COOKIE: WooCommerce starts a
    // customer session during add-to-cart and writes the new cookie straight
    // into $_COOKIE so the rest of the request can see it, which would make
    // every client look like it had sent cookies. $_SERVER['HTTP_COOKIE'] is
    // what the client actually sent and is never rewritten.
    $sent_cookies = isset( $_SERVER['HTTP_COOKIE'] ) ? trim( (string) $_SERVER['HTTP_COOKIE'] ) : '';
    if ( '' !== $sent_cookies ) {
        return true;
    }

    return brikpanel_client_daily_lock( $bucket );
}
