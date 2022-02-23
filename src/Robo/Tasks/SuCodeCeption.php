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
  protected $suites = ['acceptance', 'functional'];

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
  public function suites($suites) {
    $this->suites = explode(',', $suites);
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
   * @param string $suite
   *   Suite name.
   *
   * @return string
   *   Yaml formatted configuration.
   */
  protected function getSuiteConfig($suite) {
    $suite_config = file_get_contents("{$this->tooldir()}/config/codeception/$suite.suite.yml");
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
    $config = file_get_contents("{$this->tooldir()}/config/codeception/codeception.yml");
    $config = str_replace('localhost', $this->domain, $config);
    $config = str_replace('/var/www/html', $this->path, $config);
    return $config;
  }

  /**
   * Run the codeception tests.
   *
   * @return \Robo\Result
   *   Result of the test.
   */
  public function run() {
    if (!file_exists($this->testDir)) {
      return;
    }
    if (!file_exists("{$this->path}/codeception.yml")) {
      $this->taskComposerRequire()
        ->dir($this->path)
        ->arg('codeception/codeception:^4.0')
        ->arg('codeception/module-asserts')
        ->arg('codeception/module-phpbrowser:^1.0 || ^2.0')
        ->arg('codeception/module-webdriver')
        ->run();

      $this->taskExec('vendor/bin/codecept')
        ->dir($this->path)
        ->arg('bootstrap')
        ->run();
    }
    file_put_contents("{$this->path}/codeception.yml", $this->getCodeceptionConfig());

    $tasks[] = $this->taskRsync()
      ->fromPath("{$this->testDir}/")
      ->toPath("{$this->path}/tests/")
      ->recursive();

    foreach ($this->suites as $suite) {
      file_put_contents("{$this->path}/tests/{$suite}.suite.yml", $this->getSuiteConfig($suite));

      $tasks[] = $this->taskExec('vendor/bin/codecept')
        ->dir($this->path)
        ->arg('run')
        ->arg($suite)
        ->option('steps')
        ->option('html')
        ->option('xml')
        ->option('override', "paths: output: {$this->path}/artifacts/$suite", '=');
    }
    return $this->collectionBuilder()->addTaskList($tasks)->run();
  }

}
