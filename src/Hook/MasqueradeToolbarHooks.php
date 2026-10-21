<?php

declare(strict_types=1);

namespace Drupal\masquerade_toolbar\Hook;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\masquerade\Masquerade;
use Drupal\masquerade_toolbar\NeverUp;
use Drupal\user\RoleInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * The "Masquerade" tab of the toolbar, and a "Switch back" button for users
 * without the toolbar.
 */
final class MasqueradeToolbarHooks {

  use StringTranslationTrait;

  public function __construct(
    private readonly AccountInterface $currentUser,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly RequestStack $requestStack,
    private readonly Masquerade $masquerade,
    private readonly NeverUp $neverUp,
  ) {}

  #[Hook('toolbar')]
  public function toolbar(): array {
    $items['masquerade_toolbar'] = ['#cache' => ['contexts' => ['user.permissions', 'session.is_masquerading'], 'tags' => ['config:user_role_list']]];
    // While masquerading, Masquerade's own "Unmasquerade" tab switches back.
    if ($this->masquerade->isMasquerading()) {
      return $items;
    }
    $destination = ['query' => ['destination' => $this->requestStack->getCurrentRequest()->getRequestUri()]];
    $links = [];
    foreach ($this->entityTypeManager->getStorage('user_role')->loadMultiple() as $role) {
      if ($role->id() === RoleInterface::ANONYMOUS_ID || !$this->mayMasqueradeAs($role)) {
        continue;
      }
      $links[$role->id()] = [
        'title' => $role->label(),
        'url' => Url::fromRoute('masquerade_toolbar.role', ['user_role' => $role->id()], $destination),
      ];
    }
    $other = Url::fromRoute('masquerade.block');
    if (!$links && !$other->access()) {
      return $items;
    }
    if ($other->access()) {
      $links['other'] = [
        'title' => $this->t('Another user…'),
        'url' => $other,
        'attributes' => ['class' => ['masquerade-toolbar__other']],
      ];
    }
    $items['masquerade_toolbar'] += [
      '#type' => 'toolbar_item',
      '#weight' => 3398,
      'tab' => [
        '#type' => 'link',
        '#title' => $this->t('Masquerade'),
        '#url' => $other,
        '#attributes' => ['title' => $this->t('See the site as a role or another user'), 'class' => ['toolbar-icon', 'toolbar-icon-masquerade-toolbar']],
      ],
      'tray' => [
        '#heading' => $this->t('Masquerade'),
        'links' => [
          '#theme' => 'links',
          '#links' => $links,
          '#attributes' => ['class' => ['masquerade-toolbar']],
        ],
      ],
      '#wrapper_attributes' => ['class' => ['masquerade-toolbar-tab']],
      '#attached' => ['library' => ['masquerade_toolbar/toolbar']],
    ];
    return $items;
  }

  #[Hook('toolbar_alter')]
  public function toolbarAlter(array &$items): void {
    // Masquerade's "Unmasquerade" tab: stay on the same page.
    if (isset($items['masquerade_switch_back']['tab']['#url'])) {
      $items['masquerade_switch_back']['tab']['#url']->setOption('query', ['destination' => $this->requestStack->getCurrentRequest()->getRequestUri()]);
      $items['masquerade_switch_back']['#cache']['contexts'][] = 'url';
    }
  }

  #[Hook('page_top')]
  public function pageTop(array &$page_top): void {
    // Users without the toolbar (and so without "Unmasquerade") get a button.
    $page_top['masquerade_toolbar'] = ['#cache' => ['contexts' => ['user.permissions', 'session.is_masquerading']]];
    if (!$this->masquerade->isMasquerading() || $this->currentUser->hasPermission('access toolbar')) {
      return;
    }
    $page_top['masquerade_toolbar'] += [
      '#type' => 'link',
      '#title' => $this->t('Switch back'),
      '#url' => Url::fromRoute('masquerade.unmasquerade', [], ['query' => ['destination' => $this->requestStack->getCurrentRequest()->getRequestUri()]]),
      '#attributes' => ['class' => ['masquerade-toolbar-back']],
      '#attached' => ['library' => ['masquerade_toolbar/toolbar']],
    ];
  }

  /**
   * Whether the current user may masquerade as users with (only) this role:
   * Masquerade's permission, and never up.
   */
  private function mayMasqueradeAs(RoleInterface $role): bool {
    $permitted = $this->currentUser->id() == 1
      || $this->currentUser->hasPermission('masquerade as any user')
      || $this->currentUser->hasPermission('masquerade as ' . $role->id());
    return $permitted && $this->neverUp->allows($this->currentUser, [$role->id()]);
  }

}
