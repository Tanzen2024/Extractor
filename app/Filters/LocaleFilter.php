<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Applies the user's language to the request: the `bscd_locale` cookie when
 * it names a supported locale, else Config\App::$defaultLocale (French).
 * Runs first among the global filters so that every later message — auth
 * redirects, 403 page, flash messages — is already in that language.
 */
class LocaleFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        helper('i18n');

        $config = config('App');
        $wanted = method_exists($request, 'getCookie') ? (string) $request->getCookie(BSCD_LOCALE_COOKIE) : '';
        $locale = in_array($wanted, $config->supportedLocales, true) ? $wanted : $config->defaultLocale;

        if (method_exists($request, 'setLocale')) {
            $request->setLocale($locale);
        }

        // The language service may already exist (created with the default locale).
        service('language')->setLocale($locale);
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
    }
}
