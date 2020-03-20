<?php

namespace StanfordCaravan\Robo\Tasks;

use League\Container\ContainerAwareTrait;
use Robo\Contract\BuilderAwareInterface;
use Robo\Exception\AbortTasksException;
use Robo\Exception\TaskException;
use Robo\Exception\TaskExitException;
use Robo\LoadAllTasks;
use Robo\Task\BaseTask;
use StanfordCaravan\CaravanTrait;

/**
 * Class SuDrupalStack
 *
 * @package StanfordCaravan\Robo\Tasks
 */
class SuPhpUnitStack extends BaseTask implements BuilderAwareInterface {

  use ContainerAwareTrait;
  use LoadAllTasks;
  use CaravanTrait;

  /**
   * Run phpunit with coverage.
   *
   * @var bool
   */
  protected $withCoverage = FALSE;

  /**
   * Drupal root directory that should contain an index.php file.
   *
   * @var string
   */
  protected $dir;

  /**
   * The directory where the module or profile exists.
   *
   * @var string
   */
  protected $testDir;

  /**
   * Directory to output phpunit report files.
   *
   * @var string
   */
  protected $reportDir;

  /**
   * Name of the extension being tested.
   *
   * @var string
   */
  protected $extensionName;

  /**
   * Type of extension to test: module, profile, or theme.
   *
   * @var string
   */
  protected $extensionType;

  /**
   * Path to phpunit executable.
   *
   * @var string
   */
  protected $phpunitPath;

  /**
   * SuPhpUnitStack constructor.
   *
   * @param string $phpunit_path
   *   Path to phpunit executable.
   */
  public function __construct($phpunit_path) {
    $this->phpunitPath = $phpunit_path ?: '../vendor/bin/phpunit';
  }

  /**
   * Set the drupal root directory.
   *
   * @param string $dir
   *   Drupal root directory.
   *
   * @return $this
   */
  public function dir($dir) {
    $this->dir = $dir;
    return $this;
  }

  /**
   * Set the directory for the testable extension.
   *
   * @param string $dir
   *   Extension directory for tests.
   *
   * @return $this
   */
  public function testDir($dir) {
    $this->testDir = $dir;
    return $this;
  }

  /**
   * Set the directory where the phpunit reports are generated.
   *
   * @param string $dir
   *   Reports directory.
   *
   * @return $this
   */
  public function reportDir($dir) {
    $this->reportDir = $dir;
    return $this;
  }

  /**
   * Set the test to run coverage reports.
   *
   * @param bool $with_coverage
   *   If the test should run with coverage.
   *
   * @return $this
   */
  public function withCoverage($with_coverage = TRUE) {
    $this->withCoverage = $with_coverage;
    return $this;
  }

  /**
   * Set the type of the extension being tested.
   *
   * @param string $type
   *   Testable extension type: profile, module, or theme.
   *
   * @return $this
   */
  public function extensionType($type) {
    $this->extensionType = $type;
    return $this;
  }

  /**
   * Set the name of the extension being tested.
   *
   * @param string $name
   *   Testable extension name.
   *
   * @return $this
   */
  public function extensionName($name) {
    $this->extensionName = $name;
    return $this;
  }

  /**
   * Execute the phpunit tests.
   *
   * @return \Robo\Result
   *   Results of the tasks.
   */
  public function run() {
    $this->taskFilesystemStack()
      ->copy("{$this->toolDir()}/config/phpunit.xml", "{$this->dir}/core/phpunit.xml", TRUE)
      ->completionCode([$this, 'fixupPhpunitConfig'])
      ->run();

    $test = $this->taskPhpUnit($this->phpunitPath)
      ->dir($this->dir)
      ->arg($this->testDir)
      ->option('config', 'core', '=')
      ->option('log-junit', "{$this->reportDir}/phpunit/results.xml");

    if ($this->withCoverage) {
      $test->option('filter', '/(Unit|Kernel)/', '=')
        ->option('coverage-html', "{$this->reportDir}/phpunit/coverage/html", '=')
        ->option('coverage-xml', "{$this->reportDir}/phpunit/coverage/xml", '=')
        ->option('coverage-clover', "{$this->reportDir}/phpunit/coverage/clover.xml");
    }

    return $this->collectionBuilder()
      ->addTask($test)
      ->completionCode([$this, 'uploadCoverageCodeClimate'])
      ->run();
  }

  /**
   * Modify the PHPUnit to whitelist only the extension being tested.
   */
  public function fixupPhpunitConfig() {
    $dom = new \DOMDocument();
    $dom->loadXML(file_get_contents("{$this->dir}/core/phpunit.xml"));
    $directories = $dom->getElementsByTagName('directory');
    for ($i = 0; $i < $directories->length; $i++) {
      $directory = $directories->item($i)->nodeValue;
      $directory = str_replace('modules/custom/*', "{$this->extensionType}s/custom/{$this->extensionName}", $directory);
      $directories->item($i)->nodeValue = $directory;
    }

    file_put_contents("{$this->dir}/core/phpunit.xml", $dom->saveXML());
  }

  /**
   * Use CodeClimate CLI to upload the phpunit coverage report.
   *
   * @link https://docs.codeclimate.com/docs/circle-ci-test-coverage-example
   */
  public function uploadCoverageCodeClimate() {
    $covarge_file = "{$this->reportDir}/phpunit/coverage/clover.xml";

    if (!file_exists($covarge_file)) {
      $this->printTaskInfo('No coverage to upload to code climate.');
      return;
    }

    if (!isset($_ENV['CC_TEST_REPORTER_ID'])) {
      $this->printTaskInfo('To enable codeclimate coverage uploads, please set the "CC_TEST_REPORTER_ID" environment variable to enable this feature.');
      $this->printTaskInfo('This can be found on the codeclimate repository settings page.');
      return;
    }

    // Download the executable.
    $tasks[] = $this->taskExec("curl -L https://codeclimate.com/downloads/test-reporter/test-reporter-latest-linux-amd64 > ./cc-test-reporter")
      ->dir($this->testDir);
    $tasks[] = $this->taskExec(' chmod +x ./cc-test-reporter')
      ->dir($this->testDir);

    // Move the phpunit report into the tested directory.
    $tasks[] = $this->taskFilesystemStack()
      ->copy($covarge_file, "$this->testDir/clover.xml");

    // Use the CLI to upload the report.
    $tasks[] = $this->taskExec("./cc-test-reporter after-build -t clover")
      ->dir($this->testDir);

    return $this->collectionBuilder()
      ->addTaskList($tasks)
      ->run();
  }

}
