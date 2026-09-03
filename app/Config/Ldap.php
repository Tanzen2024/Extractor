<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Active Directory / LDAP configuration.
 *
 * Values are read exclusively from .env (ldap.* keys) and must never be
 * hardcoded or logged. Used by App\Controllers\AuthController to validate a
 * user's password with a direct ldap_bind() — the application itself stores
 * and verifies no password.
 */
class Ldap extends BaseConfig
{
    /**
     * Domain controller host name or IP. May also be a full URI
     * ('ldaps://dc01.example.com') — then $port and $useTls are ignored.
     */
    public string $host = '';

    /**
     * 389 for plain LDAP / StartTLS, 636 for LDAPS.
     */
    public int $port = 389;

    /**
     * Base DN for the (optional) post-bind attribute lookup used to resolve
     * the user's display name, e.g. 'DC=global,DC=aes,DC=com'. Leave empty to
     * skip the lookup (the login then shows the sAMAccountName).
     */
    public string $baseDn = '';

    /**
     * UPN suffix appended to the sAMAccountName to build the bind RDN:
     * "<username>@<domain>". e.g. 'global.aes.com'.
     */
    public string $domain = '';

    /**
     * Issue StartTLS right after connecting. Strongly recommended: without it
     * the password travels to the DC in clear text. Ignored when $host is an
     * 'ldaps://' URI (already encrypted).
     */
    public bool $useTls = false;
}
