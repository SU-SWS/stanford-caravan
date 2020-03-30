<?php

namespace StanfordCaravan;

/**
 * Caravan trait with shared methods.
 */
trait CaravanTrait {

  /**
   * Get the root directory of this package.
   *
   * @return string
   *   Absolute path to the tool's directory.
   */
  public function tooldir() {
    return dirname(__FILE__, 2);
  }

  /**
   * Recursively search for files with a given pattern.
   *
   * @param string $pattern
   *   PHP Glob pattern.
   * @param int $flags
   *   PHP Glob flags.
   */
  public function rglob($pattern, $flags = 0) {
    $files = glob($pattern, $flags);
    foreach (glob(dirname($pattern) . '/*', GLOB_ONLYDIR | GLOB_NOSORT) as $dir) {
      $files = array_merge($files, $this->rglob($dir . '/' . basename($pattern), $flags));
    }
    return $files;
  }

  /**
   * Get the machine name of the Drupal extension.
   *
   * @param string $dir
   *   Path to the extension.
   *
   * @return string
   *   Machine name.
   */
  protected function getExtensionName($dir) {
    $files = glob("$dir/*.info.yml");
    $info_file = basename($files[0]);
    return str_replace('.info.yml', '', $info_file);
  }

  /**
   * Get the Drupal extension type: module, theme, or profile.
   *
   * @param string $dir
   *   Path to the extension.
   *
   * @return string
   *   Drupal extension type.
   */
  protected function getExtensionType($dir) {
    $extension_name = $this->getExtensionName($dir);
    $info_contents = file_get_contents("$dir/$extension_name.info.yml");
    $matches = preg_grep('/^type:.*?$/x', explode("\n", $info_contents));
    return trim(str_replace('type: ', '', reset($matches)));
  }

}
