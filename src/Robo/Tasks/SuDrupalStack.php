<?php

namespace StanfordCaravan\Robo\Tasks;

use League\Container\ContainerAwareInterface;
use League\Container\ContainerAwareTrait;
use Robo\Common\BuilderAwareTrait;
use Robo\Common\CommandArguments;
use Robo\Common\IO;
use Robo\Contract\BuilderAwareInterface;
use Robo\Contract\TaskInterface;
use Robo\LoadAllTasks;
use Robo\Result;
use Robo\Task\BaseTask;
use Robo\Task\CommandStack;
use Robo\Task\Composer\loadTasks;
use StanfordCaravan\CaravanTrait;

/**
 * Class SuDrupalStack
 *
 * @package StanfordCaravan\Robo\Tasks
 */
class SuDrupalStack extends BaseTask implements BuilderAwareInterface {

  use ContainerAwareTrait;
  use LoadAllTasks;
  use CaravanTrait;

  /**
   * Path to install drupal.
   *
   * @var string
   */
  protected $path;

  /**
   * Absolute path to the module or profile to test.
   *
   * @var string
   */
  protected $extensionDir;

  /**
   * Flag to keep the media module configs from core.
   *
   * @var bool
   */
  protected $keepMedia = FALSE;

  function __construct($dir) {
    $this->toolDir = dirname(__FILE__, 4);
    $this->path = $dir;
  }

  /**
   * Add a testable extension into the drupal installation.
   *
   * @param string $testable_extension
   *   Path to the extension to merge in.
   *
   * @return $this
   */
  function testExtension($testable_extension) {
    $this->extensionDir = $testable_extension;
    return $this;
  }

  function keepCoreMedia($keep = FALSE) {
    $this->keepMedia = $keep;
    return $this;
  }

  /**
   * @return \Robo\Result|void
   */
  function run() {
    $this->taskExec('dockerize -wait tcp://localhost:3306 -timeout 1m')->run();
    $this->taskExec('apachectl stop; apachectl start')->run();

    $tasks = [];

    // Start fresh.
    if (file_exists($this->path)) {
      $tasks[] = $this->taskFilesystemStack()->remove($this->path);
    }

    $tasks[] = $this->taskComposerCreateProject()
      ->arg('drupal/recommended-project')
      ->arg($this->path)
      ->option('no-interaction')
      ->option('no-install');

    $tasks[] = $this->taskComposerRequire()
      ->dir($this->path)
      ->arg('drupal/core-dev')
      ->option('no-update');

    $tasks[] = $this->taskComposerRequire()
      ->dir($this->path)
      ->arg('wikimedia/composer-merge-plugin')
      ->dev()
      ->option('no-update');

    $tasks[] = $this->taskComposerUpdate()->dir($this->path);

    // Symlink docroot directory to web directory.
    $tasks[] = $this->taskExec('ln')
      ->dir($this->path)
      ->arg("{$this->path}/web")
      ->arg('docroot')
      ->option('symbolic');

    if ($this->extensionDir) {
      $extension_type = $this->getExtensionType($this->extensionDir);
      $extension_name = $this->getExtensionName($this->extensionDir);

      // Create the custom directory if it doesn't already exist.
      if (!file_exists("{$this->path}/web/{$extension_type}s/custom")) {
        $tasks[] = $this->taskFilesystemStack()
          ->mkdir("{$this->path}/web/{$extension_type}s/custom");
      }
      // Ensure the extensions's directory is clean first.
      $tasks[] = $this->taskDeleteDir("{$this->path}/web/{$extension_type}s/custom/$extension_name");
      $tasks[] = $this->taskFilesystemStack()
        ->mkdir("{$this->path}/web/{$extension_type}s/custom/");
      // Copy the extension into its appropriate path.
      $tasks[] = $this->taskRsync()
        ->fromPath("{$this->extensionDir}/")
        ->toPath("{$this->path}/web/{$extension_type}s/custom/$extension_name")
        ->recursive()
        ->option('exclude', 'html');
    }

    if (!$this->keepMedia) {
      $media_files = glob("$this->path/web/core/profiles/standard/config/optional/*media*");
      foreach ($media_files as $file) {
        $tasks[] = $this->taskFilesystemStack()->remove($file);
      }
    }

    $this->collectionBuilder()->addTaskList($tasks)->run();

    $this->printTaskInfo('Adding composer merge files.');
    $this->addComposerMergeFile("{$this->toolDir}/config/composer.json", FALSE, TRUE);

    if ($this->extensionDir) {
      $this->addComposerMergeFile("{$this->path}/web/{$extension_type}s/custom/$extension_name/composer.json", TRUE);
    }
    return new Result($this, 0);
  }

  /**
   * Add a file to composer merge.
   *
   * @param string $file_to_merge
   *   Composer.json path to be merged.
   * @param bool $update
   *   Run composer updates after merging.
   * @param bool $clear_merges
   *   Clear the merge plugin before adding the file.
   */
  protected function addComposerMergeFile($file_to_merge, $update = FALSE, $clear_merges = FALSE) {
    $composer_path = "{$this->path}/composer.json";
    $composer = json_decode(file_get_contents($composer_path), TRUE);
    if ($clear_merges) {
      $composer['extra']['merge-plugin']['require'] = [];
    }

    $composer['extra']['merge-plugin']['require'][] = $file_to_merge;
    $composer['extra']['merge-plugin']['require'] = array_unique($composer['extra']['merge-plugin']['require']);
    $composer['extra']['merge-plugin']['merge-extra'] = TRUE;
    $composer['extra']['merge-plugin']['merge-extra-deep'] = TRUE;
    $composer['extra']['merge-plugin']['merge-scripts'] = TRUE;
    $composer['extra']['merge-plugin']['replace'] = FALSE;
    $composer['extra']['merge-plugin']['ignore-duplicates'] = TRUE;

    file_put_contents($composer_path, json_encode($composer, JSON_PRETTY_PRINT));

    if ($update) {
      $this->taskComposerUpdate()
        ->dir(dirname($composer_path))
        ->run();
      // Run twice to make sure its all there.
      $this->taskComposerUpdate()
        ->dir(dirname($composer_path))
        ->run();
    }
  }

}
