<?php
/**
 * Which languages this store speaks, and which one the current request is in.
 *
 * BrikPanel is monolingual by design: every screen and every stored setting is
 * one value. A handful of features do face the shopper in their own language
 * though (the cart-abandonment signup popup is the first), and those need to
 * know the store's language list without depending on any one multilingual
 * plugin's API shape.
 *
 * Deliberately self-contained. BrikMentor ships a much larger version of this
 * (it also resolves an ORDER's language months after checkout, rewrites links
 * per language and pins a locale across a queued job), but asking BrikMentor
 * would make a BrikPanel screen depend on a plugin that may be absent, or
 * present but too old to answer - which renders an empty field the merchant
 * reads as "broken" rather than "not installed". This file answers on its own,
 * always.
 *
 * The question here is also the simpler one: the popup is rendered inside the
 * request it belongs to, so "which language is this?" is just the request's
 * own locale, not a stored one that has to survive a background job.
 *
 * @package BrikPanel
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Brikpanel_Languages {

    /**
     * Weglot names languages by ISO code and never touches the WordPress
     * locale, so its codes are mapped to locales here. Only the languages
     * WordPress itself ships a locale for are listed; anything else falls
     * through to the bare code, which still works as a storage key.
     *
     * @var array<string,string>
     */
    const WEGLOT_LOCALES = [
        'ar' => 'ar',    'bg' => 'bg_BG', 'cs' => 'cs_CZ', 'da' => 'da_DK',
        'de' => 'de_DE', 'el' => 'el',    'en' => 'en_US', 'es' => 'es_ES',
        'et' => 'et',    'fi' => 'fi',    'fr' => 'fr_FR', 'he' => 'he_IL',
        'hi' => 'hi_IN', 'hr' => 'hr',    'hu' => 'hu_HU', 'id' => 'id_ID',
        'it' => 'it_IT', 'ja' => 'ja',    'ko' => 'ko_KR', 'lt' => 'lt_LT',
        'lv' => 'lv',    'nl' => 'nl_NL', 'no' => 'nb_NO', 'pl' => 'pl_PL',
        'pt' => 'pt_PT', 'ro' => 'ro_RO', 'ru' => 'ru_RU', 'sk' => 'sk_SK',
        'sl' => 'sl_SI', 'sr' => 'sr_RS', 'sv' => 'sv_SE', 'th' => 'th',
        'tr' => 'tr_TR', 'uk' => 'uk',    'vi' => 'vi',    'zh' => 'zh_CN',
    ];

    /** @var array|null Per-request memo: [ 'provider' => string, 'languages' => array[] ]. */
    private static $memo = null;

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * The multilingual system in charge: 'polylang', 'wpml', 'translatepress',
     * 'weglot', or '' for a single-language store.
     *
     * Polylang is asked first because its WPML-compatibility layer answers the
     * WPML filters too. Weglot only counts once it holds an API key: without
     * one it translates nothing and the store IS monolingual.
     *
     * @return string
     */
    public static function provider() {
        $m = self::memo();
        return (string) $m['provider'];
    }

    /**
     * The store's languages, in the multilingual plugin's own order.
     *
     * Each entry: locale (WordPress locale), code (the plugin's own slug),
     * name (native), is_default. Empty on a monolingual store.
     *
     * @return array[]
     */
    public static function languages() {
        $m = self::memo();
        return (array) $m['languages'];
    }

    /**
     * Whether the store really speaks more than one language. Every caller
     * gates on this alone: one language means "behave exactly as BrikPanel
     * always has", with no extra field, storage or lookup anywhere.
     *
     * @return bool
     */
    public static function is_multilingual() {
        return count( self::languages() ) > 1;
    }

    /**
     * @return string '' on a monolingual store.
     */
    public static function default_locale() {
        foreach ( self::languages() as $l ) {
            if ( ! empty( $l['is_default'] ) ) {
                return (string) $l['locale'];
            }
        }
        return '';
    }

    /**
     * @param string $locale
     * @return bool
     */
    public static function is_default( $locale ) {
        $locale = self::normalize( $locale );
        return '' !== $locale && $locale === self::default_locale();
    }

    /**
     * Turn whatever a caller holds (a WordPress locale, a plugin slug, 'fi-FI',
     * a locale saved before the merchant reconfigured their languages) into one
     * of THIS store's locales, or '' when it is none of them - and always '' on
     * a monolingual store, which is what lets every caller gate on the result.
     *
     * @param mixed $raw
     * @return string
     */
    public static function normalize( $raw ) {
        // Callers hand this whatever they hold, and one of them is a filtered
        // locale a third party can return anything from. Casting an array here
        // is a PHP warning on a shopper's page load, for a value that could
        // never have named a language anyway.
        if ( ! is_scalar( $raw ) ) {
            return '';
        }
        $raw = strtolower( trim( str_replace( '-', '_', (string) $raw ) ) );
        if ( '' === $raw ) {
            return '';
        }
        $list = self::languages();
        if ( count( $list ) < 2 ) {
            return '';
        }
        foreach ( $list as $l ) {
            if ( strtolower( $l['locale'] ) === $raw || strtolower( (string) $l['code'] ) === $raw ) {
                return (string) $l['locale'];
            }
        }
        // 'fi_FI' on a store whose Finnish is 'fi', 'de' on one whose German is
        // 'de_DE': same language, so the same text. The default wins a tie.
        $prefix = self::lang_of( $raw );
        $hit    = '';
        foreach ( $list as $l ) {
            if ( self::lang_of( $l['locale'] ) === $prefix ) {
                if ( ! empty( $l['is_default'] ) ) {
                    return (string) $l['locale'];
                }
                if ( '' === $hit ) {
                    $hit = (string) $l['locale'];
                }
            }
        }
        return $hit;
    }

    /**
     * The language THIS request is being rendered in, as one of the store's
     * locales. '' on a monolingual store and '' for the default language -
     * callers treat both the same way, by using their existing single value.
     *
     * Polylang, WPML and TranslatePress all switch the WordPress locale for the
     * page they are serving, so determine_locale() is the honest answer and
     * costs nothing. Weglot is the exception: it translates the finished HTML
     * and leaves the locale alone, so it is asked directly.
     *
     * @return string
     */
    public static function current_locale() {
        if ( ! self::is_multilingual() ) {
            return '';
        }
        if ( 'weglot' === self::provider() && function_exists( 'weglot_get_current_language' ) ) {
            try {
                $code = self::normalize( (string) weglot_get_current_language() );
                if ( '' !== $code ) {
                    return $code;
                }
            } catch ( \Throwable $e ) {
                // Fall through to the locale below.
            }
        }
        return self::normalize( determine_locale() );
    }

    /**
     * The native name of one of the store's languages, for a tab label or a
     * column header. Falls back to the locale itself so a label is never blank.
     *
     * @param string $locale
     * @return string
     */
    public static function language_name( $locale ) {
        $locale = self::normalize( $locale );
        foreach ( self::languages() as $l ) {
            if ( $l['locale'] === $locale ) {
                return (string) $l['name'];
            }
        }
        return (string) $locale;
    }

    /**
     * Drop a per-request memo. Only tests and CLI need this: a web request
     * reads the language list once and the plugins cannot change under it.
     *
     * @return void
     */
    public static function flush() {
        self::$memo = null;
    }

    // -------------------------------------------------------------------------
    // Reading the plugins
    // -------------------------------------------------------------------------

    /**
     * @return array{provider:string, languages:array[]}
     */
    private static function memo() {
        if ( null === self::$memo ) {
            $provider    = self::detect();
            self::$memo = [
                'provider'  => $provider,
                'languages' => '' === $provider ? [] : self::read_languages( $provider ),
            ];
        }
        return self::$memo;
    }

    /**
     * @return string
     */
    private static function detect() {
        if ( function_exists( 'pll_languages_list' ) && function_exists( 'PLL' ) ) {
            return 'polylang';
        }
        if ( defined( 'ICL_SITEPRESS_VERSION' ) && isset( $GLOBALS['sitepress'] ) && is_object( $GLOBALS['sitepress'] ) ) {
            return 'wpml';
        }
        if ( class_exists( 'TRP_Translate_Press' ) && function_exists( 'trp_get_languages' ) ) {
            return 'translatepress';
        }
        if ( function_exists( 'weglot_get_destination_languages' ) && function_exists( 'weglot_get_api_key' ) && '' !== (string) weglot_get_api_key() ) {
            return 'weglot';
        }
        return '';
    }

    /**
     * Every reader is wrapped: these are third-party APIs read on a front-end
     * request, and a fatal from one of them would take the whole store's page
     * down over a popup's wording. An unreadable list means "monolingual",
     * which is exactly BrikPanel's pre-existing behaviour.
     *
     * @param string $provider
     * @return array[]
     */
    private static function read_languages( $provider ) {
        try {
            switch ( $provider ) {
                case 'polylang':
                    return self::read_polylang();
                case 'wpml':
                    return self::read_wpml();
                case 'translatepress':
                    return self::read_translatepress();
                case 'weglot':
                    return self::read_weglot();
            }
        } catch ( \Throwable $e ) {
            return [];
        }
        return [];
    }

    /**
     * @return array[]
     */
    private static function read_polylang() {
        $out     = [];
        $default = function_exists( 'pll_default_language' ) ? (string) pll_default_language( 'slug' ) : '';
        $model   = isset( PLL()->model ) ? PLL()->model : null;
        $list    = [];
        // PLL_Model answers get_languages_list() through __call() since 3.7, so
        // method_exists() says no while the call works: is_callable() is the test.
        if ( $model && isset( $model->languages ) && is_object( $model->languages ) && is_callable( [ $model->languages, 'get_list' ] ) ) {
            $list = (array) $model->languages->get_list();
        } elseif ( $model && is_callable( [ $model, 'get_languages_list' ] ) ) {
            $list = (array) $model->get_languages_list();
        }
        foreach ( $list as $lang ) {
            if ( ! is_object( $lang ) ) {
                continue;
            }
            // Read through __get(): PLL_Language's properties are magic, and
            // empty() on a magic property asks __isset(), which says no.
            $slug   = (string) $lang->slug;
            $locale = (string) $lang->locale;
            if ( '' === $slug || '' === $locale ) {
                continue;
            }
            $out[] = [
                'locale'     => $locale,
                'code'       => $slug,
                'name'       => (string) $lang->name,
                'is_default' => $slug === $default,
            ];
        }
        return $out;
    }

    /**
     * @return array[]
     */
    private static function read_wpml() {
        $sp = $GLOBALS['sitepress'];
        if ( ! method_exists( $sp, 'get_active_languages' ) ) {
            return [];
        }
        $default = method_exists( $sp, 'get_default_language' ) ? (string) $sp->get_default_language() : '';
        $out     = [];
        foreach ( (array) $sp->get_active_languages() as $code => $lang ) {
            $code   = (string) ( isset( $lang['code'] ) ? $lang['code'] : $code );
            $locale = method_exists( $sp, 'get_locale' )
                ? (string) $sp->get_locale( $code )
                : (string) ( isset( $lang['default_locale'] ) ? $lang['default_locale'] : '' );
            if ( '' === $code || '' === $locale ) {
                continue;
            }
            $name = isset( $lang['native_name'] ) ? $lang['native_name'] : ( isset( $lang['display_name'] ) ? $lang['display_name'] : $code );
            $out[] = [
                'locale'     => $locale,
                'code'       => $code,
                'name'       => (string) $name,
                'is_default' => $code === $default,
            ];
        }
        return $out;
    }

    /**
     * @return array[]
     */
    private static function read_translatepress() {
        $settings = get_option( 'trp_settings' );
        if ( ! is_array( $settings ) ) {
            return [];
        }
        $default = (string) ( isset( $settings['default-language'] ) ? $settings['default-language'] : '' );
        $publish = isset( $settings['publish-languages'] ) ? (array) $settings['publish-languages'] : [];
        $codes   = array_values( array_unique( array_merge( [ $default ], $publish ) ) );
        $names   = [];
        $lang_c  = self::trp_component( 'languages' );
        if ( $lang_c && method_exists( $lang_c, 'get_language_names' ) ) {
            $names = (array) $lang_c->get_language_names( $codes, 'native_name' );
        }
        $out = [];
        foreach ( $codes as $code ) {
            $code = (string) $code;
            if ( '' === $code ) {
                continue;
            }
            $out[] = [
                // TranslatePress names its languages by WordPress locale already.
                'locale'     => $code,
                'code'       => $code,
                'name'       => (string) ( isset( $names[ $code ] ) ? $names[ $code ] : $code ),
                'is_default' => $code === $default,
            ];
        }
        return $out;
    }

    /**
     * @param string $name
     * @return object|null
     */
    private static function trp_component( $name ) {
        if ( ! class_exists( 'TRP_Translate_Press' ) ) {
            return null;
        }
        $trp = TRP_Translate_Press::get_trp_instance();
        if ( ! $trp || ! method_exists( $trp, 'get_component' ) ) {
            return null;
        }
        $c = $trp->get_component( $name );
        return is_object( $c ) ? $c : null;
    }

    /**
     * @return array[]
     */
    private static function read_weglot() {
        $original = strtolower( (string) weglot_get_original_language() );
        if ( '' === $original ) {
            return [];
        }
        $out   = [];
        $out[] = self::weglot_entry( $original, $original, true );
        foreach ( (array) weglot_get_destination_languages() as $dest ) {
            $code = strtolower( (string) ( isset( $dest['language_to'] ) ? $dest['language_to'] : '' ) );
            if ( '' === $code || $code === $original ) {
                continue;
            }
            if ( isset( $dest['public'] ) && ! $dest['public'] ) {
                continue;
            }
            $custom = isset( $dest['custom_code'] ) ? (string) $dest['custom_code'] : '';
            $slug   = '' !== $custom ? strtolower( $custom ) : $code;
            $out[]  = self::weglot_entry( $code, $slug, false );
        }
        return $out;
    }

    /**
     * @param string $code
     * @param string $slug
     * @param bool   $is_default
     * @return array
     */
    private static function weglot_entry( $code, $slug, $is_default ) {
        $locale = isset( self::WEGLOT_LOCALES[ $code ] ) ? self::WEGLOT_LOCALES[ $code ] : $code;
        $name   = '';
        if ( function_exists( 'weglot_get_service' ) ) {
            try {
                $service = weglot_get_service( 'Language_Service_Weglot' );
                if ( $service && method_exists( $service, 'get_language_from_internal' ) ) {
                    $lang = $service->get_language_from_internal( $code );
                    if ( $lang && method_exists( $lang, 'getLocalName' ) ) {
                        $name = (string) $lang->getLocalName();
                    }
                }
            } catch ( \Throwable $e ) {
                $name = '';
            }
        }
        return [
            'locale'     => $locale,
            'code'       => $slug,
            'name'       => '' !== $name ? $name : strtoupper( $code ),
            'is_default' => (bool) $is_default,
        ];
    }

    /**
     * The language half of a locale ('pt' out of 'pt_BR').
     *
     * @param string $locale
     * @return string
     */
    private static function lang_of( $locale ) {
        $locale = strtolower( str_replace( '-', '_', (string) $locale ) );
        $pos    = strpos( $locale, '_' );
        return false === $pos ? $locale : substr( $locale, 0, $pos );
    }
}
