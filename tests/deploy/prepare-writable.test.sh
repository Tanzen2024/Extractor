#!/bin/sh
# Integration test of docs/deploy/prepare-writable.sh on a scratch project
# tree, runnable without root (Linux, macOS, Git Bash on Windows):
#
#   sh tests/deploy/prepare-writable.test.sh
#
# id / getent / runuser / chown / chgrp are replaced by stubs on PATH: the
# directory creation, ordering, idempotence, refusals and the "worker cannot
# write" path run for real; ownership changes are recorded, not applied
# (they need root — checked on a real server by `systemctl start` +
# `sudo -u www-data php spark export:doctor`, docs/deploy/export-worker.md).
# FAKE_DENY=<substring>: runuser fails for any command mentioning it, i.e.
# "www-data cannot write there".

set -u

HERE=$(cd "$(dirname "$0")" && pwd)
SCRIPT="$HERE/../../docs/deploy/prepare-writable.sh"
ROOT=$(mktemp -d)
STUBS="$ROOT/stubs"
LOG="$ROOT/calls.log"
FAILS=0
# The "worker" is the current account: find -user/-group need a real one.
U=$(id -un)
G=$(id -gn 2>/dev/null) || G=$(id -g)

trap 'rm -rf "$ROOT"' EXIT INT TERM

mkdir -p "$STUBS"
cat > "$STUBS/id" <<'EOF'
#!/bin/sh
[ "$#" -eq 1 ] && [ "$1" = "-u" ] && { echo 0; exit 0; }
echo 33
EOF
cat > "$STUBS/getent" <<'EOF'
#!/bin/sh
exit 0
EOF
cat > "$STUBS/runuser" <<'EOF'
#!/bin/sh
# runuser -u USER -- cmd...
shift 3
if [ -n "${FAKE_DENY:-}" ]; then
    case "$*" in *"$FAKE_DENY"*) exit 1 ;; esac
fi
exec "$@"
EOF
for cmd in chown chgrp; do
    printf '#!/bin/sh\necho "%s $*" >> "%s"\n' "$cmd" "$LOG" > "$STUBS/$cmd"
done
chmod +x "$STUBS"/*

new_project() {
    P="$ROOT/app_$1"
    mkdir -p "$P/writable/uploads" "$P/writable/logs"
    : > "$P/spark"
    echo "$P"
}

run() { PATH="$STUBS:$PATH" sh "$SCRIPT" "$1" "$U" "$G" 2>&1; }

check() { # description, condition...
    d=$1
    shift
    if "$@"; then echo "ok   - $d"; else echo "FAIL - $d"; FAILS=$((FAILS + 1)); fi
}

# 1. Fresh install: tmp/, openspout_tmp/, exports/, export-worker/, cache/ absent.
P=$(new_project fresh)
out=$(run "$P"); rc=$?
check "fresh install exits 0" [ "$rc" -eq 0 ]
for d in logs cache uploads/exports tmp tmp/openspout_tmp export-worker; do
    check "fresh install: writable/$d exists" [ -d "$P/writable/$d" ]
done
check "fresh install: openspout_tmp handed to the worker user" grep -q "chown -h $U:$G -- $P/writable/tmp/openspout_tmp" "$LOG"
check "fresh install: reports creation" sh -c "echo \"\$1\" | grep -q 'créé    .*openspout_tmp'" sh "$out"
check "no probe file left behind" [ -z "$(find "$P/writable" -name '.prepare-probe.*')" ]

# 2. Idempotent: second run, same tree, exit 0, nothing created.
out=$(run "$P"); rc=$?
check "second run exits 0" [ "$rc" -eq 0 ]
check "second run creates nothing" sh -c "! echo \"\$1\" | grep -q 'créé'" sh "$out"

# 3. Directory deleted between two starts: recreated.
rm -rf "$P/writable/tmp/openspout_tmp"
out=$(run "$P"); rc=$?
check "deleted openspout_tmp recreated (exit 0)" [ "$rc" -eq 0 ]
check "deleted openspout_tmp recreated" [ -d "$P/writable/tmp/openspout_tmp" ]

# 4. Existing directory the worker cannot write even after the group fix
#    (ACL, read-only mount...): explicit failure, non-zero exit.
out=$(FAKE_DENY=openspout_tmp run "$P"); rc=$?
check "non-writable openspout_tmp -> exit 1" [ "$rc" -ne 0 ]
check "non-writable openspout_tmp -> explicit message" sh -c "echo \"\$1\" | grep -q \"openspout_tmp toujours non inscriptible pour \$2\"" sh "$out" "$U"

# 5. Existing directory: group / setgid adjusted, owner never changed.
: > "$LOG"
run "$P" >/dev/null
check "existing dirs: chgrp only, no chown of the directory itself" sh -c "grep -q 'chgrp -h $G -- $P/writable/logs' '$LOG' && ! grep -q 'chown -h $U:$G -- $P/writable/logs\$' '$LOG'"

# 6. A file where a directory is expected: refused.
P=$(new_project file)
mkdir -p "$P/writable/tmp"
: > "$P/writable/tmp/openspout_tmp"
out=$(run "$P"); rc=$?
check "file instead of directory -> exit 1" [ "$rc" -ne 0 ]
check "file instead of directory -> message" sh -c "echo \"\$1\" | grep -q \"n'est pas un répertoire\"" sh "$out"

# 7. Symlink on a managed path: refused (only where the OS makes real ones).
P=$(new_project link)
mkdir -p "$P/writable/tmp" "$ROOT/elsewhere"
if ln -s "$ROOT/elsewhere" "$P/writable/tmp/openspout_tmp" 2>/dev/null && [ -L "$P/writable/tmp/openspout_tmp" ]; then
    out=$(run "$P"); rc=$?
    check "symlinked openspout_tmp -> exit 1" [ "$rc" -ne 0 ]
    check "symlinked openspout_tmp -> message" sh -c "echo \"\$1\" | grep -q 'lien symbolique'" sh "$out"
else
    echo "skip - symlink test (no real symlinks on this filesystem)"
fi

# 8. Not a project root.
mkdir -p "$ROOT/notaproject/writable"
out=$(run "$ROOT/notaproject"); rc=$?
check "missing spark -> exit 1" [ "$rc" -ne 0 ]

# 9. Leftover root-owned scratch is handed back (find ! -user <worker>).
#    Needs entries owned by someone else: only possible when run as root.
if [ "$(id -u)" -eq 0 ] && id -u nobody >/dev/null 2>&1; then
    P=$(new_project reclaim)
    run "$P" >/dev/null
    mkdir -p "$P/writable/tmp/openspout_tmp/export_old/xlsx1"
    /bin/chown -R nobody "$P/writable/tmp/openspout_tmp/export_old"
    : > "$LOG"
    run "$P" >/dev/null
    check "foreign scratch entries chowned to the worker" grep -q "chown -h $U:$G .*export_old" "$LOG"
else
    echo "skip - foreign-owned scratch reclaim (needs root)"
fi

echo
if [ "$FAILS" -eq 0 ]; then echo "prepare-writable: all checks passed"; else echo "prepare-writable: $FAILS check(s) FAILED"; fi
[ "$FAILS" -eq 0 ]
