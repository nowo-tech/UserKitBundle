#!/bin/sh
set -e

# FRANKENPHP_MODE: classic | worker (REQ-DEMO-010). Default: worker.
# Set via .env / Compose only — not baked into the image ENV.
MODE="${FRANKENPHP_MODE:-worker}"
case "$MODE" in
	classic)
		if [ -f /app/Caddyfile.dev ]; then
			cp /app/Caddyfile.dev /etc/caddy/Caddyfile
		elif [ -f /etc/frankenphp/Caddyfile.dev ]; then
			cp /etc/frankenphp/Caddyfile.dev /etc/frankenphp/Caddyfile
		fi
		;;
	worker)
		if [ -f /app/Caddyfile ]; then
			cp /app/Caddyfile /etc/caddy/Caddyfile
		fi
		# else keep image default Caddyfile (worker enabled)
		;;
	*)
		echo "Unknown FRANKENPHP_MODE=$MODE (expected classic|worker)" >&2
		exit 1
		;;
esac
echo "FrankenPHP mode: $MODE"

git config --global --add safe.directory /app 2>/dev/null || true
git config --global --add safe.directory /var/user-kit-bundle 2>/dev/null || true
mkdir -p /app/var/cache /app/var/log /app/var/data
chmod -R 777 /app/var 2>/dev/null || true

if [ ! -f /app/.env ]; then
	cp /app/.env.example /app/.env 2>/dev/null || true
fi

# A fresh checkout has no vendor/: install before FrankenPHP starts, otherwise the workers fail on
# autoload_runtime.php and the container exits.
if [ ! -f /app/vendor/autoload_runtime.php ]; then
	echo "vendor not found, running composer install..."
	if ! COMPOSER_MEMORY_LIMIT=-1 composer install --no-interaction --working-dir=/app; then
		echo "composer install failed; the container stays up for debugging."
		exec tail -f /dev/null
	fi
fi

if [ -d /app/vendor ]; then
	echo "Creating the schema if needed..."
	php /app/bin/console doctrine:schema:update --force --complete --no-interaction 2>/dev/null || true
fi

exec frankenphp run --config /etc/frankenphp/Caddyfile --adapter caddyfile
