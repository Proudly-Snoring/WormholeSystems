This repository is a fork of [WormholeSystems/WormholeSystems](https://github.com/WormholeSystems/WormholeSystems) with specific modifications made for Proudly Snoring usage.  
The objective is to maintain _pull_ and _push_ capabilities with the upstream repository.

>In this document, the `origin` remote will refer to [Proudly-Snoring/WormholeSystems](https://github.com/Proudly-Snoring/WormholeSystems) while the `upstream` remote will refer to [WormholeSystems/WormholeSystems](https://github.com/WormholeSystems/WormholeSystems).


## Branches

We use different types of branches:
- `development` is the shared code with the **upstream** `development` branch, which is the most up to date branch with latest developments. This branch should be in sync with the **upstream** remote.
- `main` is local to the **origin** repository.  It contains the latest code and is deployable in Proudly Snoring infrastructure. It is **not** shared with WormholeSystems/WormholeSystems as it contains specific code that should not end up there.
- `feat/xxx` are features branches (where "xxx" is the name of the feature).
- `fix/xxx` contain fixes (where "xxx" is the name of the fix).

### Rules

- You must **never** _push_ to `development` directly (on any remote).
- You should regularly merge upstream `development` into origin `development`.
- You must **never** merge `main` into `development` (on both remotes).
- You should regularly merge origin `development` into origin `main`.

For **shared** features/fixes (landing on both origin and upstream):
- You must create your branch from the **origin** `development` branch.
- You should create a Pull Request from your feature/fix branch to the **upstream** `development` branch.
- Once approved, you should merge **upstream** `development` into **origin** `development`.

For **specific** features/fixes (landing only on origin):
- You should create your branch from the **origin** `main` branch (but you can also use `development`).
- You should create a Pull Request from your feature/fix branch to the **origin** `main` branch _for  features/fixes_.


## Development workflow

### The first time
```shell
git clone git@github.com:Proudly-Snoring/WormholeSystems.git
git remote add upstream git@github.com:WormholeSystems/WormholeSystems.git
git branch --set-upstream-to=upstream/development
```

### For **shared** features/fixes

For features/fixes that will land on both origin and upstream.
- Create a new branch from origin `development`:
  ```shell
  git checkout development
  git pull origin development
  git checkout -b feat/xxx # or fix/xxx
  ```
- Make your changes and commit them.
  Do not forget to test your change and run the automated tests.
- Push your branch to origin:
  ```shell
  git push origin feat/xxx # or fix/xxx
  ```
- Open a [Pull Request](https://github.com/WormholeSystems/WormholeSystems/compare/development...Proudly-Snoring:WormholeSystems:development) from origin `feat/xxx` to the **upstream** `development` branch.
- Once the PR is approved and merged upstream, pull upstream `development` into origin `development`.

### For **specific** features/fixes

For features/fixes that will land only on origin.
- Create a new branch from origin `main`:
  ```shell
  git checkout main
  git pull origin main
  git checkout -b feat/xxx # or fix/xxx
  ```
- Make your changes and commit them.
  Do not forget to test your change and run the automated tests.
- Push your branch to origin:
  ```shell
  git push origin feat/xxx # or fix/xxx
  ```
- Open a [Pull Request](https://github.com/Proudly-Snoring/WormholeSystems/compare/Proudly-Snoring:WormholeSystems:main...development) from origin `feat/xxx` to the **origin** `main` branch.

## Tests

Backend tests:
```shell
# Run all tests
php artisan test
# Run all tests in a file
php artisan test tests/Feature/ExampleTest.php
# Run a single test by name
php artisan test --filter=testName
```

Frontend tests:
```shell
npm run test
```

Linters:
```shell
vendor/bin/pint   # PHP
npm run lint      # npm (ESLint)
```

The CI runs the full test+linter suite on every push.
A failing test or lint error will block the PR.


## Release process

- Make sure all related features are on origin `main` (merge origin `development` if needed).
- Thoroughly test from the origin `main` branch (do not forget to test the containers too).
- Create [a new release](https://github.com/Proudly-Snoring/WormholeSystems/releases/new) from origin `main`, giving it a tag following the [semantic versioning](https://semver.org/) convention (no leading `v`).

Publishing the release triggers [`.github/workflows/publish.yml`](.github/workflows/publish.yml), which builds the image from [`deploy/Dockerfile`](deploy/Dockerfile) and pushes it to `ghcr.io/proudly-snoring/wormholesystems`, tagged with the release version. Once the workflow run is green, the new image is live on GHCR.

Marking the release as a **prerelease** on GitHub is what keeps it out of the way of production: the workflow then publishes the version tag only, and leaves `latest` pointing at the previous stable release. A normal release moves `latest` too.

See [`deploy/readme.md`](deploy/readme.md) for deployment details (incl. local testing of the image).

Notes:
- The workflow only ever *publishes* - it never runs migrations or touches a live deployment — that happens on the target server when the new image starts (`deploy/files/entrypoint.sh` runs `php artisan migrate --force`).
- GHCR packages pushed by a workflow's default token can start out private.
  The first time this workflow runs, check the package's visibility under the repo's **Packages** tab and set it to **Public** if needed — otherwise anonymous `docker pull` will fail.
  This is a one-time setting per package - it doesn't need repeating on later releases.
- The image is **not** domain-agnostic. `VITE_APP_NAME` and the browser-facing `VITE_REVERB_*` values are passed as build args (declared in the workflow's `env` block) and inlined into the JavaScript bundle by Vite, so the published image is specific to `mapper.prsn.online`. Changing any of them means editing that block and publishing a new release — cf. [Values baked into the image](deploy/architecture.md#values-baked-into-the-image).
