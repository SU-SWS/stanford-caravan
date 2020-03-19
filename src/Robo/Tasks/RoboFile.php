<?php

namespace StanfordCaravan\Robo\Tasks;

use Robo\Tasks;
use Boedah\Robo\Task\Drush\loadTasks as drushTasks;
use StanfordCaravan\CaravanTrait;
use Symfony\Component\Console\Input\InputOption;

/**
 * CI/CD tools for running tests against themes, modules, and profiles.
 *
 * @package StanfordCaravan\Robo\Tasks
 */
class RoboFile extends Tasks {

  use drushTasks;
  use SuTasks;
  use CaravanTrait;

  /**
   * Path to this tool's library root.
   *
   * @var string
   */
  protected $toolDir;

  /**
   * RoboFile constructor.
   */
  public function __construct() {
    $this->toolDir = dirname(__FILE__, 4);
  }

  /**
   * @command test-stuff
   */
  public function testStuff() {
    $this->taskDrupalSetup('/var/www/deleteme')
      ->testExtension('/var/www/cardinalsites/docroot/profiles/custom/stanford_profile')
      ->run();
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
      throw new \Exception('--extension-dir is required');
    }

    $extension_dir = is_null($options['extension-dir']) ? "$html_path/.." : $options['extension-dir'];
    $this->lintPhp($extension_dir);

    if (empty($this->rglob("$extension_dir/*Test.php"))) {
      $this->say('Nothing to test');
      return;
    }

    $tasks[] = $this->taskDrupalSetup($html_path)
      ->testExtension($extension_dir);

    $extension_type = $this->getExtensionType($extension_dir);
    $extension_name = $this->getExtensionName($extension_dir);

    $tasks[] = $this->taskFilesystemStack()
      ->copy("{$this->toolDir}/config/phpunit.xml", "$html_path/web/core/phpunit.xml", TRUE);

    $test = $this->taskPhpUnit("../vendor/bin/phpunit")
      ->dir("$html_path/web")
      ->arg("$html_path/web/{$extension_type}s/custom/$extension_name")
      ->option('config', 'core', '=')
      ->option('log-junit', "$html_path/artifacts/phpunit/results.xml");

    if ($options['with-coverage']) {
      $test->option('filter', '/(Unit|Kernel)/', '=')
        ->option('coverage-html', "$html_path/artifacts/phpunit/coverage/html", '=')
        ->option('coverage-xml', "$html_path/artifacts/phpunit/coverage/xml", '=')
        ->option('coverage-clover', "$html_path/artifacts/phpunit/coverage/clover.xml");

      $this->fixupPhpunitConfig("$html_path/web/core/phpunit.xml", $extension_type, $extension_name);
    }

    $tasks[] = $test;
    $tasks[] = $this->taskExec("$html_path/vendor/bin/drupal-check")
      ->dir("$html_path/web")
      ->arg("$html_path/web/{$extension_type}s/custom/$extension_name");

    $test_result = $this->collectionBuilder()->addTaskList($tasks)->run();

    $errors = [];
    if ($options['with-coverage']) {
      $errors[] = $this->checkCoverageReport("$html_path/artifacts/phpunit/coverage/xml/index.xml", $options['coverage-required']);
      $this->uploadCoverageCodeClimate("$html_path/artifacts/phpunit/coverage/clover.xml", "$html_path/web/{$extension_type}s/custom/$extension_name");
    }

    $errors[] = $this->checkFileNameLengths($extension_dir);
    if (array_filter($errors)) {
      throw new \Exception(implode(PHP_EOL, array_filter($errors)));
    }

