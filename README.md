# [Stanford Caravan](https://github.com/SU-SWS/stanford-caravan)
##### Version: 8.x-1.x

Maintainers: [Mike Decker](https://github.com/pookmish)

Changelog: [Changelog.txt](CHANGELOG.txt)

Description
---
Tools for running tests against Drupal modules, profiles, and themes.

Installation
---
To set up CircleCI, copy the directory [config/.circleci](config/.circleci) into
the root of the desired directory. Commit that directory to a branch on github.
Then in [CircleCi](https://circleci.com/add-projects) add the desired repository 
as a project to be tested. Creating a pull request and an additional commit may
be necessary to trigger the build to run.

GitHub Actions
---
[.github/workflows/drupal-tests.yml](.github/workflows/drupal-tests.yml) is a
reusable workflow that runs PHPUnit and Codeception for a module, theme or
profile. Copy [config/.github/workflows/tests.yml](config/.github/workflows/tests.yml)
into the extension's repository to call it, and make **All Tests Pass** the
required status check.

It runs on a plain `ubuntu-24.04` runner, with no custom container: PHP comes
from `shivammathur/setup-php`, MySQL and Selenium run as service containers,
and PHP's built-in web server serves the site.

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
| `core-version` | caravan's default | Drupal core version, e.g. `11` or `11.3`. |
| `caravan-version` | `11.x-dev` | Composer constraint for this package. |
| `mysql-image` | `mysql:8.0` | MySQL service image. |
| `selenium-image` | `selenium/standalone-chrome:130.0` | Selenium image. Only the functional suite starts it. |

The optional secret `STANFORD_ENCRYPT` is passed to Codeception; `secrets: inherit`
in the caller provides it.

Test results are uploaded as `phpunit-php<version>` (on failure) and
`codeception-<suite>-php<version>-<shard>` artifacts.


Troubleshooting
---

If you are experiencing issues with this module try reverting the feature first. If you are still experiencing issues try posting an issue on the GitHub issues page.


Contribution / Collaboration
---

You are welcome to contribute functionality, bug fixes, or documentation to this module. If you would like to suggest a fix or new functionality you may add a new issue to the GitHub issue queue or you may fork this repository and submit a pull request. For more help please see [GitHub's article on fork, branch, and pull requests](https://help.github.com/articles/using-pull-requests)
