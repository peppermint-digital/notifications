#!/usr/bin/env bash
#
# Die Testreihe gegen BEIDE Treiber.
#
# SQLite allein genuegt nicht: Es nimmt Dinge an, an denen MySQL abbricht —
# ENUM-Werte, `ORDER BY` auf eine Spalte, die es nicht gibt, zu lange
# Indexnamen. Produktion laeuft bei drei der vier Produkte auf MySQL.
#
# Der MySQL-Lauf braucht eine Wegwerf-Datenbank; den Befehl dafuer nennt das
# Skript, wenn sie fehlt.
set -euo pipefail

cd "$(dirname "$0")/.."

PEST="vendor/bin/pest"
PHP_ARGS=(-d memory_limit=512M)

# pdo_mysql liegt auf diesem Rechner zwar vor, ist in der CLI-php.ini aber
# nicht geladen. `-d` statt eines Eingriffs in die globale Konfiguration:
# Dieser Rechner ist der Live-Host von AI Brain.
if [ -f /usr/lib/php/modules/pdo_mysql.so ]; then
    PHP_ARGS+=(-d extension=pdo_mysql.so)
fi

echo "######## SQLite ########"
php "${PHP_ARGS[@]}" "$PEST" --compact --colors=never "$@"

echo
echo "######## MySQL ########"
if ! docker ps --format '{{.Names}}' | grep -qx peppermint-notifications-test-db; then
    echo "Keine Test-Datenbank. Einmalig starten mit:"
    echo
    echo "  docker run -d --name peppermint-notifications-test-db \\"
    echo "    -e MYSQL_ROOT_PASSWORD=test -e MYSQL_DATABASE=notifications_test \\"
    echo "    -p 33061:3306 mariadb:11"
    echo
    exit 1
fi

DB_DRIVER=mysql \
DB_DATABASE=notifications_test \
DB_HOST=127.0.0.1 \
DB_PORT=33061 \
DB_USERNAME=root \
DB_PASSWORD=test \
    php "${PHP_ARGS[@]}" "$PEST" --compact --colors=never "$@"
