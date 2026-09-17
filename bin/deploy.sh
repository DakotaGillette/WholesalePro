#!/usr/bin/env bash
#
# Deploy protech-wholesale/ to the Cloudways STAGING application and
# flush its object cache.
#
# Safety rules (see the master prompt's Cloudways appendix — do not
# relax these without the owner's explicit say-so):
#   - This script may ONLY ever target the staging application named in
#     .env. Never point it at production.
#   - It must never deactivate, update, or otherwise touch any other
#     plugin, the theme, or WordPress core. It only syncs this plugin's
#     own directory and flushes the cache.
#   - It must never run destructive WP-CLI commands (db reset, table
#     drops, deleting files outside this plugin's directory).
#
# Usage: bin/deploy.sh   (run from anywhere; paths below are repo-relative)

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
ENV_FILE="${REPO_ROOT}/.env"

if [[ ! -f "${ENV_FILE}" ]]; then
	echo "Error: ${ENV_FILE} not found." >&2
	echo "Copy .env.example to .env and fill in your Cloudways staging credentials, then re-run this script." >&2
	exit 1
fi

set -a
# shellcheck disable=SC1090
source "${ENV_FILE}"
set +a

required_vars=(CW_SSH_HOST CW_SSH_USER CW_APP_PATH CW_STAGING_URL)
for var in "${required_vars[@]}"; do
	if [[ -z "${!var:-}" ]]; then
		echo "Error: ${var} is not set in ${ENV_FILE}. See .env.example." >&2
		exit 1
	fi
done

# Auth: either a key file (CW_SSH_KEY) or a password (CW_SSH_PASSWORD, via
# sshpass) — Cloudways app credentials come in both flavors depending on
# how the app was set up. Key auth is preferred when both are present.
SSH_OPTS=(-o StrictHostKeyChecking=accept-new)

if [[ -n "${CW_SSH_KEY:-}" ]]; then
	SSH_CMD=(ssh -i "${CW_SSH_KEY}" "${SSH_OPTS[@]}")
elif [[ -n "${CW_SSH_PASSWORD:-}" ]]; then
	if ! command -v sshpass >/dev/null 2>&1; then
		echo "Error: CW_SSH_PASSWORD is set but 'sshpass' isn't installed." >&2
		echo "Install it (e.g. 'brew install sshpass' / 'apt install sshpass'), or use CW_SSH_KEY instead." >&2
		exit 1
	fi
	SSH_CMD=(sshpass -e ssh "${SSH_OPTS[@]}")
	export SSHPASS="${CW_SSH_PASSWORD}"
else
	echo "Error: set either CW_SSH_KEY or CW_SSH_PASSWORD in ${ENV_FILE}. See .env.example." >&2
	exit 1
fi

PLUGIN_SRC="${REPO_ROOT}/protech-wholesale/"
PLUGIN_DEST="${CW_SSH_USER}@${CW_SSH_HOST}:${CW_APP_PATH}/wp-content/plugins/protech-wholesale/"

echo "Deploying protech-wholesale to staging (${CW_STAGING_URL}) ..."

# Dev-only tooling (Composer vendor/, the test suite, lint/analysis
# configs) never ships to the server — nothing in the plugin's runtime
# loads any of it.
rsync -avz --delete \
	-e "${SSH_CMD[*]}" \
	--exclude ".git" \
	--exclude ".DS_Store" \
	--exclude "Thumbs.db" \
	--exclude "*.log" \
	--exclude ".idea" \
	--exclude ".vscode" \
	--exclude "vendor/" \
	--exclude "tests/" \
	--exclude "composer.json" \
	--exclude "composer.lock" \
	--exclude "phpunit.xml.dist" \
	--exclude "phpstan.neon.dist" \
	--exclude ".phpcs.xml.dist" \
	"${PLUGIN_SRC}" \
	"${PLUGIN_DEST}"

echo "Flushing the WordPress object cache and Breeze page cache on staging ..."

# `wp cache flush` only clears the object cache. Breeze (the site's page
# cache) has its own WP-CLI command; if the plugin isn't active the
# command simply doesn't exist, so don't let that fail the deploy.
"${SSH_CMD[@]}" "${CW_SSH_USER}@${CW_SSH_HOST}" \
	"cd ${CW_APP_PATH} && wp cache flush && (wp breeze purge --cache=all 2>/dev/null || true)"

echo ""
echo "Deploy complete: protech-wholesale is live on staging."
echo "  ${CW_STAGING_URL}"
