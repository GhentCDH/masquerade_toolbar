<?php

declare(strict_types=1);

namespace Drupal\masquerade_toolbar\Controller;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Session\AccountInterface;
use Drupal\masquerade\Masquerade;
use Drupal\masquerade_toolbar\NeverUp;
use Drupal\masquerade_toolbar\TestUsers;
use Drupal\user\RoleInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Masquerades as a role, as its test user (created the first time).
 *
 * While masquerading already, it switches back first: the user who
 * masquerades decides, not the test user.
 */
final class RoleController extends ControllerBase {

  public function __construct(
    private readonly TestUsers $testUsers,
    private readonly NeverUp $neverUp,
    private readonly Masquerade $masquerade,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self($container->get(TestUsers::class), $container->get(NeverUp::class), $container->get('masquerade'));
  }

  /**
   * Access: users who may masquerade, or a session that masquerades.
   */
  public static function access(AccountInterface $account): AccessResultInterface {
    $test_users = \Drupal::service(TestUsers::class);
    return AccessResult::allowedIf($test_users->isMasquerading() || $test_users->maySee($account))->setCacheMaxAge(0);
  }

  public function switchTo(RoleInterface $user_role): RedirectResponse {
    $response = new RedirectResponse($this->getRedirectDestination()->get());
    // Administrator roles have no test user.
    if ($user_role->isAdmin() || $user_role->id() === RoleInterface::ANONYMOUS_ID) {
      throw new AccessDeniedHttpException();
    }
    if ($this->masquerade->isMasquerading()) {
      if ($this->testUsers->roleOf($this->currentUser()->id()) === $user_role->id()) {
        return $response;
      }
      $masquerader = $this->testUsers->masquerader();
      if (!$masquerader || !$this->neverUp->allowsRole($masquerader, $user_role)) {
        throw new AccessDeniedHttpException();
      }
      $this->masquerade->switchBack();
    }
    $account = $this->testUsers->forRole($user_role);
    // Masquerade's own checks (permissions, maintenance mode), before a new
    // test user is saved.
    if ($error = masquerade_switch_user_validate($account)) {
      $this->messenger()->addError($error);
      return $response;
    }
    if ($account->isNew()) {
      $this->testUsers->save($account, $user_role);
    }
    $this->masquerade->switchTo($account);
    $this->messenger()->addStatus($this->t('You are now seeing the site as %role.', ['%role' => $user_role->label()]));
    return $response;
  }

}
