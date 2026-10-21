#!/bin/sh
# Supervise la vulgarisation IA : relance la commande jusqu'à ce qu'il n'y
# plus rien à traiter. Chaque relance reprend là où la précédente s'est
# arrêtée (les textes déjà vulgarisés sont ignorés).
#
# Usage : vulgarisation-supervise.sh <shard> <nbShards>
set -u

cd /var/www/symfony || exit 1

SHARD="${1:-0/2}"
LOG="var/log/vulgarisation-$(printf '%s' "$SHARD" | tr '/' '-').log"
DELAI="${DELAI:-20}"
MAX_SANS_PROGRES="${MAX_SANS_PROGRES:-3}"

mkdir -p var/log

sans_progres=0
dernier=""

while :; do
    php -d memory_limit=2G bin/console app:vulgariser --shard="$SHARD" -v --no-debug >>"$LOG" 2>&1

    if tail -3 "$LOG" | grep -q "Rien à faire"; then
        echo "[$(date '+%F %T')] shard $SHARD : plus rien à faire" >>"$LOG"
        break
    fi

    total=$(grep -oE "Déjà vulgarisés : [0-9]+" "$LOG" | tail -1 | grep -oE "[0-9]+")
    if [ -n "${total:-}" ] && [ "$total" = "$dernier" ]; then
        sans_progres=$((sans_progres + 1))
        echo "[$(date '+%F %T')] shard $SHARD : aucun progrès ($total), essai $sans_progres/$MAX_SANS_PROGRES" >>"$LOG"
        if [ "$sans_progres" -ge "$MAX_SANS_PROGRES" ]; then
            echo "[$(date '+%F %T')] shard $SHARD : abandon (plus de progrès)" >>"$LOG"
            break
        fi
        sleep 120
    else
        sans_progres=0
        dernier="${total:-}"
    fi

    sleep "$DELAI"
done
