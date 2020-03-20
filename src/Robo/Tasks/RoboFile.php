<?php

namespace StanfordCaravan\Robo\Tasks;

use Robo\Exception\AbortTasksException;
use Robo\Result;
use Robo\Tasks;
use StanfordCaravan\CaravanTrait;
use Symfony\Component\Console\Input\InputOption;

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
   * Run phpunit tests on the given extension.
   *
   * @param string $html_path
   *   Path to the drupal project.
   * @param array $options
   *   Command options
   *
   * @option extension-dir Path to the Drupal extension.
   * @option with-coverage Flag to run PHPUnit with code coverage.
   *
   * @command phpunit
   */
  public function phpunit($html_path, $options = [
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

    $tasks[] = $this->taskExec("$html_path/vendor/bin/drupal-check")
      ->dir("$html_path/web")
      ->arg("$html_path/web/{$extension_type}s/custom/$extension_name");

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
      $this->printTaskInfo("No coverage report available.");
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
   *
   * @return string
   *   Array of messages with files that are too long.
   */
  protected function checkFileNameLengths($dir) {
    $errors = [];
    $files = $this->rglob("$dir/*/field.storage.*");
    foreach ($files as $file) {
      $filename = basename($file);
      [, , $entity_type, $field_name,] = explode('.', $filename);
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
   * Run behat commands defined in the module.
   *
   * @param string $html_path
   *   Path to the html directory.
   * @param array $options
   *   Command options.
   *
   * @throws \Robo\Exception\TaskException
   *
   * @command behat
   */
  public function behat($html_path, $options = [
    'extension-dir' => NULL,
    'profile' => 'standard',
    'latest-drupal' => FALSE,
  ]) {
    $extension_dir = is_null($options['extension-dir']) ? "$html_path/.." : $options['extension-dir'];

    if (empty($this->rglob("$extension_dir/*.feature"))) {
      $this->say('No behat features exist to test');
      return;
    }

    $tasks[] = $this->taskDrupalStack($html_path)
      ->testExtension($extension_dir);

    $extension_type = $this->getExtensionType($extension_dir);
    $extension_name = $this->getExtensionName($extension_dir);

    $profile = $extension_type == 'profile' ? $extension_name : $options['profile'];

    $tasks[] = $this->taskWriteToFile("$html_path/web/sites/default/settings.php")
      ->textFromFile("{$this->toolDir()}/config/circleci.settings.php");

    $tasks[] = $this->taskDrushStack("vendor/bin/drush")
      ->dir($html_path)
      ->siteInstall($profile);

    if ($profile != $extension_name) {
      $tasks[] = $this->taskDrushStack("vendor/bin/drush")
        ->dir($html_path)
        ->drush("pm:enable $extension_name");
    }

    $tasks[] = $this->taskDrushStack("vendor/bin/drush")
      ->dir($html_path)
      ->drush('pm:uninstall simplesamlphp_auth');

    $tasks[] = $this->taskDrushStack("vendor/bin/drush")
      ->dir($html_path)
      ->drush('cache-rebuild');

    $tasks[] = $this->taskBehat('vendor/bin/behat')
      ->dir($html_path)
      ->config("{$this->toolDir()}/config/behat.yml")
      ->arg("$html_path/web/{$extension_type}s/custom/$extension_name")
      ->option('profile', 'local')
      ->option('strict')
      ->noInteraction();

    return $this->collectionBuilder()->addTaskList($tasks)->run();
  }

}
