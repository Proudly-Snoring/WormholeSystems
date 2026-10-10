#!/bin/bash
#
# Runs the two processes that make up the web role of the `app` container:
# - the php-fpm pool, and
# - the nginx that reaches it over /run/php/php8.4-fpm.sock.
#
# The entrypoint.sh execs this when the container command is the `web` sentinel.

set -euo pipefail

# This is how FPM's own startup errors and the workers ones relayed output reach `docker logs`.
# -F keeps the master in the foreground.
# -O forces its log to stderr even though stderr is not a TTY
# (see catch_workers_output in files/php-fpm.conf)
php-fpm8.4 -F -O &
fpm_pid=$!

nginx -g 'daemon off;' &
nginx_pid=$!

# SIGQUIT is the graceful stop for both: nginx finishes the requests in flight before closing,
# php-fpm lets each worker finish the request it is on.
# `docker stop` sends SIGTERM, hence the translation here.

shutting_down=""
trap 'shutting_down=1; kill -QUIT "$fpm_pid" "$nginx_pid" 2>/dev/null || true' TERM INT

# Returns as soon as either child exits — or as soon as the trap above runs.
wait -n || true

if [ -n "$shutting_down" ]; then
	# Both were asked to drain; give them the time to actually do it rather than exiting from under them.
	wait || true
	exit 0
fi

echo "run-web.sh: nginx or php-fpm exited on its own, stopping the container..." >&2
kill -QUIT "$fpm_pid" "$nginx_pid" 2>/dev/null || true
wait || true
exit 1
