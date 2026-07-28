# Deployment

Proudly Snoring's own deployment stack for **mapper.prsn.online**, running the image published to GHCR on every release.

> [!NOTE]
> Every command below is given for both Docker and Podman.
> On the Podman side it has to be `podman compose` backed by the **docker-compose provider** (Compose v2.24 or newer):
> the stack uses `profiles:`, `condition: service_healthy` and the `!override` tag, which are not supported by the Python implementation.

## Files

The `Dockerfile` is used to build the main application image.
It's a three-stage build (composer vendor → frontend build → FrankenPHP runtime).

Everything the Dockerfile or compose stack pulls in at build or run time lives under `deploy/files/`:
- `files/backup.sh` — Dumps, compresses and encrypts the database. Run by the `backup` service, cf. the "Backups" section below.
- `files/Caddyfile` — Replaces FrankenPHP's stock one. Terminates TLS, serves `public/`, proxies `/app/*` to the websocket server, and serves a healthcheck endpoint.
- `files/entrypoint.sh` — Runs on container start: fixes storage permissions for every role, then migrates, links `public/storage` and warms caches for the web process only.
- `files/php.ini` — Runtime PHP overrides (memory limit, upload size, execution time).

The following files are used to deploy and run the application stack (including databases):
- `docker-compose.yml` — The production stack: app, queue, scheduler, reverb, killmail-listener, MariaDB, Redis, plus the `backup` and `discord` services behind their own profiles.
  Pulls the published image — contains no `build:` section.
- `docker-compose.local.yml` — Override for local testing.
  Builds from source and points everything at `localhost:8000`.
- `.env` — Configuration for the stack. Runtime keys only.
- `.env.example` — Configuration template for `.env`.

There is also `generate-env.sh`, a helper that generates random secrets and fills them into `.env`.

## Network ports

Only the `app` container is ever reachable from outside.
The others stay on the internal `wormholesystems` compose network.

**Inbound** — open on the host firewall, and routed/forwarded to the host if it sits behind a NAT:

| Port      | Purpose                                                        | Required |
|-----------|----------------------------------------------------------------|----------|
| `80/tcp`  | Let's Encrypt HTTP-01 challenge, plus the redirect to HTTPS    | Yes      |
| `443/tcp` | The site itself, and the websocket at `wss://<host>/app/<key>` | Yes      |
| `443/udp` | HTTP/3 (QUIC) — clients fall back to `443/tcp` if it is closed | No       |

NB1: Port 80 has to *stay* open: every certificate renewal repeats the HTTP-01 challenge.

NB2: `docker-compose.local.yml` publishes `8000/tcp` instead of all of the above and requests no certificate, so local testing needs nothing reachable from outside the host.

**Outbound**, HTTPS on `443/tcp`, is needed for:
- Let's Encrypt certificate management,
- The EVE ESI API and the SDE download,
- Discord API if the bot is enabled.

## First deploy

**1. Generate the configuration** (needs `openssl`):
```bash
deploy/generate-env.sh
```

It creates `deploy/.env` from `deploy/.env.example` and fills in secrets/random values.

