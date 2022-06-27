<?php

namespace StanfordCaravan\Robo\Tasks;

use Codeception\Task\Merger\ReportMerger;
use Codeception\Task\Splitter\TestsSplitterTrait;
use Robo\Contract\BuilderAwareInterface;
use Robo\LoadAllTasks;
use Robo\ResultData;
use Robo\Task\BaseTask;
use StanfordCaravan\CaravanTrait;

/**
 * Class SuCodeCeption.
 *
 * @package StanfordCaravan\Robo\Tasks
 */
class SuCodeCeption extends BaseTask implements BuilderAwareInterface {

  use TestsSplitterTrait;
  use ReportMerger;
  use LoadAllTasks;
  use CaravanTrait;

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
        ->arg('codeception/codeception:^4.0')
        ->arg('codeception/module-asserts:^2')
        ->arg('codeception/module-phpbrowser:^1.0 || ^2.0')
        ->arg('codeception/module-webdriver:^2')
        ->arg('codeception/robo-paracept:^2')
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

    $failed_test = NULL;
    foreach ($this->suites as $suite) {
      file_put_contents("{$this->path}/tests/{$suite}.suite.yml", $this->getSuiteConfig($suite));

      if (!$this->parallel) {
        $result = $this->runSequentialTests($suite);
        if ($result->wasSuccessful()) {
          continue;
        }
        $failed_test = $result;
      }
      else {
        $this->splitTests();
        return $this->runParallelTests();
      }
    }
    return $failed_test;
  }

  public function runSequentialTests($suite) {
    $test = $this->taskCodecept($this->codeceptPath)
      ->dir($this->path)
      ->suite($suite)
      ->html('html')
      ->xml('xml')
      ->option('steps')
      ->option('override', "paths: output: {$this->path}/artifacts/$suite", '=')
      ->run();
    if (!$test->wasSuccessful()) {
      return $this->taskCodecept($this->codeceptPath)
        ->dir($this->path)
        ->suite($suite)
        ->group('failed')
        ->html('html')
        ->xml('xml')
        ->option('steps')
        ->option('override', "paths: output: {$this->path}/artifacts/$suite", '=')
        ->run();
    }
    return $test;
  }

  /**
   *
   */
  public function splitTests() {
    $this->taskExec('composer global require codeception/codeception:^4.0')
      ->run();
    $this->taskSplitTestFilesByGroups(self::NUMBER_OF_GROUPS)
      ->projectRoot($this->path)
      ->testsFrom('tests')
      ->groupsTo('tests/_data/paracept_')
      ->run();
  }

  /**
   * @command codeception:parallel
   */
  public function runParallelTests() {
    // For now, only acceptance suite has been tested.
    $suite = 'acceptance';

    $parallel = $this->taskParallelExec();
    for ($i = 1; $i <= self::NUMBER_OF_GROUPS; $i++) {
      $parallel->process(
        $this->taskCodecept($this->codeceptPath)
          ->dir($this->path)
          ->suite($suite)
          ->group("paracept_$i")
          ->excludeGroup('no-parallel')
          ->html("_log/html/result_$i.html")
          ->xml("_log/xml/result_$i.xml")
          ->failGroup("failed_$i")
          ->option('steps')
          ->option('override', "paths: output: {$this->path}/artifacts/$suite", '=')
      );
    }
    $parallel_result = $parallel->run();
    $no_parallel_results = $this->taskCodecept($this->codeceptPath)
      ->dir($this->path)
      ->suite($suite)
      ->group('no-parallel')
      ->html('_log/html/no-parallel.html')
      ->xml('_log/xml/no-parallel.xml')
      ->failGroup('no-parallel')
      ->option('steps')
      ->option('override', "paths: output: {$this->path}/artifacts/$suite", '=')
      ->run();

    $this->mergeParallelResults($suite);
    if (!$parallel_result->wasSuccessful() || !$no_parallel_result->wasSuccessful()) {
      $this->say('Retrying failed tests');
      $no_parallel_result = $this->taskCodecept($this->codeceptPath)
        ->configFile('tests')
        ->group('failed')
        ->suite($suite)
        ->option('steps')
        ->option('override', 'paths: output: ../artifacts/$suite', '=')
        ->html('_log/html/retry.html')
        ->xml('_log/xml/retry.xml')
        ->run();
      return $no_parallel_result;
    }
    return $no_parallel_result->wasSuccessful() ? $parallel_result : $no_parallel_result;
  }

  /**
   * @command codeception:parallel-merge-results
   */
  public function mergeParallelResults($suite) {
    $xml_merge = $this->taskMergeXmlReports();
    $html_merge = $this->taskMergeHTMLReports();
    $failed_merge = $this->taskMergeFailedTestsReports();

    for ($i = 1; $i <= self::NUMBER_OF_GROUPS; $i++) {
      $xml_merge->from("{$this->path}/artifacts/$suite/_log/xml/result_$i.xml");
      $html_merge->from("{$this->path}/artifacts/$suite/_log/html/result_$i.html");
      $failed_merge->from("{$this->path}/artifacts/$suite/failed_$i");
    }

    $xml_merge->from("{$this->path}/artifacts/$suite/_log/xml/no-parallel.xml");
    $html_merge->from("{$this->path}/artifacts/$suite/_log/html/no-parallel.html");
    $failed_merge->from("{$this->path}/artifacts/$suite/failed");

    $xml_merge->into("{$this->path}/artifacts/$suite/result.xml")->run();
    $html_merge->into("{$this->path}/artifacts/$suite/result.html")->run();
    $failed_merge->into("{$this->path}/artifacts/$suite/failed")->run();
  }

}
