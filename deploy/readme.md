# Deployment

Proudly Snoring's own deployment stack for **mapper.prsn.online**, running the image published to GHCR on every release.


## The stack

The stack is based on docker compose (and compatible with podman compose too).
- It is declared in a single `docker-compose.yml` for production environments.
  It pulls a pre-built image from GHCR and anly the `proxy` service publishes ports (80 + 443) while all the other connections are made inside the docker network.
- An override is also provided in `docker-compose.local.yml` for local testing.
  It overrides the production file to build the image from local files, does not provide TLS certificate and is only accessible on `http://localhost:8000`. 

### Configuration

The configuration lives in `.env` (runtime keys only).
- This file is created from `.env.example` by `generate-env.sh`, a helper that generates the random secrets.
- Docker compose automatically reads this file as long as it stays besides the compose file.

### Profiles

The main compose declare several optional services gated behind **profiles** that can be enabled or disabled.
For convenience, it is recommanded to write the profiles to use in the `.env` file (cf. the `COMPOSE_PROFILES` option).

> [!WARNING]
> A `--profile x` **flag replaces** that value instead of adding to it.  
> Make sure to use either the variable or the commandline, but not both (with the exception of the backup service).

| Profile   | Contains                                                                            | Enabled by default |
| --------- | ----------------------------------------------------------------------------------- | ------------------ |
| _(none)_  | The application: app, queue, scheduler, reverb, killmail-listener, Redis            | Always             |
| `mariadb` | MariaDB. Drop it to use an external database server                                 | Yes                |
| `proxy`   | The Caddy reverse proxy that terminates TLS. Drop it to use your own                | Yes                |
| `backup`  | The one-shot job that dumps the database. Implies `mariadb`, and is started by name | No                 |
| `discord` | The bot: slash commands and personal alerts                                         | No                 |

### The proxy service

The `proxy` profile runs the Caddy reverse proxy: it gets and renews the Let's Encrypt certificate for `SERVER_NAME`, and forwards to the application over the internal network.
- Keep it when this stack owns the machine or nothing else needs to be exposed on ports 80/443.
- Drop it from `COMPOSE_PROFILES` to put your own proxy in front instead (one that already serves other applications, for example).

If you use an external proxy, it then has to:
- Route `/app/*` to `reverb:8080` (the websocket) and everything else to `app:80`,
- Set `X-Forwarded-For`, `X-Forwarded-Proto` and `X-Forwarded-Host`.

> [!WARNING]
> Routing only `app` and forgetting `/app/*` breaks realtime updates without any warning.

The compose network is explicitly named `wormholesystems`, so a proxy running in a different compose project can join it with `external: true` rather than going back out through host ports.

Without a proxy, the stack speaks plain HTTP and stays entirely on the internal Docker network — there is no port on the host to reach it with.
That is intentional: the app is never published directly.

### Trusted proxies

Because TLS is terminated before the `app` container, every request reaches PHP as plain HTTP.

By default, `bootstrap/app.php` trusts the `X-Forwarded-*` headers from the private ranges `10.0.0.0/8`, `172.16.0.0/12` and `192.168.0.0/16` - which is what tells Laravel the browser request was HTTPS, and what was the real client address (else, Laravel only sees the proxy IP).

You may need to adjust these ranges on more complex setup if your proxy chain is not fully on an internal network (ex: if using a CloudFlare service).

### Using an external database

The `mariadb` profile runs MariaDB inside the stack.
Drop it from `COMPOSE_PROFILES` to use a database this stack does not manage (ex: for better performances).

In that case, create the database and its user yourself, then set the following variables in `deploy/.env`:
`DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`.

Nothing else changes: migrations still run from the entrypoint on every start.

