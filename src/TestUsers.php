<?php

declare(strict_types=1);

namespace Drupal\masquerade_toolbar;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueStoreInterface;
use Drupal\Core\Password\PasswordGeneratorInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\masquerade\Masquerade;
use Drupal\user\RoleInterface;
use Drupal\user\UserInterface;

/**
 * The test users: one per role, only for masquerading as that role. There
 * are none for administrator roles.
 *
 * Which accounts are test users is kept here (role => user ID), not derived
 * from their names. A test user has exactly its role, is active, and cannot
 * log in other than by masquerading.
 */
final class TestUsers {

  private KeyValueStoreInterface $store;

  public function __construct(
    KeyValueFactoryInterface $keyValueFactory,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly PasswordGeneratorInterface $passwordGenerator,
    private readonly Masquerade $masquerade,
  ) {
    $this->store = $keyValueFactory->get('masquerade_toolbar.test_users');
  }

  /**
   * Returns the test user of a role; a new, unsaved one if there is none.
   *
   * Save a new one with save().
   */
  public function forRole(RoleInterface $role): UserInterface {
    $storage = $this->entityTypeManager->getStorage('user');
    $uid = $this->store->get($role->id());
    if ($uid && ($account = $storage->load($uid))) {
      return $account;
    }
    // A free name: test_<role>, or test_<role>_2, … if someone has it.
    $name = $base = 'test_' . $role->id();
    for ($i = 2; $storage->loadByProperties(['name' => $name]); $i++) {
      $name = "{$base}_$i";
    }
    $account = $storage->create(['name' => $name, 'mail' => "$name@example.com"]);
    $this->enforce($account, $role->id());
    return $account;
  }

  /**
   * Saves a new test user and keeps it as the test user of its role.
   */
  public function save(UserInterface $account, RoleInterface $role): void {
    $account->save();
    $this->store->set($role->id(), (int) $account->id());
  }

  /**
   * Returns the role of a test user, or NULL if the account is not one.
   */
  public function roleOf(int|string|null $uid): ?string {
    if (!$uid) {
      return NULL;
    }
    $role = array_search((int) $uid, $this->store->getAll(), TRUE);
    return $role === FALSE ? NULL : (string) $role;
  }

  /**
   * Returns the user IDs of all test users, by role.
   *
   * @return array<string, int>
   */
  public function all(): array {
    return $this->store->getAll();
  }

  /**
   * Forgets the test user of a role (after it was deleted).
   */
  public function forget(string $role): void {
    $this->store->delete($role);
  }

  /**
   * Gives a test user exactly its role, makes it active, and gives it a new
   * random password nobody knows.
   */
  public function enforce(UserInterface $account, string $role): void {
    foreach ($account->getRoles(TRUE) as $other) {
      $account->removeRole($other);
    }
    if ($role !== RoleInterface::AUTHENTICATED_ID) {
      $account->addRole($role);
    }
    $account->activate();
    $account->setPassword($this->passwordGenerator->generate(32));
  }

  /**
   * Deletes all test users (on uninstall), or the one of a role. Their
   * content goes to Anonymous.
   */
  public function delete(?string $role = NULL): void {
    $uids = $role ? array_filter([$role => $this->store->get($role)]) : $this->store->getAll();
    foreach ($this->entityTypeManager->getStorage('user')->loadMultiple($uids) as $account) {
      // As "Delete the account and make its content belong to Anonymous".
      \Drupal::moduleHandler()->invokeAll('user_cancel', [[], $account, 'user_cancel_reassign']);
      $account->delete();
    }
    $role ? $this->store->delete($role) : $this->store->deleteAll();
  }

  /**
   * Whether an account may masquerade at all (and so see the test users).
   */
  public function maySee(AccountInterface $account): bool {
    if ((int) $account->id() === 1) {
      return TRUE;
    }
    foreach ($this->masquerade->getPermissions() as $permission) {
      if ($account->hasPermission($permission)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Whether the current session masquerades.
   */
  public function isMasquerading(): bool {
    return $this->masquerade->isMasquerading();
  }

}
