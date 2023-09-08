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
  protected array $config = [
    'server' => [
      'REQUEST_URI' => '/',
      'REMOTE_ADDR' => '127.0.0.1',
      'REQUEST_METHOD' => 'GET',
      'SCRIPT_NAME' => '/index.php',
    ],
  ];

  /**
   * @var \Codeception\Module\WebDriver
   */
  protected $webDriver;

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
  public function __construct(ModuleContainer $container, $config = []) {

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
    $kernel->boot();
    $kernel->preHandle($request);

    if ($this->hasModule('WebDriver')) {
      $this->webDriver = $this->getModule('WebDriver');
    }
  }

  /**
   * Fill WYSIWYG editor.
   *
   * @param \Codeception\Util\IdentifiableFormFieldInterface $field
   *   Element xpath.
   * @param string $content
   *   Text to insert in CkEditor.
   */
  public function fillWysiwygEditor(IdentifiableFormFieldInterface $field, $content) {
    $selector = $this->webdriver->grabAttributeFrom($field->value, 'id');
    $script = "jQuery(function(){CKEDITOR.instances[\"$selector\"].setData(\"$content\")});";
    $this->webdriver->executeInSelenium(function (RemoteWebDriver $webDriver) use ($script) {
      $webDriver->executeScript($script);
    });
    $this->webdriver->wait(1);
  }

  /**
   * Wait for AJAX to finish.
   */
  public function waitForAjaxToFinish() {
    $condition = <<<JS
function isAjaxing(instance) {
  return instance && instance.ajaxing === true;
}
var d7_not_ajaxing = true;
if (typeof Drupal !== 'undefined' && typeof Drupal.ajax !== 'undefined' && typeof Drupal.ajax.instances === 'undefined') {
  for(var i in Drupal.ajax) { if (isAjaxing(Drupal.ajax[i])) { d7_not_ajaxing = false; } }
}
var d8_not_ajaxing = (typeof Drupal === 'undefined' || typeof Drupal.ajax === 'undefined' || typeof Drupal.ajax.instances === 'undefined' || !Drupal.ajax.instances.some(isAjaxing))
return (
  // Assert no AJAX request is running (via jQuery or Drupal) and no
  // animation is running.
  (typeof jQuery === 'undefined' || (jQuery.active === 0 && jQuery(':animated').length === 0)) &&
  d7_not_ajaxing && d8_not_ajaxing
);
JS;

    $this->webDriver->waitForJS($condition);
    // Wait 1 more second to allow anything to render.
    $this->webDriver->wait(1);
  }

  /**
   * Drop a file into a dropzoneJS field.
   *
   * @param string $path
   *   Absolute path to the file to upload.
   */
  public function dropFileInDropzone($path) {
    $file_name = basename($path);

    $type = mime_content_type($path);
    $data = file_get_contents($path);
    $base64 = "data:$type;base64," . base64_encode($data);

    $javascript = "
    function dataURLtoFile(dataurl, filename) {
        var arr = dataurl.split(','), mime = arr[0].match(/:(.*?);/)[1],
            bstr = atob(arr[1]), n = bstr.length, u8arr = new Uint8Array(n);
        while(n--){
            u8arr[n] = bstr.charCodeAt(n);
        }
        return new File([u8arr], filename, {type:mime});
    }
    var newfile = dataURLtoFile('$base64', '$file_name');
    Dropzone.instances.forEach(function(instance, index){
      if(window.getComputedStyle(instance.element).display === 'block'){
        Dropzone.instances[index].addFile(newfile);;
      }
    });
    ";

    $this->webDriver->executeJS($javascript);

    // Give dropzone 2 seconds to complete.
    $this->webDriver->wait(2);
  }

}
