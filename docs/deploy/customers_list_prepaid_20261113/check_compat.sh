#!/bin/bash
# =============================================================================
# check_compat.sh - LECTURE SEULE - compatibilite SQL / .ctl / CSV / table
#
#   ./check_compat.sh                                controles sur fichiers
#   ORA_CONNECT=CMS_RFC@cmsprod ./check_compat.sh    + colonnes de la table (sqlplus)
#   STRICT=1 ...   le .ctl et la table doivent DEJA etre a 28 colonnes (avant
#                  le remplacement du SQL : etapes 1 et 2 faites)
#
# Etats du .ctl et de la table : 28 = cible (OK) ; 27 = etape pas encore faite
# (ALERTE, ou ECHEC si STRICT=1) ; autre = ECHEC.
#
# Variables (valeurs par defaut) :
#   REPORT_DIR  /u01/COMMERCIALS_APPS/Nwameh
#   CTL         $REPORT_DIR/RECONCIL*IATION_EneoPay-CMS/control_tb_customers_list.ctl
#   CSV         $REPORT_DIR/customers_list.csv   (dernier fichier genere, s'il existe)
#   SQL         ./customers_list.sql             (SQL du kit)
#
# N'ecrit rien, ne lance aucun chargement. Code retour 0 = aucun echec.
# =============================================================================
set -u
KIT="$(cd "$(dirname "$0")" && pwd)"
REPORT_DIR="${REPORT_DIR:-/u01/COMMERCIALS_APPS/Nwameh}"
SQL="${SQL:-$KIT/customers_list.sql}"
CSV="${CSV:-$REPORT_DIR/customers_list.csv}"
EXPECTED="$KIT/expected_columns.txt"
NEW_SHA=f85882369407b53da65331ba0429230325463a2ac261501cadae086bd3ece6ca
STRICT="${STRICT:-0}"
if [ -z "${CTL:-}" ]; then
    for c in "$REPORT_DIR"/RECONCIL*IATION_EneoPay-CMS/control_tb_customers_list.ctl; do [ -f "$c" ] && CTL="$c"; done
fi
CTL="${CTL:-<introuvable>}"

FAIL=0; WARN=0
ok()   { echo "  OK    $*"; }
ko()   { echo "  ECHEC $*"; FAIL=$((FAIL+1)); }
warn() { echo "  ALERTE $*"; WARN=$((WARN+1)); }
same_list() { diff <(printf '%s\n' "$1") "$EXPECTED" >/dev/null; }
is_old27()  { diff <(printf '%s\n' "$1") <(head -27 "$EXPECTED") >/dev/null; }
show_diff() { diff <(printf '%s\n' "$1") "$EXPECTED" | sed 's/^/        /'; }
# $1 = libelle, $2 = liste de colonnes
stage() {
    if same_list "$2"; then ok "$1 : 28 colonnes dans l'ordre attendu"
    elif is_old27 "$2"; then
        if [ "$STRICT" = 1 ]; then ko "$1 : encore 27 colonnes (FAILURE_SUBCATEGORY absente)"
        else warn "$1 : 27 colonnes, FAILURE_SUBCATEGORY pas encore ajoutee (etape de la phase B)"; fi
    else ko "$1 : colonnes inattendues"; show_diff "$2"; fi
}

