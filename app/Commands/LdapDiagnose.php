<?php

namespace App\Commands;

use App\Models\AppUserModel;
use App\Services\AuthorizationService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Ldap as LdapConfig;

/**
 * Read-only Active Directory / LDAP diagnostic.
 *
 *   php spark ldap:diagnose                 config + DNS + ports + 389/StartTLS/636 anon probes
 *   php spark ldap:diagnose --user jdupont  also does a real authenticated bind + app_users check
 *
 * The password (asked interactively, echo disabled) is NEVER written to a
 * file, the .env, a log, or the shell history. This command changes nothing
 * and does not touch the login flow.
 */
class LdapDiagnose extends BaseCommand
{
    protected $group       = 'Auth';
    protected $name        = 'ldap:diagnose';
    protected $description  = 'Diagnostique la connectivite Active Directory sans exposer de mot de passe.';
    protected $usage        = 'ldap:diagnose [--user <sAMAccountName>]';
    protected $options      = ['--user' => 'Compte AD a tester reellement (mot de passe demande, jamais stocke).'];

    public function run(array $params): void
    {
        $c = config(LdapConfig::class);

        CLI::write('=== Configuration LDAP effective (Config\\Ldap <- .env) ===', 'yellow');
        CLI::table([
            ['ldap.host', $c->host !== '' ? $c->host : CLI::color('(vide)', 'red')],
            ['ldap.port', (string) $c->port],
            ['ldap.baseDn', $c->baseDn !== '' ? $c->baseDn : CLI::color('(vide)', 'red')],
            ['ldap.domain', $c->domain !== '' ? $c->domain : CLI::color('(vide)', 'red')],
            ['ldap.useTls', $c->useTls ? 'true' : 'false'],
        ], ['Clé', 'Valeur']);

        if ($this->looksLikePlaceholder($c)) {
            CLI::write('  ⚠  Ces valeurs ressemblent aux exemples par défaut — remplace-les par le vrai AD.', 'red');
        }
        if ($c->host === '' || $c->domain === '') {
            CLI::error('  host ou domain vide -> authenticate() renvoie null immediatement. STOP.');

            return;
        }

        $this->checkDns($c->host);
        $this->checkPort($c->host, 389);
        $this->checkPort($c->host, 636);

        CLI::newLine();
        CLI::write('=== Sondes LDAP (bind anonyme — beaucoup d\'AD le refusent, c\'est OK) ===', 'yellow');
        $plain     = $this->probe("ldap://{$c->host}:389", false, 'LDAP 389 (clair)');
        $startTls  = $this->probe("ldap://{$c->host}:389", true, 'LDAP 389 + StartTLS');
        $ldaps     = $this->probe("ldaps://{$c->host}:636", false, 'LDAPS 636');

        CLI::newLine();
        CLI::write('=== Recommandation transport ===', 'yellow');
        $this->recommend($c, $plain, $startTls, $ldaps);

        $user = $params['user'] ?? CLI::getOption('user');
        if (is_string($user) && $user !== '') {
            $this->realBind($c, $user);
        } else {
            CLI::newLine();
            CLI::write('Pour un test complet avec un vrai compte : php spark ldap:diagnose --user <sAMAccountName>', 'dark_gray');
        }
    }

    private function looksLikePlaceholder(LdapConfig $c): bool
    {
        $needles = ['global.aes.com', 'dc01.', 'example.', 'change', 'placeholder'];
        foreach ($needles as $n) {
            if (stripos($c->host . ' ' . $c->domain . ' ' . $c->baseDn, $n) !== false) {
                return true;
            }
        }

        return false;
    }

    private function checkDns(string $host): void
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            CLI::write("DNS      {$host} : (adresse IP littérale, pas de résolution)", 'dark_gray');

