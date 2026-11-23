# [Stanford Caravan](https://github.com/SU-SWS/stanford-caravan)
##### Version: 12.x

Maintainers: [Mike Decker](https://github.com/pookmish)

Description
---
CI tooling for Stanford's Drupal projects. It has two reusable GitHub Actions
workflows, the scripts they run, and the Codeception modules that extensions'
tests use.

| Workflow | Called from | What it does | Caller template |
|---|---|---|---|
| [Drupal tests](#drupal-tests) | A module, theme or profile repository | Runs PHPUnit, Codeception, PHPCS and PHPStan on the extension. | [config/.github/workflows/tests.yml](config/.github/workflows/tests.yml) |
| [Production security update](#production-security-update) | A Drupal site repository hosted on Acquia | Builds a deployable hotfix for security advisories in the code production runs. | [config/.github/workflows/security-update.yml](config/.github/workflows/security-update.yml) |

Both workflows check out their scripts from this repository at the
`caravan-ref` input. **Keep `caravan-ref` the same as the ref on the caller's
`uses:` line** (`@12.x`), so the workflow and its scripts come from the same
version. A push to `12.x` changes every caller at once.

| Path | What it is |
|---|---|
| [.github/workflows/](.github/workflows) | The reusable workflows. |
| [ci/](ci) | The test workflow's scripts. |
| [ci/security/](ci/security) | The security update workflow's scripts. |
| [config/](config) | Files copied into the test project (composer, settings, PHPUnit, Codeception and PHPCS configs, patches) and the caller templates. |
| [src/Codeception/Drupal/](src/Codeception/Drupal) | Codeception modules: `DrupalBootstrap`, `DrupalDrush`, `DrupalEntity` and `DrupalUser`. |

Drupal tests
---
[.github/workflows/drupal-tests.yml](.github/workflows/drupal-tests.yml) runs
PHPUnit and Codeception for a module, theme or profile. Copy
[config/.github/workflows/tests.yml](config/.github/workflows/tests.yml) into
the extension's repository to call it, and make **All Tests Pass** the required
status check.

```yaml
jobs:
  tests:
    uses: SU-SWS/stanford-caravan/.github/workflows/drupal-tests.yml@12.x
    with:
      codeception-shards: 4
    secrets: inherit
```

It runs on a plain `ubuntu-24.04` runner, with no custom container: PHP comes
from `shivammathur/setup-php`, MySQL and Selenium run as service containers,
and PHP's built-in web server serves the site. Each step runs one of the
scripts in [ci/](ci), and the test site installs this package from the same
checkout.

### Inputs

| Input | Default | What it does |
|---|---|---|
| `php-versions` | `["8.3", "8.4", "8.5"]` | PHPUnit runs on each. Codeception runs on each on release branches and manual runs. |
| `primary-php` | `8.4` | The only PHP version Codeception runs on for other branches. |
| `release-branch-pattern` | `*.x` | Bash glob for the branches that run the full Codeception matrix. |
| `phpunit` | `true` | Run PHPUnit. |
| `coverage` | `true` | Collect coverage with pcov and run only Unit and Kernel tests. |
| `coverage-required` | `90` | Minimum line coverage, in percent. |
| `codeception-suites` | `["acceptance", "functional"]` | Suites to run. `[]` turns Codeception off. |
| `codeception-shards` | `1` | Number of shards to split each suite into. |
| `profile` | `standard` | Install profile for Codeception when testing a module or theme. |
| `modules` | (none) | Comma-separated extra modules to enable before Codeception runs. |
| `test-dir` | `tests/codeception` | Codeception tests, relative to the extension. |
| `core-version` | `^11` | Drupal core version, e.g. `11`, `11.3` or `^11`. |
| `caravan-ref` | `12.x` | Ref of this repository for the `ci/` scripts, configs and Codeception modules. |
| `phpcs` | `warn` | Drupal coding standards check: `fail`, `warn` (annotations and a summary, but the run passes) or `off`. |
| `phpstan` | `warn` | PHPStan with phpstan-drupal and the deprecation rules: `fail`, `warn` or `off`. |
| `phpstan-level` | `1` | PHPStan rule level, used when the extension has no `phpstan.neon`. |
| `mysql-image` | `mysql:8.0` | MySQL service image. |
| `selenium-image` | `selenium/standalone-chrome:130.0` | Selenium image. Only the functional suite starts it. |
| `system-packages` | `imagemagick` | Space-separated apt packages installed on the runner for PHPUnit and Codeception. ImageMagick is the Stanford profile's image toolkit; without it, image uploads and image styles fail. |

The optional secret `STANFORD_ENCRYPT` is set for the whole Codeception job (the
install, the web server and the tests); `secrets: inherit` in the caller provides it.

Test results are uploaded as `phpunit-php<version>` (on failure) and
`codeception-<suite>-php<version>-<shard>` artifacts, and the code quality
reports (checkstyle XML) as `code-quality`.

### Code quality

The **Code Quality** job runs on `primary-php`:

- **PHPCS** with `drupal/coder` (the `Drupal` and `DrupalPractice` standards),
  installed apart from the extension so their dependencies can't clash. The
  default ruleset is [config/phpcs.xml](config/phpcs.xml). It checks PHP, info
  and YAML files, skips `node_modules`, `vendor`, `dist` and `.github`, allows
  Codeception's `$I`, and lets info files keep the `version` and `project` keys
  that drupal.org packaging would otherwise add. An extension with its own
  `phpcs.xml`, `phpcs.xml.dist`, `.phpcs.xml` or `.phpcs.xml.dist` uses that
  instead.
- **PHPStan** with `phpstan-drupal` and `phpstan-deprecation-rules`, in a built
  project. It analyses the whole extension except `tests/codeception` at
  `phpstan-level`. An extension with its own `phpstan.neon` or
  `phpstan.neon.dist` uses that instead.

Findings appear as annotations on the run and in the job summary, which lists up
to 200. GitHub shows only the first 10 annotations of each type per step, so check
the summary, or download the `code-quality` artifact, for the rest. In `fail` mode,
errors fail the job; warnings never do.

To fix most phpcs findings automatically, run `phpcbf` with the same standard.

### Scripts

The workflow's steps, usable on their own (for example to reproduce a CI run
locally). Each takes `<extension dir> <site dir>` and reads the settings named
at the top of the script from the environment.

| Script | What it does |
|---|---|
| `ci/lint.sh` | Checks PHP syntax and field storage name lengths (takes only `<extension dir>`). |
| `ci/build.sh` | Builds a Drupal project with the extension in it. |
| `ci/phpunit.sh` | Runs PHPUnit and checks coverage. |
| `ci/install.sh` | Installs Drupal and enables the extension and any extra modules. |
| `ci/codeception.sh` | Runs the Codeception suites and retries failed tests once. |
| `ci/phpcs.sh` | Checks coding standards (takes `<extension dir> [<report dir>]`). |
| `ci/phpstan.sh` | Runs PHPStan in a project built with `WITH_PHPSTAN=1` (takes `<extension dir> <site dir> [<report dir>]`). |
| `ci/report.php` | Turns a checkstyle report into annotations and a summary, and decides pass or fail. |

### Upgrading from 11.x

12.x removes the `sws-caravan` Robo commands (`phpunit`, `codeception`,
`back-to-dev`), deprecated in 11.x. Replace `sws-caravan phpunit` and
`sws-caravan codeception` jobs with this workflow. `back-to-dev` has no
replacement here; move those release steps into the repository.

Production security update
---
[.github/workflows/security-update.yml](.github/workflows/security-update.yml)
finds security advisories in the code a Drupal site's production runs on
Acquia, and prepares a deployable hotfix that changes as little as possible.
It never deploys; a release engineer deploys the resulting tag. The workflow's
header describes every step in detail.

For each production tag it:

1. reads that tag's `composer.lock` from the Acquia git repository and audits it;
2. updates only the vulnerable packages, starting with patch releases;
3. installs sites on the production code, switches to the updated code, runs
   the database updates and exports the config;
4. ships config the updates change (committed, or as a patch plus a pull request
   to the package that holds it);
5. commits a hotfix version (`7.0.3` → `7.0.3+1`), pushes it to a
   `security/<version>` branch and pushes the built artifact to Acquia as
   `YYYY-MM-DD_7.0.3+1`;
6. opens a pull request from `security/<version>` to the default branch, to
   review the composer changes and run the site's automated tests;
7. posts to Slack: the tag, updated dependencies, database updates, whether
   config changes, the pull requests, or what failed.

Each run writes a job summary and uploads a `security-report-<tag>` artifact
with the audits, changes, database updates, config patches, site test output,
commit message and pull request bodies.

### Using it in a site repository

Copy [config/.github/workflows/security-update.yml](config/.github/workflows/security-update.yml)
to `.github/workflows/security-update.yml` in the site repository, choose a
`ref-source`, and add the secrets. The schedule only runs from the site's
default branch.

```yaml
permissions:
  contents: write
  pull-requests: write

jobs:
  security-update:
    uses: SU-SWS/stanford-caravan/.github/workflows/security-update.yml@12.x
    with:
      caravan-ref: 12.x
      ref-source: acsf
      acsf-url: https://www.example.acsitefactory.com
    secrets: inherit
```

The caller grants `contents: write` (for `security/*` branches) and
`pull-requests: write` (for pull requests in the site repository). The hotfix
pull request uses `CONFIG_PR_TOKEN` when it can, so the site's tests start.
The site-repository config pull request, and the hotfix one without that
token, use the workflow's own token: the repository's or organization's
Actions settings must allow "GitHub Actions to create and approve pull
requests", and GitHub doesn't start other workflows for them (push to the
branch, or close and reopen the pull request, to run the tests).

The site repository needs:

- **`su-sws/sws-drush-commands`.** Builds use `drush sws:artifact:deploy` with its
  options in `drush/drush.yml` (`git-url`, `post-build-script`,
  `artifact-dir`), and test sites are installed with
  `drush drupal:install --site=<site>`.
- **CI database settings the job's MySQL service can satisfy.** Database
  `drupal`, reached as root with no password on `127.0.0.1`, or as
  `drupal`/`drupal` on the host `mysql`. Both work.
- **Release tags.** Production tags are named `YYYY-MM-DD_<release>`, and the
  artifact's commit message has a `Built from <branch>@<sha>` line
  (`sws:artifact:deploy` writes it).

### Inputs

| Input | Default | What it does |
|---|---|---|
| `ref-source` | `cloud` | Where to find the production tag: `cloud` or `acsf` (below). |
| `acquia-apps` | (none) | `cloud`: application UUIDs, one per line. Defaults to `command.sws.options.app-id` in `drush/drush.yml`. |
| `acquia-environment` | `prod` | `cloud`: the environment to audit. |
| `acsf-url` | (none) | `acsf`: the Site Factory URL, e.g. `https://www.example.acsitefactory.com`. |
| `acsf-stack-id` | `1` | `acsf`: the stack. Pass it as a string (`'4'`). |
| `prod-ref` | (none) | A tag to audit instead of asking an API. Pushed to every `git-url` in `drush/drush.yml`. |
| `scope` | `drupal` | `drupal` (only `drupal/*` packages) or `all`. |
| `dry-run` | `false` | Build the artifacts but push nothing, open no pull requests and post nothing to Slack. |
| `sites` | (none) | Sites to consider for the test, one per line. See [Multisites](#multisites). |
| `php-version` | `8.4` | PHP version for the job. |
| `php-extensions` | (the usual Drupal set) | PHP extensions for `shivammathur/setup-php`. |
| `caravan-ref` | `12.x` | Ref of this repository for the `ci/security/` scripts. |
| `caravan-repository` | `SU-SWS/stanford-caravan` | Where the scripts come from. Change only to test a fork. |

### Where the production tag comes from

| `ref-source` | Uses | Secrets |
|---|---|---|
| `cloud` (default) | The Cloud Platform API: the `acquia-environment` of each application in `acquia-apps`. | `ACQUIA_API_KEY`, `ACQUIA_API_SECRET` |
| `acsf` | The Site Factory API at `acsf-url`, for the stack `acsf-stack-id`. | `ACSF_USER`, `ACSF_KEY` |
| either, with `prod-ref` | The given tag, pushed to every `git-url` in `drush/drush.yml`. | none |

If several applications run the same code, list them all in `acquia-apps`.
Applications on the same tag share one hotfix. Each different tag gets its own
hotfix, built one at a time and pushed only to the applications that run it.

### Secrets

Pass them with `secrets: inherit`.

| Secret | Needed for |
|---|---|
| `ACQUIA_SSH_PRIVATE_KEY` | Reading and pushing the Acquia git repositories (dry runs read too). |
| `ACQUIA_API_KEY`, `ACQUIA_API_SECRET` | `ref-source: cloud`. |
| `ACSF_USER`, `ACSF_KEY` | `ref-source: acsf`. |
| `CONFIG_PR_TOKEN` | Optional. Contents and pull request write access to packages that hold config, such as install profiles, **and to the site repository**. It pushes `security/<version>` and opens the hotfix pull request, so the site's GitHub Actions tests start; GitHub doesn't start workflows for pushes or pull requests made with the workflow's own token. Without it, open the package pull requests by hand from the report, and the site's GitHub Actions tests don't run on their own (CircleCI still builds the branch). |
| `SLACK_WEBHOOK_URL` | Optional. Slack messages. |
| `STANFORD_ENCRYPT` | Optional. Set as an environment variable for sites whose settings need it. |

### Variables

Set them on the site repository or its organization (Settings → Secrets and
variables → Actions → Variables). The workflow reads the calling repository's
variables, so the caller doesn't pass them.

| Variable | What it does |
|---|---|
| `CONFIG_PR_ASSIGNEE` | Optional. GitHub login (or several, comma-separated) to assign the pull requests to (the hotfix pull request and the config ones), including existing ones a later run comments on. If the person can't be assigned in that repository, the run only warns. |

### Multisites

The test considers `sites` (input), else `command.sws.options.multisites` in
`drush/drush.yml`, else `default`. Sites that share an install profile (from the
site's `sws.yml`, then `project.profile`, then `command.site.install.profile`)
and a config sync directory get the same updates, so only the first of each
group is installed and updated.

### Config the updates change

Production's `drush deploy` imports config right after the database updates, so
config an update changes is reverted unless the sync directory has it too. For
each changed sync directory:

- **Committed in the site repository:** the exported change is committed with
  the hotfix. Because `security/<version>` is never merged back, one pull
  request with only the config changes (every such directory together) is also
  opened against the site repository's default branch. It's built on the tip of
  that branch, or on the commit production runs if it conflicts. Merge it
  before the site's next regular release.
- **Inside a composer package** (for example an install profile): the change
  ships as `patches/security/<package>-config-<version>.patch`, listed under
  `extra.patches` in `composer.json`. A pull request with the same change is
  opened in the package's GitHub repository. Its branch is named after the
  change, so site repositories that need the same change share one pull request.
  Merge it before the site's next regular release, then remove the patch.

### Working with hotfixes

- **The source of each hotfix** is on `security/<version>` and on
  `refs/security/<version>`, which survives the branch being deleted and doesn't
  trigger tag-based CI. List them with `git ls-remote origin 'refs/security/*'`.
- **Don't push a git tag for a hotfix.** Tag-based CI would try to build the
  same Acquia tag again.
- **Don't delete `refs/security/*` for a version production runs.** The next
  run needs its source commit.
- **A run that finds a hotfix already built** for the same advisories on the
  same production tag stops. To rebuild, delete that version's ref
  (`git push origin :refs/security/<version>`); it isn't deployed.
- **A hotfix whose artifact push failed** (its ref exists but its Acquia tag
  doesn't) is built again by the next run, from its saved source and under the
  same version, for the applications missing the tag. Deleting its
  `security/<version>` branch doesn't matter; the run restores it.
- **Try changes with `dry-run`.** It builds the artifacts but pushes nothing,
  opens no pull requests and posts nothing to Slack.

### Scripts

The workflow checks out only [ci/security/](ci/security) and copies it out of
the workspace before running, because `drush sws:artifact:deploy` refuses to
build with untracked files. The scripts run from the site repository's root.

| Script | What it does |
|---|---|
| `find-production.sh` | Lists production tags and the git URLs that run them, as JSON. |
| `security-update.sh` | Audits `composer.lock` and updates the fewest packages. Safe to run locally on a copy of a `composer.json`/`composer.lock` pair. |
| `test-update.sh` | Installs, updates and exports test sites. **Don't run it locally:** it reinstalls sites and wipes their databases. |
| `config-pr.sh` | Opens the config pull requests: one per package, and one in the site repository for config committed there. |
| `hotfix-pr.sh` | Opens the pull request from `security/<version>` to the default branch. |
| `lib.sh` | Shared helpers (reading `drush/drush.yml`, Slack escaping). |

Testing changes to this repository
---
1. Push the change to a branch other than `12.x`.
2. In a test branch of a calling repository, point both `uses: ...@<branch>`
   and `caravan-ref: <branch>` at it. For the security update, run it with
   `dry-run`.
3. Merge to `12.x` once the run passes, and point the caller back at `12.x`.

Troubleshooting
---
Check the failed step's log and the job summary first. The security update's
Slack message names the step that failed, and its `security-report-<tag>`
artifact has the full output. If you're still stuck, open an issue in this
repository's GitHub issue queue.

Contribution / Collaboration
---
You are welcome to contribute functionality, bug fixes, or documentation. Open
an issue in the GitHub issue queue, or fork this repository and submit a pull
request. For more help, see
[GitHub's article on fork, branch, and pull requests](https://help.github.com/articles/using-pull-requests).
