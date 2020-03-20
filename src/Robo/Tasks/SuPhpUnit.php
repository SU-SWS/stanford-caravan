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
class SuPhpUnit extends BaseTask implements BuilderAwareInterface {

  use ContainerAwareTrait;
  use LoadAllTasks;
  use CaravanTrait;

  protected $withCoverage = FALSE;

  protected $dir;

  protected $testDir;

  protected $reportDir;

  protected $extensionName;

  protected $extensionType;

  protected $requiredCoverage = 90;

  protected $uploadToCodeClimate = FALSE;

  public function __construct() {
  }

  public function dir($dir) {
    $this->dir = $dir;
    return $this;
  }

  public function testDir($dir) {
    $this->testDir = $dir;
    return $this;
  }

  public function reportDir($dir) {
    $this->reportDir = $dir;
    return $this;
  }

  public function withCoverage($with_coverage = TRUE) {
    $this->withCoverage = $with_coverage;
    return $this;
  }

  public function extensionType($type) {
    $this->extensionType = $type;
    return $this;
  }

  public function extensionName($name) {
    $this->extensionName = $name;
    return $this;
  }

  public function coverageRequired($coverage = 90) {
    $this->requiredCoverage = $coverage;
    return $this;
  }

  public function uploadToCodeClimate($upload_results = TRUE) {
    $this->uploadToCodeClimate = $upload_results;
    return $this;
  }

  public function run() {
    $this->taskFilesystemStack()
      ->copy("{$this->toolDir()}/config/phpunit.xml", "{$this->dir}/core/phpunit.xml", TRUE)
      ->completionCode([$this, 'fixupPhpunitConfig'])
      ->run();

    $test = $this->taskPhpUnit("../vendor/bin/phpunit")
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

    $tasks[] = $this->taskExec("curl -L https://codeclimate.com/downloads/test-reporter/test-reporter-latest-linux-amd64 > ./cc-test-reporter")
      ->dir($this->testDir);
    $tasks[] = $this->taskExec(' chmod +x ./cc-test-reporter')
      ->dir($this->testDir);

    $tasks[] = $this->taskFilesystemStack()
      ->copy($covarge_file, "$this->testDir/clover.xml");

    $tasks[] = $this->taskExec("./cc-test-reporter after-build -t clover")
      ->dir($this->testDir);

    $tasks = $this->collectionBuilder();
    $tasks->addTaskList($tasks);
    $tasks->run();
  }

}
