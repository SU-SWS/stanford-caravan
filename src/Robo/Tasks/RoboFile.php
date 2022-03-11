<?php

namespace StanfordCaravan\Robo\Tasks;

use Robo\Exception\AbortTasksException;
use Robo\Tasks;
use StanfordCaravan\CaravanTrait;

/**
 * CI/CD tools for running tests against themes, modules, and profiles.
 *
 * @package StanfordCaravan\Robo\Tasks
 */
class RoboFile extends Tasks {

  use loadTasks;
  use CaravanTrait;

  /**
   * RoboFile constructor.
   */
  public function __construct() {
  }

  /**
   * Set the global git configs if they aren't already set.
   */
  protected function setGlobalGitConfigs() {
    // Set username and email for git config.
    $git_config = $this->taskGitStack()
      ->exec(['config', '--global --list'])
      ->printOutput(FALSE)
      ->run()
      ->getMessage();
    if (
      strpos($git_config, 'user.email') === FALSE ||
      strpos($git_config, 'user.name') === FALSE
    ) {
      $this->taskGitStack()
        ->exec(['config', '--global user.email "CircleCI"'])
        ->run();
      $this->taskGitStack()->exec([
        'config',
        '--global user.name "sws-developers@lists.stanford.edu"',
      ])->run();
    }
  }

  /**
   * Adjust the composer.json and info.yml files back to dev after a release.
   *
   * @param string $old_semver
   *   Recently released semver tag.
   * @param string $directory
   *   Directory where the module is to be updated.
   * @param string $main_branch
   *   Optional name of main branch. Default is 'master'
   *
   * @command back-to-dev
   * @usage vendor/bin/sws-caravan back-to-dev 8.1.0 /var/www/stanford_module
   * @usage vendor/bin/sws-caravan back-to-dev ${CIRCLE_TAG} ${CIRCLE_WORKING_DIRECTORY}
   * @usage vendor/bin/sws-caravan back-to-dev ${CIRCLE_TAG} ${CIRCLE_WORKING_DIRECTORY} main
   */
  public function backToDev($old_semver, $directory, $main_branch='master') {
    $this->setGlobalGitConfigs();

    list($major, $minor, $point) = explode('.', $old_semver);
    $branch = "$major.x-$minor.x";

    // Merge master into the release branch first.
    $tasks[] = $this->taskGitStack()->dir($directory)->checkout($main_branch);
    $tasks[] = $this->taskGitStack()->dir($directory)->pull('origin', $main_branch);
    $tasks[] = $this->taskGitStack()->dir($directory)->checkout($branch);
    $tasks[] = $this->taskGitStack()
      ->dir($directory)
      ->exec("reset --hard origin/$branch");
    $tasks[] = $this->taskGitStack()
      ->dir($directory)
      ->merge("--strategy-option=theirs $main_branch --no-edit");
    $result = $this->collectionBuilder()->addTaskList($tasks)->run();

    if (!$result->wasSuccessful()) {
      return $result;
    }

    // Adjust the versions in the yaml files.
    $info_yamls = $this->rglob("$directory/*.info.yml");
    foreach ($info_yamls as $yaml_file) {
      $new_point = (int) $point + 1;
      $new_version = "$major.x-$minor.$new_point-dev";
      $yaml = file_get_contents($yaml_file);
      $yaml = preg_replace('/version:.*?$/m', 'version: ' . $new_version, $yaml);
      file_put_contents($yaml_file, $yaml);
    }

    $result = $this->taskGitStack()
      ->dir($directory)
      ->checkout("origin/$branch -- composer.json")
      ->run();

    if (!$result->wasSuccessful()) {
      return $result;
    }

    return $this->taskGitStack()
      ->dir($directory)
      ->add('.')
      ->commit('Back to dev')
      ->push('origin', $branch)
      ->run();
  }

  /**
   * Run phpunit tests on the given extension.
   *
   * @param string $html_path
   *   Path to the drupal project.
   * @param array $options
   *   Command options.
   *
   * @options extension-dir Path to the Drupal extension.
   * @options with-coverage Flag to run PHPUnit with code coverage.
   * @options coverage-required Set the percent of the coverage that is needed.
   *
   * @command phpunit
   */
  public function phpunit($html_path, array $options = [
    'extension-dir' => NULL,
    'with-coverage' => FALSE,
    'coverage-required' => 90,
  ]) {

    if (empty($options['extension-dir'])) {
      throw new AbortTasksException('--extension-dir is required');
    }

    $extension_dir = $options['extension-dir'];
    $this->lintPhp($extension_dir);
    $this->checkFileNameLengths($extension_dir);

    if (empty($this->rglob("$extension_dir/*Test.php"))) {
      $this->say('Nothing to test');
      return;
    }

    $extension_type = $this->getExtensionType($extension_dir);
    $extension_name = $this->getExtensionName($extension_dir);

    $tasks[] = $this->taskDrupalStack($html_path)
      ->testExtension($extension_dir);

    $tasks[] = $this->taskSuPhpUnitStack()
      ->dir("$html_path/web")
      ->testDir("$html_path/web/{$extension_type}s/custom/$extension_name")
      ->withCoverage($options['with-coverage'])
      ->reportDir("$html_path/artifacts")
      ->extensionType($extension_type)
      ->extensionName($extension_name);

    $test_result = $this->collectionBuilder()->addTaskList($tasks)->run();

    if ($options['with-coverage']) {
      $this->checkCoverageReport("$html_path/artifacts/phpunit/coverage/xml/index.xml", $options['coverage-required']);
    }

    return $test_result;
  }

