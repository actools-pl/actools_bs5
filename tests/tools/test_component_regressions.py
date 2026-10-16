#!/usr/bin/env python3
"""Run Drupal component and Layout Builder regressions in a disposable test site.

Usage:
  python3 tests/tools/test_component_regressions.py --drupal-root /path/to/web

The theme and tests/drupal-module/actools_bs5_test helper module must be in the
Drupal tree. This wrapper does not replace rendering with source-pattern checks.
Without a Drupal root it prints an explicit skip; --require-runtime makes that
condition a failure for CI.
"""

from __future__ import annotations

import argparse
import shutil
import subprocess
import sys
from pathlib import Path


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--drupal-root", type=Path)
    parser.add_argument("--phpunit", type=Path)
    parser.add_argument("--configuration", type=Path)
    parser.add_argument("--suite", choices=["all", "components", "layout-builder"], default="all")
    parser.add_argument("--require-runtime", action="store_true")
    args = parser.parse_args()
    if args.drupal_root is None or shutil.which("php") is None:
        print("SKIP: Drupal root and PHP CLI are required; no Drupal render tests ran.")
        return 2 if args.require_runtime else 0
    root = args.drupal_root.resolve()
    phpunit = args.phpunit or root.parent / "vendor/bin/phpunit"
    configuration = args.configuration or root / "core/phpunit.xml.dist"
    if not phpunit.is_file() or not configuration.is_file():
        print("ERROR: PHPUnit or Drupal PHPUnit configuration is missing.", file=sys.stderr)
        return 2
    theme = Path(__file__).resolve().parents[2]
    kernel = theme / "tests/drupal-module/actools_bs5_test/tests/src/Kernel"
    suites = {
        "components": kernel / "ComponentRenderingRegressionTest.php",
        "layout-builder": kernel / "LayoutBuilderIntegrationKernelTest.php",
    }
    selected = suites if args.suite == "all" else {args.suite: suites[args.suite]}
    for name, test in selected.items():
        if not test.is_file():
            print(f"ERROR: {name} test file is missing: {test}", file=sys.stderr)
            return 2
        print(f"Running {name} Drupal render tests", flush=True)
        result = subprocess.run(
            [str(phpunit.resolve()), "-c", str(configuration.resolve()), str(test)],
            cwd=root,
            check=False,
        )
        if result.returncode:
            return result.returncode
    return 0


if __name__ == "__main__":
    sys.exit(main())
