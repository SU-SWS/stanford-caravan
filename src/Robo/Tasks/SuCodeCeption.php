<?php

namespace StanfordCaravan\Robo\Tasks;

use League\HTMLToMarkdown\HtmlConverter;
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

  use CaravanTrait;
  use LoadAllTasks;

  const NUMBER_OF_GROUPS = 6;

  /**
   * Root path of the composer installation.
   *
   * @var string
   */
  protected $path;

  /**
   * Path to codeception executable.
   *
   * @var string
   */
  protected $codeceptPath;

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
   * If the tests should be executed in parallel.
   *
   * @var bool
   */
  protected $parallel = FALSE;

  /**
   * SuCodeCeption constructor.
   *
   * @param string $root_path
   *   Path of Drupal Directory.
   */
  public function __construct($root_path) {
    $this->path = $root_path;
    $this->codeceptPath = "{$this->path}/vendor/bin/codecept";
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

  public function parallel($parallel = FALSE) {
    $this->parallel = $parallel;
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
        ->arg('codeception/codeception:^5')
        ->arg('codeception/module-asserts:^3')
        ->arg('codeception/module-phpbrowser:^3')
        ->arg('codeception/module-webdriver:^3')
        ->arg('codeception/robo-paracept')
        ->run();
      $this->taskExec('vendor/bin/codecept')
        ->dir($this->path)
        ->arg('bootstrap')
        ->run();
    }

    file_put_contents("{$this->path}/codeception.yml", $this->getCodeceptionConfig());
    $this->taskRsync()
      ->fromPath("{$this->testDir}/")
      ->toPath("{$this->path}/tests/")
      ->recursive()
      ->run();
    $this->taskExec('vendor/bin/codecept')
      ->dir($this->path)
      ->arg('build')
      ->run();

    $dirs_to_make = [
      "{$this->path}/_data",
      "{$this->path}/_output",
      "{$this->path}/_support",
    ];
    foreach ($dirs_to_make as $dir) {
      if (!file_exists($dir)) {
        $this->taskFilesystemStack()->mkdir($dir)->run();
      }
    }

    $return_result = NULL;
    foreach ($this->suites as $suite) {
      file_put_contents("{$this->path}/tests/{$suite}.suite.yml", $this->getSuiteConfig($suite));

      $result = $this->runSequentialTests($suite);
      if ($result->wasSuccessful()) {
        continue;
      }
      $return_result = $result;
    }
    return $return_result;
  }

  /**
   * Run tests like normal.
   *
   * @param string $suite
   *   Test suite.
   *
   * @return \Robo\Result
   *   Test result.
   */
  public function runSequentialTests($suite) {
    $test = $this->taskCodecept($this->codeceptPath)
      ->dir($this->path)
      ->suite($suite)
      ->html('results.html')
      ->xml('results.xml')
      ->option('steps')
      ->option('override', "paths: output: {$this->path}/artifacts/$suite", '=');

    $environment = [];
    if (getenv('CI')) {
      $environment[] = 'ci';
    }
    if (getenv('GITHUB_ACTIONS')) {
      $environment[] = 'github_actions';
    }
    if ($environment) {
      $test->option('env', implode(',', $environment), '=');
    }
    $test = $test->run();
    $result_markup = file_get_contents("{$this->path}/artifacts/$suite/results.html");
    if (!$test->wasSuccessful()) {
      $test = $this->taskCodecept($this->codeceptPath)
        ->dir($this->path)
        ->suite($suite)
        ->group('failed')
        ->html('retry.html')
        ->xml('retry.xml')
        ->option('steps')
        ->option('override', "paths: output: {$this->path}/artifacts/$suite", '=');

      if ($environment) {
        $test->option('env', implode(',', $environment), '=');
      }
      $test = $test->run();
      $result_markup = file_get_contents("{$this->path}/artifacts/$suite/retry.html");
    }
    if ($gh_summary = getenv('GITHUB_STEP_SUMMARY')) {
      libxml_use_internal_errors(TRUE);
      $markdown_converter = new HtmlConverter();
      $dom = new \DOMDocument();
      $dom->loadHTML($result_markup);
      $xpath = new \DOMXPath($dom);

      /** @var \DOMElement[] $body */
      $body = $xpath->query('//body');
      file_put_contents($gh_summary, $markdown_converter->convert($dom->saveHTML($body[0])));
    }
    return $test;
  }

}
