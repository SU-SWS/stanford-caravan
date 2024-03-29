<?php

namespace StanfordCaravan\Codeception\Drupal;

use Codeception\Module;
use StanfordCaravan\Codeception\Drupal\Util\Drush;

/**
 * Class DrupalDrush.
 *
 * Example to include:
 * modules:
 *   - StanfordCaravan\Codeception\Drupal\DrupalDrush:
 *     working_directory: './docroot'
 *     drush: './vendor/bin/drush'
 *     options:
 *       uri: mydomain.com
 *       root: /app/web
 *
 * @package StanfordCaravan\Codeception\Drupal\DrupalDrush
 */
class DrupalDrush extends Module {

  /**
   * Default module configuration.
   *
   * @var array
   */
  protected array $config = [
    'drush' => 'drush',
    'options' => [],
  ];

  /**
   * Execute a drush command.
   *
   * @param string $command
   *   Command to run.
   *   e.g. "en devel -y".
   * @param array $options
   *   Associative array of options.
   *
   * @return string
   *   The process output.
   */
  public function runDrush($command, array $options = []) {
    if (!empty($options)) {
      $command = $this->normalizeOptions($options) . $command;
    }
    elseif ($this->_getConfig('options')) {
      $command = $this->normalizeOptions($this->_getConfig('options')) . $command;
    }
    $command .= ' --no-interaction';
    $result = Drush::runDrush($command, $this->_getConfig('drush'), $this->_getConfig('working_directory'));

    echo $result . PHP_EOL;
    return $result;
  }

  /**
   * Returns options as sting.
   *
   * @param array $options
   *   Associative array of options.
   *
   * @return string
   *   Sring of options.
   */
  protected function normalizeOptions(array $options) {
    $command = '';
    foreach ($options as $key => $value) {
      if (is_string($value)) {
        $command .= '--' . $key . '=' . $value . ' ';
      }
    }
    return $command;
  }

  /**
   * Gets login uri.
   *
   * @param string $name
   *   User id.
   *
   * @return bool|string
   *   Login uri.
   */
  public function getLoginUri($name = '') {
    $user = '';
    if (!empty($name)) {
      $user = '--name=' . $name;
    }
    $gen_url = str_replace(PHP_EOL, '', $this->runDrush('uli ' . $user));

    return substr($gen_url, strpos($gen_url, '/user/reset'));
  }

}