echo "== 1. SQL du kit : $SQL"
if [ -f "$SQL" ]; then
    [ "$(sha256sum "$SQL" | cut -d' ' -f1)" = "$NEW_SHA" ] && ok "empreinte = version validee" || ko "empreinte differente de la version validee"
    for cte in prepaid_reference prepaid_new_buyers prepaid_last prepaid_recense prepaid_rfm postpaid_profile postpaid_last; do
        grep -q "^$cte AS (" "$SQL" && ok "etape $cte presente" || ko "etape $cte absente"
    done
    for lit in "'4-Suspect Dormant'" "'5-Dormant'" "'6-Never Vending'" "'1-Stable'"; do
        grep -q "THEN $lit" "$SQL" && ok "libelle $lit produit" || ko "libelle $lit absent"
    done
    grep -q "RECENSE >= 60" "$SQL" && grep -q "RECENSE >= 30" "$SQL" && \
        [ "$(grep -n 'RECENSE >= 60' "$SQL" | cut -d: -f1)" -lt "$(grep -n 'RECENSE >= 30' "$SQL" | cut -d: -f1)" ] \
        && ok "bornes : >= 60 teste avant >= 30 (30..59 = 4-Suspect Dormant)" || ko "ordre des bornes RECENSE"
    for old in "6 Old_Dormant" "7 Never Vending" "6 Never Vending"; do
        grep -q "'$old'" "$SQL" && ko "ancien libelle '$old' encore produit" || ok "pas de '$old'"
    done
    grep -qi "PREPAID_RECENCY" "$SQL" && ko "colonnes PREPAID_RECENCY presentes (hors perimetre)" || ok "aucune colonne PREPAID_RECENCY_*"
    grep -q "END AS FAILURE_SUBCATEGORY" "$SQL" && ok "colonne 28 FAILURE_SUBCATEGORY presente" || ko "colonne 28 absente"
    grep -qF "pp.SERVICE_NO IS NULL OR pp.PROFILE LIKE '6 D%faillant'" "$SQL" \
        && ok "colonne 28 : population = Defaillant de la regle en place + sans profil" || ko "condition de population de la colonne 28"
    for comp in ALWAYS_LATE NEVER_PAID SOMETIME_PAID; do
        grep -q "THEN '$comp'" "$SQL" && ok "composante $comp" || ko "composante $comp absente"
    done
    grep -q "ELSE 'NEVER_PAID'" "$SQL" && ok "reste du profil -> NEVER_PAID (aucun non decompose)" || ko "pas de ELSE NEVER_PAID"
    grep -qE "THEN '(OTHER|UNASSIGNED)'" "$SQL" && ko "composante hors nomenclature" || ok "aucune autre composante"
    grep -q "ELSE '8 Autre'" "$SQL" && ! grep -q "profile_calculation" "$SQL" \
        && ok "POSTPAID = regle du 01/10 (postpaid_profile, 8 Autre), profile_calculation absent" || ko "regle POSTPAID modifiee"
    # Colonnes du SELECT final, dans l'ordre (niveau 4 espaces ou alias AS)
    SQLCOLS=$(sed -n '/RESULTAT FINAL/,/^FROM lister l/p' "$SQL" | tr -d '\r' \
        | sed -nE 's/^ {4}([a-z]+\.)?([A-Z_0-9]+),?$/\2/p; s/^.* AS ([A-Z_0-9]+),?$/\1/p' | grep -vxE 'CASE|END|ELSE|WHEN|THEN')
    same_list "$SQLCOLS" && ok "SELECT final : 28 colonnes dans l'ordre attendu" || { ko "colonnes du SELECT final"; show_diff "$SQLCOLS"; }
    [ "$(head -c3 "$SQL" | od -An -tx1 | tr -d ' ')" = "efbbbf" ] && warn "BOM UTF-8 en tete du SQL" || ok "pas de BOM"
    [ "$(tr -cd '\r' < "$SQL" | wc -c)" -eq 0 ] && ok "fins de ligne LF" || warn "fins de ligne CRLF"
    iconv -f UTF-8 -t UTF-8 "$SQL" >/dev/null 2>&1 && ok "UTF-8 valide" || ko "SQL non UTF-8"
else
    ko "SQL absent"
fi

