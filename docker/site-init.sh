#!/bin/bash
# Idempotent site setup. Runs on every container start:
#   1. wait for WordPress core + the database,
#   2. sync the vendored theme/plugin into wp-content,
#   3. install WordPress once (Romanian), then run the seed (brand, pages, menus, ads, article import).
set -u
WP="wp --allow-root --path=/var/www/html"
log(){ echo "[site-init] $*"; }

for i in $(seq 1 120); do
  [ -f /var/www/html/wp-config.php ] && $WP db check >/dev/null 2>&1 && break
  sleep 3
done
$WP db check >/dev/null 2>&1 || { log "database not reachable, giving up"; exit 0; }

log "syncing theme + plugin"
for d in themes/viral-reader themes/crosetam plugins/automation-hamri; do
  rm -rf "/var/www/html/wp-content/$d"
  mkdir -p "$(dirname /var/www/html/wp-content/$d)"
  cp -r "/opt/site/wp-content/$d" "/var/www/html/wp-content/$d"
done
chown -R www-data:www-data /var/www/html/wp-content

URL="${SITE_URL:-}"
[ -z "$URL" ] && URL="${SERVICE_FQDN_WORDPRESS:-https://crosetam.getemoji.site}"
if ! $WP core is-installed >/dev/null 2>&1; then
  log "installing WordPress at $URL"
  $WP core install --url="$URL" --title="Croșetăm" \
    --admin_user="${WP_ADMIN_USER:-redactia}" --admin_password="${WP_ADMIN_PASSWORD:?WP_ADMIN_PASSWORD missing}" \
    --admin_email="${WP_ADMIN_EMAIL:-daseknahri@gmail.com}" --skip-email
fi
# Romanian core strings (retried every start until the download succeeds).
$WP language core is-installed ro_RO || $WP language core install ro_RO || log "language pack download failed (retried next start)"
$WP language core is-installed ro_RO && [ "$($WP option get WPLANG 2>/dev/null)" != "ro_RO" ] && $WP site switch-language ro_RO

$WP theme activate crosetam
$WP plugin activate automation-hamri
# Google Site Kit (Analytics + AdSense reports in wp-admin). Installed once from wordpress.org, then kept on the volume.
$WP plugin is-installed google-site-kit || $WP plugin install google-site-kit || log "site kit download failed (retried next start)"
$WP plugin is-installed google-site-kit && $WP plugin activate google-site-kit
$WP eval-file /opt/site/seed/seed.php && log "seed done"
chown -R www-data:www-data /var/www/html/wp-content/uploads 2>/dev/null || true
