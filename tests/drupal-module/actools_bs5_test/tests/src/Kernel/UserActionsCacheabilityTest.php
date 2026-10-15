<?php

declare(strict_types=1);

namespace Drupal\Tests\actools_bs5_test\Kernel;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Drupal\Core\Entity\Entity\EntityViewMode;
use Drupal\KernelTests\KernelTestBase;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests viewer-dependent profile actions through Drupal's entity render cache.
 */
#[Group('actools_bs5')]
final class UserActionsCacheabilityTest extends KernelTestBase {

  protected static $modules = ['system', 'user', 'file', 'field'];

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installSchema('user', ['users_data']);
    $this->installConfig(['system', 'user']);
    $this->container->get('theme_installer')->install(['actools_bs5']);
    $this->config('system.theme')->set('default', 'actools_bs5')->save();
    $active = $this->container->get('theme.initialization')->getActiveThemeByName('actools_bs5');
    $this->container->get('theme.manager')->setActiveTheme($active);
    $theme_path = $this->container->get('extension.list.theme')->getPath('actools_bs5');
    require_once DRUPAL_ROOT . '/' . $theme_path . '/actools_bs5.theme';

    // Make both view modes cacheable and deliberately display no fields. This
    // exercises the denied/empty-content path, where action metadata must still
    // reach the parent user render array through the template.
    foreach (['full', 'compact'] as $mode) {
      $view_mode = EntityViewMode::load('user.' . $mode) ?? EntityViewMode::create([
        'id' => 'user.' . $mode,
        'targetEntityType' => 'user',
        'label' => ucfirst($mode),
      ]);
      $view_mode->set('cache', TRUE)->save();
      EntityViewDisplay::create([
        'targetEntityType' => 'user',
        'bundle' => 'user',
        'mode' => $mode,
        'status' => TRUE,
        'content' => [],
        'hidden' => ['member_for' => TRUE],
      ])->save();
    }
    $this->container->get('router.builder')->rebuild();
  }

  /**
   * Both warm-cache orders preserve the correct actions for every viewer.
   */
  public function testProfileActionsVaryByViewerAcrossWarmRenderCache(): void {
    $outsider = $this->createUser(3, 'outsider');
    $admin_role = Role::create(['id' => 'profile_administrator', 'label' => 'Profile administrator']);
    $admin_role->grantPermission('administer users')->save();
    $admin = $this->createUser(4, 'administrator', ['profile_administrator']);
    $owners = [$this->createUser(2, 'owner_first'), $this->createUser(5, 'denied_first')];
    $orders = [
      ['owner', 'outsider', 'admin', 'owner', 'outsider'],
      ['outsider', 'owner', 'admin', 'outsider', 'owner'],
    ];

    foreach ($owners as $index => $owner) {
      $viewers = ['owner' => $owner, 'outsider' => $outsider, 'admin' => $admin];
      foreach ($orders[$index] as $viewer_name) {
        [$html, $cache] = $this->renderUserAs($owner, $viewers[$viewer_name], 'full');
        $this->assertNotSame(0, $cache['max-age'], 'The regression must exercise cacheable entity output.');
        if ($viewer_name === 'outsider') {
          $this->assertStringNotContainsString('user__action-edit', $html);
          $this->assertStringNotContainsString('user__action-logout', $html);
          $this->assertStringNotContainsString('user__actions', $html);
        }
        else {
          $this->assertStringContainsString('user__action-edit', $html);
          $this->assertStringContainsString('user__action-logout', $html);
        }
        if ($viewer_name !== 'admin') {
          $this->assertContains('user', $cache['contexts'], 'Owner-dependent access metadata bubbles even when access is denied.');
        }
      }
    }
  }

  /**
   * Access metadata merges with pre-existing field cacheability on both paths.
   */
  public function testAccessMetadataPreservesExistingContentCacheability(): void {
    $owner = $this->createUser(2, 'owner');
    $outsider = $this->createUser(3, 'outsider');
    foreach ([$owner, $outsider] as $viewer) {
      $variables = [
        'user' => $owner,
        'elements' => ['#view_mode' => 'full'],
        'content' => ['#cache' => [
          'contexts' => ['url.query_args:profile-test'],
          'tags' => ['profile-test:retained'],
          'max-age' => 600,
        ]],
      ];
      $switcher = $this->container->get('account_switcher');
      $switcher->switchTo($viewer);
      try {
        actools_bs5_preprocess_user($variables);
      }
      finally {
        $switcher->switchBack();
      }
      $metadata = CacheableMetadata::createFromRenderArray($variables['content']);
      $this->assertContains('url.query_args:profile-test', $metadata->getCacheContexts());
      $this->assertContains('user', $metadata->getCacheContexts());
      $this->assertContains('profile-test:retained', $metadata->getCacheTags());
      $this->assertSame(600, $metadata->getCacheMaxAge());
    }
  }

  /**
   * Compact author pictures remain free of private actions for every viewer.
   */
  public function testCompactViewsRemainShareableAndOmitActions(): void {
    $owner = $this->createUser(2, 'owner');
    $outsider = $this->createUser(3, 'outsider');
    foreach ([$owner, $outsider, $owner] as $viewer) {
      [$html, $cache] = $this->renderUserAs($owner, $viewer, 'compact');
      $this->assertStringContainsString('user--compact', $html);
      $this->assertStringNotContainsString('user__actions', $html);
      $this->assertStringNotContainsString('user__name', $html);
      $this->assertNotContains('user', $cache['contexts'], 'Hidden profile actions must not personalize author-picture caching.');
    }
  }

  private function createUser(int $uid, string $name, array $roles = []): UserInterface {
    $user = User::create([
      'uid' => $uid,
      'name' => $name,
      'mail' => $name . '@example.com',
      'status' => 1,
      'roles' => $roles,
    ]);
    $user->save();
    return $user;
  }

  /**
   * Render a fresh build without flushing the entity render cache between users.
   *
   * @return array{0: string, 1: array}
   *   Rendered HTML and its bubbled cache metadata.
   */
  private function renderUserAs(UserInterface $profile, UserInterface $viewer, string $view_mode): array {
    $switcher = $this->container->get('account_switcher');
    $switcher->switchTo($viewer);
    try {
      $build = $this->container->get('entity_type.manager')->getViewBuilder('user')->view($profile, $view_mode);
      // Cache hits replace the render array with a payload that omits its
      // original keys. Verify caching is enabled before invoking the renderer.
      $this->assertSame(['entity_view', 'user', $profile->id(), $view_mode], $build['#cache']['keys']);
      $html = (string) $this->container->get('renderer')->renderRoot($build);
      return [$html, $build['#cache']];
    }
    finally {
      $switcher->switchBack();
    }
  }

}
