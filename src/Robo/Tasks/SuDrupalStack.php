<?php

namespace StanfordCaravan\Robo\Tasks;

use League\Container\ContainerAwareTrait;
use Robo\Contract\BuilderAwareInterface;
use Robo\LoadAllTasks;
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
  public function __construct($dir) {
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
  public function testExtension($testable_extension) {
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
  public function keepCoreMedia($keep = TRUE) {
    $this->keepMedia = $keep;
    return $this;
  }

  /**
   * Execute the tasks.
   *
   * @return \Robo\Result
   *   Result of the tasks.
   */
  public function run() {
    // CircleCI wait for database and reload apache.
    $this->taskExec('dockerize -wait tcp://localhost:3306 -timeout 1m')->run();
    $this->taskExec('apachectl stop; apachectl start')->run();

    chdir(dirname($this->path));
    // Start fresh with an empty directory.
    if (file_exists($this->path)) {
      $this->taskFilesystemStack()->remove($this->path)->run();
    }

    // Create the project.
    // @link https://www.drupal.org/docs/develop/using-composer/using-composer-to-install-drupal-and-manage-dependencies
    $this->taskComposerCreateProject()
      ->arg('drupal/recommended-project')
      ->arg($this->path)
      ->option('no-interaction')
      ->option('no-install')
      ->run();

    chdir($this->path);

    $extension_type = $this->getExtensionType($this->extensionDir);
    $extension_name = $this->getExtensionName($this->extensionDir);

    // Symlink `docroot` to the `web` directory for browser tests.
    $this->taskFilesystemStack()
      ->symlink("{$this->path}/web", "{$this->path}/docroot")->run();

    // Create the custom directory if it doesn't already exist.
    $this->taskFilesystemStack()
      ->mkdir("{$this->path}/web/{$extension_type}s/custom")
      ->run();

    // Copy the extension into its appropriate path.
    $this->taskRsync()
      ->fromPath("{$this->extensionDir}/")
      ->toPath("{$this->path}/web/{$extension_type}s/custom/$extension_name")
      ->recursive()
      ->option('exclude', 'html')
      ->run();

    $this->addComposer("{$this->toolDir()}/config/composer.json");
    $this->addComposer("{$this->path}/web/{$extension_type}s/custom/$extension_name/composer.json");

    $this->taskComposerUpdate()->dir($this->path)->run();

    // Delete media configs from the standard profile. This keeps from conflicts
    // with stanford media tests.
    if (!$this->keepMedia) {
      $media_files = glob("$this->path/web/core/profiles/standard/config/optional/*media*");
      $remove_task = $this->taskFilesystemStack();
      foreach ($media_files as $file) {
        $remove_task->remove($file);
      }
      $remove_task->run();
    }

    $this->requireThisCaravanVersion($this->path);
    $this->taskFilesystemStack()->mkdir("$this->path/artifacts")->run();

    return $this->taskExec('vendor/bin/pcov')
      ->dir($this->path)
      ->arg('clobber')
      ->run();

  }

  /**
   * Require the currently running version of caravan for the extensions.
   *
   * @param string $dir
   *   Directory of the composer.json.
   */
  protected function requireThisCaravanVersion($dir) {
    $local_caravan_version = $this->getLocalCaravanVersion($dir);

    $versions = $this->taskExec('composer')
      ->dir($dir)
      ->arg('global')
      ->arg('show')
      ->option('format', 'json', '=')
      ->printOutput(FALSE)
      ->run()
      ->getMessage();
    $versions = json_decode($versions, TRUE);

    // Find the caravan package and require that version.
    foreach ($versions['installed'] as $package) {
      if ($package['name'] == 'su-sws/stanford-caravan') {
        $version = substr($package['version'], 0, strpos($package['version'], ' '));
        $version = $version ?: $package['version'];

        if ($local_caravan_version != $version) {
          $this->taskComposerRequire()
            ->dir($dir)
            ->arg("su-sws/stanford-caravan:$version")
            ->run();
        }
        return;
      }
    }
  }

  /**
   * Get the version of caravan in the directory.
   *
   * @param string $dir
   *   Path of the drupal composer.json.
   *
   * @return string|null
   *   Installed version of the caravan package.
   */
  protected function getLocalCaravanVersion($dir) {
    $versions = $this->taskExec('composer')
      ->dir($dir)
      ->arg('show')
      ->option('format', 'json', '=')
      ->printOutput(FALSE)
      ->run()
      ->getMessage();
    $versions = json_decode($versions, TRUE);

    foreach ($versions['installed'] as $package) {
      if ($package['name'] == 'su-sws/stanford-caravan') {
        $version = substr($package['version'], 0, strpos($package['version'], ' '));
        return $version ?: $package['version'];
      }
    }
  }

  /**
   * Add a file to composer merge.
   *
   * @param string $file_to_merge
   *   Composer.json path to be merged.
   */
  protected function addComposer($file_to_merge) {
    $composer_path = "{$this->path}/composer.json";
    $composer_path = str_replace('//', '/', $composer_path);
    $composer = json_decode(file_get_contents($composer_path), TRUE);

    $composer_to_add = json_decode(file_get_contents($file_to_merge), TRUE);

    foreach (['extra', 'require', 'require-dev', 'config'] as $merge_key) {
      if (isset($composer_to_add[$merge_key])) {
        if (isset($composer[$merge_key])) {
          $composer[$merge_key] = self::arrayMergeRecursive($composer[$merge_key], $composer_to_add[$merge_key]);
          continue;
        }

        $composer[$merge_key] = $composer_to_add[$merge_key];
      }
    }

    // We don't want to merge these because the keys can be the same.
    if (isset($composer_to_add['repositories'])) {
      foreach ($composer_to_add['repositories'] as $repository) {
        $composer['repositories'][] = $repository;
      }
    }
    file_put_contents($composer_path, json_encode($composer, JSON_PRETTY_PRINT));
  }

  /**
   * Merge two arrays recursively.
   *
   * @param array $array1
   *   First array to merge.
   * @param array $array2
   *   Second array to merge.
   *
   * @return array
   *   Merged array.
   *
   * @link https://stackoverflow.com/questions/25712099/php-multidimensional-array-merge-recursive
   */
  protected static function arrayMergeRecursive(array $array1, array $array2) {
    $merged = $array1;

    foreach ($array2 as $key => & $value) {
      if (is_array($value) && isset($merged[$key]) && is_array($merged[$key])) {
        $merged[$key] = self::arrayMergeRecursive($merged[$key], $value);
      }
      else {
        if (is_numeric($key)) {
          if (!in_array($value, $merged)) {
            $merged[] = $value;
          }
        }
        else {
          $merged[$key] = $value;
        }
      }
    }

    return $merged;
  }

}
