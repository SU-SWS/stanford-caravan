<?php

namespace StanfordCaravan\Robo\Tasks;

use League\Container\ContainerAwareTrait;
use Robo\Contract\BuilderAwareInterface;
use Robo\LoadAllTasks;
use Robo\Task\BaseTask;
use StanfordCaravan\CaravanTrait;

/**
 * Class SuCodeCeption.
 *
 * @package StanfordCaravan\Robo\Tasks
 */
class SuCodeCeption extends BaseTask implements BuilderAwareInterface {

  use ContainerAwareTrait;
  use LoadAllTasks;
  use CaravanTrait;

  /**
   * Root path of the composer installation.
   *
   * @var string
   */
  protected $path;

  /**
   * Codeception suite to execute.
   *
   * @var string
   */
  protected $suite = 'acceptance';

  /**
   * Domain to run tests on.
   *
   * @var string
   */
  protected $domain = 'localhost';

  /**
   * Directory where tests are located.
   *
   * @var string
   */
  protected $testDir;

  /**
   * SuCodeCeption constructor.
   *
   * @param string $root_path
   *   Path of Drupal Directory.
   */
  public function __construct($root_path) {
    $this->path = $root_path;
  }

  /**
   * Set the suite to test.
   *
   * @param string $suite
   *   Suite name.
   */
  public function suite($suite) {
    $this->suite = $suite;
  }

  /**
   * Directory with codeception tests.
   *
   * @param string $dir
   *   Directory path.
   */
  public function testDir($dir) {
    $this->testDir = $dir;
  }

  /**
   * Run the tests on the given domain.
   *
   * @param string $domain
   *   Domain to use for tests.
   */
  public function domain($domain) {
    $this->domain = $domain;
  }

  /**
   * Get the modified configuration for the current suite.
   *
   * @return string
   *   Yaml formatted configuration.
   */
  protected function getSuiteConfig() {
    $suite_config = file_get_contents("{$this->tooldir()}/config/codeception/{$this->suite}.suite.yml");
    $suite_config = str_replace('localhost', $this->domain, $suite_config);
    $suite_config = str_replace('/var/www/html', $this->path, $suite_config);
    return $suite_config;
  }

  /**
   * Get the codeception configuration from the tool directory.
   *
   * @return string
   *   Codeception.yml config.
   */
  protected function getCodeceptionConfig() {
    return file_get_contents("{$this->tooldir()}/config/codeception/codeception.yml");
  }

  /**
   * Run the codeception tests.
   *
   * @return \Robo\Result
   *   Result of the test.
   */
  public function run() {
    if (!file_exists("{$this->path}/codeception.yml")) {
      $this->taskComposerRequire()
        ->dir($this->path)
        ->arg('codeception/codeception')
        ->arg('codeception/module-asserts')
        ->arg('codeception/module-phpbrowser')
        ->arg('codeception/module-webdriver')
        ->run();

      $this->taskExec('vendor/bin/codecept')
        ->dir($this->path)
        ->arg('bootstrap')
        ->run();
    }
    file_put_contents("{$this->path}/tests/{$this->suite}.suite.yml", $this->getSuiteConfig());
    file_put_contents("{$this->path}/codeception.yml", $this->getCodeceptionConfig());

    $tasks[] = $this->taskRsync()
      ->fromPath("{$this->testDir}/")
      ->toPath("{$this->path}/tests/")
      ->recursive();

    $tasks[] = $this->taskExec('vendor/bin/codecept')
      ->dir($this->path)
      ->arg('run')
      ->arg($this->suite)
      ->option('steps')
      ->option('xml', "{$this->path}/artifacts/codeception.xml", '=');

    return $this->collectionBuilder()->addTaskList($tasks)->run();
  }

}
