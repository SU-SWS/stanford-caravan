<?php

namespace StanfordBehat\DrupalExtension\Context;

use Behat\Behat\Hook\Scope\AfterStepScope;
use Behat\Mink\Exception\ElementNotFoundException;
use Drupal\DrupalExtension\Context\RawDrupalContext;
use Drupal\DrupalExtension\Context\DrushContext;
use Behat\Behat\Context\SnippetAcceptingContext;
use Drupal\Core\Entity\EntityInterface;
use Exception;

/**
 * FeatureContext class defines custom step definitions for Behat.
 */
class SwsContext extends RawDrupalContext implements SnippetAcceptingContext {

  /**
   * Create an HTML file of the current page if a test failed.
   *
   * @param \Behat\Behat\Hook\Scope\AfterStepScope $event
   *   Triggered event.
   *
   * @AfterStep
   */
  public function afterStepFailure(AfterStepScope $event) {
    if (!$event->getTestResult()->isPassed()) {
      $test_title = $event->getFeature()->getTitle();
      $test_title = preg_replace("/[^a-z]/", '_', strtolower($test_title));
      $line = $event->getStep()->getLine();
      $page = $this->getSession()->getPage();
      $drupal_directory = $this->getDrupalParameter('drupal')['drupal_root'];
      if (!file_exists("$drupal_directory/../artifacts/")) {
        mkdir("$drupal_directory/../artifacts/");
      }
      file_put_contents("$drupal_directory/../artifacts/$test_title-$line.html", $page->getOuterHtml());
    }
  }

  /**
   * Cleans up files and media after every scenario.
   *
   * @AfterScenario
   */
  public function afterScenarioCleanUpMedia() {
    $user = $this->getUserManager()->getCurrentUser();

    $entity_type_manager = \Drupal::entityTypeManager();

    if (!$user || !$entity_type_manager->hasDefinition('media')) {
      return;
    }

    $media_entities = $entity_type_manager->getStorage('media')
      ->loadByProperties(['uid' => $user->uid]);

    // Delete the media entities.
    foreach ($media_entities as $media_item) {
      $this->deleteEntity('media', $media_item);
    }

    $files = $entity_type_manager->getStorage('file')
      ->loadByProperties(['uid' => $user->uid]);

    // Delete the files that were on those media entities.
    foreach ($files as $file) {
      $this->deleteEntity('file', $file);
    }
  }

  /**
   * Delete the given entity.
   *
   * @param string $entity_type
   *   Type of entity.
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   Entity to delete.
   *
   * @link https://github.com/jhedstrom/DrupalDriver/blob/4c56f48ebf35646cfe012cad01c5c3405b2273b3/src/Drupal/Driver/DrupalDriver.php#L332
   */
  protected function deleteEntity($entity_type, EntityInterface $entity) {
    $stdClass_item = new \stdClass();
    $stdClass_item->id = $entity->id();
    $this->getDriver()->entityDelete($entity_type, $stdClass_item);
  }

  /**
   * Checks, that (?P<num>\d+) CSS elements exist in the given region.
   *
   * Example: Then I should see 5 "div" elements in the "content" region
   * Example: And I should see 5 "div" elements in the "header" region
   *
   * @param int $num
   *   Number of elements.
   * @param string $element
   *   Element selector.
   * @param string region
   *   Region name.
   *
   * @throws \Behat\Mink\Exception\ExpectationException
   *
   * @Then /^(?:|I )should see (?P<num>\d+) "(?P<element>[^"]*)" elements in the "(?P<region>[^"]*)" region?$/
   * @Then /^(?:|I )should see (?P<num>\d+) "(?P<element>[^"]*)" element in the "(?P<region>[^"]*)" region?$/
   */
  public function iShouldSeeElementsInTheRegion($num, $element, $region) {
    $regionObj = $this->getRegion($region);
    $this->assertSession()
      ->elementsCount('css', $element, intval($num), $regionObj);
  }

