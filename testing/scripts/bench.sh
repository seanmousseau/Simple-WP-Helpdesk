#!/usr/bin/env bash
# Performance baseline driver. Runs each scenario, captures median/p95/p99
# wall-clock timings, writes a markdown table to stdout.
#
# Usage: testing/scripts/bench.sh [count]
#   count: ticket count to seed (default 100)
#
# Prereqs:
#   - docker-compose.test.yml stack running (db, wordpress, wpcli, mailhog)
#   - WordPress installed + plugin activated (run bash docker/setup-test-wp.sh)
#
# Output: a markdown table on stdout. Capture with:
#   make bench > /tmp/bench.out
#   then paste into docs/internal/performance-baseline.md

set -euo pipefail

COUNT="${1:-100}"
REPS="${REPS:-10}"
WP_URL="${WP_URL:-http://localhost:8080}"
WP_ADMIN_USER="${WP_ADMIN_USER:-admin}"
WP_ADMIN_PASS="${WP_ADMIN_PASS:-adminpass123}"
INBOUND_SECRET="${INBOUND_SECRET:-swh-ci-webhook-secret}"

DC="docker compose -f docker-compose.test.yml"
WP="$DC exec -T wpcli wp --allow-root --path=/var/www/html"
COOKIE_JAR="$(mktemp -t swh-bench-cookies.XXXXXX)"
trap 'rm -f "$COOKIE_JAR"' EXIT

log() { printf '%s\n' "$*" >&2; }

# Percentile helper. Reads newline-separated numeric seconds from stdin, prints
# "median p95 p99" in milliseconds, space-separated.
percentiles() {
	sort -n | awk '
		{ a[NR]=$1 }
		END {
			n=NR
			if (n==0) { print "n/a n/a n/a"; exit }
			med_idx = int((n+1)/2)
			p95_idx = int(n*0.95 + 0.5); if (p95_idx<1) p95_idx=1; if (p95_idx>n) p95_idx=n
			p99_idx = int(n*0.99 + 0.5); if (p99_idx<1) p99_idx=1; if (p99_idx>n) p99_idx=n
			printf "%.0f %.0f %.0f\n", a[med_idx]*1000, a[p95_idx]*1000, a[p99_idx]*1000
		}
	'
}

curl_get_timings() {
	local url="$1"
	local extra="${2:-}"
	local times=()
	for _ in $(seq 1 "$REPS"); do
		# shellcheck disable=SC2086
		t=$(curl -sk -o /dev/null -b "$COOKIE_JAR" -c "$COOKIE_JAR" \
			-w '%{time_total}\n' $extra "$url")
		times+=("$t")
	done
	printf '%s\n' "${times[@]}"
}

# ---------- 1. Login (get admin cookie) ----------
log "→ Logging in as admin..."
curl -sk -c "$COOKIE_JAR" -b "$COOKIE_JAR" \
	-d "log=${WP_ADMIN_USER}&pwd=${WP_ADMIN_PASS}&wp-submit=Log+In&redirect_to=${WP_URL}/wp-admin/&testcookie=1" \
	"$WP_URL/wp-login.php" -o /dev/null

# ---------- 2. Seed tickets ----------
log "→ Seeding ${COUNT} tickets via wp eval-file..."
$DC exec -T \
	-v "$(pwd)/testing/scripts/seed_perf.php:/tmp/seed_perf.php:ro" \
	wpcli sh -c "true" >/dev/null 2>&1 || true
# wpcli already has /workspace via different path? Use cp into container.
docker cp testing/scripts/seed_perf.php "$($DC ps -q wpcli):/tmp/seed_perf.php"
$WP eval-file /tmp/seed_perf.php "$COUNT" >&2 || log "seed failed (continuing)"

# Collect bench data (plain vars for bash 3.2 compatibility on macOS)
R_LIST="n/a n/a n/a"
R_SETTINGS="n/a n/a n/a"
R_SLA="n/a n/a n/a"
R_KPI_COLD="n/a n/a n/a"
R_KPI_WARM="n/a n/a n/a"
R_PORTAL="n/a n/a n/a"
R_SUBMIT="n/a n/a n/a"
R_INBOUND="n/a n/a n/a"

log ""
log "=== Running scenarios (REPS=$REPS) ==="

