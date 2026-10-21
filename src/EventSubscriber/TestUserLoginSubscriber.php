<?php

declare(strict_types=1);

namespace Drupal\masquerade_toolbar\EventSubscriber;

use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\masquerade_toolbar\TestUsers;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Logs out a test user who is not masqueraded as.
 *
 * Test users can only be used by masquerading. Any other way in (a
 * password, a one-time login link) ends at the next request.
 */
final class TestUserLoginSubscriber implements EventSubscriberInterface {

  use StringTranslationTrait;

  public function __construct(
    private readonly AccountInterface $currentUser,
    private readonly TestUsers $testUsers,
    private readonly MessengerInterface $messenger,
  ) {}

  public static function getSubscribedEvents(): array {
    // After authentication (300).
    return [KernelEvents::REQUEST => ['onRequest', 290]];
  }

  public function onRequest(RequestEvent $event): void {
    if (!$event->isMainRequest() || !$this->testUsers->roleOf($this->currentUser->id()) || $this->testUsers->isMasquerading()) {
      return;
    }
    user_logout();
    $this->messenger->addError($this->t('This is a test user for masquerading; it cannot log in.'));
    $event->setResponse(new RedirectResponse(Url::fromRoute('<front>')->toString()));
  }

}