  /**
   * Check if the code coverage is sufficient.
   *
   * @param string $report
   *   Path to coverage report.
   * @param int $required_coverage
   *   Required coverage percent.
   */
  public function checkCoverageReport($report, $required_coverage) {
    if (!file_exists($report)) {
      $this->say("<info>No coverage report available.</info>");
      return;
    }

    $dom = new \DOMDocument();
    libxml_use_internal_errors(TRUE);
    $dom->loadHTML(file_get_contents($report));
    $xpath = new \DOMXPath($dom);
    $total_coverage = $xpath->query("//directory[@name='/']/totals/lines/@percent")
      ->item(0)->nodeValue;

    if ((float) $total_coverage < (float) $required_coverage) {
      throw new AbortTasksException("Code coverage is not sufficient at $total_coverage%. $required_coverage% is required.");
    }
    $this->say("<info>Code coverage at $total_coverage%.</info>");
  }

  /**
   * Lint php files.
   *
   * @param string $dir
   *   Path to lint.
   *
   * @throws \Exception
   */
  protected function lintPhp($dir) {
    $php_lint_extenstions = [
      'php',
      'module',
      'theme',
      'install',
    ];

    array_walk($php_lint_extenstions, function (&$extension) {
      $extension = "-name '*.$extension'";
    });
    $php_lint_extenstions = implode(' -o ', $php_lint_extenstions);

    exec("find $dir -type f \( $php_lint_extenstions \) -exec php -l {} \; | grep -v 'No syntax errors detected'", $output);
    if (!empty(array_filter($output))) {
      throw new \Exception(implode(PHP_EOL, array_filter($output)));
    }
  }

  /**
   * Chec field storage configs to make sure they aren't too long.
   *
   * @param string $dir
   *   Path to the directory that might contain configs.
   */
  protected function checkFileNameLengths($dir) {
    $errors = [];
    $files = $this->rglob("$dir/*/field.storage.*");
    foreach ($files as $file) {
      $filename = basename($file);
      list(, , $entity_type, $field_name,) = explode('.', $filename);
      if (strlen("{$entity_type}_revision__$field_name") >= 48) {
        $count = 48 - strlen("{$entity_type}_revision__");
        $errors[] = "$filename field name is too long. Keep the field name under $count characters on '$entity_type' entities.";
      }
    }
    if (!empty($errors)) {
      throw new AbortTasksException(implode(PHP_EOL, $errors));
    }
  }

  /**
   * Install drupal in the given directory with a desired profile.
   *
   * @param string $drupal_root
   *   Drupal web root directory.
   * @param string $profile
   *   Profile to install.
   * @param array $enable_modules
   *   Array of modules to enable after installation.
   * @param array $disable_modules
   *   Which modules to disable after installing drupal.
   *
   * @return array
   *   Array of tasks to be executed.
   */
  protected function installDrupal($drupal_root, $profile, array $enable_modules = [], array $disable_modules = []) {
    $tasks[] = $this->taskWriteToFile("$drupal_root/sites/default/settings.php")
      ->textFromFile("{$this->toolDir()}/config/circleci.settings.php");

    $tasks[] = $this->taskDrushStack("../vendor/bin/drush")
      ->dir($drupal_root)
      ->siteInstall($profile);

    if ($enable_modules) {
      $tasks[] = $this->taskDrushStack("../vendor/bin/drush")
        ->dir($drupal_root)
        ->drush("pm:enable " . implode(',', $enable_modules));
    }

    if ($disable_modules) {
      $tasks[] = $this->taskDrushStack("../vendor/bin/drush")
        ->dir($drupal_root)
        ->drush('pm:uninstall ' . implode(',', $disable_modules));
    }

    $tasks[] = $this->taskDrushStack("../vendor/bin/drush")
      ->dir($drupal_root)
      ->drush('cache-rebuild');

    return $tasks;
  }

  /**
   * Run codeception tests.
   *
   * @param string $html_path
   *   Path location where to install drupal.
   * @param array $options
   *   Array of command options.
   *
   * @command codeception
   *
   * @options extension-dir Path to the the drupal extension to test.
   *   (Required)
   * @options profile Drupal profile to install if different than "Standard".
   * @options suite Codeception suite to test.
   * @options test-dir Path within the extension-dir where codeception tests
   *   are.
   * @options domain Change the domain for the tests if needed.
   *
   * @link https://codeception.com/quickstart
   */
  public function codeCeption($html_path, array $options = [
    'extension-dir' => NULL,
    'profile' => 'standard',
    'modules' => '',
    'suites' => 'acceptance,functional',
    'test-dir' => 'tests/codeception',
    'domain' => 'localhost',
  ]) {

    $extension_dir = is_null($options['extension-dir']) ? "$html_path/.." : $options['extension-dir'];
    $tasks[] = $this->taskDrupalStack($html_path)
      ->testExtension($extension_dir);

    $extension_type = $this->getExtensionType($extension_dir);
    $extension_name = $this->getExtensionName($extension_dir);

    $profile = $options['profile'];
    $enable_modules = explode(',', $options['modules']);
    $enable_modules[] = $extension_name;
    $enable_modules = array_values(array_unique(array_filter($enable_modules)));
    $disable_modules = [];

    if ($extension_type == 'profile') {
      $profile = $extension_name;
      $enable_modules = [];
      $disable_modules[] = "simplesamlphp_auth";
    }

    $tasks = array_merge($tasks, $this->installDrupal("$html_path/web", $profile, $enable_modules, $disable_modules));
    $tasks[] = $this->taskCodeCeptionStack($html_path)
      ->testDir("$html_path/web/{$extension_type}s/custom/$extension_name/{$options['test-dir']}")
      ->suites($options['suites'])
      ->domain($options['domain']);

    return $this->collectionBuilder()
      ->addTaskList($tasks)
      ->run();
  }

}
