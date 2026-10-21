<?php

declare(strict_types=1);

namespace Drupal\Tests\masquerade_toolbar\Functional;

use Drupal\masquerade_toolbar\TestUsers;
use Drupal\Tests\BrowserTestBase;
use Drupal\user\Entity\Role;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The Masquerade menu in the toolbar.
 */
#[Group('masquerade_toolbar')]
#[RunTestsInSeparateProcesses]
final class MasqueradeToolbarTest extends BrowserTestBase {

  protected static $modules = ['masquerade_toolbar', 'node'];

  protected $defaultTheme = 'stark';

  protected function setUp(): void {
    parent::setUp();
    $this->createRole(['access toolbar', 'access content'], 'editor', 'Editor');
    // Before the manager: "Masquerade as Boss" exists once Boss does.
    $this->createRole(['access toolbar', 'access content', 'administer users', 'administer site configuration'], 'boss', 'Boss');
    $this->createRole(['access toolbar', 'access content', 'administer users', 'masquerade as editor', 'masquerade as boss'], 'manager', 'Manager');
  }

  /**
   * Returns the labels in the Masquerade menu.
   */
  private function menu(): array {
    return array_map(fn($link) => trim($link->getText()), $this->getSession()->getPage()->findAll('css', 'ul.masquerade-toolbar a'));
  }

  private function assertLoggedInAs(string $name): void {
    $this->assertSession()->elementTextContains('css', '#toolbar-item-user', $name);
  }

  public function testSwitching(): void {
    $admin = $this->createUser([], 'admin_user', TRUE);
    $this->drupalLogin($admin);
    $this->drupalGet('user/' . $admin->id());
    $this->assertSession()->elementExists('css', '.masquerade-toolbar-menu__toggle');
    $this->assertSame(['Authenticated user', 'Editor', 'Boss', 'Manager'], $this->menu(), 'The roles, not the administrator role, and no Unmasquerade.');

    $this->clickLink('Editor');
    $this->assertLoggedInAs('test_editor');
    $this->assertSession()->addressEquals('user/' . $admin->id());
    $this->assertSame('Unmasquerade (admin_user)', $this->menu()[0], 'Unmasquerade first in the menu.');
    $this->assertSession()->elementTextEquals('css', 'ul.masquerade-toolbar a.is-active', 'Editor');
    $this->assertSession()->elementExists('css', '.masquerade-toolbar-menu__toggle.is-masquerading');

    // Another role directly; the roles are those of the admin.
    $this->clickLink('Manager');
    $this->assertLoggedInAs('test_manager');

    $this->clickLink('Unmasquerade (admin_user)');
    $this->assertLoggedInAs('admin_user');
    $this->assertSession()->addressEquals('user/' . $admin->id());
    $this->assertNotContains('Unmasquerade (admin_user)', $this->menu());
  }

  public function testNeverUp(): void {
    $manager = $this->createUser([], 'a_manager', FALSE, ['roles' => ['manager']]);
    $this->drupalLogin($manager);
    $this->drupalGet('<front>');
    $this->assertSame(['Editor'], $this->menu(), 'Not Boss: it has a permission the manager lacks.');
  }

  public function testNoMenuWithoutPermission(): void {
    $this->drupalLogin($this->createUser([], NULL, FALSE, ['roles' => ['editor']]));
    $this->drupalGet('<front>');
    $this->assertSession()->elementNotExists('css', '.masquerade-toolbar-menu');
  }

  public function testNoLoginOtherThanMasquerading(): void {
    $test_users = $this->container->get(TestUsers::class);
    $account = $test_users->forRole(Role::load('editor'));
    $test_users->save($account, Role::load('editor'));

    $this->drupalGet(user_pass_reset_url($account) . '/login');
    $this->drupalGet('user/login');
    $this->assertSession()->fieldExists('name');
    $this->assertSession()->pageTextNotContains('test_editor');
  }

}
