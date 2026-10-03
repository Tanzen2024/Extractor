#!/bin/sh
# Déploiement « snapshot actif + index DuckDB » d'Extractor sur le serveur Linux.
#
#   sudo sh deploy_snapshot_engine.sh --check   [--package <tgz>]   (défaut : LECTURE SEULE)
#   sudo sh deploy_snapshot_engine.sh --apply   --package <tgz> [--install-duckdb <duckdb_cli-linux-*.zip>]
#   sudo sh deploy_snapshot_engine.sh --rollback <répertoire de sauvegarde>
#
# Le paquet (extractor-snapshot-engine-<date>.tgz) contient les fichiers du
# code modifiés depuis le commit b642811, DELETED (fichiers à retirer),
# SHA256SUMS et BASE_COMMIT. Il ne s'applique QUE sur un serveur dont
# /var/www/extractor est exactement à BASE_COMMIT (pas d'état partiel).
#
# Garanties :
#   - --check ne modifie rien ;
#   - --apply sauvegarde d'abord (code, current.json, table export_jobs,
#     crons) dans /var/backups/extractor/<horodatage>/, n'arrête le worker
#     qu'une fois le paquet vérifié et la sauvegarde faite, ne supprime aucun
#     snapshot, ne touche ni à tnsnames.ora ni à .env, jamais de chmod 777 ;
#   - toute étape en échec arrête le script ; --rollback <sauvegarde> remet
#     le code et les crons d'avant (les données snapshot ne sont pas touchées :
#     l'index DuckDB est un fichier en plus dans chaque version).
set -eu

APP_DIR=/var/www/extractor
APP_USER=www-data
SERVICE=extractor-export-worker
BACKUP_ROOT=/var/backups/extractor
MODE=check
PACKAGE=
DUCKDB_ZIP=
ROLLBACK_DIR=
MIN_FREE_GB=5

say()  { printf '[deploy] %s\n' "$*"; }
ok()   { printf '[deploy] OK    %s\n' "$*"; }
warn() { printf '[deploy] WARN  %s\n' "$*"; }
die()  { printf '[deploy] ERREUR %s\n' "$*" >&2; exit 1; }
as_app() { runuser -u "$APP_USER" -- "$@"; }
spark()  { (cd "$APP_DIR" && as_app php spark "$@"); }

while [ $# -gt 0 ]; do
    case "$1" in
        --check)          MODE=check; shift ;;
        --apply)          MODE=apply; shift ;;
        --rollback)       MODE=rollback; ROLLBACK_DIR=${2:-}; shift 2 ;;
        --package)        PACKAGE=$2; shift 2 ;;
        --install-duckdb) DUCKDB_ZIP=$2; shift 2 ;;
        --app-dir)        APP_DIR=$2; shift 2 ;;
        *) die "option inconnue : $1" ;;
    esac
done

[ "$(id -u)" -eq 0 ] || die "à lancer en root (sudo)"
[ -f "$APP_DIR/spark" ] || die "$APP_DIR/spark absent"

# ---------------------------------------------------------------- rollback
if [ "$MODE" = rollback ]; then
    [ -n "$ROLLBACK_DIR" ] && [ -f "$ROLLBACK_DIR/code.tgz" ] || die "sauvegarde invalide : $ROLLBACK_DIR"
    say "arrêt propre du worker (le job en cours se termine, TimeoutStopSec)"
    systemctl stop "$SERVICE" || warn "arrêt du worker en échec"
    say "restauration du code depuis $ROLLBACK_DIR/code.tgz"
    tar -xzf "$ROLLBACK_DIR/code.tgz" -C "$APP_DIR"
    if [ -f "$ROLLBACK_DIR/added_files" ]; then
        # fichiers apportés par le paquet et absents avant : retirés
        while IFS= read -r f; do [ -n "$f" ] && rm -f -- "$APP_DIR/$f"; done < "$ROLLBACK_DIR/added_files"
    fi
    [ -f "$ROLLBACK_DIR/cron.d.extractor-customers-refresh" ] && cp -p "$ROLLBACK_DIR/cron.d.extractor-customers-refresh" /etc/cron.d/extractor-customers-refresh
    [ -f "$ROLLBACK_DIR/no_cron_file" ] && rm -f /etc/cron.d/extractor-customers-refresh
    spark cache:clear >/dev/null 2>&1 || true
    systemctl start "$SERVICE"
    sleep 3
    systemctl is-active --quiet "$SERVICE" && ok "worker actif sur le code restauré" || die "worker inactif après rollback : journalctl -u $SERVICE"
    say "rollback terminé. La colonne export_jobs.snapshot_version (additive) est laissée en place ; les index .duckdb sont ignorés par l'ancien code."
    exit 0