# ---------- Scenario 1: Admin ticket list ----------
log "[1/7] Admin ticket list page..."
R_LIST=$(curl_get_timings "$WP_URL/wp-admin/edit.php?post_type=helpdesk_ticket" | percentiles)

# ---------- Scenario 2: Settings save round-trip ----------
log "[2/7] Settings save round-trip..."
times=()
for _ in $(seq 1 "$REPS"); do
	# Fetch nonce
	nonce=$(curl -sk -b "$COOKIE_JAR" -c "$COOKIE_JAR" \
		"$WP_URL/wp-admin/admin.php?page=swh-settings" \
		| grep -oE 'name="swh_settings_nonce" value="[^"]+"' | head -1 \
		| sed 's/.*value="\([^"]*\)".*/\1/')
	if [ -z "$nonce" ]; then
		log "    (no settings nonce found — falling back to plain GET of settings page)"
		t=$(curl -sk -b "$COOKIE_JAR" -c "$COOKIE_JAR" -o /dev/null -w '%{time_total}\n' \
			"$WP_URL/wp-admin/admin.php?page=swh-settings")
	else
		t=$(curl -sk -b "$COOKIE_JAR" -c "$COOKIE_JAR" -o /dev/null -w '%{time_total}\n' \
			-d "swh_settings_nonce=${nonce}&swh_email_from_name=Helpdesk&action=swh_save_settings" \
			"$WP_URL/wp-admin/admin.php?page=swh-settings")
	fi
	times+=("$t")
done
R_SETTINGS=$(printf '%s\n' "${times[@]}" | percentiles)

# ---------- Scenario 3: SLA cron ----------
log "[3/7] swh_sla_check_event cron run..."
times=()
for _ in $(seq 1 "$REPS"); do
	# Clear lock and run
	$WP transient delete swh_lock_sla >/dev/null 2>&1 || true
	start=$($WP eval 'echo microtime(true);' 2>/dev/null || echo "0")
	$WP cron event run swh_sla_check_event >/dev/null 2>&1 || true
	end=$($WP eval 'echo microtime(true);' 2>/dev/null || echo "0")
	t=$(awk -v s="$start" -v e="$end" 'BEGIN{printf "%.4f", e-s}')
	times+=("$t")
done
R_SLA=$(printf '%s\n' "${times[@]}" | percentiles)

# ---------- Scenario 4a: KPI cold ----------
log "[4/7] swh_report_kpi_data() cold..."
times=()
for _ in $(seq 1 "$REPS"); do
	$WP transient delete swh_report_kpi >/dev/null 2>&1 || true
	t=$($WP eval 'require_once WP_PLUGIN_DIR."/simple-wp-helpdesk/admin/class-reporting.php"; $s=microtime(true); if(function_exists("swh_report_kpi_data")){swh_report_kpi_data();} echo microtime(true)-$s;' 2>/dev/null || echo "0")
	times+=("$t")
done
R_KPI_COLD=$(printf '%s\n' "${times[@]}" | percentiles)

# ---------- Scenario 4b: KPI warm ----------
log "[4b/7] swh_report_kpi_data() warm..."
# Prime once
$WP eval 'require_once WP_PLUGIN_DIR."/simple-wp-helpdesk/admin/class-reporting.php"; if(function_exists("swh_report_kpi_data")){swh_report_kpi_data();}' >/dev/null 2>&1 || true
times=()
for _ in $(seq 1 "$REPS"); do
	t=$($WP eval 'require_once WP_PLUGIN_DIR."/simple-wp-helpdesk/admin/class-reporting.php"; $s=microtime(true); if(function_exists("swh_report_kpi_data")){swh_report_kpi_data();} echo microtime(true)-$s;' 2>/dev/null || echo "0")
	times+=("$t")
done
R_KPI_WARM=$(printf '%s\n' "${times[@]}" | percentiles)

# ---------- Scenario 5: Portal ticket view ----------
log "[5/7] Portal ticket view..."
# Pick a seeded ticket and grab its portal URL
ticket_id=$($WP post list --post_type=helpdesk_ticket \
	--meta_key=_swh_perf_seed --meta_value=1 --format=ids 2>/dev/null \
	| tr ' ' '\n' | head -1)
