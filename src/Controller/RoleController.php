<?php

declare(strict_types=1);

namespace Drupal\masquerade_toolbar\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\masquerade_toolbar\TestUsers;
use Drupal\user\RoleInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Masquerades as a role, as its test user (created the first time).
 */
final class RoleController extends ControllerBase {

  public function __construct(private readonly TestUsers $testUsers) {}

  public static function create(ContainerInterface $container): self {
    return new self($container->get(TestUsers::class));
  }

  public function switchTo(RoleInterface $user_role): RedirectResponse {
    // Administrator roles have no test user.
    if ($user_role->isAdmin() || $user_role->id() === RoleInterface::ANONYMOUS_ID) {
      throw new AccessDeniedHttpException();
    }
    $account = $this->testUsers->forRole($user_role);
    // Masquerade's own checks (permissions, maintenance mode), before a new
    // test user is saved.
    if ($error = masquerade_switch_user_validate($account)) {
      $this->messenger()->addError($error);
    }
    else {
      if ($account->isNew()) {
        $this->testUsers->save($account, $user_role);
      }
      \Drupal::service('masquerade')->switchTo($account);
      $this->messenger()->addStatus($this->t('You are now seeing the site as %role.', ['%role' => $user_role->label()]));
    }
    return new RedirectResponse($this->getRedirectDestination()->get());
  }

}
