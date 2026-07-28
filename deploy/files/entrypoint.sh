#!/bin/sh
set -e

# CONTACT_EMAIL feeds the ESI user agent (CCP requires a reachable contact) and Caddy's ACME account.
if [ -z "${CONTACT_EMAIL:-}" ]; then
	echo "error: CONTACT_EMAIL is not set, please fill it in deploy/.env." >&2
	exit 1
fi

# Every service (app, queue, scheduler, reverb, killmail-listener, discord) shares
# this image and entrypoint, and all of them write to the same `storage`
# volume, so permissions are fixed up regardless of role.
mkdir -p storage/app/public storage/framework/sessions storage/framework/views storage/framework/cache storage/logs bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache

# Only the web process (the default CMD, "frankenphp run ...") migrates the database and warms caches.
# Other roles override the command in docker-compose.yml and wait for the app service to report healthy first.
# Running this in every container would be redundant and racy.
# No database wait loop is needed: `app` declares `depends_on: mariadb: service_healthy`, and that
# healthcheck is an authenticated TCP query against the application database and user. The other
# app-role services then wait on `app: service_healthy`, so they inherit the same guarantee.
if [ "$1" = "frankenphp" ]; then
	echo "Running database migrations..."
	php artisan migrate --force

	# public/ lives in the image layer while storage/ is the laravel-storage volume, so the link
	# cannot be made at build time and has to be (re)established here. --force because a plain
	# restart finds it already there, and a bare storage:link would then fail under `set -e`.
	echo "Linking public storage..."
	php artisan storage:link --force

	echo "Caching configuration..."
	php artisan optimize:clear --except=cache # preserve redis application cache
	php artisan optimize
fi

exec "$@"