  /**
   * Checks, that no CSS elements exist in the given region.
   *
   * Example: Then I should not see "form" elements in the "content" region
   * Example: And I should not see "input" elements in the "header" region
   *
   * @param string $element
   *   Element selector.
   * @param string region
   *   Region name.
   *
   * @throws \Behat\Mink\Exception\ExpectationException
   *
   * @Then /^(?:|I )should not see a "(?P<element>[^"]*)" element in the "(?P<region>[^"]*)" region?$/
   * @Then /^(?:|I )should not see an "(?P<element>[^"]*)" element in the "(?P<region>[^"]*)" region?$/
   */
  public function iShouldNotSeeElementsInTheRegion($element, $region) {
    $regionObj = $this->getRegion($region);
    $this->assertSession()
      ->elementsCount('css', $element, 0, $regionObj);
  }

  /**
   * Check what the attribute value is on the given element selector.
   *
   * @param string $element
   *   Element selector.
   * @param string attribute
   *   Attribute name.
   * @param string $value
   *   Expected value of the attribute.
   *
   * @throws \Behat\Mink\Exception\ExpectationException
   *
   * @Then the element :element should have the attribute :attribute with the
   *   value :value
   */
  public function theElementShouldHaveAttribute($element, $attribute, $value) {
    $this->getMink()
      ->assertSession()
      ->elementAttributeContains('css', $element, $attribute, $value);
  }

  /**
   * Return a region from the current page.
   *
   * @param string $region
   *   The machine name of the region to return.
   *
   * @return \Behat\Mink\Element\NodeElement
   *   The region element.
   *
   * @throws \Exception
   *   If region cannot be found.
   */
  public function getRegion($region) {
    $session = $this->getSession();
    $regionObj = $session->getPage()->find('region', $region);
    if (!$regionObj) {
      throw new \Exception(sprintf('No region "%s" found on the page %s.', $region, $session->getCurrentUrl()));
    }
    return $regionObj;
  }

  /**
   * @Then I check :button button is disabled
   */
  public function iCheckButtonIsDisabled($button) {
    $element = $this->getSession()->getPage();
    $buttonObj = $element->findButton($button);
    if (empty($buttonObj)) {
      throw new \Exception(sprintf("The button '%s' was not found on the page %s", $button, $this->getSession()
        ->getCurrentUrl()));
    }

    return $buttonObj->getAttribute('disabled');
  }

  /**
   * @Given the :module module is enabled
   */
  public function theModuleIsEnabled($module) {
    if (!$this->checkThatTheModuleIsEnabled($module)) {
      // Try to enable the module.
      return \Drupal::service('module_installer')->install([$module]);;
    }

    return TRUE;
  }

  /**
   * @Then check that the :module module is enabled
   */
  public function checkThatTheModuleIsEnabled($module) {
    // Check if enabled.
    $moduleHandler = \Drupal::service('module_handler');
    if ($moduleHandler->moduleExists($module)) {
      return TRUE;
    }

    // Nope.
    return FALSE;
  }

  /**
   * @Then I fill in element :locator with :value
   */
  public function iFillInElementWith($locator, $value) {
    $field = $this->getSession()->getPage()->find('css', $locator);
    if (NULL === $field) {
      throw new ElementNotFoundException($this->getDriver(), 'form field', 'css', $locator);
    }
    $field->setValue($value);
  }

  /**
   * @Then /^the response header "([^"]*)" should contain "([^"]*)"$/
   */
  public function theResponseHeaderShouldContain($arg1, $arg2) {
    $headers = $this->getSession()->getResponseHeaders();
    if (!isset($headers[$arg1])) {
      throw new Exception('The HTTP header "' . $arg1 . '" does not appear to be set.');
    }
    if (!in_array($arg2, $headers[$arg1])) {
      throw new Exception('The HTTP header "' . $arg1 . '" did not contain "' . $arg2 . '"');
    }
  }

  /**
   * @Then /^the response header should not have "([^"]*)"$/
   */
  public function theResponseHeaderShouldNotHave($arg1) {
    $headers = $this->getSession()->getResponseHeaders();

    if (isset($headers[$arg1])) {
      throw new Exception('The HTTP header "' . $arg1 . '" is set to: ' . array_pop($headers[$arg1]) . '.');
    }

  }


}
