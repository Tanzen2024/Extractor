<?php

namespace App\Services;

use Config\Ldap as LdapConfig;

/**
 * Active Directory / LDAP — the sole authentication authority.
 *
 * Responsibility is limited to: connect, (optionally StartTLS), bind as the
 * user, read a couple of identity attributes. It never decides application
 * access — roles/permissions come from MariaDB (App\Services\AuthorizationService).
 * Technical errors are logged, never surfaced to the controller.
 */
class ActiveDirectoryService
{
    private LdapConfig $config;

    public function __construct(?LdapConfig $config = null)
    {
        $this->config = $config ?? config('Ldap');
    }

    /**
     * Validates the credentials against AD.
     *
     * @return array{username:string, displayName:string|null, groups:list<string>}|null
     *         null on any failure (bad credentials, unreachable DC, misconfig).
     */
    public function authenticate(string $username, string $password): ?array
    {
        // An empty password with a non-empty DN can become an "unauthenticated
        // bind" that some directories accept — always refuse it up front.
        if ($username === '' || $password === '') {
            return null;
        }

        if (! function_exists('ldap_connect')) {
            log_message('critical', 'Extension PHP ldap absente : authentification impossible.');

            return null;
        }

        if ($this->config->host === '' || $this->config->domain === '') {
            log_message('critical', 'LDAP non configure (ldap.host / ldap.domain manquants dans .env).');

            return null;
        }

        $uri  = $this->uri();
        $ldap = @ldap_connect($uri);
        if ($ldap === false) {
            log_message('error', 'LDAP: ldap_connect a echoue pour {uri}', ['uri' => $uri]);

            return null;
        }

        ldap_set_option($ldap, LDAP_OPT_PROTOCOL_VERSION, 3);
        ldap_set_option($ldap, LDAP_OPT_REFERRALS, 0);
        ldap_set_option($ldap, LDAP_OPT_NETWORK_TIMEOUT, 5);

        if ($this->config->useTls && ! str_starts_with($uri, 'ldaps://') && ! @ldap_start_tls($ldap)) {
            log_message('error', 'LDAP: STARTTLS refuse par {uri} — verifier ldap.useTls / port (389 vs 636 LDAPS).', ['uri' => $uri]);
            @ldap_unbind($ldap);

            return null;
        }

        // The login screen recommends a bare sAMAccountName ("hugues.nwameh"),
        // but tolerate someone typing the full UPN ("hugues.nwameh@camlight.cm")
        // or the legacy "CAMLIGHT\hugues.nwameh" — never build a double suffix.
        $bindRdn = self::toBindRdn($username, $this->config->domain);
        $sam     = self::toSamAccountName($username);

        if (! @ldap_bind($ldap, $bindRdn, $password)) {
            log_message('notice', 'LDAP: bind refuse pour {rdn} ({err})', [
                'rdn' => $bindRdn,
                'err' => ldap_error($ldap),
            ]);
            @ldap_unbind($ldap);

            return null;
        }

        $identity = $this->readIdentity($ldap, $sam);
        @ldap_unbind($ldap);

        return $identity;
    }

    /**
     * Bind RDN for ldap_bind(): a UPN. Keeps an already-qualified input as-is,
     * strips a "DOMAIN\" prefix, otherwise appends "@<domain>".
     */
    private static function toBindRdn(string $input, string $domain): string
    {
        $v = trim($input);

        if (($pos = strrpos($v, '\\')) !== false) {
            $v = substr($v, $pos + 1);
        }
        if ($v === '' || str_contains($v, '@')) {
            return $v;
        }

        return $v . '@' . $domain;
    }

    /**
     * Bare sAMAccountName for the directory search and the app_users match:
     * "CAMLIGHT\hugues.nwameh" / "hugues.nwameh@camlight.cm" -> "hugues.nwameh".
     */
    private static function toSamAccountName(string $input): string
    {
        $v = trim($input);

        if (($pos = strrpos($v, '\\')) !== false) {
            $v = substr($v, $pos + 1);
        }
        if (($pos = strpos($v, '@')) !== false) {
            $v = substr($v, 0, $pos);
        }

        return $v;
    }

    /**
     * Best-effort read of sAMAccountName / displayName / memberOf using the
     * connection already bound as the user. Never fatal.
     *
     * @param resource|\LDAP\Connection $ldap
     *
     * @return array{username:string, displayName:string|null, groups:list<string>}
     */
    private function readIdentity($ldap, string $username): array
    {
        $fallback = ['username' => $username, 'displayName' => null, 'groups' => []];

        if ($this->config->baseDn === '') {
            return $fallback;
        }

        $filter = '(sAMAccountName=' . ldap_escape($username, '', LDAP_ESCAPE_FILTER) . ')';
        $search = @ldap_search($ldap, $this->config->baseDn, $filter, ['samaccountname', 'displayname', 'cn', 'memberof'], 0, 1, 5);
        if ($search === false) {
            return $fallback;
        }

        $entries = @ldap_get_entries($ldap, $search) ?: ['count' => 0];
        if (($entries['count'] ?? 0) < 1) {
            return $fallback;
        }

        $entry  = $entries[0];
        $groups = [];
        if (isset($entry['memberof']) && is_array($entry['memberof'])) {
            unset($entry['memberof']['count']);
            $groups = array_values($entry['memberof']);
        }

        return [
            'username'    => $entry['samaccountname'][0] ?? $username,
            'displayName' => $entry['displayname'][0] ?? $entry['cn'][0] ?? null,
            'groups'      => $groups,
        ];
    }

    private function uri(): string
    {
        if (str_contains($this->config->host, '://')) {
            return $this->config->host;
        }

        return sprintf('ldap://%s:%d', $this->config->host, $this->config->port);
    }
}