**2. Fill in the rest of `deploy/.env`** by hand:
- `CONTACT_EMAIL` (for the EVE API and the Caddy ACME contact),
- `EVE_CLIENT_ID`/`EVE_CLIENT_SECRET` from the [EVE developer portal](https://developers.eveonline.com) (cf. the "EVE application setup" section of the main [readme](../readme.md)).

**3. Start the stack:**
```bash
# With docker
docker compose -f deploy/docker-compose.yml up -d
# With podman
podman compose -f deploy/docker-compose.yml up -d
```

**4. Bootstrap the database.** The first boot needs an SDE-derived database:
```bash
# With docker
docker compose -f deploy/docker-compose.yml exec app php artisan sde:download
docker compose -f deploy/docker-compose.yml exec app php artisan db:seed --force
# With podman
podman compose -f deploy/docker-compose.yml exec app php artisan sde:download
podman compose -f deploy/docker-compose.yml exec app php artisan db:seed --force
```
This is only needed once, unless the database is wiped. Migrations themselves run automatically on every start, from `files/entrypoint.sh`.

## Local testing

To test the docker build and (some of) the compose file locally, you can run:
```bash
# With docker
docker compose -f deploy/docker-compose.yml -f deploy/docker-compose.local.yml up --build -d
# With podman
podman compose -f deploy/docker-compose.yml -f deploy/docker-compose.local.yml up --build -d
```

The override builds the image from source as `wormholesystems:local`, points the build args and the runtime environment at `localhost`, and publishes the app on `8000` instead of 80/443.
Needs Compose v2.24 or newer (it uses the `!override` tag to drop the production port mappings).
`deploy/.env` still supplies the secrets and the database credentials.

The app is then on <http://localhost:8000>, and the websocket at `ws://localhost:8000/app/local`.

For the first run, do not forget to seed the database:
```bash
# With docker
docker compose -f deploy/docker-compose.yml -f deploy/docker-compose.local.yml exec app php artisan sde:download
docker compose -f deploy/docker-compose.yml -f deploy/docker-compose.local.yml exec app php artisan db:seed --force
# With podman
podman compose -f deploy/docker-compose.yml -f deploy/docker-compose.local.yml exec app php artisan sde:download
podman compose -f deploy/docker-compose.yml -f deploy/docker-compose.local.yml exec app php artisan db:seed --force
```

### Only build the image

To build the image alone, without running anything, use:
```bash
# With docker
docker build -f deploy/Dockerfile -t wormholesystems .
# With podman
podman build -f deploy/Dockerfile -t wormholesystems .
```

## Stopping, removing, and resetting

**Stop** (containers are kept, so `up` later resumes where you left off):
```bash
# With docker
docker compose -f deploy/docker-compose.yml stop
# With podman
podman compose -f deploy/docker-compose.yml stop
```

**Remove** the containers and network (keep volumes):
```bash
# With docker
docker compose -f deploy/docker-compose.yml down
# With podman
podman compose -f deploy/docker-compose.yml down
```

**Reset** — also removes the volumes:
```bash
# ⚠️ This wipes the database, the stored files, and Caddy's certificate.
# ⚠️ Re-requesting the certificate counts against Let's Encrypt's five-per-week duplicate limit.

# With docker
docker compose -f deploy/docker-compose.yml down -v
# With podman
podman compose -f deploy/docker-compose.yml down -v
```

The SDE bootstrap from step 4 has to be repeated after a reset.

## Updating

Every [GitHub release](https://github.com/Proudly-Snoring/WormholeSystems/releases) publishes a new image tag to GHCR (see [contributing.md](../contributing.md#release-process)).

Updating only requires to change the image version in the compose file:
1. Bump the `image:` tag under `x-app` in `deploy/docker-compose.yml` to the release you want.
   Pinning a version rather than tracking `latest` is recommended in production.
2. Then, restart the stack:
   ```bash
   # With docker
   docker compose -f deploy/docker-compose.yml up -d
   # With podman
   podman compose -f deploy/docker-compose.yml up -d
   ```

Database migrations automatically run on start, so there is no separate step for them.

## Backups

The `backup` service dumps the database, then gzips it and encrypts it with OpenSSL (AES-256-CBC, key derived by PBKDF2 from `BACKUP_ENCRYPTION_PASSPHRASE` in `deploy/.env`).
It writes into `deploy/backups/` on the host and keeps 14 days of history, pruning older files on every run.

### Scheduling

The service is tagged `profiles: ["backup"]`, so compose does not start it automatically (it does not have any "cron" feature).
Trigger it on a schedule from outside the stack instead, ex. a host crontab entry:
```bash
# With docker
docker compose -f deploy/docker-compose.yml run --rm backup
# With podman
podman compose -f deploy/docker-compose.yml run --rm backup
```
Each run starts the `backup` container, dumps and encrypts, then exits and is removed (`--rm`) — nothing from it stays running between backups.

### Restoring

1. Stop the application services first, so nothing writes during the restore — `mariadb` itself has
   to stay up, since the restore pipes into it:
   ```bash
   # With docker
   docker compose -f deploy/docker-compose.yml --profile discord \
     stop app queue reverb scheduler killmail-listener discord
   # With podman
   podman compose -f deploy/docker-compose.yml --profile discord \
     stop app queue reverb scheduler killmail-listener discord
   ```
   (`--profile discord` is only needed to name the profiled `discord` service; drop both if you do
   not run the bot.)
2. Then run the following (you need a valid `.env` with the same secrets as the backup):
   ```bash
   # Change `<file>.sql.gz.enc` by the actual backup you want to restore.
   # `-pbkdf2` must match the one backup.sh encrypts with, otherwise the key derivation differs.
 
   # With docker
   docker compose -f deploy/docker-compose.yml run --rm -T --entrypoint bash backup -c \
     'openssl enc -d -aes-256-cbc -pbkdf2 -pass file:<(printf "%s" "$BACKUP_ENCRYPTION_PASSPHRASE") -in /backups/<file>.sql.gz.enc | gzip -d' \
     | docker compose -f deploy/docker-compose.yml exec -T mariadb sh -c \
     'exec mariadb -u root -p"$MARIADB_ROOT_PASSWORD" wormholesystems'
 
   # With podman
   podman compose -f deploy/docker-compose.yml run --rm -T --entrypoint bash backup -c \
     'openssl enc -d -aes-256-cbc -pbkdf2 -pass file:<(printf "%s" "$BACKUP_ENCRYPTION_PASSPHRASE") -in /backups/<file>.sql.gz.enc | gzip -d' \
     | podman compose -f deploy/docker-compose.yml exec -T mariadb sh -c \
     'exec mariadb -u root -p"$MARIADB_ROOT_PASSWORD" wormholesystems'
   ```
3. Bring the stopped services back up (add `--profile discord` if you run the bot, otherwise it
   stays stopped):
   ```bash
   # With docker
   docker compose -f deploy/docker-compose.yml up -d
   # With podman
   podman compose -f deploy/docker-compose.yml up -d
   ```

### Saving the backup files

Backup files are in `deploy/backups/` (encrypted). The folder is gitignored.

Just copy or `scp` the `.sql.gz.enc` files off the host with whatever tool you like.

>Do not forget to backup your `.env` too!


## Discord bot

Account linking works as soon as the `DISCORD_*` keys are set in `deploy/.env`.
The bot slash commands and personal alerts are a separate long-running process, shipped as the `discord` service behind the `discord` profile so it stays stopped on instances that do not use it.

Register the slash commands once (only needed again when the commands themselves change):
```bash
# With docker
docker compose -f deploy/docker-compose.yml exec app php artisan discord:register-commands --global
# With podman
podman compose -f deploy/docker-compose.yml exec app php artisan discord:register-commands --global
```

Then start the bot alongside the rest of the stack:
```bash
# With docker
docker compose -f deploy/docker-compose.yml --profile discord up -d
# With podman
podman compose -f deploy/docker-compose.yml --profile discord up -d
```

Exactly one `discord:listen` process must run, so do not scale this service beyond one replica.
