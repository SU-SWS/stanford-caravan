<?php

namespace StanfordCaravan\Codeception\Drupal;

use Codeception\Lib\ModuleContainer;
use Codeception\Module;
use Drupal\user\Entity\User;
use Faker\Factory;
use StanfordCaravan\Codeception\Drupal\Util\Drush;

/**
 * Class DrupalUser.
 *
 * Exmple configuration to include:
 * modules:
 *   - StanfordCaravan\Codeception\Drupal\DrupalUser:
 *       default_role: 'authenticated'
 *       driver: 'PhpBrowser'
 *       drush: './vendor/bin/drush'
 *       cleanup_entities:
 *         - media
 *         - file
 *         - paragraph
 *       cleanup_test: false
 *       cleanup_failed: false
 *       cleanup_suite: true
 *
 * @package Codeception\Module
 */
class DrupalUser extends Module {

  /**
   * Driver to use.
   *
   * @var \WebDriver|PhpBrowser|null
   */
  protected $driver;

  /**
   * A list of user ids created during test suite.
   *
   * @var array
   */
  protected $users;

  /**
   * Drush config settings.
   *
   * @var array
   */
  protected $drushConfig = [];

  /**
   * Default module configuration.
   *
   * @var array
   */
  protected $config = [
    'default_role' => 'authenticated',
    'driver' => 'WebDriver',
    'drush' => 'drush',
    'cleanup_entities' => [],
    'cleanup_test' => TRUE,
    'cleanup_failed' => TRUE,
    'cleanup_suite' => TRUE,
  ];

  /**
   * Codeception DrupalUser constructor.
   */
  public function __construct(ModuleContainer $moduleContainer, $config = NULL) {
    parent::__construct($moduleContainer, $config);
    $this->drushConfig = $this->getModule(DrupalDrush::class)->_getConfig();
  }

  /**
   * {@inheritdoc}
   */
  public function _beforeSuite($settings = []) { // @codingStandardsIgnoreLine
    $this->driver = NULL;
    if (!$this->hasModule($this->_getConfig('driver'))) {
      $this->fail('User driver module not found: ' . $this->_getConfig('driver'));
    }

    $this->driver = $this->getModule($this->_getConfig('driver'));
  }

  /**
   * {@inheritdoc}
   */
  public function _after(\Codeception\TestCase $test) { // @codingStandardsIgnoreLine
    if ($this->_getConfig('cleanup_test')) {
      $this->userCleanup();
    }
  }

  /**
   * {@inheritdoc}
   */
  public function _failed(\Codeception\TestCase $test, $fail) { // @codingStandardsIgnoreLine
    if ($this->_getConfig('cleanup_failed')) {
      $this->userCleanup();
    }
  }

  /**
   * {@inheritdoc}
   */
  public function _afterSuite() { // @codingStandardsIgnoreLine
    if ($this->_getConfig('cleanup_suite')) {
      $this->userCleanup();
    }
  }

  /**
   * Create test user with specified roles.
   *
   * @param array $roles
   *   List of user roles.
   * @param mixed $password
   *   Password.
   *
   * @return \Drupal\user\UserInterface
   *   User object.
   */
  public function createUserWithRoles(array $roles = [], $password = FALSE) {
    $faker = Factory::create();

    /** @var \Drupal\user\Entity\User $user */
    try {
      $user = \Drupal::entityTypeManager()->getStorage('user')->create([
        'name' => $faker->userName,
        'mail' => $faker->email,
        'roles' => $this->getRoleMachineNames($roles),
        'pass' => $password ? $password : $faker->password(12, 14),
        'status' => 1,
      ]);

      $user->save();
      $this->users[] = $user->id();
    }
    catch (\Exception $e) {
      $this->fail(sprintf('Could not create user with roles: %s. Error: %s', implode(', ', $roles), $e->getMessage()));
    }

    return $user;
  }

  /**
   * Get the clean list of role ID's.
   *
   * @param array $roles
   *   Array of role ids or labels.
   *
   * @return array
   *   Array of role ids.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  protected function getRoleMachineNames(array $roles = []): array {
    $roles = empty($roles) ? $this->_getConfig('default_role') : $roles;
    $role_storage = \Drupal::entityTypeManager()->getStorage('user_role');
    foreach ($roles as &$role_id) {
      // If the test calls for the label of the role, lets try to find the id
      // of the role entity.
      if (!$role_storage->load($role_id)) {
        $loaded_roles = $role_storage->loadByProperties(['label' => $role_id]);
        $role_id = $loaded_roles ? key($loaded_roles) : NULL;
      }
    }
    return array_filter($roles);
  }

  /**
   * Log in user by username or user id.
   *
   * @param string|int $username
   *   User name or user id.
   */
  public function logInAs($username) {
    if ((int) $username) {
      $command = sprintf('uli --uid=%s --uri=%s --no-browser', $username, $this->drushConfig['options']['uri']);
    }
    else {
      $command = sprintf('uli --name=%s --uri=%s --no-browser', $username, $this->drushConfig['options']['uri']);
    }
    $output = Drush::runDrush($command, $this->_getConfig('drush'), $this->_getConfig('working_directory'));
    $gen_url = str_replace(PHP_EOL, '', $output);
    $url = substr($gen_url, strpos($gen_url, '/user/reset'));
    $this->driver->amOnPage($url);
  }

  /**
   * Create user with role and Log in.
   *
   * @param string $role
   *   Role.
   *
   * @return \Drupal\user\UserInterface
   *   User object.
   */
  public function logInWithRole($role) {
    $user = $this->createUserWithRoles([$role], Factory::create()
      ->password(12, 14));
    $this->logInAs($user->getDisplayName());

    return $user;
  }

  /**
   * Delete stored entities.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  private function userCleanup() {
    if (isset($this->users) && !empty($this->users)) {
      $users = User::loadMultiple($this->users);
      /** @var \Drupal\user\Entity\User $user */
      foreach ($users as $user) {
        $this->deleteUsersContent($user->id());
        $user->delete();
      }
      \Drupal::service('cache.render')->invalidateAll();
      \Drupal::service('cache.bootstrap')->invalidate('paragraphs_type_icon_uuid');
    }
  }

  /**
   * Delete user created entities.
   *
   * @param string|int $uid
   *   User id.
   */
  private function deleteUsersContent($uid) {
    $errors = [];
    $cleanup_entities = $this->_getConfig('cleanup_entities');
    if (!is_array($cleanup_entities)) {
      return;
    }
    $entity_manager = \Drupal::entityTypeManager();

    foreach ($cleanup_entities as $cleanup_entity) {
      if (!is_string($cleanup_entity) || !$entity_manager->hasDefinition($cleanup_entity)) {
        continue;
      }

      try {
        $storage = $entity_manager->getStorage($cleanup_entity);

        foreach ($storage->loadByProperties(['uid' => $uid]) as $entity) {
          $entity->delete();
        }
      }
      catch (\Exception $e) {
        $errors[] = 'Unable to delete all entities. error: ' . $e->getMessage();
        continue;
      }
    }

    if ($errors) {
      $this->fail(implode(PHP_EOL, $errors));
    }
  }

}
