#!/usr/bin/env bash
# Installs and configures WordPress for the Playwright E2E test suite.
# Runs against the docker-compose.test.yml stack (wpcli service).
#
# Usage: bash docker/setup-test-wp.sh
# Must be run from the repo root after `docker compose -f docker-compose.test.yml up -d`.

set -euo pipefail

WP_URL="${WP_URL:-http://localhost:8080}"
WP_ADMIN_USER="${WP_ADMIN_USER:-admin}"
WP_ADMIN_PASS="${WP_ADMIN_PASS:-adminpass123}"
WP_ADMIN_EMAIL="${WP_ADMIN_EMAIL:-admin@example.com}"
WP_TECH1_USER="${WP_TECH1_USER:-tech1}"
WP_TECH1_PASS="${WP_TECH1_PASS:-techpass123}"
WP_TECH1_EMAIL="${WP_TECH1_EMAIL:-tech1@example.com}"
WP_TECH2_USER="${WP_TECH2_USER:-tech2}"
WP_TECH2_PASS="${WP_TECH2_PASS:-techpass123}"
WP_TECH2_EMAIL="${WP_TECH2_EMAIL:-tech2@example.com}"
CLIENT1_NAME="${CLIENT1_NAME:-Test Client One}"
CLIENT1_EMAIL="${CLIENT1_EMAIL:-client1@example.com}"
CLIENT2_NAME="${CLIENT2_NAME:-Test Client Two}"
CLIENT2_EMAIL="${CLIENT2_EMAIL:-client2@example.com}"

DC="docker compose -f docker-compose.test.yml"
WP="$DC exec -T wpcli wp --allow-root --path=/var/www/html"

echo "→ Waiting for WordPress to be ready..."
for i in $(seq 1 30); do
  curl -sf "$WP_URL/wp-login.php" > /dev/null 2>&1 && break
  sleep 2
  if [ "$i" -eq 30 ]; then
    echo "  ✗ WordPress did not become ready at $WP_URL after 60 seconds." >&2
    exit 1
  fi
done
echo "  ✓ WordPress is up"

echo "→ Installing WordPress core..."
if ! $WP core is-installed 2>/dev/null; then
  $WP core install \
    --url="$WP_URL" \
    --title="SWH Test" \
    --admin_user="$WP_ADMIN_USER" \
    --admin_password="$WP_ADMIN_PASS" \
    --admin_email="$WP_ADMIN_EMAIL" \
    --skip-email
fi

echo "→ Setting permalink structure..."
$WP rewrite structure '/%postname%/' --hard

echo "→ Writing .htaccess with Authorization header passthrough..."
docker compose -f docker-compose.test.yml exec -T -u root wordpress \
  bash -c "printf '# BEGIN WordPress\n<IfModule mod_rewrite.c>\nRewriteEngine On\nRewriteBase /\nRewriteRule ^index\\.php$ - [L]\nRewriteCond %%{HTTP:Authorization} .\nRewriteRule .* - [E=HTTP_AUTHORIZATION:%%{HTTP:Authorization}]\nRewriteCond %%{REQUEST_FILENAME} !-f\nRewriteCond %%{REQUEST_FILENAME} !-d\nRewriteRule . /index.php [L]\n</IfModule>\n# END WordPress\n' > /var/www/html/.htaccess" 2>/dev/null || true
echo "  ✓ .htaccess written"

echo "→ Creating uploads directory..."
docker compose -f docker-compose.test.yml exec -T -u root wordpress \
  bash -c "mkdir -p /var/www/html/wp-content/uploads/swh-helpdesk && chown -R www-data:www-data /var/www/html/wp-content/uploads" 2>/dev/null || true

echo "→ Activating plugin..."
$WP plugin activate simple-wp-helpdesk

echo "→ Setting inbound webhook secret..."
$WP option update swh_inbound_secret "swh-ci-webhook-secret"

echo "→ Creating technician users..."
$WP user create "$WP_TECH1_USER" "$WP_TECH1_EMAIL" \
  --role=technician --user_pass="$WP_TECH1_PASS" --porcelain 2>/dev/null || true
$WP user create "$WP_TECH2_USER" "$WP_TECH2_EMAIL" \
  --role=technician --user_pass="$WP_TECH2_PASS" --porcelain 2>/dev/null || true

echo "→ Creating submission page..."
SUBMIT_ID=$($WP post create \
  --post_type=page \
  --post_status=publish \
  --post_title="Submit a Ticket" \
  --post_content="[submit_ticket]" \
  --porcelain)

echo "→ Creating portal page..."
PORTAL_ID=$($WP post create \
  --post_type=page \
  --post_status=publish \
  --post_title="Helpdesk Portal" \
  --post_content="[helpdesk_portal]" \
  --porcelain)

echo "→ Saving portal page ID to plugin settings..."
$WP option update swh_ticket_page_id "$PORTAL_ID"

SUBMIT_URL=$($WP post get "$SUBMIT_ID" --field=url)
PORTAL_URL=$($WP post get "$PORTAL_ID" --field=url)

echo ""
echo "✅ WordPress setup complete."
echo ""
echo "Add these to your testing/.env (or CI environment variables):"
echo ""
echo "  WP_URL=$WP_URL"
echo "  WP_LOGIN_URL=$WP_URL/wp-login.php"
echo "  WP_ADMIN_URL=$WP_URL/wp-admin/"
echo "  WP_SUBMIT_PAGE=$SUBMIT_URL"
echo "  WP_PORTAL_PAGE=$PORTAL_URL"
echo "  WP_ADMIN_USER=$WP_ADMIN_USER"
echo "  WP_ADMIN_PASS=$WP_ADMIN_PASS"
echo "  WP_TECH1_EMAIL=$WP_TECH1_EMAIL"
echo "  WP_TECH1_USER=$WP_TECH1_USER"
echo "  WP_TECH1_PASS=$WP_TECH1_PASS"
echo "  WP_TECH2_USER=$WP_TECH2_USER"
echo "  WP_TECH2_PASS=$WP_TECH2_PASS"
echo "  CLIENT1_NAME=$CLIENT1_NAME"
echo "  CLIENT1_EMAIL=$CLIENT1_EMAIL"
echo "  CLIENT2_NAME=$CLIENT2_NAME"
echo "  CLIENT2_EMAIL=$CLIENT2_EMAIL"
echo "  WP_MODE=docker"
echo "  WP_CONTAINER=wpcli"
echo "  WP_PATH=/var/www/html"
