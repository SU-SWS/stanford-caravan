<?php

namespace StanfordCaravan\Codeception\Drupal;

use Codeception\Configuration;
use Codeception\Lib\ModuleContainer;
use Codeception\Module;
use Symfony\Component\HttpFoundation\Request;
use Drupal\Core\DrupalKernel;

/**
 * Class DrupalBootstrap.
 *
 * All of the server settings are optional.
 *
 * modules:
 *  enabled
 *    - StanfordCaravan\Codeception\Drupal\DrupalBootstrap:
 *        root: './web'
 *        server:
 *          SERVER_PORT: null
 *          REQUEST_URI: '/'
 *          REMOTE_ADDR: '127.0.0.1'
 *          REQUEST_METHOD: 'GET
 *          HTTP_HOST: 'site.domain.loc'
 *
 * @package StanfordCaravan\Codeception\Drupal\DrupalBootstrap
 */
class DrupalBootstrap extends Module {

  /**
   * Default module configuration.
   *
   * @var array
   */
  protected $config = [
    'server' => [
      'REQUEST_URI' => '/',
      'REMOTE_ADDR' => '127.0.0.1',
      'REQUEST_METHOD' => 'GET',
      'SCRIPT_NAME' => '/index.php',
    ],
  ];

  /**
   * DrupalBootstrap constructor.
   *
   * @param \Codeception\Lib\ModuleContainer $container
   *   Module container.
   * @param null|array $config
   *   Array of configurations or null.
   *
   * @throws \Codeception\Exception\ModuleConfigException
   * @throws \Codeception\Exception\ModuleException
   */
  public function __construct(ModuleContainer $container, $config = NULL) {

    foreach ($this->config as $key => $value) {
      $config[$key] += $value;
    }
    parent::__construct($container, $config);

    if (!isset($this->config['root'])) {
      $this->_setConfig(['root' => Configuration::projectDir() . 'docroot']);
    }

    foreach ($this->_getConfig('server') as $key => $value) {
      if (!is_null($value)) {
        $_SERVER[$key] = $value;
      }
    }

    chdir($this->_getConfig('root'));
    $request = Request::createFromGlobals();
    $autoloader = require 'autoload.php';

    $kernel = DrupalKernel::createFromRequest($request, $autoloader, 'prod');

    try {
      $kernel->boot();
    }
    catch (\Exception $e) {
      $this->fail($e->getMessage());
    }
    $kernel->prepareLegacyRequest($request);
  }

}
