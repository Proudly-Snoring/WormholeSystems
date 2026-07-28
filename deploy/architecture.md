# Deployment

This file describes in more detail the deployment architecture and which configuration lies where.

## TLS

FrankenPHP embeds Caddy, so the `app` container terminates TLS itself: it takes ports 80 and 443, obtains a Let's Encrypt certificate for `SERVER_NAME`, and proxies `/app/*` to `reverb:8080` over the internal network. The websocket therefore lives on the application's own origin (`wss://mapper.prsn.online/app/<key>`) — one hostname, one certificate, one DNS record.

Requirements, all checked only at runtime:
- `mapper.prsn.online`'s DNS points at the host,
- Ports 80 and 443 are free — the HTTP-01 challenge answers on 80, and renewals need it to stay that way.
  `443/udp` is published too, for HTTP/3; it is the only optional one, cf. the "Network ports"
  section of [deploy/readme.md](readme.md#network-ports),
- The `caddy-data` volume stays mounted. It holds the issued certificate - without it every restart
  re-requests one and the deployment hits Let's Encrypt's limit.

## Values baked into the image

None of these are configurable from `deploy/.env` — they are declared in [`.github/workflows/publish.yml`](../.github/workflows/publish.yml) and take effect only when a new image is built:

**Inlined into the JavaScript bundle by Vite:**

| Build arg             | Baked value                                                    |
|-----------------------|----------------------------------------------------------------|
| `VITE_APP_NAME`       | the name in browser tab titles                                 |
| `VITE_REVERB_HOST`    | `mapper.prsn.online`                                           |
| `VITE_REVERB_PORT`    | `443`                                                          |
| `VITE_REVERB_SCHEME`  | `https`                                                        |
| `VITE_REVERB_APP_KEY` | the Reverb app key, also baked as the runtime `REVERB_APP_KEY` |

All of them have a default pointing at a localhost stack, so a plain `docker build` produces a working image. `VITE_REVERB_APP_KEY` defaults to `local`, the same value `docker-compose.local.yml` uses, because an empty key stops matching Reverb's `/app/{appKey}` route and would break the websocket without any visible error.

**Baked as plain runtime `ENV` by `deploy/Dockerfile`** — read by Laravel/Caddy, not by Vite:

| Build arg               | Baked value                                   |
|-------------------------|-----------------------------------------------|
| `APP_URL`               | `https://mapper.prsn.online`                  |
| `SERVER_NAME`           | `mapper.prsn.online`                          |
| `EVE_CALLBACK`          | `https://mapper.prsn.online/eve/callback`     |
| `DISCORD_CALLBACK`      | `https://mapper.prsn.online/discord/callback` |
| `SESSION_SECURE_COOKIE` | `true`                                        |

Changing the domain in `APP_URL` alone is therefore not enough: `VITE_REVERB_HOST` would still point the browser's websocket at the old host, and `SERVER_NAME` would still request a certificate for it.
Change the workflow's `env` block and publish a new release instead — a bare `docker run` of the published image otherwise immediately attempts an ACME certificate for `mapper.prsn.online`.

None of the keys above have an entry in `deploy/.env.example` either, and deliberately so: `env_file` values take precedence over an image's own `ENV`, so setting any of them in `deploy/.env` would silently override the baked value instead of being ignored — for `REVERB_APP_KEY` that means a desync from the browser-side copy baked into the JS bundle, breaking every websocket handshake with a pusher error 4001; for the others, a domain mismatch between Laravel/Caddy and the browser.

## Values fixed by the compose stack

The following variables are set directly in `docker-compose.yml`'s `environment:` block rather than `deploy/.env`:
- `BROADCAST_CONNECTION`,
- `DB_CONNECTION`,
- `DB_HOST`,
- `DB_DATABASE`,
- `DB_USERNAME`,
- `QUEUE_CONNECTION`,
- `CACHE_STORE`,
- `REDIS_HOST`,
- `REVERB_HOST`,
- `REVERB_PORT`,
- `REVERB_SCHEME`.

They name the other services defined in that same file (`mariadb`, `redis`, `reverb`), so changing one without moving the matching service definition just breaks the stack.
Unlike the group above, this one *is* safe to leave stray values for in `deploy/.env` — a Compose service's `environment:` attribute always wins over `env_file`, the opposite precedence from Dockerfile `ENV`.

`APP_USER_AGENT` and `CADDY_GLOBAL_OPTIONS` are built there too, both from `CONTACT_EMAIL` — the only address `deploy/.env` still asks for by hand.
