<?php

namespace StanfordCaravan\Robo\Tasks;

use Boedah\Robo\Task\Drush\DrushStack;

/**
 * Robo tasks loader.
 *
 * @package StanfordCaravan\Robo\Tasks
 */
trait loadTasks {

  /**
   * Drupal directory builder stack.
   *
   * @param string $dir
   *   Path where to install drupal.
   *
   * @return \StanfordCaravan\Robo\Tasks\SuDrupalStack
   *   Task executable.
   */
  protected function taskDrupalStack($dir) {
    return $this->task(SuDrupalStack::class, $dir);
  }

  /**
   * Carvan phpunit task stack.
   *
   * @param string $path
   *   Path to phpunit binary.
   *
   * @return \StanfordCaravan\Robo\Tasks\SuPhpUnitStack
   *   PHPUnit task execution stack.
   */
  protected function taskSuPhpUnitStack($path = NULL) {
    return $this->task(SuPhpUnitStack::class, $path);
  }

  /**
   * Drush task runner.
   *
   * @param string $pathToDrush
   *   Path to drush binary.
   *
   * @return \Boedah\Robo\Task\Drush\DrushStack
   *   Task executable.
   */
  protected function taskDrushStack($pathToDrush = 'drush') {
    return $this->task(DrushStack::class, $pathToDrush);
  }

  /**
   * CodeCeption task runner.
   *
   * @param string $path
   *   Path to execute tests.
   *
   * @return \StanfordCaravan\Robo\Tasks\SuCodeCeption
   *   Task executable.
   */
  protected function taskCodeCeptionStack($path = NULL) {
    return $this->task(SuCodeCeption::class, $path);
  }

}
