# AGENTS.md

Guidance for AI coding agents working in this repository (`su-sws/stanford-caravan`).

## What this repo is

Test tooling for Stanford's Drupal modules, themes and profiles, plus a workflow that
builds security hotfixes of Drupal sites. It has these parts:

| Path | What it is |
|---|---|
| `.github/workflows/drupal-tests.yml` | Reusable GitHub Actions workflow (`on: workflow_call`) that runs PHPUnit and Codeception for the extension in the calling repository. |
| `ci/` | The shell and PHP scripts that workflow runs, one per step. |
| `.github/workflows/security-update.yml` | Reusable workflow that Drupal **site** repositories call to build security hotfixes of what their production runs on Acquia. |
| `ci/security/` | The bash scripts the security update workflow runs. |
| `src/Codeception/Drupal/` | Codeception modules that extensions' tests use: `DrupalBootstrap`, `DrupalDrush`, `DrupalEntity`, `DrupalUser`. |
| `config/` | Files copied into the test project: `composer.json` (merged in), `circleci.settings.php` (becomes `settings.php`), `phpunit.xml`, `codeception/*.yml`, `patches/`. Also `phpcs.xml` (the default coding standard), and the caller templates `config/.github/workflows/tests.yml` and `config/.github/workflows/security-update.yml`. |

12.x has no command-line tool. The `sws-caravan` Robo commands (`phpunit`,
`codeception`, `back-to-dev`) were deprecated in 11.x and removed here; `ci/` and
the workflow replace them. Don't add Robo back as a dependency. Fixes for
repositories still on the Robo commands go on the 11.x branch.

## Branches and versions

- This is the `12.x` branch. There are no tags yet; callers use `@12.x`.
- `11.x` still has the deprecated Robo commands for repositories that haven't moved.
  Both branches have the workflow and `ci/`, so a fix there may be needed on both
  until every caller is on `12.x`.
- **A push to `12.x` changes CI for every caller at once.** Nothing pins a caller to a
  commit. Test workflow and `ci/` changes on another branch first (below).
- Don't commit or push unless asked. Never add AI/Claude attribution to commits or
  pull requests.

## How the workflow fits together

- The caller checks out its extension to `project/`. The workflow checks out this
  repository at the `caravan-ref` input to `caravan/` and builds the site in `site/`,
  all under `$GITHUB_WORKSPACE`.
- `caravan-ref` must match the ref on the caller's `uses:` line, or the workflow
  and its scripts come from different versions.
- `ci/merge-composer.php --caravan=<path>` adds this checkout to the test project as
  a Composer `path` repository and requires it at `*`. The site's Codeception
  modules and the configs therefore always come from the same checkout as the
  scripts, whatever version the extension's `composer.json` asks for.
- Jobs: `matrix` → `phpunit` (per PHP version), `code-quality` (phpcs and
  PHPStan, on `primary-php`) and `codeception` (suite × PHP × shard) → `result`
  ("Test Results"). `result` fails unless every job succeeded,
  or was turned off by an input. Callers add their own **All Tests Pass** job on
  top of it, and that is the required status check, so don't rename either job
  casually.
- There is no custom container. `shivammathur/setup-php` installs PHP, and MySQL and
  Selenium are service containers. PHP's built-in server serves the site on
  port 80 as the runner user.
- `config/codeception/codeception.yml`'s `github_actions` environment expects the
  hostnames `selenium` (WebDriver) and `drupal8ci` (the site URL the browser
  opens). The workflow adds both to the runner's `/etc/hosts`, and Selenium maps
  `drupal8ci` to the runner with `--add-host=drupal8ci:host-gateway`. Changing
  those names means changing the config and the workflow together.
- The web server is a separate process started in its own step, and it's the
  process that renders pages. Anything the site reads from the environment
  (like `STANFORD_ENCRYPT`) must be set at job level, not only on the test step.
- The runner image has no ImageMagick, which the Stanford profile uses as its image
  toolkit. The `system-packages` input (default `imagemagick`) installs it with
  apt. Without it, image uploads and derivatives silently fail.
- Only the `functional` suite uses WebDriver, so Selenium starts only for it (an
  empty service `image` skips the service).

## The ci/ scripts

- These notes cover the test scripts directly in `ci/`; `ci/security/` is separate (below).
- Each takes `<extension dir> <site dir>` (`lint.sh` only takes the extension) and
  reads its settings from environment variables listed in its header comment.
  Keep that header accurate.
- `lib.sh` is sourced, not run. It sets `CI_DIR`, `CARAVAN_DIR` and `CONFIG_DIR`,
  and has the extension helpers (`ext_name`, `ext_type`, `ext_path`,
  `core_constraint`, `codeception_config`).
- Scripts use `set -euo pipefail`. A script's exit status is the step's result.
- `codeception.sh` reruns failed tests once with `--group failed`. The retry's result
  decides the step.
- Run shellcheck and actionlint after changes:

  ```bash
  docker run --rm -v "$PWD:/mnt" -w /mnt koalaman/shellcheck:v0.10.0 -x -P ci ci/*.sh
  docker run --rm -v "$PWD:/mnt" -w /mnt koalaman/shellcheck:v0.10.0 -x -P ci/security ci/security/*.sh
  docker run --rm -v "$PWD:/repo" -w /repo rhysd/actionlint:1.7.7 -color=false
  ```

  actionlint 1.7.7 rejects YAML anchors, so the workflow doesn't use them.

