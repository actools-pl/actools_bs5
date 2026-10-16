<?php

declare(strict_types=1);

namespace Drupal\actools_download_test\Render;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Security\TrustedCallbackInterface;

/**
 * A deferred decision made by core before a file field/item/link is rendered.
 */
final class DownloadAccessProbe implements TrustedCallbackInterface {

  public static function trustedCallbacks(): array {
    return ['access'];
  }

  public static function access(array $element): AccessResultInterface|bool {
    $mode = $element['#actools_download_access_mode'];
    if ($mode === 'boolean_denied') {
      return FALSE;
    }
    $access = match ($mode) {
      'viewer' => AccessResult::allowedIf((int) \Drupal::currentUser()->id() === 2)->cachePerUser(),
      'allowed' => AccessResult::allowed(),
      'neutral' => AccessResult::neutral(),
      default => AccessResult::forbidden(),
    };
    return $access->addCacheTags(['actools_download_access:' . $mode]);
  }

}
