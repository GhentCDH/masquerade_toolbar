<?php

declare(strict_types=1);

namespace Drupal\masquerade_toolbar\Hook;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\masquerade_toolbar\NeverUp;
use Drupal\masquerade_toolbar\TestUsers;
use Drupal\user\RoleInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * The "Masquerade" tab of the toolbar: a menu with the roles, and
 * "Unmasquerade" first while masquerading; and a "Switch back" button for
 * users without the toolbar.
 */
final class MasqueradeToolbarHooks {

  use StringTranslationTrait;

  public function __construct(
    private readonly AccountInterface $currentUser,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly RequestStack $requestStack,
    private readonly TestUsers $testUsers,
    private readonly NeverUp $neverUp,
  ) {}

  #[Hook('toolbar')]
  public function toolbar(): array {
    $masquerading = $this->testUsers->isMasquerading();
    // Users who cannot masquerade get no tab; their toolbar keeps caching per
    // permissions. For the others, the tab differs per session: who
    // masquerades, and as which role.
    if (!$masquerading && !$this->testUsers->maySee($this->currentUser)) {
      $items['masquerade_toolbar'] = ['#cache' => ['contexts' => ['user.permissions', 'session.is_masquerading']]];
      return $items;
    }
    $items['masquerade_toolbar'] = ['#cache' => ['contexts' => ['user', 'session'], 'tags' => ['config:user_role_list']]];

    // The roles are those of the user who masquerades.
    $actor = $masquerading ? $this->testUsers->masquerader() : $this->currentUser;
    if (!$actor) {
      return $items;
    }
    $current_role = $masquerading ? $this->testUsers->roleOf($this->currentUser->id()) : NULL;
    $destination = ['query' => ['destination' => $this->requestUri()]];

    $links = [];
    if ($masquerading) {
      $back = $this->backLink();
      $links['unmasquerade'] = [
        'title' => $back['#title'],
        'url' => $back['#url'],
        'attributes' => ['class' => ['masquerade-toolbar__back']],
      ];
    }
    foreach ($this->entityTypeManager->getStorage('user_role')->loadMultiple() as $role) {
      if ($role->id() === RoleInterface::ANONYMOUS_ID || !$this->neverUp->allowsRole($actor, $role)) {
        continue;
      }
      $links[$role->id()] = [
        'title' => $role->label(),
        'url' => Url::fromRoute('masquerade_toolbar.role', ['user_role' => $role->id()], $destination),
        'attributes' => $role->id() === $current_role ? ['class' => ['is-active'], 'aria-current' => 'true'] : [],
      ];
    }
    if ($links) {
      // A menu under the button (not a toolbar tray across the screen).
      $items['masquerade_toolbar'] += [
        '#type' => 'toolbar_item',
        '#weight' => 3398,
        'tab' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['masquerade-toolbar-menu']],
          'toggle' => [
            '#type' => 'html_tag',
            '#tag' => 'button',
            '#value' => $this->t('Masquerade'),
            '#attributes' => [
              'type' => 'button',
              'class' => array_merge(['toolbar-icon', 'toolbar-icon-masquerade-toolbar', 'masquerade-toolbar-menu__toggle'], $masquerading ? ['is-masquerading'] : []),
              'title' => $this->t('See the site as a role'),
              'aria-expanded' => 'false',
              'aria-haspopup' => 'true',
            ],
          ],
          'links' => [
            '#theme' => 'links',
            '#links' => $links,
            '#attributes' => ['class' => ['masquerade-toolbar']],
          ],
        ],
        '#wrapper_attributes' => ['class' => ['masquerade-toolbar-tab']],
        '#attached' => ['library' => ['masquerade_toolbar/toolbar']],
      ];
    }
    return $items;
  }

  #[Hook('toolbar_alter')]
  public function toolbarAlter(array &$items): void {
    // "Unmasquerade" is in the Masquerade menu instead.
    unset($items['masquerade_switch_back']);
  }

  #[Hook('page_top')]
  public function pageTop(array &$page_top): void {
    // Users without the toolbar get the button on the page.
    $page_top['masquerade_toolbar'] = ['#cache' => ['contexts' => ['user.permissions', 'session.is_masquerading']]];
    if (!$this->testUsers->isMasquerading() || $this->currentUser->hasPermission('access toolbar')) {
      return;
    }
    $page_top['masquerade_toolbar'] += $this->backLink() + [
      '#attributes' => ['class' => ['masquerade-toolbar-back']],
      '#attached' => ['library' => ['masquerade_toolbar/toolbar']],
    ];
  }

  /**
   * Returns the link that switches back, to the same page.
   */
  private function backLink(): array {
    $masquerader = $this->testUsers->masquerader();
    return [
      '#type' => 'link',
      '#title' => $masquerader ? $this->t('Unmasquerade (@name)', ['@name' => $masquerader->getDisplayName()]) : $this->t('Unmasquerade'),
      '#url' => Url::fromRoute('masquerade.unmasquerade', [], ['query' => ['destination' => $this->requestUri()]]),
    ];
  }

  private function requestUri(): string {
    return $this->requestStack->getCurrentRequest()->getRequestUri();
  }

}
