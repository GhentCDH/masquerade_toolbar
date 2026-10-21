<?php

declare(strict_types=1);

namespace Drupal\masquerade_toolbar\Hook;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Database\Query\AlterableInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\masquerade_toolbar\NeverUp;
use Drupal\masquerade_toolbar\TestUsers;
use Drupal\user\RoleInterface;
use Drupal\user\UserInterface;

/**
 * Keeps the test users for masquerading only.
 *
 * - Only users who may masquerade see them (profile, lists, autocompletes).
 * - Nobody can edit them; deleting needs its own permission.
 * - Every save gives them exactly their role again, and makes them active.
 * - They cannot log in other than by masquerading (see TestUserLoginSubscriber).
 */
final class TestUserHooks {

  use StringTranslationTrait;

  public function __construct(
    private readonly TestUsers $testUsers,
    private readonly AccountInterface $currentUser,
    private readonly NeverUp $neverUp,
  ) {}

  #[Hook('masquerade_access')]
  public function masqueradeAccess(AccountInterface $user, UserInterface $target_account): ?bool {
    // Not to an administrator role, and never to more permissions: for all of
    // Masquerade, also its own form and block. FALSE wins over any TRUE.
    return $this->neverUp->allows($user, $target_account->getRoles()) ? NULL : FALSE;
  }

  #[Hook('user_access')]
  public function access(UserInterface $account, string $operation, AccountInterface $user): AccessResultInterface {
    if (!$this->testUsers->roleOf($account->id())) {
      return AccessResult::neutral();
    }
    $allowed = match ($operation) {
      // Also the test user itself, while someone masquerades as it.
      'view' => $this->testUsers->maySee($user) || $account->id() == $user->id(),
      'delete' => $user->hasPermission('delete masquerade test users'),
      // Nobody edits a test user.
      default => FALSE,
    };
    // Forbidden, not neutral: it overrides "Administer users".
    return ($allowed ? AccessResult::allowed() : AccessResult::forbidden())
      ->cachePerPermissions()
      ->cachePerUser()
      ->addCacheTags(['user:' . $account->id()]);
  }

  #[Hook('query_user_access_alter')]
  public function queryAlter(AlterableInterface $query): void {
    // People list, user autocompletes: only for users who may masquerade.
    $uids = $this->testUsers->all();
    if (!$uids || $this->testUsers->maySee($this->currentUser)) {
      return;
    }
    foreach ($query->getTables() as $alias => $table) {
      if (in_array($table['table'], ['users', 'users_field_data'], TRUE)) {
        $query->condition("$alias.uid", array_values($uids), 'NOT IN');
        return;
      }
    }
  }

  #[Hook('user_presave')]
  public function presave(UserInterface $account): void {
    if (!$account->isNew() && ($role = $this->testUsers->roleOf($account->id()))) {
      $this->testUsers->enforce($account, $role);
    }
  }

  #[Hook('user_delete')]
  public function delete(UserInterface $account): void {
    if ($role = $this->testUsers->roleOf($account->id())) {
      $this->testUsers->forget($role);
    }
  }

  #[Hook('user_role_delete')]
  public function roleDelete(RoleInterface $role): void {
    $this->testUsers->delete($role->id());
  }

  #[Hook('form_user_cancel_form_alter')]
  public function cancelFormAlter(array &$form, FormStateInterface $form_state): void {
    // Only deleting: a test user stays active as long as it exists.
    $account = $form_state->getFormObject()->getEntity();
    if (!$this->testUsers->roleOf($account->id()) || !isset($form['user_cancel_method'])) {
      return;
    }
    foreach (['user_cancel_block', 'user_cancel_block_unpublish'] as $method) {
      unset($form['user_cancel_method']['#options'][$method]);
    }
    $form['user_cancel_method']['#default_value'] = 'user_cancel_reassign';
    $form['user_cancel_method']['#access'] = TRUE;
    $form['user_cancel_confirm']['#access'] = FALSE;
    $form['user_cancel_notify']['#access'] = FALSE;
    $form['description']['#markup'] = $this->t('This test user is recreated the next time someone masquerades as its role.');
  }

}
