<?php

namespace StanfordCaravan\Robo\Tasks;

trait SuTasks {

  /**
   * Example task to compile assets
   *
   * @param string $pathToCompileAssets
   *
   * @return \MyAssetTasks\CompileAssets
   */
  protected function taskDrupalSetup($path = NULL) {
    // Always construct your tasks with the `task()` task builder.
    return $this->task(SuDrupalStack::class, $path);
  }

}