            return;
        }
        $ip = @gethostbyname($host);
        if ($ip === $host) {
            CLI::write("DNS      {$host} : " . CLI::color('NON RÉSOLU', 'red') . ' — vérifier /etc/resolv.conf, le suffixe DNS, ou mettre l\'IP du DC.');
        } else {
            CLI::write("DNS      {$host} -> {$ip}", 'green');
        }
    }

    private function checkPort(string $host, int $port): void
    {
        $t0  = microtime(true);
        $fp  = @fsockopen($host, $port, $errno, $errstr, 3.0);
        $ms  = round((microtime(true) - $t0) * 1000);
        if ($fp) {
            fclose($fp);
            CLI::write("TCP      {$host}:{$port} : " . CLI::color("ouvert ({$ms} ms)", 'green'));
        } else {
            CLI::write("TCP      {$host}:{$port} : " . CLI::color("fermé/filtré — {$errstr} ({$errno})", 'red'));
        }
    }

    /**
     * @return array{ok:bool, msg:string}
     */
    private function probe(string $uri, bool $startTls, string $label): array
    {
        $ldap = @ldap_connect($uri);
        if ($ldap === false) {
            CLI::write(sprintf('  %-24s %s', $label, CLI::color('ldap_connect a échoué', 'red')));

            return ['ok' => false, 'msg' => 'connect failed'];
        }
        ldap_set_option($ldap, LDAP_OPT_PROTOCOL_VERSION, 3);
        ldap_set_option($ldap, LDAP_OPT_REFERRALS, 0);
        ldap_set_option($ldap, LDAP_OPT_NETWORK_TIMEOUT, 5);

        if ($startTls && ! @ldap_start_tls($ldap)) {
            $err = ldap_error($ldap);
            CLI::write(sprintf('  %-24s %s', $label, CLI::color("StartTLS KO — {$err}", 'red')));
            CLI::write('  ' . str_repeat(' ', 24) . CLI::color('(souvent = certificat de la CA du DC absent du magasin système, pas un mauvais port)', 'dark_gray'));
            @ldap_unbind($ldap);

            return ['ok' => false, 'msg' => $err];
        }

        $bound = @ldap_bind($ldap); // anonymous
        $err   = ldap_error($ldap);
        @ldap_unbind($ldap);

        // Le serveur répond mais refuse l'anon (unwilling / inappropriate auth /
        // confidentiality required / invalid credentials) => transport OK.
        $answered = ['unwilling', 'inappropriate', 'confidentiality', 'strong', 'invalid cred', 'operations error'];
        $reachable = $bound;
        foreach ($answered as $needle) {
            if (stripos($err, $needle) !== false) {
                $reachable = true;
            }
        }

        $txt = $bound ? 'bind anonyme OK' : "anonyme refusé ({$err})";
        CLI::write(sprintf('  %-24s %s', $label, CLI::color(($reachable ? 'transport OK — ' : '') . $txt, $reachable ? 'green' : 'red')));

        return ['ok' => $reachable, 'msg' => $err];
    }

    private function recommend(LdapConfig $c, array $plain, array $startTls, array $ldaps): void
    {
        if ($startTls['ok']) {
            CLI::write('  -> StartTLS sur 389 fonctionne. Config OK : ldap.port=389, ldap.useTls=true', 'green');
        } elseif ($ldaps['ok']) {
            CLI::write('  -> LDAPS 636 fonctionne, pas StartTLS. Mets : ldap.host = \'ldaps://' . $c->host . '\'  (le code ignore alors port/useTls)', 'green');
        } elseif ($plain['ok']) {
            CLI::write('  -> Seul le LDAP clair 389 répond (StartTLS/LDAPS non joignables depuis ce poste).', 'yellow');
            CLI::write('     La config historique utilisait ldap:// simple sur 389 : ldap.useTls=false PEUT convenir.', 'yellow');
            CLI::write('     MAIS beaucoup de DC refusent un bind AVEC identifiants sur canal non chiffré.', 'yellow');
            CLI::write('     -> lancer  php spark ldap:diagnose --user <compte_reel>  :', 'white');
            CLI::write('        - bind OK           => ldap.useTls=false est bon.', 'dark_gray');
            CLI::write('        - "confidentiality required" / erreur 8 => installer la CA du DC', 'dark_gray');
            CLI::write('          (/usr/local/share/ca-certificates/ + update-ca-certificates, ou /etc/ldap/ldap.conf TLS_CACERT)', 'dark_gray');
            CLI::write('          puis ldap.useTls=true (StartTLS) OU ldap.host=\'ldaps://' . $c->host . '\'. Ne PAS désactiver la vérif TLS.', 'dark_gray');
        } else {
            CLI::write('  -> Aucun transport ne répond : DNS ou pare-feu. Rien à corriger côté application tant que le réseau ne passe pas.', 'red');
        }
    }

    private function realBind(LdapConfig $c, string $user): void
    {
        CLI::newLine();
        CLI::write("=== Bind réel : {$user}@{$c->domain} ===", 'yellow');

        $pw = $this->readSecret('Mot de passe AD (saisie masquée, jamais enregistrée) : ');
        if ($pw === '') {
            CLI::error('Mot de passe vide — abandon (un bind à mot de passe vide est refusé par sécurité).');

            return;
        }

        // Choisir le transport comme le fait ActiveDirectoryService.
        $uri     = str_contains($c->host, '://') ? $c->host : "ldap://{$c->host}:{$c->port}";
        $ldap    = @ldap_connect($uri);
        ldap_set_option($ldap, LDAP_OPT_PROTOCOL_VERSION, 3);
        ldap_set_option($ldap, LDAP_OPT_REFERRALS, 0);
        ldap_set_option($ldap, LDAP_OPT_NETWORK_TIMEOUT, 5);

        if ($c->useTls && ! str_starts_with($uri, 'ldaps://') && ! @ldap_start_tls($ldap)) {
            CLI::error('StartTLS KO : ' . ldap_error($ldap) . '  -> le login échouera pour la même raison.');
            unset($pw);

            return;
        }

        $ok  = @ldap_bind($ldap, "{$user}@{$c->domain}", $pw);
        $err = ldap_error($ldap);
        unset($pw); // effacer immédiatement

        if (! $ok) {
            CLI::error("ldap_bind KO : {$err}");
            CLI::write('  49/"Invalid credentials" = utilisateur ou mot de passe faux, OU le suffixe ldap.domain n\'est pas le bon UPN.', 'dark_gray');
            @ldap_unbind($ldap);

            return;
        }
        CLI::write('  ldap_bind : ' . CLI::color('OK', 'green') . ' — Active Directory accepte ce compte.', 'green');

        // Recherche + correspondance app_users (comme le fait le login).
        $sam = $user;
        if ($c->baseDn !== '') {
            $filter = '(sAMAccountName=' . ldap_escape($user, '', LDAP_ESCAPE_FILTER) . ')';
            $res    = @ldap_search($ldap, $c->baseDn, $filter, ['samaccountname', 'displayname', 'dn'], 0, 1, 5);
            $ent    = $res ? (@ldap_get_entries($ldap, $res) ?: ['count' => 0]) : ['count' => 0];
            if (($ent['count'] ?? 0) > 0) {
                $sam = $ent[0]['samaccountname'][0] ?? $user;
                CLI::write("  Annuaire : trouvé — DN={$ent[0]['dn']}  sAMAccountName={$sam}  displayName=" . ($ent[0]['displayname'][0] ?? '—'), 'green');
            } else {
                CLI::write("  Annuaire : bind OK mais aucune entrée pour le filtre sous baseDn={$c->baseDn} — baseDn probablement trop restrictif/incorrect.", 'yellow');
            }
        }
        @ldap_unbind($ldap);

        $account = (new AppUserModel())->findByUsername($sam);
        CLI::newLine();
        if ($account === null) {
            CLI::write("  app_users : " . CLI::color("AUCUNE ligne pour '{$sam}'", 'red') . " -> login refusé (\"Compte AD valide mais absent de app_users\").");
            CLI::write("  Corriger : INSERT app_users(username,display_name,is_active) + app_user_roles vers ADMIN — via l'écran Admin ou un seed. Pas de mot de passe.", 'dark_gray');

            return;
        }
        CLI::write("  app_users : id={$account['id']} username={$account['username']} is_active={$account['is_active']}", (int) $account['is_active'] === 1 ? 'green' : 'red');
        if ((int) $account['is_active'] !== 1) {
            CLI::write('  -> is_active = 0 : login refusé. Réactiver le compte.', 'red');

            return;
        }
        $authz = AuthorizationService::forUser((int) $account['id']);
        CLI::write('  Rôles       : ' . (implode(', ', $authz->roles()) ?: CLI::color('AUCUN', 'red')));
        CLI::write('  Permissions : ' . (count($authz->permissions()) ? implode(', ', $authz->permissions()) : CLI::color('AUCUNE', 'red')));
        CLI::newLine();
        CLI::write('  => Ce compte devrait pouvoir se connecter. Si l\'écran web échoue encore, comparer date/heure serveur (Kerberos/TLS) et relire writable/logs/.', 'green');
    }

    private function readSecret(string $prompt): string
    {
        fwrite(STDOUT, $prompt);
        $hidden = false;
        if (DIRECTORY_SEPARATOR === '/' && @shell_exec('command -v stty') !== null) {
            @shell_exec('stty -echo');
            $hidden = true;
        }
        $value = rtrim((string) fgets(STDIN), "\r\n");
        if ($hidden) {
            @shell_exec('stty echo');
            fwrite(STDOUT, "\n");
        } else {
            CLI::write("\n  (saisie non masquée sur cette plateforme — préférer un shell Linux)", 'yellow');
        }

        return $value;
    }
}
