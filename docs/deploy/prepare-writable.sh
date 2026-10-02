#!/bin/sh
# extractor-prepare-writable — prepares the writable/ directories that the
# export worker (and the web server) write to. Root only, idempotent: safe at
# every deployment, every (re)start of the service, after a reboot.
#
#   sudo extractor-prepare-writable [APP_DIR] [APP_USER] [APP_GROUP]
#   defaults: /var/www/extractor www-data www-data
#
# Installed (root-owned, NOT run from the repository: a file the deploy user
# or www-data can edit must never be executed as root):
#   sudo install -o root -g root -m 0755 docs/deploy/prepare-writable.sh /usr/local/sbin/extractor-prepare-writable
# and called by systemd before each start of extractor-export-worker
# (ExecStartPre=+...), so a missing / root-owned directory is repaired on the
# next start, restart or reboot without anyone running chown by hand.
#
# Model ("shared group"), applied to the managed directories only, never
# recursively, never chmod 777:
#   - missing directory  -> created APP_USER:APP_GROUP, mode 2775;
#   - existing directory -> OWNER KEPT (deploy user, root...), group set to
#     APP_GROUP, g+rwx and setgid (g+s: files created inside inherit
#     APP_GROUP, so a log file created by the deploy user stays writable
#     by www-data); other bits unchanged;
#   - then a real test as APP_USER (create + delete a file and a folder):
#     exit 1 with an explicit message if it still fails (ACL, read-only
#     mount, AppArmor...).
# Contents are only touched where they are worker-private: OpenSpout
# scratch folders and worker state left by a root run are handed back to
# APP_USER, so the worker can clean / reuse them.
#
# Symlinks are refused for every managed path (root must not chown a target
# chosen by whoever can write the parent). APP_DIR and writable/ themselves
# may be symlinks (release layouts, separate volume): they are resolved first.

set -eu

APP_DIR=${1:-/var/www/extractor}
APP_USER=${2:-www-data}
APP_GROUP=${3:-$APP_USER}
TAG=extractor-prepare-writable

say() { printf '%s: %s\n' "$TAG" "$*"; }
die() { printf '%s: ERREUR: %s\n' "$TAG" "$*" >&2; exit 1; }

[ "$(id -u)" -eq 0 ] || die "à exécuter en root (sudo) : il crée et rectifie des répertoires pour $APP_USER"
id -u "$APP_USER" >/dev/null 2>&1 || die "utilisateur $APP_USER inconnu"
getent group "$APP_GROUP" >/dev/null 2>&1 || die "groupe $APP_GROUP inconnu"
command -v runuser >/dev/null 2>&1 || die "runuser introuvable (paquet util-linux)"

[ -d "$APP_DIR" ] || die "$APP_DIR absent"
APP_DIR=$(readlink -f "$APP_DIR")
[ -f "$APP_DIR/spark" ] || die "$APP_DIR/spark absent : $APP_DIR n'est pas la racine du projet"
[ -d "$APP_DIR/writable" ] || die "$APP_DIR/writable absent (dossier versionné) : déploiement incomplet ?"
W=$(readlink -f "$APP_DIR/writable")

# The worker only needs to traverse writable/ (its subfolders are prepared
# here): its own mode is the administrator's choice and is not changed.
as_worker() { runuser -u "$APP_USER" -- "$@"; }
as_worker test -x "$W" || die "$W non traversable par $APP_USER (droit x manquant sur $W ou un parent) : corriger les droits de ce dossier"

# Create + delete a file and a sub-folder as APP_USER — what the worker does.
can_write() {
    as_worker sh -c 'f=$(mktemp -p "$1" .prepare-probe.XXXXXX) && rm -f -- "$f" && d=$(mktemp -d -p "$1" .prepare-probe.XXXXXX) && rmdir -- "$d"' sh "$1" 2>/dev/null
}

ensure_dir() {
    d=$1
    [ -L "$d" ] && die "$d est un lien symbolique : refusé (root modifierait sa cible)"
    if [ -e "$d" ] && [ ! -d "$d" ]; then
        die "$d existe mais n'est pas un répertoire"
    fi

    if [ ! -d "$d" ]; then
        mkdir -- "$d"
        chown -h "$APP_USER:$APP_GROUP" -- "$d"
        chmod 2775 -- "$d"
        say "créé    $d ($APP_USER:$APP_GROUP 2775)"
    else
        before=$(stat -c '%U:%G %a' -- "$d")
        chgrp -h "$APP_GROUP" -- "$d"
        chmod g+rwxs -- "$d"
        after=$(stat -c '%U:%G %a' -- "$d")
        if [ "$before" = "$after" ]; then
            say "ok      $d ($after)"
        else
            say "ajusté  $d ($before -> $after, propriétaire inchangé)"
        fi
    fi

    can_write "$d" || die "$d toujours non inscriptible pour $APP_USER après préparation ($(stat -c '%U:%G %a' -- "$d")) : ACL, montage en lecture seule, AppArmor ? Vérifier avec : getfacl $d ; findmnt -T $d"
}

# Hands entries not owned by APP_USER back to it (no symlink followed).
reclaim() {
    d=$1
    shift
    n=$(find "$d" -mindepth 1 "$@" ! -user "$APP_USER" -print | wc -l)
    if [ "$n" -gt 0 ]; then
        find "$d" -mindepth 1 "$@" ! -user "$APP_USER" -exec chown -h "$APP_USER:$APP_GROUP" {} +
        say "rendu   $n entrée(s) de $d à $APP_USER (créées par un autre utilisateur, root le plus souvent)"
    fi
}

# Order matters: each parent before its children.
ensure_dir "$W/logs"
ensure_dir "$W/cache"
[ -d "$W/uploads" ] || ensure_dir "$W/uploads"
ensure_dir "$W/uploads/exports"
ensure_dir "$W/tmp"
ensure_dir "$W/tmp/openspout_tmp"
ensure_dir "$W/export-worker"

# Worker-private contents: OpenSpout scratch (export_*) and lock/heartbeat.
# A root-owned worker.lock makes every www-data worker believe another one
# is running; a root-owned scratch folder can never be swept.
reclaim "$W/tmp/openspout_tmp"
reclaim "$W/export-worker" -maxdepth 1 -type f
# Log files: group only (owner kept), so www-data can append to a log of the
# day first created by the deploy user.
find "$W/logs" -maxdepth 1 -type f -name 'log-*.log' ! -user "$APP_USER" \( ! -group "$APP_GROUP" -o ! -perm -g=w \) \
    -exec chgrp -h "$APP_GROUP" {} + -exec chmod g+rw {} +

say "terminé : répertoires du worker prêts pour $APP_USER:$APP_GROUP sous $W"
