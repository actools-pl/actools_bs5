#!/usr/bin/env python3
"""Check native layout registration and editor-region contracts without Drupal.

This is a static regression check, not a Layout Builder browser/runtime test.
Pass an alternate theme root as the first argument to inspect an extracted pack.
"""

from pathlib import Path
import re
import sys
import unittest

import yaml


THEME_ROOT = Path(sys.argv.pop(1)).resolve() if len(sys.argv) > 1 and not sys.argv[1].startswith('-') else Path(__file__).resolve().parents[2]
EXPECTED = {
    'actools_bs5_onecol_contained': ['content'],
    'actools_bs5_onecol_full': ['content'],
    'actools_bs5_twocol_equal': ['first', 'second'],
    'actools_bs5_twocol_narrow_wide': ['first', 'second'],
    'actools_bs5_twocol_wide_narrow': ['first', 'second'],
    'actools_bs5_threecol_equal': ['first', 'second', 'third'],
}


class LayoutContractsTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.layouts = yaml.safe_load((THEME_ROOT / 'actools_bs5.layouts.yml').read_text())

    def test_registered_layouts_and_assets(self):
        self.assertEqual(set(EXPECTED), set(self.layouts))
        libraries = yaml.safe_load((THEME_ROOT / 'actools_bs5.libraries.yml').read_text())
        self.assertIn('layout-builder', libraries)
        styles = libraries['layout-builder']['css']
        assets = [asset for group in styles.values() for asset in group]
        self.assertIn('css/layout/builder.layout.css', assets)
        for asset in assets:
            self.assertTrue((THEME_ROOT / asset).is_file(), asset)

    def test_every_region_retains_its_render_array_and_editor_attributes(self):
        for layout_id, regions in EXPECTED.items():
            with self.subTest(layout=layout_id):
                definition = self.layouts[layout_id]
                self.assertEqual(regions, list(definition['regions']))
                self.assertIn(definition['default_region'], regions)
                self.assertEqual('actools_bs5/layout-builder', definition['library'])
                mapped = {region for row in definition['icon_map'] for region in row}
                self.assertEqual(set(regions), mapped)
                template = THEME_ROOT / (definition['template'] + '.html.twig')
                source = template.read_text()
                self.assertIn('attributes.addClass(', source)
                self.assertIn("'actools-layout'", source)
                for region in regions:
                    self.assertIn('region_attributes.' + region + '.addClass(', source)
                    self.assertRegex(source, r'\{\{\s*content\.' + region + r'\s*\}\}')
                # Never convert render arrays into raw component strings.
                self.assertNotIn('|raw', source)

    def test_styles_stack_columns_and_do_not_break_out_of_the_parent(self):
        source = (THEME_ROOT / 'css/layout/builder.layout.css').read_text()
        self.assertIn('grid-template-columns: minmax(0, 1fr)', source)
        self.assertIn('@media (min-width: 768px)', source)
        self.assertIn('minmax(0, 1fr) minmax(0, 2fr)', source)
        self.assertIn('minmax(0, 2fr) minmax(0, 1fr)', source)
        self.assertIn('repeat(3, minmax(0, 1fr))', source)
        self.assertNotRegex(source, r'100vw|overflow\s*:|margin[^:]*:\s*-')
        selectors = re.findall(r'([^{}]+)\{', re.sub(r'/\*.*?\*/', '', source, flags=re.S))
        for selector in selectors:
            selector = selector.strip()
            if not selector.startswith('@media'):
                self.assertTrue(selector.startswith('.actools-layout'), selector)
        full = self.layouts['actools_bs5_onecol_full']
        full_source = (THEME_ROOT / (full['template'] + '.html.twig')).read_text()
        self.assertNotIn("'container'", full_source)


if __name__ == '__main__':
    unittest.main()
