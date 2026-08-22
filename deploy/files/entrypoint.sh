#!/bin/sh
set -e

# CONTACT_EMAIL feeds the ESI user agent, which CCP requires to be a reachable address (cf. docker-compose.yml).
# If none is supplied, the container should not start.
if [ -z "${CONTACT_EMAIL:-}" ]; then
	echo "error: CONTACT_EMAIL is not set, please fill it in deploy/.env." >&2
	exit 1
fi

# Every service (app, queue, scheduler, reverb, killmail-listener, discord) shares
# this image and entrypoint, and all of them write to the same `storage`
# volume, so permissions are fixed up regardless of role.
mkdir -p storage/app/public storage/framework/sessions storage/framework/views storage/framework/cache storage/logs bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache

wait_for_database() {
	attempt=1
	while [ "$attempt" -le 60 ]; do
		if php -r '
			$dsn = sprintf(
				"mysql:host=%s;port=%s;dbname=%s",
				getenv("DB_HOST") ?: "127.0.0.1",
				getenv("DB_PORT") ?: "3306",
				getenv("DB_DATABASE") ?: "forge"
			);
			new PDO($dsn, getenv("DB_USERNAME") ?: "forge", getenv("DB_PASSWORD") ?: "", [PDO::ATTR_TIMEOUT => 2]);
		' 2>/dev/null; then
			echo "Database is up."
			return 0
		fi

		echo "Waiting for the database at ${DB_HOST:-127.0.0.1}:${DB_PORT:-3306}... (${attempt}/60)"
		attempt=$((attempt + 1))
		sleep 1
	done

	echo "error: the database at ${DB_HOST:-127.0.0.1} did not answer after 60 attempts." >&2
	exit 1
}

# Only the web process migrates the database and warms caches.
# The other services wait for the app service to report healthy.
if [ "$1" = "web" ]; then
	wait_for_database

	echo "Running database migrations..."
	php artisan migrate --force

	# public/ lives in the image layer while storage/ is the laravel-storage volume, so the link
	# cannot be made at build time and has to be (re)established here.
	echo "Linking public storage..."
	php artisan storage:link --force # --force avoids error on restarts as the link is already created

	echo "Caching configuration..."
	php artisan optimize:clear --except=cache # preserve redis application cache
	php artisan optimize

	set -- /usr/local/bin/run-web.sh
fi

exec "$@"
