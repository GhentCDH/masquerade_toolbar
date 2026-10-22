<?php

declare(strict_types=1);

namespace Drupal\Tests\masquerade_toolbar\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\masquerade_toolbar\TestUsers;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The test users exist only for masquerading.
 */
#[Group('masquerade_toolbar')]
#[RunTestsInSeparateProcesses]
final class TestUsersTest extends KernelTestBase {

  use UserCreationTrait;

  protected static $modules = ['system', 'user', 'node', 'field', 'text', 'filter', 'masquerade', 'masquerade_toolbar'];

  private TestUsers $testUsers;

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installSchema('user', ['users_data']);
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['filter', 'node']);
    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();
    // User 1 may do everything: keep it out of the way.
    $this->createUser();
    $this->createRole(['access content'], 'editor', 'Editor');
    Role::create(['id' => 'boss', 'label' => 'Boss', 'is_admin' => TRUE])->save();
    $this->testUsers = $this->container->get(TestUsers::class);
  }

  /**
   * Returns the saved test user of the editor role.
   */
  private function editorTestUser(): User {
    $account = $this->testUsers->forRole(Role::load('editor'));
    if ($account->isNew()) {
      $this->testUsers->save($account, Role::load('editor'));
    }
    return User::load($account->id());
  }

  public function testOneRoleAlways(): void {
    $new = $this->testUsers->forRole(Role::load('editor'));
    $this->assertTrue($new->isNew(), 'Created only when needed, not saved yet.');
    $this->assertSame('test_editor', $new->getAccountName());
    $this->assertSame(['editor'], $new->getRoles(TRUE));

    $account = $this->editorTestUser();
    $this->assertSame('editor', $this->testUsers->roleOf($account->id()));
    $this->assertSame($account->id(), $this->testUsers->forRole(Role::load('editor'))->id(), 'The same test user the next time.');

    // Whatever changes it (a form, Drush, a bulk action): exactly its role,
    // and active.
    $account->addRole('boss')->block()->save();
    $account = User::load($account->id());
    $this->assertSame(['editor'], $account->getRoles(TRUE));
    $this->assertTrue($account->isActive());
  }

  public function testRecognisedByIdNotByName(): void {
    $impostor = $this->createUser([], 'test_editor');
    $this->assertNull($this->testUsers->roleOf($impostor->id()), 'An account named like a test user is not one.');
    $this->assertSame('test_editor_2', $this->testUsers->forRole(Role::load('editor'))->getAccountName());
  }

  public function testAccess(): void {
    $account = $this->editorTestUser();
    $user_admin = $this->createUser(['administer users']);
    $masquerader = $this->createUser(['masquerade as editor']);
    $deleter = $this->createUser(['administer users', 'delete masquerade test users']);

    $this->assertFalse($account->access('update', $user_admin), 'Nobody edits a test user, "Administer users" neither.');
    $this->assertFalse($account->access('view', $user_admin), 'Only who may masquerade sees it.');
    $this->assertTrue($account->access('view', $masquerader));
    $this->assertFalse($account->access('delete', $user_admin));
    $this->assertTrue($account->access('delete', $deleter), 'Deleting needs its own permission.');
    $this->assertFalse($account->access('view label', $user_admin));
    $this->assertTrue($account->access('view label', $account), 'While masquerading, as the author of content.');
    $this->assertFalse($account->access('update', $account));
  }

  public function testHiddenFromLists(): void {
    $account = $this->editorTestUser();
    $query = fn() => $this->container->get('entity_type.manager')->getStorage('user')->getQuery()->accessCheck(TRUE)->execute();

    $this->setCurrentUser($this->createUser(['administer users']));
    $this->assertNotContains($account->id(), array_values($query()));

    $this->setCurrentUser($this->createUser(['administer users', 'masquerade as editor']));
    $this->assertContains($account->id(), array_values($query()));

    // While masquerading: itself, as the author of what it saves.
    $writer = $this->createRole([], 'writer', 'Writer');
    $other = $this->testUsers->forRole(Role::load($writer));
    $this->testUsers->save($other, Role::load($writer));
    $this->setCurrentUser($account);
    $this->assertContains($account->id(), array_values($query()));
    $this->assertNotContains($other->id(), array_values($query()));
  }

  public function testDeletingARoleDeletesItsTestUser(): void {
    $account = $this->editorTestUser();
    Role::load('editor')->delete();
    $this->assertNull(User::load($account->id()));
    $this->assertSame([], $this->testUsers->all());
  }

  public function testUninstallDeletesTestUsersButKeepsContent(): void {
    $account = $this->editorTestUser();
    $node = Node::create(['type' => 'page', 'title' => 'Made while masquerading', 'uid' => $account->id()]);
    $node->save();

    $this->container->get('module_installer')->uninstall(['masquerade_toolbar']);

    $this->assertNull(User::load($account->id()));
    $this->container->get('entity_type.manager')->getStorage('node')->resetCache();
    $node = Node::load($node->id());
    $this->assertNotNull($node, 'The content stays.');
    $this->assertSame(0, (int) $node->getOwnerId(), 'It belongs to Anonymous.');
  }

}