fi

# ---------------------------------------------------------------- 1. état
say "=== 1. État du serveur (lecture seule) ==="
HEAD=unknown
if [ -d "$APP_DIR/.git" ]; then
    HEAD=$(git -C "$APP_DIR" rev-parse --short HEAD 2>/dev/null || echo unknown)
    say "code déployé : $(git -C "$APP_DIR" rev-parse --abbrev-ref HEAD 2>/dev/null) @ $HEAD"
    DIRTY=$(git -C "$APP_DIR" status --porcelain --untracked-files=no 2>/dev/null | wc -l)
    [ "$DIRTY" -eq 0 ] && ok "arbre de travail propre" || warn "$DIRTY fichier(s) suivis modifiés sur le serveur : git -C $APP_DIR status"
else
    warn "$APP_DIR n'est pas un dépôt git : version déployée non vérifiable"
fi

PHP_BIN=$(command -v php) || die "php introuvable"
say "PHP : $PHP_BIN $(php -r 'echo PHP_VERSION;')"
php -r 'exit(PHP_VERSION_ID >= 80200 ? 0 : 1);' || die "PHP >= 8.2 requis"
for ext in mysqli intl mbstring json dom xmlreader zip zlib fileinfo; do
    php -r "exit(extension_loaded('$ext') ? 0 : 1);" && ok "ext $ext" || die "extension PHP $ext absente"
done
php -r "exit(extension_loaded('oci8') ? 0 : 1);" && ok "ext oci8 (refresh Oracle)" || warn "oci8 absente : customers:refresh ne pourra pas lire Oracle"

FREE_KB=$(df -Pk "$APP_DIR/writable" | awk 'NR==2 {print $4}')
FREE_GB=$((FREE_KB / 1048576))
[ "$FREE_GB" -ge "$MIN_FREE_GB" ] && ok "disque : ${FREE_GB} Go libres sous writable/" || die "disque : ${FREE_GB} Go libres < ${MIN_FREE_GB} Go (CSV ~0,8 Go + index ~0,2 Go par version + export)"

say "uname -m : $(uname -m)"
DUCKDB=$(command -v duckdb || true)
if [ -n "$DUCKDB" ]; then
    as_app "$DUCKDB" -version >/dev/null 2>&1 && ok "duckdb $DUCKDB ($(as_app "$DUCKDB" -version)) exécutable par $APP_USER" || die "$DUCKDB non exécutable par $APP_USER"
    echo "SELECT 42 AS answer;" | as_app "$DUCKDB" -json | grep -q 42 && ok "duckdb : requête de test" || die "duckdb : requête de test en échec"
else
    warn "duckdb absent (requis par le nouveau code) — --install-duckdb <zip> à --apply"
fi

say "worker : $(systemctl is-active "$SERVICE" 2>/dev/null || echo inconnu) / $(systemctl show -p User --value "$SERVICE" 2>/dev/null)"
spark snapshot:status || warn "snapshot:status en échec"
STORE="$APP_DIR/writable/data/snapshots/customers"
if [ -f "$STORE/current.json" ]; then
    ACTIVE=$(sed -n 's/.*"version"[^"]*"\([^"]*\)".*/\1/p' "$STORE/current.json" | head -1)
    ok "snapshot actif : $ACTIVE"
    as_app test -r "$STORE/versions/$ACTIVE/customers_list.csv" && ok "CSV actif lisible par $APP_USER ($(du -h "$STORE/versions/$ACTIVE/customers_list.csv" | cut -f1))" || die "CSV actif illisible par $APP_USER"
    [ -f "$STORE/versions/$ACTIVE/customers_list.duckdb" ] && ok "index DuckDB présent" || warn "index DuckDB absent pour $ACTIVE (construit à --apply)"
