<?php

namespace StanfordCaravan\Robo\Tasks;

use League\Container\ContainerAwareTrait;
use Robo\Contract\BuilderAwareInterface;
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

  protected $toolDir;

  protected $withCoverage;

  protected $dir;

  protected $testDir;

  protected $reportDir;

  protected $extensionName;

  protected $extensionType;

  public function __construct() {
    $this->toolDir = dirname(__FILE__, 4);
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

  /**
   *
   */
  public function run() {
    $tasks[] = $this->taskFilesystemStack()
      ->copy("{$this->toolDir}/config/phpunit.xml", "{$this->dir}/core/phpunit.xml", TRUE);

    $test = $this->taskPhpUnit("../vendor/bin/phpunit")
      ->dir($this->dir)
      ->arg($this->testDir)
      ->option('config', 'core', '=')
      ->option('log-junit', "{$this->reportDir}/artifacts/phpunit/results.xml");

    if ($this->withCoverage) {
      $test->option('filter', '/(Unit|Kernel)/', '=')
        ->option('coverage-html', "{$this->reportDir}/artifacts/phpunit/coverage/html", '=')
        ->option('coverage-xml', "{$this->reportDir}/artifacts/phpunit/coverage/xml", '=')
        ->option('coverage-clover', "{$this->reportDir}/artifacts/phpunit/coverage/clover.xml");

      $this->fixupPhpunitConfig();
    }

    $tasks[] = $test;

    return $this->collectionBuilder()->addTaskList($tasks)->run();
  }

  /**
   * Modify the PHPUnit to whitelist only the extension being tested.
   */
  protected function fixupPhpunitConfig() {
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

}
