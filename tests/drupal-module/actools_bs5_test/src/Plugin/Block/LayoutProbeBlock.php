<?php

declare(strict_types=1);

namespace Drupal\actools_bs5_test\Plugin\Block;

use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Test-only block used to exercise core Layout Builder section rendering.
 */
#[Block(
  id: 'actools_bs5_layout_probe',
  admin_label: new TranslatableMarkup('Actools layout test probe'),
)]
final class LayoutProbeBlock extends BlockBase {

  public function defaultConfiguration(): array {
    return ['probe_text' => 'Layout Builder probe'] + parent::defaultConfiguration();
  }

  public function build(): array {
    return [
      '#plain_text' => $this->configuration['probe_text'],
      '#attributes' => ['data-actools-block-probe' => $this->configuration['probe_text']],
    ];
  }

  public function getCacheContexts(): array {
    return ['url.path'];
  }

  public function getCacheTags(): array {
    return ['actools_bs5_test:layout_probe'];
  }

  public function getCacheMaxAge(): int {
    return 3600;
  }

}