else
    warn "aucun snapshot actif ($STORE/current.json absent) : le tableau de bord répondra 503 tant que customers:refresh n'a pas réussi"
fi

say "crons existants pour customers:refresh :"
grep -rl "customers:refresh" /etc/cron.d /etc/crontab 2>/dev/null || true
crontab -u "$APP_USER" -l 2>/dev/null | grep -n "customers:refresh" || true

if [ -n "$PACKAGE" ]; then
    say "=== paquet $PACKAGE ==="
    WORK=$(mktemp -d)
    tar -xzf "$PACKAGE" -C "$WORK"
    (cd "$WORK/files" && sha256sum -c --quiet ../SHA256SUMS) && ok "empreintes SHA-256 du paquet" || die "paquet corrompu (SHA256SUMS)"
    BASE=$(cat "$WORK/BASE_COMMIT")
    say "paquet construit sur $BASE ; serveur à $HEAD"
    if [ "$HEAD" != unknown ] && ! git -C "$APP_DIR" merge-base --is-ancestor "$BASE" HEAD 2>/dev/null; then
        die "le serveur ($HEAD) ne contient pas $BASE : déployer d'abord $BASE (git pull), sinon état partiel"
    fi
    [ "$HEAD" = unknown ] || [ "$(git -C "$APP_DIR" rev-parse --short "$BASE")" = "$HEAD" ] || warn "serveur ($HEAD) au-delà de $BASE : vérifier qu'aucun fichier du paquet n'a été modifié depuis"
fi

[ "$MODE" = check ] && { say "--check terminé : rien n'a été modifié."; exit 0; }

# ---------------------------------------------------------------- 2. apply
[ -n "$PACKAGE" ] || die "--apply exige --package"
if [ -z "$DUCKDB" ]; then
    [ -n "$DUCKDB_ZIP" ] || die "duckdb absent : fournir --install-duckdb duckdb_cli-linux-$(uname -m | sed 's/x86_64/amd64/').zip (github.com/duckdb/duckdb/releases)"
    T=$(mktemp -d); unzip -q "$DUCKDB_ZIP" -d "$T"
    install -o root -g root -m 0755 "$T/duckdb" /usr/local/bin/duckdb
    DUCKDB=/usr/local/bin/duckdb
    as_app "$DUCKDB" -version >/dev/null && ok "duckdb installé : $(as_app $DUCKDB -version)" || die "duckdb installé mais non exécutable par $APP_USER"
fi

TS=$(date +%Y%m%d_%H%M%S)
BACKUP="$BACKUP_ROOT/$TS"
say "=== 2. Sauvegarde dans $BACKUP ==="
install -d -m 0750 "$BACKUP"
echo "$HEAD" > "$BACKUP/HEAD"
# Code actuel des fichiers que le paquet touche (+ ceux qu'il supprime).
LIST="$BACKUP/files_touched"
(cd "$WORK/files" && find . -type f | sed 's#^\./##') > "$LIST"
cat "$WORK/DELETED" >> "$LIST"
: > "$BACKUP/added_files"
EXISTING="$BACKUP/files_existing"; : > "$EXISTING"
while IFS= read -r f; do
    [ -z "$f" ] && continue
    if [ -e "$APP_DIR/$f" ]; then echo "$f" >> "$EXISTING"; else echo "$f" >> "$BACKUP/added_files"; fi
done < "$LIST"
tar -czf "$BACKUP/code.tgz" -C "$APP_DIR" -T "$EXISTING"
cp -p "$STORE/current.json" "$BACKUP/" 2>/dev/null || true
if [ -f /etc/cron.d/extractor-customers-refresh ]; then cp -p /etc/cron.d/extractor-customers-refresh "$BACKUP/cron.d.extractor-customers-refresh"; else touch "$BACKUP/no_cron_file"; fi
crontab -u "$APP_USER" -l > "$BACKUP/crontab.$APP_USER" 2>/dev/null || true
# Table export_jobs (structure + données), identifiants lus dans .env sans les afficher.
DBH=$(sed -n 's/^[[:space:]]*database\.default\.hostname[[:space:]]*=[[:space:]]*//p' "$APP_DIR/.env" | tr -d "'\"" | head -1)
DBN=$(sed -n 's/^[[:space:]]*database\.default\.database[[:space:]]*=[[:space:]]*//p' "$APP_DIR/.env" | tr -d "'\"" | head -1)
DBU=$(sed -n 's/^[[:space:]]*database\.default\.username[[:space:]]*=[[:space:]]*//p' "$APP_DIR/.env" | tr -d "'\"" | head -1)
DBP=$(sed -n 's/^[[:space:]]*database\.default\.password[[:space:]]*=[[:space:]]*//p' "$APP_DIR/.env" | tr -d "'\"" | head -1)
if command -v mysqldump >/dev/null && [ -n "$DBN" ]; then
    MYSQL_PWD="$DBP" mysqldump -h "${DBH:-localhost}" -u "$DBU" --single-transaction "$DBN" export_jobs > "$BACKUP/export_jobs.sql" && ok "export_jobs sauvegardée" || warn "mysqldump export_jobs en échec (sauvegarde du code faite)"
