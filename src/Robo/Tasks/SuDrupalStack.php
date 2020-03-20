<?php

namespace StanfordCaravan\Robo\Tasks;

use League\Container\ContainerAwareTrait;
use Robo\Contract\BuilderAwareInterface;
use Robo\LoadAllTasks;
use Robo\Result;
use Robo\Task\BaseTask;
use StanfordCaravan\CaravanTrait;

/**
 * Class SuDrupalStack.
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

  /**
   * SuDrupalStack constructor.
   *
   * @param string $dir
   *   Directory to install drupal.
   */
  function __construct($dir) {
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

  /**
   * When building the drupal directory, keep the media config files.
   *
   * @param bool $keep
   *   Keep the configs from standard profile.
   *
   * @return $this
   */
  function keepCoreMedia($keep = TRUE) {
    $this->keepMedia = $keep;
    return $this;
  }

  /**
   * Execute the tasks.
   *
   * @return \Robo\Result
   *   Result of the tasks.
   */
  function run() {
    // CircleCI wait for database and reload apache.
    $this->taskExec('dockerize -wait tcp://localhost:3306 -timeout 1m')->run();
    $this->taskExec('apachectl stop; apachectl start')->run();

    $tasks = [];

    // Start fresh with an empty directory.
    if (file_exists($this->path)) {
      $tasks[] = $this->taskFilesystemStack()->remove($this->path);
    }

    // Create the project.
    // @link https://www.drupal.org/docs/develop/using-composer/using-composer-to-install-drupal-and-manage-dependencies
    $tasks[] = $this->taskComposerCreateProject()
      ->arg('drupal/recommended-project')
      ->arg($this->path)
      ->option('no-interaction')
      ->option('no-install');

    // Add some dependencies.
    $tasks[] = $this->taskComposerRequire()
      ->dir($this->path)
      ->arg('drupal/core-dev')
      ->arg('wikimedia/composer-merge-plugin')
      ->dev();

    // Symlink `docroot` to the `web` directory for browser tests.
    $tasks[] = $this->taskFilesystemStack()
      ->symlink("{$this->path}/web", "{$this->path}/docroot");

    if ($this->extensionDir) {
      $extension_type = $this->getExtensionType($this->extensionDir);
      $extension_name = $this->getExtensionName($this->extensionDir);

      // Create the custom directory if it doesn't already exist.
      $tasks[] = $this->taskFilesystemStack()
        ->mkdir("{$this->path}/web/{$extension_type}s/custom");

      // Copy the extension into its appropriate path.
      $tasks[] = $this->taskRsync()
        ->fromPath("{$this->extensionDir}/")
        ->toPath("{$this->path}/web/{$extension_type}s/custom/$extension_name")
        ->recursive()
        ->option('exclude', 'html');
    }

    // Delete media configs from the standard profile. This keeps from conflicts
    // with stanford media tests.
    if (!$this->keepMedia) {
      $media_files = glob("$this->path/web/core/profiles/standard/config/optional/*media*");
      foreach ($media_files as $file) {
        $tasks[] = $this->taskFilesystemStack()->remove($file);
      }
    }

    $this->collectionBuilder()->addTaskList($tasks)->run();

    $this->printTaskInfo('Adding composer merge files.');
    $this->addComposerMergeFile("{$this->toolDir()}/config/composer.json", FALSE, TRUE);

    if ($this->extensionDir) {
      $this->addComposerMergeFile("{$this->path}/web/{$extension_type}s/custom/$extension_name/composer.json", TRUE);
    }

    return $this->taskExec('vendor/bin/pcov')
      ->dir($this->path)
      ->arg('clobber')
      ->run();
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
