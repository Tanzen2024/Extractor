<?php

/**
 * Internationalisation helpers (French by default, English available).
 *
 *   Server-rendered text   lang('File.key')  — app/Language/{fr,en}/*.php
 *   Browser-rendered text  bscdI18n.t('key') — public/assets/i18n/{fr,en}.json,
 *                          inlined into the page by i18n_script_tag()
 *
 * The active locale comes from the `bscd_locale` cookie (set by
 * LanguageController, read by App\Filters\LocaleFilter on every request),
 * else Config\App::$defaultLocale ('fr').
 */

if (! defined('BSCD_LOCALE_COOKIE')) {
    define('BSCD_LOCALE_COOKIE', 'bscd_locale');
}

if (! function_exists('current_locale')) {
    function current_locale(): string
    {
        $locale = service('request')->getLocale();

        return in_array($locale, supported_locales(), true) ? $locale : config('App')->defaultLocale;
    }
}

if (! function_exists('supported_locales')) {
    /**
     * @return list<string>
     */
    function supported_locales(): array
    {
        return config('App')->supportedLocales;
    }
}

if (! function_exists('locale_switch_url')) {
    /**
     * Link that switches to $locale and comes back to the current page,
     * query string included (filters in the URL are kept).
     */
    function locale_switch_url(string $locale): string
    {
        $request = service('request');
        $return  = '/' . ltrim(uri_string(), '/');
        $query   = $request instanceof \CodeIgniter\HTTP\IncomingRequest ? (string) $request->getUri()->getQuery() : '';

        if ($query !== '') {
            $return .= '?' . $query;
        }

        return site_url('language/' . $locale) . '?' . http_build_query(['return' => $return]);
    }
}

if (! function_exists('i18n_catalog')) {
    /**
     * The browser catalog of one locale (public/assets/i18n/{locale}.json).
     *
     * @return array<string, mixed>
     */
    function i18n_catalog(string $locale): array
    {
        $file = FCPATH . 'assets/i18n/' . $locale . '.json';
        $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;

        return is_array($data) ? $data : [];
    }
}

if (! function_exists('i18n_script_tag')) {
    /**
     * Inline <script> handing the active locale and its catalog to
     * public/assets/js/i18n.js — must come before every other app script.
     */
    function i18n_script_tag(): string
    {
        $payload = ['locale' => current_locale(), 'messages' => i18n_catalog(current_locale())];

        return '<script>window.BSCD_I18N = '
            . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)
            . ';</script>';
    }
}
