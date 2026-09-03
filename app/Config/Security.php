<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

class Security extends BaseConfig
{
    /**
     * --------------------------------------------------------------------------
     * CSRF Protection Method
     * --------------------------------------------------------------------------
     *
     * Protection Method for Cross Site Request Forgery protection.
     *
     * @var string 'cookie' or 'session'
     */
    public string $csrfProtection = 'cookie';

    /**
     * --------------------------------------------------------------------------
     * CSRF Token Randomization
     * --------------------------------------------------------------------------
     *
     * Randomize the CSRF Token for added security.
     */
    public bool $tokenRandomize = false;

    /**
     * --------------------------------------------------------------------------
     * CSRF Token Name
     * --------------------------------------------------------------------------
     *
     * Token name for Cross Site Request Forgery protection.
     */
    public string $tokenName = 'csrf_test_name';

    /**
     * --------------------------------------------------------------------------
     * CSRF Header Name
     * --------------------------------------------------------------------------
     *
     * Header name for Cross Site Request Forgery protection.
     */
    public string $headerName = 'X-CSRF-TOKEN';

    /**
     * --------------------------------------------------------------------------
     * CSRF Cookie Name
     * --------------------------------------------------------------------------
     *
     * Cookie name for Cross Site Request Forgery protection.
     */
    public string $cookieName = 'csrf_cookie_name';

    /**
     * --------------------------------------------------------------------------
     * CSRF Expires
     * --------------------------------------------------------------------------
     *
     * Expiration time for Cross Site Request Forgery protection cookie.
     *
     * Raised from the 7200s (2h) default to 8h: the login form (and other
     * plain-form screens) can sit open on a desk far longer than two hours,
     * and once this cookie expires the next submit fails CSRF validation with
     * SecurityException::forDisallowedAction() ("The action you requested is
     * not allowed."). Eight hours covers a normal working day.
     */
    public int $expires = 28800;

    /**
     * --------------------------------------------------------------------------
     * CSRF Regenerate
     * --------------------------------------------------------------------------
     *
     * Regenerate CSRF Token on every submission.
     *
     * Kept false on purpose: with $csrfProtection = 'cookie' the token lives in
     * a single cookie shared by every tab. Rotating it after each validated POST
     * invalidates the token already embedded in any other open page (second tab,
     * the back button / bfcache, a double-clicked submit button), which then
     * fails CSRF validation with SecurityException::forDisallowedAction()
     * ("The action you requested is not allowed."). A fixed per-session token is
     * still secret, unguessable and validated server-side, so CSRF protection is
     * unchanged. See CodeIgniter user guide, Security > "CSRF Regenerate".
     */
    public bool $regenerate = false;

    /**
     * --------------------------------------------------------------------------
     * CSRF Redirect
     * --------------------------------------------------------------------------
     *
     * Redirect to previous page with error on failure.
     *
     * Kept true in every environment (not just production). On a stale token
     * the CSRF filter then bounces the user back to the page they came from
     * with a flash error and a freshly generated token, so they simply retry
     * and succeed. With this false, a dev hitting an expired login form got
     * the raw CRITICAL exception page instead.
     *
     * @see https://codeigniter4.github.io/userguide/libraries/security.html#redirection-on-failure
     */
    public bool $redirect = true;
}
