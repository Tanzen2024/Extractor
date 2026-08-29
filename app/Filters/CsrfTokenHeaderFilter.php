<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Echoes the *current* CSRF hash on every response as an X-CSRF-TOKEN header.
 *
 * Config\Security::$regenerate is true, so the hash rotates after each
 * validated state-changing request. A single-page screen (the dashboard)
 * that makes several POSTs would otherwise keep sending the stale token from
 * its original <meta> tag and every call after the first would 403 — which
 * the frontend then mis-rendered as "Export volumineux (0 lignes)".
 *
 * bscdFetch() (public/assets/js/app.js) reads this header off every response
 * and updates its token, so the page stays in sync without a reload.
 */
class CsrfTokenHeaderFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        // nothing to do before
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        if (function_exists('csrf_hash')) {
            $response->setHeader('X-CSRF-TOKEN', csrf_hash());
        }
    }
}
