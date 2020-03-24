<?php

namespace StanfordCaravan\Robo\Tasks;

use League\Container\ContainerAwareTrait;
use Robo\Contract\BuilderAwareInterface;
use Robo\LoadAllTasks;
use Robo\Result;
use Robo\Task\BaseTask;
use StanfordCaravan\CaravanTrait;

class SuCodeCeption extends BaseTask implements BuilderAwareInterface {

  use ContainerAwareTrait;
  use LoadAllTasks;
  use CaravanTrait;

  /**
   * Codeception suite to execute.
   *
   * @var string
   */
  protected $suite = 'acceptance';

  /**
   * Directory where tests are located.
   *
   * @var string
   */
  protected $testDir;

  public function __construct($root_path) {
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
   * @return \Robo\Result|void
   */
  public function run() {
    // todo: work this out.
    return new Result($this, 1);
  }

}