else
    warn "mysqldump indisponible : table export_jobs non sauvegardée (la migration est seulement additive)"
fi
ok "sauvegarde : $BACKUP (rollback : sh $0 --rollback $BACKUP)"

say "=== 3. Arrêt propre du worker ==="
systemctl stop "$SERVICE"
ok "worker arrêté (job en cours terminé avant arrêt)"

say "=== 4. Application du paquet ==="
(cd "$WORK/files" && tar -cf - .) | (cd "$APP_DIR" && tar -xpf - --no-same-owner)
while IFS= read -r f; do [ -n "$f" ] && rm -f -- "$APP_DIR/$f"; done < "$WORK/DELETED"
ok "$(wc -l < "$LIST") fichier(s) appliqués / supprimés"

say "=== 5. Migration, index, cache ==="
spark migrate
spark export:doctor | grep -E "db.export_jobs|db.migrations" || true
spark snapshot:index
spark dashboard:warm
spark cache:clear >/dev/null 2>&1 || true

say "=== 6. Diagnostic ==="
spark export:doctor --preflight
spark export:doctor || warn "export:doctor signale des points (voir ci-dessus)"

say "=== 7. Cron 05:00 + rattrapage 06:00 ==="
# Un seul planificateur : le fichier /etc/cron.d/extractor-customers-refresh
# (réécrit ci-dessous). Toute autre entrée customers:refresh arrête le script.
OTHER=$(grep -l "customers:refresh" /etc/crontab /etc/cron.d/* 2>/dev/null | grep -v '^/etc/cron.d/extractor-customers-refresh$' || true)
if crontab -u "$APP_USER" -l 2>/dev/null | grep -q "customers:refresh"; then OTHER="$OTHER crontab:$APP_USER"; fi
[ -z "$OTHER" ] || die "autre(s) cron customers:refresh : $OTHER — le(s) retirer d'abord (pas de refresh concurrents)"
sh "$APP_DIR/docs/snapshot/extractor/install_customers_refresh_cron.sh" --app-dir "$APP_DIR" --user "$APP_USER" --duckdb "$DUCKDB" --dry-run
sh "$APP_DIR/docs/snapshot/extractor/install_customers_refresh_cron.sh" --app-dir "$APP_DIR" --user "$APP_USER" --duckdb "$DUCKDB"
cat /etc/cron.d/extractor-customers-refresh

say "=== 8. Redémarrage du worker ==="
systemctl start "$SERVICE"
sleep 5
systemctl is-active --quiet "$SERVICE" && ok "worker : active (running)" || die "worker inactif : journalctl -u $SERVICE -n 100 (rollback : sh $0 --rollback $BACKUP)"
journalctl -u "$SERVICE" --since "-2 min" --no-pager | tail -n 20
if journalctl -u "$SERVICE" --since "-2 min" --no-pager | grep -iE "duckdb|exception|error" | grep -v "0 error"; then
    warn "lignes suspectes dans le journal du worker (ci-dessus)"
fi

say "=== Terminé ==="
say "Vérification zéro Oracle pendant un parcours utilisateur (navigateur : filtres, tableau, export) :"
say "  watch -n1 \"ss -tnp '( dport = :1521 )' | grep -E 'apache|php' || echo 'aucune connexion Oracle'\""
say "Rollback si nécessaire : sh $0 --rollback $BACKUP"
