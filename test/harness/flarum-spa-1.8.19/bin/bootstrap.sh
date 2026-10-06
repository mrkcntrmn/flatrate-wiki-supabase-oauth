#!/usr/bin/env bash
# Disposable Flarum 1.8.19 install for FORUM-IDENTITY-002-R3 SPA boot CI.
set -euo pipefail
export PATH="/usr/bin:/bin:/usr/local/bin:${PATH:-}"

HARNESS_DIR="$(cd "$(dirname "$0")/.." && pwd)"
EXTENSION_ROOT="$(cd "$HARNESS_DIR/../../.." && pwd)"
WORK_DIR="${FLARUM_WORK_DIR:-$HARNESS_DIR/.work}"
FLARUM_DIR="$WORK_DIR/flarum"
BASE_URL="${FLARUM_BASE_URL:-http://127.0.0.1:8080}"

DB_HOST="${MARIADB_HOST:-127.0.0.1}"
DB_PORT="${MARIADB_PORT:-3306}"
DB_NAME="${MARIADB_DATABASE:-flarum_spa}"
DB_USER="${MARIADB_USER:-flarum}"
DB_PASSWORD="${MARIADB_PASSWORD:-flarum}"

mkdir -p "$WORK_DIR"
rm -rf "$FLARUM_DIR"

sed \
  -e "s#http://127.0.0.1:8080#${BASE_URL}#g" \
  -e "s#host: 127.0.0.1#host: ${DB_HOST}#g" \
  -e "s#port: 3306#port: ${DB_PORT}#g" \
  -e "s#database: flarum_spa#database: ${DB_NAME}#g" \
  -e "s#username: flarum#username: ${DB_USER}#g" \
  -e "s#password: flarum#password: ${DB_PASSWORD}#g" \
  "$HARNESS_DIR/install.yml" > "$WORK_DIR/install.yml"

composer create-project flarum/flarum:1.8.1 "$FLARUM_DIR" --no-interaction --prefer-dist
cd "$FLARUM_DIR"

# Composer blocks the Flysystem 1.x line required by supported Flarum 1.8.x.
# Permit only the two already reviewed advisory IDs in this disposable tree.
# Do not disable advisory blocking, and do not change the extension composer.json.
php -r '
$path = "composer.json";
$root = json_decode((string) file_get_contents($path), true);
if (!is_array($root)) { fwrite(STDERR, "disposable composer.json is invalid\n"); exit(1); }
$root["config"]["policy"]["advisories"]["block"] = true;
$root["config"]["policy"]["advisories"]["ignore-id"] = [
    "PKSA-w9tt-7782-78jx" => "Required transitively by supported Flarum 1.8.x; disposable qualification only.",
    "PKSA-pwh8-d4fr-nywn" => "Required transitively by supported Flarum 1.8.x; disposable qualification only.",
];
$encoded = json_encode($root, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
if (!is_string($encoded)) { fwrite(STDERR, "disposable composer.json encode failed\n"); exit(1); }
file_put_contents($path, $encoded . "\n");
'
grep -q PKSA-w9tt-7782-78jx composer.json
grep -q PKSA-pwh8-d4fr-nywn composer.json

composer require --no-interaction --prefer-dist \
  flarum/core:1.8.19 \
  flarum/nicknames:1.8.3 \
  flarum/tags:1.8.8 \
  fof/oauth:1.7.4 \
  fof/gamification:1.6.12

composer config repositories.flatrate "{\"type\":\"path\",\"url\":\"$EXTENSION_ROOT\",\"options\":{\"symlink\":true}}"
composer require --no-interaction --prefer-dist flatrate/wiki-supabase-oauth:@dev

php flarum install --file="$WORK_DIR/install.yml"
php flarum cache:clear
php flarum migrate
php flarum extension:enable flarum-nicknames
php flarum extension:enable flarum-tags
php flarum extension:enable flarum-mentions
php flarum extension:enable fof-oauth
php flarum extension:enable fof-gamification
php flarum extension:enable flatrate-wiki-supabase-oauth
php flarum migrate
php flarum cache:clear
php flarum assets:publish || true

php "$HARNESS_DIR/bin/seed.php" "$FLARUM_DIR" "$WORK_DIR/seed.json"
cp "$HARNESS_DIR/bin/flarum-router.php" "$FLARUM_DIR/router.php"
php "$HARNESS_DIR/bin/inspect-assets.php" "$FLARUM_DIR" "$WORK_DIR/assets.json"

printf '%s\n' "$FLARUM_DIR" > "$WORK_DIR/flarum-dir.txt"
echo "FLARUM_SPA_BOOTSTRAP=PASS"
echo "FLARUM_DIR=$FLARUM_DIR"
echo "FLARUM_CORE_VERSION_TESTED=1.8.19"
echo "FOF_GAMIFICATION_VERSION=1.6.12"
