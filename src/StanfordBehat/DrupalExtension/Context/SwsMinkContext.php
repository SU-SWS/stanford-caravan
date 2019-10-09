<?php

namespace StanfordBehat\DrupalExtension\Context;

use Behat\Behat\Hook\Scope\BeforeScenarioScope;
use Behat\Mink\Exception\ExpectationException;
use Behat\MinkExtension\Context\RawMinkContext;

/**
 * Javascript related tests.
 */
class SwsMinkContext extends RawMinkContext {

  /**
   * Current feature test.
   *
   * @var \Behat\Gherkin\Node\FeatureNode
   */
  protected $currentFeature;

  /**
   * Before the feature runs, set the context parameters.
   *
   * @param \Behat\Behat\Hook\Scope\BeforeScenarioScope $scope ;
   *   The scope of the current scenario
   *
   * @BeforeScenario
   */
  public function beforeScenario(BeforeScenarioScope $scope) {
    $this->currentFeature = $scope->getFeature();
  }

  /**
   * Set the window to a breakpoint size.
   *
   * @param string $size
   *   Small, Medium, Large, or 'Extra Large' screen.
   *
   * @Then I set the window size to :size
   */
  public function iSetWindowSize($size) {
    // Sizes are width, height values for the window size.
    $sizes = [
      'extra small' => [575, 320],
      'small' => [576, 320],
      'medium' => [768, 1024],
      'large' => [992, 768],
      'extra large' => [1500, 800],
    ];

    $size = strtolower($size);
    if (array_key_exists($size, $sizes)) {
      $this->iSetWindowDimensions($sizes[$size][0], $sizes[$size][1]);
      return;
    }

    $this->iSetWindowDimensions($sizes['extra large'][0], $sizes['extra large'][1]);
  }

  /**
   * Change the window dimensions.
   *
   * @param int $width
   *   Window width.
   * @param int $height
   *   Window height.
   *
   * @Then I set window dimensions to :width x :height
   */
  public function iSetWindowDimensions($width, $height) {
    $this->getSession()->resizeWindow((int) $width, (int) $height, 'current');
  }

  /**
   * Click a specific element as defined by a css selector.
   *
   * @param string $selector
   *   Css Selector.
   *
   * @throws \Exception
   *
   * @Given I click the :selector element
   */
  public function iClickTheElement($selector) {
    $page = $this->getSession()->getPage();
    $element = $page->find('css', $selector);
    if (empty($element)) {
      throw new \Exception("No html element found for the selector ('$selector')");
    }
    $element->click();
  }

  /**
   * Change the context to an iframe with a specific ID.
   *
   * @param string $name
   *   Iframe name
   *
   * @Then I switch to :name iframe
   */
  public function iSwitchToiFrame($name) {
    $this->getSession()->switchToIFrame($name);
  }

  /**
   * Switch context back to the main window.
   *
   * @Then I exit iframe
   */
  public function iExitiFrame() {
    $this->getSession()->switchToWindow();
  }

  /**
   * Wait for a defined number of seconds.
   *
   * @param int $seconds
   *   Number of seconds to wait.
   *
   * @Then I wait :seconds seconds
   */
  public function iWaitSeconds($seconds) {
    $this->getSession()->wait(1000 * $seconds);
  }

  /**
   * Put an image into dropzone js area.
   *
   * @param string $path
   *   Absolute or relative (to behat media directory) path to the file.
   *
   * @Then I drop :path file into dropzone
   */
  public function iDropAFileIntoDropzone($path) {
    $path = $this->getAbsoluteFilePath($path);
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
    $this->getSession()->executeScript($javascript);

    // Give dropzone 2 seconds to complete.
    $this->getSession()->wait(2000);
  }

  /**
   * Populate a CKEditor text editor with contents from a file or string.
   *
   * @param string $locator
   *   WYSIWYG textarea element selector.
   * @param string $value
   *   New value of the wysiwyg.
   *
   * @throws \Behat\Mink\Exception\ExpectationException
   *
   * @Then I fill in wysiwyg :locator with :value
   */
  public function iFillInWysiwygOnFieldWith($locator, $value) {
    $element = $this->getSession()->getPage()->findField($locator);

    if (empty($element)) {
      throw new ExpectationException('Could not find WYSIWYG with locator: ' . $locator, $this->getSession());
    }

    $fieldId = $element->getAttribute('id');

    if (empty($fieldId)) {
      throw new \Exception('Could not find an id for field with locator: ' . $locator);
    }

    $html_file = $this->getAbsoluteFilePath($value);
    $value = $html_file ? file_get_contents($html_file) : $value;

    // Clean up the HTML to pass into javascript.
    $value = str_replace("'", "\'", str_replace("\n", '', $value));
    $this->getSession()
      ->executeScript("CKEDITOR.instances['$fieldId'].setData('$value');");
  }

  /**
   * Get the absolute path to the file relative to behat configuration.
   *
   * @param string $path
   *   Relative path string.
   *
   * @return bool|string
   *   Absolute path or false if no file exists.
   */
  protected function getAbsoluteFilePath($path) {

    // Check for a file relative to the current feature first.
    if (file_exists(dirname($this->currentFeature->getFile()) . DIRECTORY_SEPARATOR . $path)) {
      return dirname($this->currentFeature->getFile()) . DIRECTORY_SEPARATOR . $path;
    }

    // No file available relative to the feature, try to find the file as
    // defined by the mink parameters.
    if ($this->getMinkParameter('files_path')) {
      $fullPath = rtrim(realpath($this->getMinkParameter('files_path')), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $path;
      if (file_exists($fullPath)) {
        return $fullPath;
      }
    }
    return file_exists($path) ? $path : FALSE;
  }

}
