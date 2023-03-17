<?php

namespace StanfordCaravan\Codeception\Drupal;

use Codeception\Module;
use Drupal\Core\Entity\FieldableEntityInterface;
use Codeception\TestCase;

/**
 * Class DrupalEntity.
 *
 * Example to include:
 * modules:
 *   enabled:
 *    - StanfordCaravan\Codeception\Drupal\DrupalEntity:
 *      cleanup_test: true
 *      cleanup_failed: false
 *      cleanup_suite: true
 *      route_entities:
 *        - node
 *        - taxonomy_term.
 *
 * @package Codeception\Module
 */
class DrupalEntity extends Module {

  /**
   * Default module configuration.
   *
   * @var array
   */
  protected $config = [
    'cleanup_test' => TRUE,
    'cleanup_failed' => TRUE,
    'cleanup_suite' => TRUE,
    'route_entities' => [
      'node',
      'taxonomy_term',
      'media',
    ],
  ];

  /**
   * Entities to be deleted after test suite.
   *
   * @var array
   */
  protected $entities = [];

  /**
   * {@inheritdoc}
   */
  public function _afterSuite() { // @codingStandardsIgnoreLine
    if ($this->config['cleanup_suite']) {
      $this->doEntityCleanup();
    }
  }

  /**
   * {@inheritdoc}
   */
  public function _after(TestCase $test) { // @codingStandardsIgnoreLine
    if ($this->config['cleanup_test']) {
      $this->doEntityCleanup();
    }
  }

  /**
   * {@inheritdoc}
   */
  public function _failed(TestCase $test, $fail) { // @codingStandardsIgnoreLine
    if ($this->config['cleanup_failed']) {
      $this->doEntityCleanup();
    }
  }

  /**
   * Create entity from values.
   *
   * @param array $values
   *   Data for creating entity.
   * @param string $type
   *   Entity type.
   * @param bool $validate
   *   If the entity should be validated.
   *
   * @return \Drupal\Core\Entity\EntityInterface|bool
   *   Created entity.
   */
  public function createEntity(array $values = [], $type = 'node', $validate = FALSE) {
    $tries = 0;
    while ($tries < 3) {
      try {
        /** @var \Drupal\Core\Entity\EntityTypeInterface $entity_definition */
        $entity_definition = \Drupal::entityTypeManager()->getDefinition($type);
        // ALways publish entities, unless the user specifically said not to.
        if ($status_key = $entity_definition->getKey('status')) {
          $values[$status_key] = isset($values[$status_key]) ? $values[$status_key] : TRUE;
        }

        $entity = \Drupal::entityTypeManager()
          ->getStorage($type)
          ->create($values);
        if ($validate && $entity instanceof FieldableEntityInterface) {
          $violations = $entity->validate();
          if ($violations->count() > 0) {
            $message = PHP_EOL;
            foreach ($violations as $violation) {
              $message .= $violation->getPropertyPath() . ': ' . $violation->getMessage() . PHP_EOL;
            }
            throw new \Exception($message);
          }
        }

        $entity->save();
        $this->registerTestEntity($entity->getEntityTypeId(), $entity->id());
        \Drupal::service('cache_tags.invalidator')
          ->invalidateTags([$type . '_list:' . $entity->bundle()]);
        return $entity;
      }
      catch (\Exception $e) {
        $tries++;
      }
    }
    $this->fail('Could not create entity. Error message: ' . $e->getMessage());
  }

  /**
   * Delete stored entities.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function doEntityCleanup() {
    if (empty($this->entities)) {
      return;
    }
    $entity_type_manager = \Drupal::entityTypeManager();
    foreach ($this->entities as $type => $ids) {
      if ($entity_type_manager->hasDefinition($type)) {
        $entities = $entity_type_manager->getStorage($type)
          ->loadMultiple($ids);
        foreach ($entities as $entity) {
          $entity->delete();
        }
      }
    }
    \Drupal::service('cache.render')->invalidateAll();
    \Drupal::service('cache.bootstrap')->invalidate('paragraphs_type_icon_uuid');
  }

  /**
   * Register test entity to be deleted after tests.
   *
   * @param string $type
   *   Entity type.
   * @param string|int $id
   *   Entity id.
   */
  public function registerTestEntity($type, $id) {
    try {
      \Drupal::entityTypeManager()->getStorage($type);
    }
    catch (\Exception $e) {
      $this->fail('Invalid entity type specified: ' . $type);
    }
    $this->entities[$type][] = $id;
  }

}
