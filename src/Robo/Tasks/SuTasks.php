<?php

namespace StanfordCaravan\Robo\Tasks;

trait SuTasks {

  /**
   * Example task to compile assets
   *
   * @param string $pathToCompileAssets
   *
   * @return \StanfordCaravan\Robo\Tasks\SuDrupalStack
   */
  protected function taskDrupalSetup($path = NULL) {
    // Always construct your tasks with the `task()` task builder.
    return $this->task(SuDrupalStack::class, $path);
  }

    /**
   * Example task to compile assets
   *
   * @param string $pathToCompileAssets
   *
   * @return \StanfordCaravan\Robo\Tasks\SuPhpUnit
   */
  protected function taskSuPhpUnit($path = NULL) {
    // Always construct your tasks with the `task()` task builder.
    return $this->task(SuPhpUnit::class, $path);
  }

}

