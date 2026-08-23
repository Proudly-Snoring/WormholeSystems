# Deployment

This file describes in more detail the deployment architecture and which configuration lies where.

## Values baked into the image

None of these are configurable from `deploy/.env` — they are declared in [`.github/workflows/publish.yml`](../.github/workflows/publish.yml) and take effect only when a new image is built:

**Inlined into the JavaScript bundle by Vite:**

| Build arg             | Baked value                                                    |
| --------------------- | -------------------------------------------------------------- |
| `VITE_APP_NAME`       | the name in browser tab titles                                 |
| `VITE_REVERB_HOST`    | `mapper.prsn.online`                                           |
| `VITE_REVERB_PORT`    | `443`                                                          |
| `VITE_REVERB_SCHEME`  | `https`                                                        |
| `VITE_REVERB_APP_KEY` | the Reverb app key, also baked as the runtime `REVERB_APP_KEY` |

All of them have a default pointing at a localhost stack, so a plain `docker build` produces a working image. `VITE_REVERB_APP_KEY` defaults to `local`, the same value `docker-compose.local.yml` uses, because an empty key stops matching Reverb's `/app/{appKey}` route and would break the websocket without any visible error.

**Baked as plain runtime `ENV` by `deploy/Dockerfile`** — read by Laravel, not by Vite:

| Build arg               | Baked value                                   |
| ----------------------- | --------------------------------------------- |
| `APP_URL`               | `https://mapper.prsn.online`                  |
| `EVE_CALLBACK`          | `https://mapper.prsn.online/eve/callback`     |
| `DISCORD_CALLBACK`      | `https://mapper.prsn.online/discord/callback` |
| `SESSION_SECURE_COOKIE` | `true`                                        |

Changing the domain in `APP_URL` alone is therefore not enough: `VITE_REVERB_HOST` would still point the browser's websocket at the old host.
Change the workflow's `env` block and publish a new release instead.

`SERVER_NAME` is deliberately **not** in that table any more. The image no longer terminates TLS, so nothing in it reads that value: it is a deployment setting, supplied to the `proxy` service and overridable from `deploy/.env`. It still has to match `VITE_REVERB_HOST`, since the browser opens its websocket on the host baked into the JS bundle — so overriding it only makes sense together with an image built for the new domain.

None of the *baked* keys above have an entry in `deploy/.env.example`, and deliberately so: `env_file` values take precedence over an image's own `ENV`, so setting any of them in `deploy/.env` would silently override the baked value instead of being ignored — for `REVERB_APP_KEY` that means a desync from the browser-side copy baked into the JS bundle, breaking every websocket handshake with a pusher error 4001; for the others, a domain mismatch between Laravel and the browser.

## Values fixed by the compose stack

The following variables are set directly in `docker-compose.yml`'s `environment:` block rather than `deploy/.env`:
- `BROADCAST_CONNECTION`,
- `DB_CONNECTION`,
- `QUEUE_CONNECTION`,
- `CACHE_STORE`,
- `REDIS_HOST`,
- `REVERB_HOST`,
- `REVERB_PORT`,
- `REVERB_SCHEME`.

They name the other services defined in that same file (`redis`, `reverb`), so changing one without moving the matching service definition just breaks the stack.
Unlike the group above, this one *is* safe to leave stray values for in `deploy/.env` — a Compose service's `environment:` attribute always wins over `env_file`, the opposite precedence from Dockerfile `ENV`.

`DB_HOST`, `DB_PORT`, `DB_DATABASE` and `DB_USERNAME` are in that same block but written as `${DB_HOST:-mariadb}` and so on, which makes them the exception: they *are* overridable from `deploy/.env`. That is what makes running without the `mariadb` profile possible at all — with a hardcoded value there, an external database could not be configured, because `environment:` beats `env_file:`.

`APP_USER_AGENT` is built there too, from `CONTACT_EMAIL` — the only address `deploy/.env` still asks for by hand. `CADDY_GLOBAL_OPTIONS` is built from it as well, but on the `proxy` service, since the ACME account belongs to the proxy.

## Processes in the image

Every service in the stack runs the same image and the same entrypoint, and is told apart by its command:

| Service             | Command                                |
| ------------------- | -------------------------------------- |
| `app`               | `web` (the image's `CMD`)              |
| `queue`             | `php artisan queue:work …`             |
| `scheduler`         | `php artisan schedule:work`            |
| `reverb`            | `php artisan reverb:start …`           |
| `killmail-listener` | `php artisan app:listen-for-killmails` |
| `discord`           | `php artisan discord:listen`           |

`web` is a sentinel rather than a program. `files/entrypoint.sh` recognises it as the web role — the only one that waits for the database, migrates, links `public/storage` and runs `artisan optimize` — and then replaces it with `files/run-web.sh`. Every other role skips that block, because they wait for `app` to report healthy and would otherwise migrate concurrently.

The scheduler uses Laravel's own `schedule:work` rather than a cron daemon. It starts `schedule:run` at second 0 of every minute and tracks overlapping runs, which this application needs — `routes/console.php` has `everyFiveSeconds()` and `everyThirtySeconds()` tasks, so a single `schedule:run` occupies its whole minute.

`files/run-web.sh` runs php-fpm and nginx as two background jobs and waits on whichever exits first, in place of a process supervisor. If either dies the container exits and `restart: unless-stopped` recycles it, rather than leaving nginx to answer 502 in front of a dead pool while still looking alive.

### Which user the application runs as

`www-data`, everywhere, from PID 1 — the image declares `USER www-data` and nothing in it is ever root: not the entrypoint, not the nginx or php-fpm masters, not a `docker compose exec`.

This is uniform on purpose. All six services mount the same `laravel-storage` volume and log through the `daily` channel, which creates `storage/logs/laravel-<date>.log` owned by whichever process logs first, mode `0644`. Leave a single role as root and the day's file can appear root-owned at the midnight rollover, after which every other container is locked out of its own log until the next restart. One user removes that class of bug rather than managing it.

Running unprivileged costs three things, all handled in the `Dockerfile`:

- **The web port is 8080, not 80.** Ports below 1024 need root to bind. Nothing outside the compose network reaches this port — the proxy connects to `app:8080` — so the number is free to change.
- **Runtime state moves out of root-owned directories.** `/run/php` (the fpm socket and pid) and `/run/nginx` (the nginx pid, in place of the unwritable `/run/nginx.pid`) are created and chowned at build time, `/var/lib/nginx` likewise for the spool files. `/var/log/php8.4-fpm.log` is symlinked to stderr, which both makes it writable and puts it in `docker logs`.
- **Everything the application writes to is chowned at build time**, because no process can chown at runtime anymore: `storage/`, `bootstrap/cache/`, `resources/static` (the scheduler's daily `generate:static-data`) and `public/` itself, whose directory `artisan storage:link --force` needs to recreate the symlink in.

`storage/` being www-data-owned *in the image* is also what makes the volume work: Docker seeds a fresh named volume from the image directory it is mounted over, ownership included.

Neither daemon is told which user to run as: php-fpm's `user`/`group` and nginx's `user` are only honoured by a master that starts as root, and here neither does — both simply inherit `www-data`. `files/php-fpm.conf` drops those directives, and `files/nginx.conf` replaces Debian's whole configuration rather than being included by it, which is also what moves the nginx pid file out of the root-owned `/run` (a `pid` directive belongs to the main context, so there is no way to override it from an included file).

> [!WARNING]
> A `laravel-storage` volume created by an image older than this change holds root-owned files, and nothing in the stack can fix them anymore. Upgrading such a deployment needs a one-time chown — cf. [readme.md](readme.md#upgrading-from-an-image-that-ran-as-root).

### php-fpm and the environment

`files/php-fpm.conf` replaces the pool Debian ships, and sets `clear_env = no`. That directive is load-bearing: php-fpm's default is to wipe the container's environment before the workers see it, so `APP_KEY`, `DB_HOST` and everything else from `deploy/.env` would be gone by the time Laravel reads them.

In practice the entrypoint's `artisan optimize` runs under the CLI SAPI, where the environment is intact, so `bootstrap/cache/config.php` would usually hide the problem. What `clear_env = no` removes is the dependence on that: without it, a cleared or failed config cache does not fail loudly — it silently falls back to framework defaults (`DB_HOST` to `127.0.0.1`, an empty `APP_KEY`).

The pool file has to *replace* `www.conf` rather than sit beside it. Two files in `pool.d/` both declaring `[www]` is a duplicate pool definition, which php-fpm refuses to start with; pools are not merged.

## Waiting for the database

`files/entrypoint.sh` retries a PDO connection 60 times, one second apart, before running the migrations.

This is not belt-and-braces: the database is optional in this stack. `app` does declare `depends_on: mariadb: {condition: service_healthy, required: false}`, but that only bites when the `mariadb` profile is on — an instance pointed at an external server has no compose healthcheck to wait on at all, and would otherwise fail its first migration on a connection refused.

`required: false` is also what makes that `depends_on` legal in the first place: compose rejects a reference to a service whose profile is disabled. It does not weaken the wait — with the profile on, `app` still blocks until `mariadb` reports healthy.