if [ -n "$ticket_id" ]; then
	# Generate a token if missing
	$WP eval "
		\$id=$ticket_id;
		\$tok=get_post_meta(\$id,'_ticket_token',true);
		if(!\$tok){\$tok=bin2hex(random_bytes(16));update_post_meta(\$id,'_ticket_token',\$tok);update_post_meta(\$id,'_ticket_token_created',time());}
		\$page_id=(int)get_option('swh_ticket_page_id',0);
		\$url=\$page_id?get_permalink(\$page_id):'';
		echo add_query_arg(array('ticket_id'=>\$id,'token'=>\$tok),\$url);
	" 2>/tmp/portal_url.err > /tmp/portal_url.txt
	portal_url=$(cat /tmp/portal_url.txt)
	if [ -n "$portal_url" ]; then
		R_PORTAL=$(curl_get_timings "$portal_url" | percentiles)
	else
		R_PORTAL="n/a n/a n/a"
	fi
else
	R_PORTAL="n/a n/a n/a"
fi

# ---------- Scenario 6: Submission form POST ----------
log "[6/7] Submission form POST..."
submit_url=$($WP eval "echo get_permalink((int)\$post_id=array_values(get_posts(array('post_type'=>'page','title'=>'Submit a Ticket','posts_per_page'=>1,'fields'=>'ids')))[0] ?? 0);" 2>/dev/null || echo "")
if [ -z "$submit_url" ] || [ "$submit_url" = "0" ]; then
	submit_url="$WP_URL/submit-a-ticket/"
fi
times=()
for _ in $(seq 1 "$REPS"); do
	# Get nonce
	nonce=$(curl -sk "$submit_url" \
		| grep -oE 'name="swh_ticket_nonce" value="[^"]+"' | head -1 \
		| sed 's/.*value="\([^"]*\)".*/\1/')
	t=$(curl -sk -o /dev/null -w '%{time_total}\n' \
		-d "swh_ticket_nonce=${nonce}&swh_client_name=Bench&swh_client_email=bench@example.test&swh_subject=Bench&swh_description=Bench+body&swh_submit_ticket=1" \
		"$submit_url")
	times+=("$t")
done
R_SUBMIT=$(printf '%s\n' "${times[@]}" | percentiles)

# ---------- Scenario 7: REST inbound webhook ----------
log "[7/7] REST inbound webhook (curl loop — siege not used)..."
times=()
for _ in $(seq 1 "$REPS"); do
	t=$(curl -sk -o /dev/null -w '%{time_total}\n' \
		-H "Authorization: Bearer ${INBOUND_SECRET}" \
		-H "Content-Type: application/json" \
		-d "{\"from\":\"perfseed+0@example.test\",\"subject\":\"Re: [TKT-${ticket_id:-1}] Bench\",\"body\":\"reply body\"}" \
		"$WP_URL/wp-json/swh/v1/inbound-email")
	times+=("$t")
done
R_INBOUND=$(printf '%s\n' "${times[@]}" | percentiles)

# ---------- Output ----------
cat <<EOF

## Results — count=${COUNT}, reps=${REPS}, $(date -u +%Y-%m-%dT%H:%M:%SZ)

| Scenario | Count | Median (ms) | p95 (ms) | p99 (ms) |
|---|---|---|---|---|
| Admin ticket list page load | ${COUNT} | $(echo "${R_LIST}" | awk '{printf "%s | %s | %s", $1,$2,$3}') |
| Settings save round-trip | n/a | $(echo "${R_SETTINGS}" | awk '{printf "%s | %s | %s", $1,$2,$3}') |
| swh_sla_check_event cron | ${COUNT} open* | $(echo "${R_SLA}" | awk '{printf "%s | %s | %s", $1,$2,$3}') |
| swh_report_kpi_data() cold | n/a | $(echo "${R_KPI_COLD}" | awk '{printf "%s | %s | %s", $1,$2,$3}') |
| swh_report_kpi_data() warm | n/a | $(echo "${R_KPI_WARM}" | awk '{printf "%s | %s | %s", $1,$2,$3}') |
| Portal ticket view | n/a | $(echo "${R_PORTAL}" | awk '{printf "%s | %s | %s", $1,$2,$3}') |
| Submission form POST | n/a | $(echo "${R_SUBMIT}" | awk '{printf "%s | %s | %s", $1,$2,$3}') |
| REST inbound webhook (curl loop) | n/a | $(echo "${R_INBOUND}" | awk '{printf "%s | %s | %s", $1,$2,$3}') |

\* SLA cron operates on tickets with status in \`swh_sla_open_statuses\` filter; not all seeded tickets are open.

EOF
