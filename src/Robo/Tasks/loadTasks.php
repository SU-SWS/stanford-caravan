<?php

namespace StanfordCaravan\Robo\Tasks;

use Boedah\Robo\Task\Drush\DrushStack;

trait loadTasks {

  /**
   * Example task to compile assets
   *
   * @param string $dir
   *
   * @return \StanfordCaravan\Robo\Tasks\SuDrupalStack
   */
  protected function taskDrupalStack($dir) {
    return $this->task(SuDrupalStack::class, $dir);
  }

  /**
   * Example task to compile assets
   *
   * @return \StanfordCaravan\Robo\Tasks\SuPhpUnitStack
   */
  protected function taskSuPhpUnitStack($path = NULL) {
    return $this->task(SuPhpUnitStack::class, $path);
  }

  /**
   * Drush task runner.
   *
   * @param string $pathToDrush
   *
   * @return \Boedah\Robo\Task\Drush\DrushStack
   */
  protected function taskDrushStack($pathToDrush = 'drush') {
    return $this->task(DrushStack::class, $pathToDrush);
  }

  /**
   * CodeCeption task runner.
   *
   * @return \StanfordCaravan\Robo\Tasks\SuCodeCeption
   */
  protected function taskCodeCeptionStack($path = NULL) {
    return $this->task(SuCodeCeption::class, $path);
  }

}