## Code quality

- `phpcs` and `phpstan` take `fail`, `warn` or `off`, and default to `warn`.
  Existing extensions have hundreds of phpcs findings, so a `fail` default would
  break every caller at once. Keep `warn` the default; each repository opts into
  `fail`.
- `config/composer.json` replaces `drupal/coder` in the test project, so
  `ci/phpcs.sh` installs coder in its own Composer project (`CODER_DIR`). Don't
  move coder into the test project.
- `drupal/core-dev` brings PHPStan and phpstan-drupal. `build.sh` adds only
  `phpstan/phpstan-deprecation-rules`, with `WITH_PHPSTAN=1`.
- Some extensions have no PHP (ckeditor5_plugins is JavaScript and YAML). PHPStan
  stops with "No files found to analyse" and writes no report then, so
  `phpstan.sh` checks `has_php_files` first and passes with a note. An empty
  report anywhere else means the tool broke, and `report.php` fails on it.
- Both tools write checkstyle XML. `ci/report.php` makes annotations and the job
  summary from it, and is the only thing that decides pass or fail, so keep the
  tools' own exit codes ignored (`|| true`).
- Annotation paths must be relative to the calling repository's root. phpcs reports
  absolute paths (prefix `$EXT_DIR`); PHPStan reports paths relative to the site
  directory (prefix `web/<type>s/custom/<name>`).

## Running the scripts locally

The scripts work outside GitHub Actions. You need PHP (with pcov for coverage),
Composer, rsync and a MySQL server. For example, with MySQL in Docker on port 33307:

```bash
export DRUPAL_DATABASE_NAME=drupal DRUPAL_DATABASE_USERNAME=drupal \
  DRUPAL_DATABASE_PASSWORD=drupal DRUPAL_DATABASE_HOST=127.0.0.1 DRUPAL_DATABASE_PORT=33307
ci/lint.sh ../my_module
WITH_CODECEPTION=1 ci/build.sh ../my_module /tmp/site
COVERAGE=true ci/phpunit.sh ../my_module /tmp/site
ci/install.sh ../my_module /tmp/site
(cd /tmp/site/web && php -S 127.0.0.1:8089 .ht.router.php &)
SUITES=acceptance DOMAIN=localhost:8089 ci/codeception.sh ../my_module /tmp/site
```

- `build.sh` deletes the site directory first.
- Without `GITHUB_ACTIONS` set, Codeception doesn't use the `github_actions`
  environment, so the functional suite needs a local Selenium on port 4444.

## Testing a workflow change on GitHub

1. Push this repository's change to a branch other than `12.x`.
2. In a test branch of a calling repository, point both `uses: ...@<branch>` and
   `caravan-ref: <branch>` at it, then push.
3. Merge to `12.x` once the run passes, and point the caller back at `12.x`.

## The security update workflow

`.github/workflows/security-update.yml` (`on: workflow_call`) runs in Drupal site repositories,
not extensions. The README's "Production security update" section covers usage, and the
workflow's header describes each step.

- **Callers name a ref** on their `uses:` line and pass the same `caravan-ref`, which the workflow
  uses to check out `ci/security/` (a sparse checkout). Changing an input, output or secret name
  breaks callers. Add new inputs with defaults that keep the current behavior.
- **The scripts run against the caller's repository**, from its root. They read its
  `drush/drush.yml` through `drush_yml` in `ci/security/lib.sh` (not `ci/lib.sh`), which uses
  Python's yaml module or falls back to `yq`.
- **The workflow copies `ci/security/` to `/tmp/security-tools/scripts`** and removes the checkout,
  because `drush sws:artifact:deploy` refuses to build with untracked files.
- **Give new hotfix steps an `id`** and a line in `OUTCOMES` in "Notify Slack of a failure", so
  failures name them.
- **Test what you can locally:**
  - `security-update.sh` on a copy of a `composer.json`/`composer.lock` pair in a scratch
    directory.
  - `find-production.sh` with `PROD_REF` set.
  - `config-pr.sh` with a stub `gh` on `PATH` and `CLONE_URL_TEMPLATE` pointing at local bare
    repositories.
  - `hotfix-pr.sh` with a stub `gh` on `PATH`.
- **Don't run `test-update.sh` against a real site:** it reinstalls sites and drops their
  databases. Test the full workflow with a caller's `dry-run` input, pointing its `uses:` ref and
  `caravan-ref` at a branch of this repository.
- The commits and pull requests the workflow creates never carry AI/Claude attribution either.

## Codeception modules

The `src/Codeception/Drupal/` classes are part of the public API for every
extension's tests: suite configs enable them by class name, and tests call their
methods (`createEntity`, `createUserWithRoles`, `logInWithRole`, `runDrush`,
`fillWysiwygEditor`, `waitForAjaxToFinish`, ...). Keep those names and
signatures backward compatible. `DrupalEntity` and `DrupalUser` delete what tests
create, controlled by their `cleanup_test`, `cleanup_failed` and `cleanup_suite`
settings in `config/codeception/codeception.yml`.
