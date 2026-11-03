<?php

namespace App\Controllers;

/**
 * GET /language/{fr|en}?return=/path — stores the chosen language in a
 * one-year cookie (kept across sessions, before or after login) and goes
 * back to the page the user was on. `return` must be a local path: anything
 * else falls back to the dashboard (no open redirect).
 */
class LanguageController extends BaseController
{
    private const ONE_YEAR = 31536000;

    public function change(string $locale)
    {
        if (! in_array($locale, supported_locales(), true)) {
            $locale = config('App')->defaultLocale;
        }

        $return = (string) $this->request->getGet('return');
        $target = preg_match('#^/(?!/)[^\\\\\s]*$#', $return) === 1 ? $return : '/dashboard';

        return redirect()->to(site_url(ltrim($target, '/')))->setCookie(BSCD_LOCALE_COOKIE, $locale, self::ONE_YEAR);
    }
}
