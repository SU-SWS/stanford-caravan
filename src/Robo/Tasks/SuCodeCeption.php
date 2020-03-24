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
   * @return \Robo\Result|void
   */
  public function run() {
    // todo: work this out.
    return new Result($this, 1);
  }

}