echo "== 2. Fichier de controle SQL*Loader : $CTL"
if [ -f "$CTL" ]; then
    C=$(tr -d '\r' < "$CTL")
    # Liste des champs : apres « trailing nullcols » (casse indifferente)
    CTLCOLS=$(printf '%s\n' "$C" | awk 'f; tolower($0) ~ /trailing[ \t]+nullcols/ {f=1}' \
        | sed -nE 's/^\s*([A-Za-z_0-9]+)\s+(CHAR|INTEGER|DATE|DECIMAL|FILLER|char|integer|date|decimal|filler)\b.*/\1/p' | tr 'a-z' 'A-Z')
    stage ".ctl" "$CTLCOLS"
    printf '%s\n' "$C" | grep -qiE "terminated[[:space:]]+by[[:space:]]+[\"']#[\"']" && ok "separateur #" || ko "separateur different de #"
    CS=$(printf '%s\n' "$C" | grep -oiE '^\s*characterset\s+\S+' | awk '{print toupper($2)}')
    case "$CS" in
        AL32UTF8|UTF8) ok "characterset $CS (valeurs ajoutees en ASCII : sans effet)" ;;
        *) warn "characterset '${CS:-absent}' (UTF8 ou AL32UTF8 attendu)" ;;
    esac
    printf '%s\n' "$C" | grep -qiE "trailing[[:space:]]+nullcols" && ok "trailing nullcols" || warn "pas de trailing nullcols"
    N=$(printf '%s\n' "$C" | grep -ci 'DATE "YYYY-MM-DD HH24:MI:SS"'); [ "$N" -eq 4 ] && ok "4 dates au format YYYY-MM-DD HH24:MI:SS" || warn "$N masques de date (4 attendus)"
    MODE=$(printf '%s\n' "$C" | grep -oiwE 'append|truncate|replace|insert' | head -1 | tr 'a-z' 'A-Z')
    echo "        mode : ${MODE:-INSERT (defaut : table vide exigee : truncate_proc.sh avant sqlldr)}"
    echo "        $(printf '%s\n' "$C" | grep -oi "INFILE.*" | head -1)"
    echo "        $(printf '%s\n' "$C" | grep -oiE '^\s*options\s*\(.*\)' | head -1 | sed 's/^ *//')"
    printf '%s\n' "$C" | grep -qiE 'errors\s*=\s*0\b' && warn "options errors=0 : la moindre ligne rejetee interrompt le chargement (table deja videe)"
    printf '%s\n' "$C" | grep -qiE 'skip\s*=\s*1\b' && ok "skip=1 : ligne d'en-tete du CSV ignoree"
else
    ko ".ctl introuvable (definir CTL=...)"
fi

echo "== 3. Dernier CSV genere : $CSV"
if [ -f "$CSV" ]; then
    HCOLS=$(head -1 "$CSV" | tr -d '\r"' | tr '#' '\n')
    if same_list "$HCOLS"; then ok "en-tete CSV = 28 colonnes attendues"
    elif is_old27 "$HCOLS"; then warn "en-tete CSV = 27 colonnes : CSV genere par l'ancien SQL"
    else warn "en-tete CSV different (ou absent)"; show_diff "$HCOLS"; fi
    echo "        champs par ligne (500 000 premieres lignes) :"
    head -500000 "$CSV" | awk -F'#' '{n[NF]++} END {for (k in n) printf "          %s champs : %s lignes\n", k, n[k]}'
    head -c 200000000 "$CSV" | iconv -f UTF-8 -t UTF-8 >/dev/null 2>&1 && ok "200 premiers Mo : UTF-8 valide" || warn "octets non UTF-8 dans le CSV actuel (preexistant, non lie au SQL du kit)"
    for col in SEGMENTATION FAILURE_SUBCATEGORY; do
        I=$(printf '%s\n' "$HCOLS" | grep -nx "$col" | cut -d: -f1)
        [ -n "$I" ] || continue
        echo "        valeurs de $col (col. $I) :"
        awk -F'#' -v s="$I" 'NR>1 {v=$s; gsub(/"/,"",v); n[v]++} END {for (k in n) printf "          %-24s %s\n", (k==""?"<vide>":k), n[k]}' "$CSV" | sort
    done
else
    warn "CSV absent : controle du format a refaire apres la prochaine generation"
fi

echo "== 4. Table CMS_RFC.TB_CUSTOMERS_LIST"
if [ -n "${ORA_CONNECT:-}" ] && command -v sqlplus >/dev/null; then
    TCOLS=$(sqlplus -S "$ORA_CONNECT" <<'EOF' | grep -E '^[A-Z_0-9]+$'
SET HEADING OFF FEEDBACK OFF PAGESIZE 0 LINESIZE 200
SELECT column_name FROM all_tab_columns WHERE owner = 'CMS_RFC' AND table_name = 'TB_CUSTOMERS_LIST' ORDER BY column_id;
EXIT
EOF
)
    stage "table" "$TCOLS"
elif [ "$STRICT" = 1 ]; then
    ko "table non verifiee : ORA_CONNECT et sqlplus requis"
else
    echo "        (ignore : definir ORA_CONNECT et disposer de sqlplus ; 13/11 depuis Extractor : 27 colonnes, sans FAILURE_SUBCATEGORY)"
fi

echo "== Bilan : $FAIL echec(s), $WARN alerte(s)"
[ "$FAIL" -eq 0 ]