The `backup` service goes with that same profile, because it dumps the in-stack `mariadb` over the compose network (cf. the [backups](#backups) section).

> [!WARNING]
> When running an external database, make sure to setup another backups system.

### Using an external redis service

The `redis` profile runs Redis inside the stack.
Drop it from `COMPOSE_PROFILES` to use a database this stack does not manage (ex: for better performances).

In that case, create the database and its user yourself, then set the following variables in `deploy/.env`:
`REDIS_HOST`, `REDIS_PORT`, `REDIS_USERNAME`, `REDIS_PASSWORD


## Network ports

Only the `proxy` service publishes anything.
Every other service stays on the internal `wormholesystems` docker network with no host port at all.

### Inbound ports

Open on the host firewall, and routed/forwarded to the host if it sits behind a NAT:

| Port      | Purpose                                                        | Required |
| --------- | -------------------------------------------------------------- | -------- |
| `80/tcp`  | Let's Encrypt HTTP-01 challenge, plus the redirect to HTTPS    | Yes      |
| `443/tcp` | The site itself, and the websocket at `wss://<host>/app/<key>` | Yes      |
| `443/udp` | HTTP/3 (QUIC) — clients fall back to `443/tcp` if it is closed | No       |

NB1: Port 80 has to *stay* open: every certificate renewal repeats the HTTP-01 challenge.

NB2: Running without the `proxy` profile publishes none of the above — supply the equivalent from your own proxy.

For local stacks, `docker-compose.local.yml` publishes `127.0.0.1:8000` instead and requests no certificate.
Local testing is only accessible on localhost and nothing is exposed to the outside world, even on the local network.

### Outbound ports

HTTPS egress on port `443/tcp`, is needed for:
- Let's Encrypt certificate management (https://api.letsencrypt.org - subdomains included),
- The EVE login service (https://login.eveonline.com),
- The EVE ESI API (https://esi.evetech.net),
- The SDE download (https://developers.eveonline.com),
- The ZKillboard API (https://zkillboard.com - subdomains included),
- The Killmail archives from https://data.everef.net,
- The EvE-Scout API (https://api.eve-scout.com),
- The Discord API _if the bot is enabled_ (https://discord.com - subdomains included),
- The sentry API defined in `SENTRY_LARAVEL_DSN` (if any).

Note that you also need to be able to download the docker images from ghcr.io and the Docker Hub.


## First deploy

> [!NOTE]
> Every command below is given for both Docker and Podman.
> On the Podman side it has to be `podman compose` backed by the **docker-compose provider** (Compose v2.24 or newer):
> the stack uses `profiles:`, `condition: service_healthy`, and `required: false`, which are not supported by the Python implementation.

> [!NOTE]
> The docker/podman commands below are expected to run from inside the `deploy/` folder.
> Else, make sure to add `-f deploy/docker-compose.yml` so docker can locate the correct compose file.

**1. Generate the configuration** (needs `openssl`):
```bash
deploy/generate-env.sh
```

It creates `deploy/.env` from `deploy/.env.example` and fills in secrets/random values.

**2. Fill in the rest of `deploy/.env`** by hand:
- `CONTACT_EMAIL` (for the EVE API and the Caddy ACME contact),
- `EVE_CLIENT_ID`/`EVE_CLIENT_SECRET` from the [EVE developer portal](https://developers.eveonline.com) (cf. the "EVE application setup" section of the main [readme](../readme.md)).

**3. Pick the parts of the stack to run**, with `COMPOSE_PROFILES` in `deploy/.env`:
```dotenv
COMPOSE_PROFILES=mariadb,proxy,redis
```

Drop `mariadb` to use an external database, or `proxy` to use your own proxy (cf. above).

**4. Start the stack:**
```bash
# With docker
docker compose up -d
# With podman
podman compose up -d
```

**5. Bootstrap the database.** The first boot needs an SDE-derived database:
```bash
# With docker
docker compose exec app php artisan sde:download
docker compose exec app php artisan db:seed --force
# With podman
podman compose exec app php artisan sde:download
podman compose exec app php artisan db:seed --force
```
This is only needed once, unless the database is wiped. Migrations themselves run automatically on every start, from `files/entrypoint.sh`.


## General administration

> [!WARNING]
> Make sure to run these commands with the **same `COMPOSE_PROFILES`** the stack was started with.  
> This is automatic as long as it stays in `deploy/.env` and you pass no `--profile` flag.

**(Re)start** the stack (`-d` detaches the process from the current terminal):
```bash
# With docker
docker compose up -d
# With podman
podman compose up -d
```

**Stop** (containers are kept, so `up` later resumes where you left off):
```bash
# With docker
docker compose stop
# With podman
podman compose stop
```

**Remove** the containers and network (keep volumes):
```bash
# With docker
docker compose down
# With podman
podman compose down
```

**Reset** — also removes the volumes:
```bash
# ⚠️ This wipes the database, the stored files, and Caddy's certificate.
# ⚠️ Re-requesting the certificate counts against Let's Encrypt's five-per-week duplicate limit.
# ℹ️ The SDE bootstrap from step 5 has to be repeated after a reset.

# With docker
docker compose down -v
# With podman
podman compose down -v
```


## Local testing

> [!WARNING]
> The local stack requires compose v2.24 or newer (it uses the `!override` tag to drop the production port mappings).

To start the local stack, call compose with both files:
```bash
# ℹ️ Make sure to keep the default `COMPOSE_PROFILES=mariadb,proxy,redis`
# ⚠️ The files order is important! docker-compose.yml needs to be first.

# With docker
docker compose -f docker-compose.yml -f docker-compose.local.yml up --build -d
# With podman
podman compose -f docker-compose.yml -f docker-compose.local.yml up --build -d
```

The override builds the image from source as `wormholesystems:local`, points the build args and the runtime environment at `localhost`, and makes the proxy serve plain HTTP on `127.0.0.1:8000`.
- `deploy/.env` still supplies the secrets and the database credentials.
- The app is then on <http://localhost:8000>, and the websocket at `ws://localhost:8000/app/local`.
- Both are bound to the loopback interface only.
- This instance runs with `APP_DEBUG=true`.

For the first run, do not forget to seed the database too:
```bash
# With docker
docker compose -f docker-compose.yml -f docker-compose.local.yml exec app php artisan sde:download
docker compose -f docker-compose.yml -f docker-compose.local.yml exec app php artisan db:seed --force
# With podman
podman compose -f docker-compose.yml -f docker-compose.local.yml exec app php artisan sde:download
podman compose -f docker-compose.yml -f docker-compose.local.yml exec app php artisan db:seed --force
```

### Only build the image

To build the image alone, without running anything, use:
```bash
# With docker
docker build -f deploy/Dockerfile -t wormholesystems .
# With podman
podman build -f deploy/Dockerfile -t wormholesystems .
```


## Updating

Every [GitHub release](https://github.com/Proudly-Snoring/WormholeSystems/releases) publishes a new image tag to GHCR (see [contributing.md](../contributing.md#release-process)).

Updating requires changing the image version in the compose file:
1. Bump the `image:` tag under `x-app` in `deploy/docker-compose.yml` to the release you want.
   Pinning a version rather than tracking `latest` is recommended in production.
2. Then, restart the stack:
   ```bash
   # With docker
   docker compose up -d
   # With podman
   podman compose up -d
   ```

Database migrations automatically run on start, so there is no separate step for them.


## Backups

The `backup` service dumps the database, then gzips it and encrypts it with OpenSSL (AES-256-CBC, key derived by PBKDF2 from `BACKUP_ENCRYPTION_PASSPHRASE` in `deploy/.env`).
It writes into `deploy/backups/` on the host and keeps 14 days of history, pruning older files on every run.

It dumps the in-stack `mariadb` over the compose network, so it has no meaning on an instance using an external database — back that one up with whatever its operator provides.

### Scheduling

The service is behind the `backup` profile, so compose never starts it with the rest of the stack (it does not have any "cron" feature).

Each run starts the `backup` container, dumps and encrypts, then exits and is removed (`--rm`) — nothing from it stays running between backups.

Trigger it on a schedule from outside the stack instead (ex: a host crontab entry):
```bash
# With docker
docker compose run --rm backup
# With podman
podman compose run --rm backup
```
NB: Naming `backup` enables its profile on its own, so nothing has to be added to `COMPOSE_PROFILES` for this.

### Restoring

1. Stop the application services first, so nothing writes during the restore — `mariadb` itself has to stay up, since the restore pipes into it:
   ```bash
   # With docker
   docker compose stop app queue reverb scheduler killmail-listener discord
   # With podman
   podman compose stop app queue reverb scheduler killmail-listener discord
   ```
   Naming `discord` is harmless on an instance that does not run the bot — it is simply not running.
   The `proxy` is left out on purpose: it just answers 502 while the app is down.
2. Then run the following (you need a valid `.env` with the same secrets as the backup):
   ```bash
   # Change `<file>.sql.gz.enc` by the actual backup you want to restore.
   # `-pbkdf2` must match the one backup.sh encrypts with, otherwise the key derivation differs.

   # With docker
   docker compose run --rm -T --entrypoint bash backup -c \
     'openssl enc -d -aes-256-cbc -pbkdf2 -pass file:<(printf "%s" "$BACKUP_ENCRYPTION_PASSPHRASE") -in /backups/<file>.sql.gz.enc | gzip -d' \
     | docker compose exec -T mariadb sh -c \
     'exec mariadb -u root -p"$MARIADB_ROOT_PASSWORD" "$MARIADB_DATABASE"'

   # With podman
   podman compose run --rm -T --entrypoint bash backup -c \
     'openssl enc -d -aes-256-cbc -pbkdf2 -pass file:<(printf "%s" "$BACKUP_ENCRYPTION_PASSPHRASE") -in /backups/<file>.sql.gz.enc | gzip -d' \
     | podman compose exec -T mariadb sh -c \
     'exec mariadb -u root -p"$MARIADB_ROOT_PASSWORD" "$MARIADB_DATABASE"'
   ```
3. Bring the stopped services back up:
   ```bash
   # With docker
   docker compose up -d
   # With podman
   podman compose up -d
   ```

### Saving the backup files

Backup files are in `deploy/backups/` (encrypted). The folder is gitignored.

Just copy or `scp` the `.sql.gz.enc` files off the host with whatever tool you like.

> [!CAUTION]
>Do not forget to backup your `.env` too!


## Discord bot

Account linking works as soon as the `DISCORD_*` keys are set in `deploy/.env`.

The bot slash commands and personal alerts are a separate long-running process, shipped as the `discord` service behind the `discord` profile so it stays stopped on instances that do not use it.

Register the slash commands once (only needed again when the commands themselves change):
```bash
# With docker
docker compose exec app php artisan discord:register-commands --global
# With podman
podman compose exec app php artisan discord:register-commands --global
```

Then add `discord` to the compose profiles (`COMPOSE_PROFILES=mariadb,...,discord`) and bring the stack back up, which now includes the bot.

Exactly one `discord:listen` process must run, so do not scale this service beyond one replica.


## Files details

The `Dockerfile` is used to build the main application image.
- It's a three-stage build (composer vendor → frontend build → Debian runtime with nginx and php-fpm).
- The image serves plain HTTP on port 80 and declares no `EXPOSE`: it is only ever reached from inside the compose network.

Everything the Dockerfile or compose stack pulls in at build or run time lives under `deploy/files/`:
- `files/backup.sh` — Dumps, compresses and encrypts the database. Run by the `backup` service, cf. the "Backups" section below.
- `files/Caddyfile` — Configuration for the **optional proxy**, not for the app image. Terminates TLS and routes `/app/*` to the websocket server and everything else to the app.
- `files/entrypoint.sh` — Runs on container start: fixes storage permissions for every role, then waits for the database, migrates, links `public/storage` and warms caches for the web process only.
- `files/nginx.conf` — The web server inside the app container. Serves `public/`, hands the rest to php-fpm. Access logs are off by design; the proxy logs instead.
- `files/php-fpm.conf` — The php-fpm pool, replacing the one Debian ships.
- `files/php.ini` — Runtime PHP overrides (memory limit, upload size, execution time, opcache).
- `files/run-web.sh` — Runs nginx and php-fpm side by side in the app container, and takes the container down if either dies.