    return $test_result;
  }

  /**
   * @param $clover_coverage
   */
  protected function uploadCoverageCodeClimate($clover_coverage, $extension_dir) {
    if (!file_exists($clover_coverage)) {
      $this->say('No coverage to upload to code climate.');
      return;
    }

    if (!isset($_ENV['CC_TEST_REPORTER_ID'])) {
      $this->say('To enable codeclimate coverage uploads, please set the "CC_TEST_REPORTER_ID" environment variable to enable this feature.');
      $this->say('This can be found on the codeclimate repository settings page.');
      return;
    }

    $get_report_tool = $this->taskExec("curl -L https://codeclimate.com/downloads/test-reporter/test-reporter-latest-linux-amd64 > ./cc-test-reporter")
      ->dir($extension_dir);
    $executable_tool = $this->taskExec(' chmod +x ./cc-test-reporter')
      ->dir($extension_dir);

    $copy_clover = $this->taskFilesystemStack()
      ->copy($clover_coverage, "$extension_dir/clover.xml");

    $upload_coverage = $this->taskExec("./cc-test-reporter after-build -t clover")
      ->dir($extension_dir);

    $tasks = $this->collectionBuilder();
    $tasks->addTaskList([
      $get_report_tool,
      $executable_tool,
      $copy_clover,
      $upload_coverage,
    ]);
    $tasks->run();
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
    return implode(PHP_EOL, $errors);
  }

  /**
   * Check if the code coverage is sufficient.
   *
   * @param string $report
   *   Path to coverage report.
   */
  public function checkCoverageReport($report, $required_coverage) {
    if (!file_exists($report)) {
      return;
    }
    $dom = new \DOMDocument();
    libxml_use_internal_errors(TRUE);
    $dom->loadHTML(file_get_contents($report));
    $xpath = new \DOMXPath($dom);
    $total_coverage = $xpath->query("//directory[@name='/']/totals/lines/@percent")
      ->item(0)->nodeValue;
    if ((float) $total_coverage < (float) $required_coverage) {
      return "Code coverage is not sufficient at $total_coverage%. $required_coverage% is required.";
    }
    $this->yell("Code coverage at $total_coverage%.");
  }

  /**
   * Modify the PHPUnit to whitelist only the extension being tested.
   *
   * @param string $config_path
   *   Path to the PHPUnit config.
   * @param string $extension_type
   *   Drupal extension type.
   * @param string $extension_name
   *   Drupal extension name being tested.
   */
  protected function fixupPhpunitConfig($config_path, $extension_type, $extension_name) {
    $dom = new \DOMDocument();
    $dom->loadXML(file_get_contents($config_path));
    $directories = $dom->getElementsByTagName('directory');
    for ($i = 0; $i < $directories->length; $i++) {
      $directory = $directories->item($i)->nodeValue;
      $directory = str_replace('modules/custom/*', "{$extension_type}s/custom/$extension_name", $directory);
      $directories->item($i)->nodeValue = $directory;
    }
    file_put_contents($config_path, $dom->saveXML());
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

    $tasks[] = $this->taskDrupalSetup($html_path)
      ->testExtension($extension_dir);

    $extension_type = $this->getExtensionType($extension_dir);
    $extension_name = $this->getExtensionName($extension_dir);

    $profile = $extension_type == 'profile' ? $extension_name : $options['profile'];

    $tasks[] = $this->taskWriteToFile("$html_path/web/sites/default/settings.php")
      ->textFromFile("{$this->toolDir}/config/circleci.settings.php");

    $tasks[] = $this->taskDrushStack("vendor/bin/drush")
      ->dir($html_path)
      ->siteInstall($profile);

    $tasks[] = $this->taskDrushStack("vendor/bin/drush")
      ->dir($html_path)
      ->drush("pm:enable $extension_name");

    $tasks[] = $this->taskDrushStack("vendor/bin/drush")
      ->dir($html_path)
      ->drush('pmu simplesamlphp_auth');

    $tasks[] = $this->taskDrushStack("vendor/bin/drush")
      ->dir($html_path)
      ->drush('cr');

    $tasks[] = $this->taskBehat('vendor/bin/behat')
      ->dir($html_path)
      ->config("{$this->toolDir}/config/behat.yml")
      ->arg("$html_path/web/{$extension_type}s/custom/$extension_name")
      ->option('profile', 'local')
      ->option('strict')
      ->noInteraction();

    return $this->collectionBuilder()->addTaskList($tasks)->run();
  }

}
