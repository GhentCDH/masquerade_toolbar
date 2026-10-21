<?php

declare(strict_types=1);

namespace Drupal\masquerade_toolbar;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\user\RoleInterface;

/**
 * Masquerading never goes up.
 *
 * Nobody masquerades as an administrator role, and nobody masquerades as
 * roles with a permission they do not have themselves.
 */
final class NeverUp {

  public function __construct(private readonly EntityTypeManagerInterface $entityTypeManager) {}

  /**
   * Whether an account may masquerade as an account with these roles.
   *
   * @param string[] $target_roles
   *   The role IDs of the target (authenticated is added).
   */
  public function allows(AccountInterface $account, array $target_roles): bool {
    $storage = $this->entityTypeManager->getStorage('user_role');
    $targets = $storage->loadMultiple(array_unique([RoleInterface::AUTHENTICATED_ID, ...$target_roles]));
    $permissions = [];
    foreach ($targets as $role) {
      if ($role->isAdmin()) {
        return FALSE;
      }
      $permissions = array_merge($permissions, $role->getPermissions());
    }
    if ((int) $account->id() === 1) {
      return TRUE;
    }
    foreach ($storage->loadMultiple($account->getRoles()) as $role) {
      if ($role->isAdmin()) {
        return TRUE;
      }
    }
    foreach (array_unique($permissions) as $permission) {
      if (!$account->hasPermission($permission)) {
        return FALSE;
      }
    }
    return TRUE;
  }

}
