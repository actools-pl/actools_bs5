<?php

/**
 * @file
 * theme-settings.php
 *
 * Provides theme settings for Actools BS5
 */

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\Core\Link;

/**
 * Implements hook_form_FORM_ID_alter().
 */
function actools_bs5_form_system_theme_settings_alter(&$form, FormStateInterface $form_state, $form_id = NULL) {

  // General "alters" use a form id. Settings should not be set here. The only
  // thing useful about this is if you need to alter the form for the running
  // theme and *not* the theme setting.
  // @see http://drupal.org/node/943212
  if (isset($form_id)) {
    return;
  }

  // Theme settings provider (replaces the deprecated theme_get_setting()).
  $tsp = \Drupal::service('Drupal\Core\Extension\ThemeSettingsProvider');
  $theme_key = $form_state->getBuildInfo()['args'][0] ?? 'actools_bs5';

  // Bootstrap 5 Responsive containers options.
  $container_options = [
    '' => t('- none -'),
    'no-container' => t('No Container'),
    'container' => t('Container'),
    'container-md' => t('Container Medium'),
    'container-lg' => t('Container Large'),
    'container-xl' => t('Container Extra Large'),
    'container-xxl' => t('Container Extra Extra Large'),
    'container-fluid' => t('Container Fluid'),
  ];

  // Containers configurations.
  $form['containers'] = [
    '#type' => 'details',
    '#title' => t('Containers'),
    '#collapsible' => TRUE,
    '#collapsed' => TRUE,
    '#description' => t('Containers are a fundamental building block of Bootstrap that contain, pad, and align your content within a given device or viewport. Have a look at @containers_link.', [
      '@containers_link' => Link::fromTextAndUrl('Containers in the Bootstrap 5 documentation', Url::fromUri('https://getbootstrap.com/docs/5.3/layout/containers/#responsive-containers', ['absolute' => TRUE, 'fragment' => 'containers']))->toString(),
    ]),
  ];

  $actools_bs5_contained_regions = [
    'content' => t('Content'),
    'header' => t('Header'),
    'footer' => t('Footer '),
  ];

  foreach ($actools_bs5_contained_regions as $contained_region_name => $contained_region_title) {
    $contained_region_container_setting_name = 'container_' . $contained_region_name;
    $contained_region_container_setting_title = t('Container for') . ' ' . $contained_region_title . ' ' . t('region');
    $form['containers'][$contained_region_container_setting_name] = [
      '#type' => 'select',
      '#title' => $contained_region_container_setting_title,
      '#default_value' => $tsp->getSetting($contained_region_container_setting_name, $theme_key) ?? 'container',
      '#options' => $container_options,
    ];
  }

  // Header configurations.
  $form['header'] = [
    '#type' => 'details',
    '#title' => t('Header'),
    '#collapsible' => TRUE,
    '#collapsed' => TRUE,
  ];

  $form['header']['sticky_header'] = [
    '#type' => 'checkbox',
    '#title' => t('Sticky header'),
    '#description' => t('Keep the header pinned to the top of the viewport as the page scrolls. When enabled, the header clears the Drupal admin toolbar and picks up a drop shadow once the page is scrolled.'),
    '#default_value' => $tsp->getSetting('sticky_header', $theme_key) ?? TRUE,
  ];

  // Design tokens.
  $token_defaults = [
    'tokens_brand_color' => '#0d6efd',
    'tokens_body_font' => 'figtree',
    'tokens_base_font_size' => '18',
    'tokens_corner_radius' => 'default',
  ];
  $form['design_tokens'] = [
    '#type' => 'details',
    '#title' => t('Design tokens'),
    '#collapsible' => TRUE,
    '#collapsed' => TRUE,
    '#description' => t('Brand colour, fonts and shape, applied through Bootstrap 5 CSS custom properties. Changes are written to an external stylesheet (never inlined), so they stay compatible with a strict Content-Security-Policy. Leave a control at its default to keep the theme’s built-in value.'),
  ];
  $form['design_tokens']['tokens_brand_color'] = [
    '#type' => 'color',
    '#title' => t('Brand colour'),
    '#default_value' => $tsp->getSetting('tokens_brand_color', $theme_key) ?: $token_defaults['tokens_brand_color'],
    '#description' => t('Primary colour for buttons and primary-styled elements. Link colours are adjusted for contrast on the standard light and dark page backgrounds.'),
  ];
  $form['design_tokens']['tokens_body_font'] = [
    '#type' => 'select',
    '#title' => t('Body font'),
    '#options' => [
      'figtree' => t('Figtree (bundled)'),
      'system' => t('System sans-serif'),
      'serif' => t('Serif'),
    ],
    '#default_value' => $tsp->getSetting('tokens_body_font', $theme_key) ?: $token_defaults['tokens_body_font'],
  ];
  $form['design_tokens']['tokens_base_font_size'] = [
    '#type' => 'select',
    '#title' => t('Base font size'),
    '#options' => [
      '16' => t('16px'),
      '17' => t('17px'),
      '18' => t('18px (default)'),
      '19' => t('19px'),
      '20' => t('20px'),
    ],
    '#default_value' => (string) ($tsp->getSetting('tokens_base_font_size', $theme_key) ?: $token_defaults['tokens_base_font_size']),
    '#description' => t('Changes the body text size. Heading sizes and spacing retain their own rem-based scale.'),
  ];
  $form['design_tokens']['tokens_corner_radius'] = [
    '#type' => 'select',
    '#title' => t('Corner radius'),
    '#options' => [
      'sharp' => t('Sharp (0)'),
      'subtle' => t('Subtle'),
      'default' => t('Default'),
      'rounded' => t('Rounded'),
    ],
    '#default_value' => $tsp->getSetting('tokens_corner_radius', $theme_key) ?: $token_defaults['tokens_corner_radius'],
    '#description' => t('Border radius for buttons, cards, inputs and other components.'),
  ];

  // Regenerate the design-token stylesheet as soon as settings are saved
  // (see actools_bs5_tokens_settings_submit() in actools_bs5.theme), so it is
  // current immediately rather than on the next front-end page request. Core's
  // ThemeSettingsForm require_once's the .theme file, so the handler is defined;
  // the guard keeps this safe even if that ever changes.
  if (function_exists('actools_bs5_tokens_settings_submit')) {
    $form['#submit'][] = 'actools_bs5_tokens_settings_submit';
  }

}
