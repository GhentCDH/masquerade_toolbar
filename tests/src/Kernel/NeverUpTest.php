<?php

declare(strict_types=1);

namespace Drupal\Tests\masquerade_toolbar\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\masquerade_toolbar\NeverUp;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\Entity\Role;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Masquerading never goes up, and never to an administrator role.
 */
#[Group('masquerade_toolbar')]
#[RunTestsInSeparateProcesses]
final class NeverUpTest extends KernelTestBase {

  use UserCreationTrait;

  protected static $modules = ['system', 'user', 'masquerade', 'masquerade_toolbar'];

  private NeverUp $neverUp;

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installSchema('user', ['users_data']);
    // User 1 may do everything: keep it out of the way.
    $this->createUser();
    $this->createRole(['access content'], 'editor', 'Editor');
    $this->createRole(['access content', 'administer users'], 'manager', 'Manager');
    Role::create(['id' => 'boss', 'label' => 'Boss', 'is_admin' => TRUE])->save();
    $this->neverUp = $this->container->get(NeverUp::class);
  }

  public function testNeverUp(): void {
    $manager = $this->createUser([], NULL, FALSE, ['roles' => ['manager']]);
    $editor = $this->createUser([], NULL, FALSE, ['roles' => ['editor']]);
    $this->assertTrue($this->neverUp->allows($manager, ['editor']), 'Down: a manager has all permissions of an editor.');
    $this->assertTrue($this->neverUp->allows($manager, []), 'Down: to only authenticated.');
    $this->assertFalse($this->neverUp->allows($editor, ['manager']), 'Up: an editor lacks "administer users".');
    $this->assertFalse($this->neverUp->allows($editor, ['editor', 'manager']), 'Up: any role with more permissions.');
  }

  public function testNeverToAnAdministratorRole(): void {
    $boss = $this->createUser([], NULL, FALSE, ['roles' => ['boss']]);
    $this->assertTrue($this->neverUp->allows($boss, ['manager']), 'An administrator may masquerade as any other role.');
    $this->assertFalse($this->neverUp->allows($boss, ['boss']), 'Not even an administrator, to an administrator role.');
    $this->assertFalse($this->neverUp->allows($boss, ['editor', 'boss']));
  }

  public function testRolePermission(): void {
    $manager = $this->createUser([], NULL, FALSE, ['roles' => ['manager']]);
    $this->assertFalse($this->neverUp->allowsRole($manager, Role::load('editor')), 'Masquerade\'s permission is needed.');

    Role::load('manager')->grantPermission('masquerade as editor')->save();
    $manager = $this->reloadUser($manager);
    $this->assertTrue($this->neverUp->allowsRole($manager, Role::load('editor')));

    Role::load('editor')->grantPermission('masquerade as manager')->save();
    $editor = $this->createUser([], NULL, FALSE, ['roles' => ['editor']]);
    $this->assertFalse($this->neverUp->allowsRole($editor, Role::load('manager')), 'Masquerade\'s permission does not allow going up.');

    Role::load('manager')->grantPermission('masquerade as any user')->save();
    $manager = $this->reloadUser($manager);
    $this->assertFalse($this->neverUp->allowsRole($manager, Role::load('boss')), '"Masquerade as any user" does not allow an administrator role.');
  }

  private function reloadUser($account) {
    $this->container->get('entity_type.manager')->getStorage('user')->resetCache();
    return $this->container->get('entity_type.manager')->getStorage('user')->load($account->id());
  }

}
