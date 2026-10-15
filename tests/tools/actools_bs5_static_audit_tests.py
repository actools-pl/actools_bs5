#!/usr/bin/env python3
"""
Static automated tests for the standalone actools_bs5 Drupal theme.

Usage:
  python3 tools/actools_bs5_static_audit_tests.py /path/to/actools_bs5
  python3 tools/actools_bs5_static_audit_tests.py /path/to/actools_bs5 --strict-advisory

The script intentionally includes regression tests for findings from the red-team
audit. Some tests are expected to fail on the audited beta until those issues are
fixed.
"""

from __future__ import annotations

import argparse
import json
import os
import re
import shutil
import subprocess
import sys
from dataclasses import dataclass
from pathlib import Path
from typing import Any, Iterable

try:
    import yaml
except Exception as exc:  # pragma: no cover - dependency failure path.
    print("FATAL: PyYAML is required: pip install pyyaml", file=sys.stderr)
    print(str(exc), file=sys.stderr)
    sys.exit(2)


@dataclass
class Finding:
    severity: str
    name: str
    message: str
    path: str | None = None
    advisory: bool = False


class Audit:
    def __init__(self, theme_dir: Path, strict_advisory: bool = False, require_php: bool = False) -> None:
        self.theme_dir = theme_dir.resolve()
        self.strict_advisory = strict_advisory
        self.findings: list[Finding] = []
        self.passed: list[str] = []
        self.skipped: list[str] = []
        self.require_php = require_php
        self.component_names: set[str] = set()
        self.library_names: set[str] = set()

    def run(self) -> int:
        self.require_theme_root()
        tests = [
            self.test_no_node_modules_or_junk,
            self.test_php_lint,
            self.test_yaml_and_json_parse,
            self.test_library_paths_and_dependencies,
            self.test_component_dependencies_and_literal_sdc_refs,
            self.test_config_schema_install_alignment,
            self.test_optional_block_regions,
            self.test_removed_coupling_regressions,
            self.test_token_css_source_load_assumptions,
            self.test_theme_settings_provider_migration_markers,
            self.test_link_primary_hover_override_is_real_css,
            self.test_token_file_writer_has_failure_handling,
            self.test_no_standalone_broken_drimage_branch,
            self.test_raw_sdc_sinks_are_explicitly_restricted,
            self.test_no_hardcoded_theme_preview_paths,
            # --- Additional coverage (extends the original pack). ---
            self.test_js_syntax,
            self.test_dark_mode_token_companion,
            self.test_file_api_not_deprecated,
            self.test_icon_webfont_not_forced_sitewide,
            self.test_build_is_reproducible,
            self.test_no_dead_removed_feature_css,
            self.test_no_unreachable_drimage_ratio_branch,
            self.test_carousel_prop_contract,
            self.test_hover_active_shade_vs_tint,
            # --- Regression guards for the fixed defects (positive-lock). ---
            self.test_token_attach_uses_alter_hook,
            self.test_no_container_wrapper_guarded,
            self.test_no_broken_core_image_override,
            self.test_link_button_url_scheme_sanitized,
            self.test_token_cleanup_deferred_not_immediate,
        ]
        for test in tests:
            before = len(self.findings)
            before_skips = len(self.skipped)
            try:
                test()
            except Exception as exc:  # Keep the runner useful even on unexpected input.
                self.fail("High", test.__name__, f"Test crashed: {exc!r}")
            if len(self.findings) == before and len(self.skipped) == before_skips:
                self.passed.append(test.__name__)
        self.report()
        return self.exit_code()

    def require_theme_root(self) -> None:
        required = [
            "actools_bs5.info.yml",
            "actools_bs5.libraries.yml",
            "actools_bs5.theme",
            "theme-settings.php",
            "components",
            "config/schema/actools_bs5.schema.yml",
            "config/install/actools_bs5.settings.yml",
        ]
        missing = [p for p in required if not (self.theme_dir / p).exists()]
        if missing:
            print(f"FATAL: {self.theme_dir} does not look like the actools_bs5 root.", file=sys.stderr)
            print("Missing: " + ", ".join(missing), file=sys.stderr)
            sys.exit(2)

    def fail(self, severity: str, name: str, message: str, path: str | None = None) -> None:
        self.findings.append(Finding(severity, name, message, path, advisory=False))

    def warn(self, severity: str, name: str, message: str, path: str | None = None) -> None:
        self.findings.append(Finding(severity, name, message, path, advisory=True))

    def rel(self, path: Path) -> str:
        try:
            return str(path.resolve().relative_to(self.theme_dir))
        except ValueError:
            return str(path)

    # The audit must not scan its own bundled test pack (the script and its docs
    # contain the very strings they grep for) nor build/VCS artefacts.
    IGNORE_DIRS = {"tests", "node_modules", ".git", "vendor"}
    IGNORE_FILES = {"TESTING.md"}

    def all_files(self, *patterns: str) -> Iterable[Path]:
        for pattern in patterns:
            for path in self.theme_dir.rglob(pattern):
                try:
                    parts = path.relative_to(self.theme_dir).parts
                except ValueError:
                    parts = path.parts
                if parts and parts[0] in self.IGNORE_DIRS:
                    continue
                if path.name in self.IGNORE_FILES:
                    continue
                yield path

    def read(self, rel_path: str) -> str:
        return (self.theme_dir / rel_path).read_text(encoding="utf-8", errors="replace")

    def load_yaml(self, path: Path) -> Any:
        with path.open("r", encoding="utf-8") as fh:
            return yaml.safe_load(fh) or {}

    def test_no_node_modules_or_junk(self) -> None:
        forbidden_dirs = {"node_modules", ".git", ".svn", "vendor"}
        bad_dirs = []
        for root, dirs, _files in os.walk(self.theme_dir):
            for dirname in list(dirs):
                if dirname in forbidden_dirs:
                    bad_dirs.append(self.rel(Path(root) / dirname))
                    dirs.remove(dirname)
        for bad in bad_dirs:
            self.fail("Medium", "packaging.forbidden_dir", f"Forbidden shipped directory: {bad}", bad)

        junk_patterns = ["*.orig", "*.rej", "*.bak", "*.swp", "*.tmp", ".DS_Store"]
        for path in self.all_files(*junk_patterns):
            self.warn("Low", "packaging.junk_file", f"Potential junk file shipped: {self.rel(path)}", self.rel(path))

    def test_php_lint(self) -> None:
        if shutil.which('php') is None:
            if self.require_php:
                self.fail('High', 'php.unavailable', 'PHP CLI is required for this release check but is not installed.')
            else:
                self.skipped.append('PHP syntax lint: PHP CLI is not installed; use --require-php for a release gate.')
            return
        php_files = sorted(set(self.all_files("*.php", "*.theme", "*.inc", "*.install")))
        for path in php_files:
            proc = subprocess.run(
                ["php", "-l", str(path)],
                text=True,
                stdout=subprocess.PIPE,
                stderr=subprocess.STDOUT,
                check=False,
            )
            if proc.returncode != 0:
                self.fail("High", "php.syntax", proc.stdout.strip(), self.rel(path))

    def test_yaml_and_json_parse(self) -> None:
        for path in sorted(self.all_files("*.yml", "*.yaml")):
            try:
                self.load_yaml(path)
            except Exception as exc:
                self.fail("High", "yaml.parse", f"YAML parse failed: {exc}", self.rel(path))
        for path in sorted(self.all_files("*.json")):
            try:
                json.loads(path.read_text(encoding="utf-8"))
            except Exception as exc:
                self.fail("High", "json.parse", f"JSON parse failed: {exc}", self.rel(path))

    def _collect_theme_libraries(self) -> dict[str, Any]:
        data = self.load_yaml(self.theme_dir / "actools_bs5.libraries.yml")
        self.library_names = set(data.keys())
        return data

    def _check_asset_paths_in_library_tree(self, tree: Any, source_path: Path, context: str, base_dir: Path | None = None) -> None:
        if not isinstance(tree, dict):
            return
        base_dir = base_dir or self.theme_dir
        for asset_type in ("css", "js"):
            section = tree.get(asset_type)
            if not isinstance(section, dict):
                continue
            if asset_type == "css":
                containers = [v for v in section.values() if isinstance(v, dict)]
                assets: dict[str, Any] = {}
                for container in containers:
                    assets.update({k: v for k, v in container.items() if isinstance(k, str)})
            else:
                assets = {k: v for k, v in section.items() if isinstance(k, str)}
            for asset_path in assets:
                if asset_path.startswith(("http://", "https://", "//")):
                    continue
                if not (base_dir / asset_path).exists():
                    self.fail(
                        "High",
                        "library.missing_asset",
                        f"{context} references missing {asset_type} asset: {asset_path}",
                        self.rel(source_path),
                    )

    def _check_library_deps(self, deps: Any, source_path: Path, context: str) -> None:
        if not isinstance(deps, list):
            return
        for dep in deps:
            if not isinstance(dep, str):
                self.fail("Medium", "library.bad_dependency", f"Non-string dependency: {dep!r}", self.rel(source_path))
                continue
            if dep.startswith("actools_bs5/"):
                lib = dep.split("/", 1)[1]
                if lib not in self.library_names:
                    self.fail("High", "library.undefined_dependency", f"{context} depends on undefined library {dep}", self.rel(source_path))
            elif dep.startswith("core/components.actools_bs5--"):
                comp = dep.split("--", 1)[1]
                if comp not in self.component_names:
                    self.fail("High", "component.undefined_dependency", f"{context} depends on missing component {dep}", self.rel(source_path))

    def test_library_paths_and_dependencies(self) -> None:
        self._collect_components()
        libraries = self._collect_theme_libraries()
        for name, lib in libraries.items():
            if not isinstance(lib, dict):
                self.fail("High", "library.invalid", f"Library {name} is not a mapping", "actools_bs5.libraries.yml")
                continue
            self._check_asset_paths_in_library_tree(lib, self.theme_dir / "actools_bs5.libraries.yml", f"library {name}", self.theme_dir)
            self._check_library_deps(lib.get("dependencies"), self.theme_dir / "actools_bs5.libraries.yml", f"library {name}")

    def _collect_components(self) -> dict[str, Path]:
        components: dict[str, Path] = {}
        for component_yml in self.theme_dir.glob("components/**/*.component.yml"):
            stem = component_yml.name.removesuffix(".component.yml")
            components[stem] = component_yml
        self.component_names = set(components.keys())
        return components

    def test_component_dependencies_and_literal_sdc_refs(self) -> None:
        self._collect_theme_libraries()
        components = self._collect_components()
        if not components:
            self.fail("High", "component.none", "No SDC component YAML files found", "components")
            return

        for name, component_yml in sorted(components.items()):
            data = self.load_yaml(component_yml)
            if data.get("name") is None:
                self.warn("Low", "component.missing_name", f"Component {name} has no display name", self.rel(component_yml))
            for lib in data.get("libraries", []) or []:
                if not isinstance(lib, dict):
                    continue
                self._check_asset_paths_in_library_tree(lib, component_yml, f"component {name}", component_yml.parent)
                self._check_library_deps(lib.get("dependencies"), component_yml, f"component {name}")
            overrides = data.get("libraryOverrides") or {}
            if isinstance(overrides, dict):
                self._check_asset_paths_in_library_tree(overrides, component_yml, f"component override {name}", component_yml.parent)
                self._check_library_deps(overrides.get("dependencies"), component_yml, f"component override {name}")

        literal_patterns = [
            re.compile(r"(?:include|embed|extends|use)\s+['\"]actools_bs5:([A-Za-z0-9_-]+)['\"]"),
            re.compile(r"include\s*\(\s*['\"]actools_bs5:([A-Za-z0-9_-]+)['\"]"),
        ]
        for twig in self.all_files("*.twig"):
            text = twig.read_text(encoding="utf-8", errors="replace")
            for pattern in literal_patterns:
                for match in pattern.finditer(text):
                    comp = match.group(1)
                    if comp not in self.component_names:
                        self.fail("High", "component.dangling_literal_ref", f"Twig references missing component actools_bs5:{comp}", self.rel(twig))

        carousel = self.theme_dir / "components/organisms/carousel/carousel.twig"
        if carousel.exists():
            text = carousel.read_text(encoding="utf-8", errors="replace")
            guarded = re.search(r"if\s+slide_component\s+not\s+in\s+\[", text)
            if "slide_component" in text and "include" in text and not guarded:
                self.warn("Medium", "component.dynamic_include_allowlist", "Carousel dynamically includes slide_component; add an allow-list or enum-backed validation before editor-facing use", self.rel(carousel))

    def test_config_schema_install_alignment(self) -> None:
        install = self.load_yaml(self.theme_dir / "config/install/actools_bs5.settings.yml")
        schema = self.load_yaml(self.theme_dir / "config/schema/actools_bs5.schema.yml")
        mapping = ((schema.get("actools_bs5.settings") or {}).get("mapping") or {})
        expected_extra = {"favicon", "features", "logo"}
        install_keys = set(install.keys()) - expected_extra
        schema_keys = set(mapping.keys())
        missing_schema = sorted(install_keys - schema_keys)
        missing_install = sorted(schema_keys - install_keys)
        if missing_schema:
            self.fail("High", "config.schema_missing", f"Install settings missing from schema: {missing_schema}", "config/schema/actools_bs5.schema.yml")
        if missing_install:
            self.fail("Medium", "config.install_missing", f"Schema settings missing from install config: {missing_install}", "config/install/actools_bs5.settings.yml")

        type_expectations = {
            "sticky_header": "boolean",
            "tokens_brand_color": "string",
            "tokens_body_font": "string",
            "tokens_base_font_size": "string",
            "tokens_corner_radius": "string",
        }
        for key, expected_type in type_expectations.items():
            actual = (mapping.get(key) or {}).get("type")
            if actual != expected_type:
                self.fail("High", "config.schema_type", f"{key} schema type is {actual!r}; expected {expected_type!r}", "config/schema/actools_bs5.schema.yml")

    def test_optional_block_regions(self) -> None:
        info = self.load_yaml(self.theme_dir / "actools_bs5.info.yml")
        regions = set((info.get("regions") or {}).keys())
        for path in sorted((self.theme_dir / "config/optional").glob("block.block.*.yml")):
            block = self.load_yaml(path)
            region = block.get("region")
            if region not in regions:
                self.fail("High", "config.block_bad_region", f"Optional block uses missing region {region!r}; known regions: {sorted(regions)}", self.rel(path))
            themes = ((block.get("dependencies") or {}).get("theme") or [])
            if "actools_bs5" not in themes:
                self.fail("Medium", "config.block_missing_theme_dep", "Optional block lacks actools_bs5 theme dependency", self.rel(path))

    def test_removed_coupling_regressions(self) -> None:
        forbidden = {
            "webshare_share_data": "Vanilla-breaking Webshare Twig/function coupling",
            "varbase_media_drimage(": "Vanilla-breaking Varbase media Drimage function call",
            "/themes/contrib/actools_bs5": "Hard-coded contrib install path",
            "hero_slider": "Removed hero-slider coupling",
            "social_auth": "Removed social-auth coupling",
        }
        doc_paths = {"README.md", "CHANGELOG.md", "INSTALL.md"}
        for path in self.all_files("*"):
            if path.is_dir():
                continue
            rel = self.rel(path)
            if rel in doc_paths or any(part in {".git", "node_modules"} for part in Path(rel).parts):
                continue
            text = path.read_text(encoding="utf-8", errors="ignore")
            # Strip Twig/PHP comments so migration notes do not become false positives.
            text = re.sub(r"\{#.*?#\}", "", text, flags=re.S)
            text = re.sub(r"/\*.*?\*/", "", text, flags=re.S)
            text = re.sub(r"//.*", "", text)
            for needle, msg in forbidden.items():
                if needle in text:
                    self.fail("High", "regression.removed_coupling", f"{msg}: found {needle!r}", rel)

        # Actual deprecated calls only, not comments/documentation mentioning the migration.
        php_like = sorted(set(self.all_files("*.php", "*.theme", "*.inc", "*.install")))
        for path in php_like:
            text = path.read_text(encoding="utf-8", errors="ignore")
            text = re.sub(r"/\*.*?\*/", "", text, flags=re.S)
            text = re.sub(r"//.*", "", text)
            if re.search(r"(?<![A-Za-z0-9_])theme_get_setting\s*\(", text):
                self.fail("High", "regression.theme_get_setting_call", "Deprecated theme_get_setting() call remains", self.rel(path))

    def test_token_css_source_load_assumptions(self) -> None:
        libs = self.load_yaml(self.theme_dir / "actools_bs5.libraries.yml")
        framework = libs.get("framework") or {}
        framework_deps = framework.get("dependencies") or []
        if "actools_bs5/bootstrap-styles" not in framework_deps:
            self.fail("High", "tokens.framework_missing_bootstrap_styles", "framework library must depend on actools_bs5/bootstrap-styles", "actools_bs5.libraries.yml")
        bootstrap_styles = libs.get("bootstrap-styles") or {}
        css_base = (((bootstrap_styles.get("css") or {}).get("base")) or {})
        css_order = list(css_base.keys())
        required = ["css/base/bootstrap.base.css", "css/base/tokens.base.css"]
        for req in required:
            if req not in css_order:
                self.fail("High", "tokens.missing_library_asset", f"bootstrap-styles library does not load {req}", "actools_bs5.libraries.yml")
        if all(req in css_order for req in required):
            if css_order.index("css/base/tokens.base.css") < css_order.index("css/base/bootstrap.base.css"):
                self.fail("High", "tokens.bad_load_order", "tokens.base.css must load after bootstrap.base.css", "actools_bs5.libraries.yml")

        bootstrap_scss = self.read("scss/base/bootstrap.base.scss")
        if "@import \"bootstrap/scss/root\"" in bootstrap_scss and "// @import \"bootstrap/scss/root\"" not in bootstrap_scss:
            self.fail("Medium", "tokens.duplicate_root_source", "Bootstrap root partial appears to be active in bootstrap.base.scss", "scss/base/bootstrap.base.scss")
        tokens_css = self.read("css/base/tokens.base.css")
        if ":root" not in tokens_css or "data-bs-theme=dark" not in tokens_css:
            self.fail("High", "tokens.base_incomplete", "tokens.base.css should contain Bootstrap :root and dark-mode variables", "css/base/tokens.base.css")

    def test_theme_settings_provider_migration_markers(self) -> None:
        php_text = "\n".join(
            path.read_text(encoding="utf-8", errors="replace")
            for path in sorted(set(self.all_files("*.php", "*.theme")))
        )
        code_text = re.sub(r"/\*.*?\*/", "", php_text, flags=re.S)
        code_text = re.sub(r"//.*", "", code_text)
        if re.search(r"(?<![A-Za-z0-9_])theme_get_setting\s*\(", code_text):
            self.fail("High", "theme_settings_provider.deprecated_call", "theme_get_setting() still exists in executable PHP/theme code")
        if "Drupal\\Core\\Extension\\ThemeSettingsProvider" not in php_text:
            self.fail("High", "theme_settings_provider.missing", "ThemeSettingsProvider class service is not used")
        if "->getSetting($name, $theme)" not in php_text and "->getSetting(" not in php_text:
            self.fail("Medium", "theme_settings_provider.no_getsetting", "No getSetting() call found")

    def test_link_primary_hover_override_is_real_css(self) -> None:
        theme = self.read("actools_bs5.theme")
        # Tolerate a specificity prefix (e.g. `:root:root .link-primary:hover`),
        # which is the recommended order-independent form.
        match = re.search(r"\.link-primary:hover[^{]*\.link-primary:focus[^{]*\{([^}]*)\}", theme)
        if not match:
            self.fail("Medium", "tokens.link_primary_rule_missing", "No generated .link-primary:hover/.focus rule found", "actools_bs5.theme")
            return
        body = match.group(1)
        required_props = ["color:", "text-decoration-color:"]
        missing = [prop for prop in required_props if prop not in body]
        if missing:
            self.fail(
                "Medium",
                "tokens.link_primary_hover_not_real_css",
                "Generated .link-primary:hover/.focus rule only changes variables; it must emit actual color and text-decoration-color to beat Bootstrap's hard-coded !important utility. Missing: " + ", ".join(missing),
                "actools_bs5.theme",
            )

    def test_token_file_writer_has_failure_handling(self) -> None:
        theme = self.read("actools_bs5.theme")
        writer_match = re.search(r"function _actools_bs5_write_tokens_file\([^)]*\) \{(?P<body>.*?)\n\}", theme, flags=re.S)
        if not writer_match:
            self.fail("High", "tokens.writer_missing", "_actools_bs5_write_tokens_file() not found", "actools_bs5.theme")
            return
        body = writer_match.group("body")
        if "prepareDirectory" not in body or "saveData" not in body:
            self.fail("High", "tokens.writer_incomplete", "Token writer does not call expected file-system operations", "actools_bs5.theme")
        if "try" not in body or "catch" not in body:
            self.fail("Medium", "tokens.writer_no_try_catch", "Token writer should catch file-system exceptions and degrade gracefully", "actools_bs5.theme")
        if not re.search(r"if\s*\([^)]*prepareDirectory", body) and "prepareDirectory(" in body:
            self.fail("Medium", "tokens.writer_prepare_not_checked", "prepareDirectory() return value is not checked", "actools_bs5.theme")

    def test_no_standalone_broken_drimage_branch(self) -> None:
        """M-5: the standalone build must not render an empty *dummy* image as the visible src.

        Drimage was removed and no JS processor ships. The legitimate fix is to
        either remove the Drimage branch OR bind the visible <img>/<source> to a
        real image (e.g. data.original_source). The defect is specifically the
        *dummy* being the visible src/srcset — not the mere presence of a
        dynamic-responsive-image component or a dormant Canvas/Drimage code path.
        """
        twig = self.theme_dir / "components/molecules/dynamic-responsive-image/dynamic-responsive-image.twig"
        if not twig.exists():
            return
        text = twig.read_text(encoding="utf-8", errors="ignore")
        if re.search(
            r"picture_(?:img|source)_attributes\s*\.setAttribute\(\s*['\"](?:src|srcset)['\"]\s*,\s*dummy_image\s*\)",
            text,
        ):
            self.fail(
                "Medium",
                "standalone.drimage_dummy_render",
                "dynamic-responsive-image binds the visible <img>/<source> src/srcset to the empty dummy_image "
                "(the removed Drimage JS was expected to swap it in). Point the visible image at a real source "
                "or remove the branch.",
                self.rel(twig),
            )

    def test_raw_sdc_sinks_are_explicitly_restricted(self) -> None:
        info = self.load_yaml(self.theme_dir / "actools_bs5.info.yml")
        enforce = info.get("enforce_prop_schemas")
        raw_twigs = []
        url_prop_components = []
        for twig in self.all_files("*.twig"):
            text = twig.read_text(encoding="utf-8", errors="ignore")
            if "|raw" in text:
                raw_twigs.append(self.rel(twig))
        for comp_yml in self.theme_dir.glob("components/**/*.component.yml"):
            text = comp_yml.read_text(encoding="utf-8", errors="ignore")
            if "format: uri-reference" in text or "format: uri" in text:
                url_prop_components.append(self.rel(comp_yml))
        # Schema validation is not HTML sanitization. Flag actual raw-output
        # sinks; URL output is checked separately below and by Drupal renders.
        if raw_twigs:
            self.warn(
                "High",
                "sdc.raw_or_url_props_unrestricted",
                "Unfiltered raw HTML output remains. Use core render-array filtering or trusted render output. Raw Twig files: "
                + ", ".join(raw_twigs[:20])
                + (" ..." if len(raw_twigs) > 20 else "")
                + "; URL components: "
                + ", ".join(url_prop_components[:20]),
                "actools_bs5.info.yml",
            )

    def test_no_hardcoded_theme_preview_paths(self) -> None:
        hits = []
        for path in self.all_files("*.yml", "*.twig", "*.md"):
            rel = self.rel(path)
            if rel.startswith("CHANGELOG") or rel.startswith("README") or rel == "INSTALL.md":
                continue
            text = path.read_text(encoding="utf-8", errors="ignore")
            if "/themes/custom/actools_bs5" in text or "/themes/contrib/actools_bs5" in text:
                hits.append(rel)
        if hits:
            self.warn(
                "Low",
                "docs.preview_hardcoded_theme_path",
                "Hard-coded theme install paths can break component previews/examples outside themes/custom. Files: " + ", ".join(sorted(set(hits))),
            )

    # ------------------------------------------------------------------
    # Additional checks (extend the original pack).
    #
    # Each maps to a finding from the consolidated red-team review that the
    # original runner did not yet cover. Advisory findings become failures under
    # --strict-advisory. Every check guards for missing files so the runner stays
    # useful on a differently-shaped tree.
    # ------------------------------------------------------------------

    def _function_body(self, text: str, func_name: str) -> str | None:
        """Return the body of a top-level PHP function that closes with a column-0 brace."""
        match = re.search(
            r"function\s+" + re.escape(func_name) + r"\s*\([^)]*\)\s*\{(?P<body>.*?)\n\}",
            text,
            flags=re.S,
        )
        return match.group("body") if match else None

    def test_js_syntax(self) -> None:
        """Lint shipped JavaScript with `node --check` (skipped if Node is absent)."""
        js_files = sorted(set(self.all_files("*.js")))
        if not js_files:
            return
        for path in js_files:
            try:
                proc = subprocess.run(
                    ["node", "--check", str(path)],
                    text=True,
                    stdout=subprocess.PIPE,
                    stderr=subprocess.STDOUT,
                    check=False,
                )
            except FileNotFoundError:
                # Node is not installed in this environment; JS lint is a bonus check.
                return
            if proc.returncode != 0:
                self.fail("High", "js.syntax", proc.stdout.strip() or "node --check failed", self.rel(path))

    def test_dark_mode_token_companion(self) -> None:
        """M-2: a :root:root brand override must not silently win over dark-mode tokens.

        The override uses `:root:root` (specificity 0,2,0), which beats Bootstrap's
        single-attribute `[data-bs-theme=dark]` block. Unless the generator emits a
        dark-mode companion (or scopes the override to light mode), a dark brand
        colour re-colours dark mode and can become unreadable. Either fix strategy
        makes the generated CSS reference `data-bs-theme`.
        """
        theme = self.read("actools_bs5.theme")
        body = self._function_body(theme, "_actools_bs5_build_tokens_css") or theme
        if ":root:root" in body and "data-bs-theme" not in body:
            self.warn(
                "Medium",
                "tokens.dark_mode_companion_missing",
                "Generated brand override uses :root:root but emits no dark-mode companion. "
                "A dark brand colour will re-colour [data-bs-theme=dark] and can be unreadable. "
                "Emit an explicit :root[data-bs-theme=\"dark\"] companion or scope the override to light mode.",
                "actools_bs5.theme",
            )

    def test_file_api_not_deprecated(self) -> None:
        """L-1: FileSystemInterface::EXISTS_* int constants are removed in Drupal 12.

        They still work on 11.x (via FileExists::fromLegacyInt) but emit a deprecation
        on every call. Advisory because the theme targets ^11.3; use the FileExists enum.
        """
        legacy = ["EXISTS_REPLACE", "EXISTS_RENAME", "EXISTS_ERROR"]
        for path in sorted(set(self.all_files("*.php", "*.theme", "*.inc", "*.install"))):
            text = path.read_text(encoding="utf-8", errors="ignore")
            text = re.sub(r"/\*.*?\*/", "", text, flags=re.S)
            text = re.sub(r"//.*", "", text)
            hits = [f"FileSystemInterface::{name}" for name in legacy if f"FileSystemInterface::{name}" in text]
            if hits:
                self.warn(
                    "Low",
                    "fileapi.deprecated_exists_constant",
                    "Deprecated (removed in Drupal 12) file constant(s): "
                    + ", ".join(sorted(set(hits)))
                    + ". Use \\Drupal\\Core\\File\\FileExists (e.g. FileExists::Replace) instead.",
                    self.rel(path),
                )

    def test_icon_webfont_not_forced_sitewide(self) -> None:
        """L-2: the Bootstrap-Icons webfont is loaded and preloaded on every page.

        `framework` (attached site-wide) depending on the icon webfont, plus an
        unconditional woff2 <link rel=preload>, is critical-path weight even on
        pages that render no icon. Advisory: attach from the icon components instead.
        """
        libs = self.load_yaml(self.theme_dir / "actools_bs5.libraries.yml")
        framework_deps = ((libs.get("framework") or {}).get("dependencies")) or []
        forces_icons = any("bootstrap-icons" in str(dep) for dep in framework_deps)
        preload = self.theme_dir / "templates/includes/preload.twig"
        preloads_icons = False
        if preload.exists():
            ptext = preload.read_text(encoding="utf-8", errors="ignore")
            preloads_icons = "bootstrap-icons" in ptext and "rel=\"preload\"" in ptext.replace("'", "\"")
        if forces_icons and preloads_icons:
            self.warn(
                "Low",
                "perf.icon_webfont_forced_sitewide",
                "framework depends on the Bootstrap-Icons webfont and preload.twig preloads its woff2 on every page. "
                "Attach actools_bs5/bootstrap-icons from the icon components and drop the unconditional preload.",
                "actools_bs5.libraries.yml",
            )

    def test_build_is_reproducible(self) -> None:
        """L-7: pin the toolchain so the committed CSS is reproducible.

        A missing package-lock.json plus a ranged Sass version means a clean rebuild
        is not byte-reproducible from the archive. Advisory only.
        """
        pkg_path = self.theme_dir / "package.json"
        if not pkg_path.exists():
            return
        if not (self.theme_dir / "package-lock.json").exists():
            self.warn(
                "Low",
                "build.no_lockfile",
                "No package-lock.json: the SCSS toolchain is not pinned, so a clean rebuild is not byte-reproducible. "
                "Commit a lockfile and regenerate the committed CSS from it.",
                "package.json",
            )
        try:
            pkg = json.loads(pkg_path.read_text(encoding="utf-8"))
        except Exception:
            return
        deps = {}
        for key in ("dependencies", "devDependencies"):
            deps.update(pkg.get(key) or {})
        sass_spec = str(deps.get("sass", ""))
        # A lockfile pins the exact resolved tree, so `npm ci` is reproducible even
        # with a ranged spec. Only flag the range when there is no lockfile.
        has_lock = (self.theme_dir / "package-lock.json").exists()
        if not has_lock and sass_spec and re.search(r"[\^~*x]|\s-\s|\|\|", sass_spec):
            self.warn(
                "Low",
                "build.sass_not_pinned",
                f"Sass is declared as a range ({sass_spec!r}) with no lockfile; pin an exact version or commit "
                "package-lock.json so builds are reproducible.",
                "package.json",
            )

    def test_no_dead_removed_feature_css(self) -> None:
        """L-6: leftover CSS for removed integrations (the underscore grep misses hyphenated classes)."""
        dead_selectors = [".social-auth-login-hint"]
        for path in sorted(set(self.all_files("*.css", "*.scss"))):
            text = path.read_text(encoding="utf-8", errors="ignore")
            hits = [sel for sel in dead_selectors if sel in text]
            if hits:
                self.warn(
                    "Low",
                    "cleanup.dead_removed_feature_css",
                    "Dead CSS for a removed feature: " + ", ".join(hits) + ". Remove the rule.",
                    self.rel(path),
                )

    def test_no_unreachable_drimage_ratio_branch(self) -> None:
        """L-5: the aspect-ratio branch in image.twig is unreachable dead code.

        `drimage_data` is only truthy when `has_canvas_drimage` is true, yet the guard
        requires `not has_canvas_drimage`, so the aspect-ratio merge never runs.
        """
        image_twig = self.theme_dir / "components/atoms/image/image.twig"
        if not image_twig.exists():
            return
        text = image_twig.read_text(encoding="utf-8", errors="ignore")
        if re.search(r"if\s+not\s+has_canvas_drimage\s+and\b.*\bdrimage_data", text):
            self.warn(
                "Low",
                "component.unreachable_drimage_branch",
                "image.twig contains an unreachable `if not has_canvas_drimage and ... and drimage_data` branch "
                "(drimage_data is only set when has_canvas_drimage is true). Remove or fix the guard.",
                self.rel(image_twig),
            )

    def test_carousel_prop_contract(self) -> None:
        """L-4: constrain carousel props before editor-facing use.

        `slide_component` is fed to a dynamic include() and should be an enum allow-list;
        `id` is used for control targeting and should have a default (or be auto-generated).
        """
        carousel = self.theme_dir / "components/organisms/carousel/carousel.component.yml"
        if not carousel.exists():
            return
        data = self.load_yaml(carousel)
        props = (((data.get("props") or {}).get("properties")) or {})
        slide = props.get("slide_component") or {}
        cid = props.get("id") or {}
        if "enum" not in slide:
            self.warn(
                "Medium",
                "component.carousel_slide_component_no_enum",
                "carousel slide_component is an unconstrained string passed to include(); add an `enum` allow-list.",
                self.rel(carousel),
            )
        twig = carousel.parent / "carousel.twig"
        twig_text = twig.read_text(encoding="utf-8", errors="ignore") if twig.exists() else ""
        auto_id = bool(re.search(r"\bid\s*=\s*id\s*(\?\?|\?|\bor\b|\|default)", twig_text)) or "html_id" in twig_text or "random(" in twig_text or 'clean_unique_id' in twig_text
        if "default" not in cid and not auto_id:
            self.warn(
                "Low",
                "component.carousel_id_no_default",
                "carousel `id` has no default and is not auto-generated; missing/duplicate ids break the prev/next/indicator controls.",
                self.rel(carousel),
            )

    def test_hover_active_shade_vs_tint(self) -> None:
        """L-3: hover/active always shade; Bootstrap tints when the brand colour is light.

        The generator derives hover/active with _actools_bs5_shade_color() only, and picks
        text colour with a YIQ threshold rather than Bootstrap's WCAG contrast ratio, so a
        light brand colour darkens on hover where Bootstrap lightens. Advisory (cosmetic).
        """
        theme = self.read("actools_bs5.theme")
        uses_shade = "_actools_bs5_shade_color(" in theme
        has_tint = ("_actools_bs5_tint_color(" in theme) or re.search(r"\btint\b", theme) is not None
        if uses_shade and not has_tint:
            self.warn(
                "Low",
                "tokens.hover_shade_not_tint",
                "Hover/active states always shade (darken); Bootstrap tints (lightens) when button text is dark. "
                "A light brand colour will darken on hover instead of lightening. Tint when the contrast colour is dark, "
                "or document that the brand colour should be a mid/dark tone.",
                "actools_bs5.theme",
            )

    def test_token_attach_uses_alter_hook(self) -> None:
        """C1 (critical regression): themes must attach page assets via the ALTER hook.

        Drupal fires hook_page_attachments_alter() for themes but NOT the plain
        hook_page_attachments(). Attaching the token stylesheet from the non-alter
        form silently never runs, killing the whole configurable-token feature.
        """
        theme = self.read("actools_bs5.theme")
        if "function actools_bs5_page_attachments_alter(" in theme:
            return
        if re.search(r"function\s+actools_bs5_page_attachments\s*\(", theme):
            self.fail(
                "High",
                "tokens.attach_wrong_hook",
                "Tokens attach via hook_page_attachments() — themes only receive the _alter form, so it never "
                "runs. Rename to actools_bs5_page_attachments_alter().",
                "actools_bs5.theme",
            )
        else:
            self.warn(
                "Medium",
                "tokens.attach_hook_absent",
                "No actools_bs5_page_attachments_alter(); the generated token stylesheet may not be attached.",
                "actools_bs5.theme",
            )

    def test_no_container_wrapper_guarded(self) -> None:
        """Default-path regression: 'no-container' must not emit a no-op wrapper.

        There is no `.no-container` CSS rule, so `{% if container* %}` without a
        `!= 'no-container'` guard wraps header/footer in a meaningless <div> on
        every page.
        """
        for rel in ("components/pages/page/page.twig", "components/organisms/navbar/navbar.twig"):
            twig = self.theme_dir / rel
            if not twig.exists():
                continue
            text = twig.read_text(encoding="utf-8", errors="ignore")
            m = re.search(r"{%-?\s*if\s+(container[a-z_]*)\s*-?%}", text)
            if m:
                var = m.group(1)
                self.fail(
                    "Medium",
                    "template.no_container_noop",
                    f"{rel}: `{{% if {var} %}}` does not exclude 'no-container', so a no-op "
                    f"<div class=\"no-container\"> is emitted. Guard with `and {var} != 'no-container'`.",
                    rel,
                )

    def test_no_broken_core_image_override(self) -> None:
        """Default-path regression: the broken core image override must stay removed.

        A templates/system/image.html.twig routing the core `image` hook through the
        SDC image component (which gates <img> on media.src) emits an empty <figure>
        for standard Image fields / user pictures. It must not return.
        """
        override = self.theme_dir / "templates/system/image.html.twig"
        if override.exists() and "actools_bs5:image" in override.read_text(encoding="utf-8", errors="ignore"):
            self.fail(
                "High",
                "template.broken_image_override",
                "templates/system/image.html.twig routes the core image hook through the SDC image component, "
                "emitting an empty <figure> for standard Image fields. Remove the override.",
                self.rel(override),
            )

    def test_link_button_url_scheme_sanitized(self) -> None:
        """H-1 positive-lock: URL-outputting components must neutralise dangerous schemes.

        href/src props are HTML-escaped but the scheme is not stripped, so a
        `javascript:`/`data:`/`vbscript:` URL renders as a clickable sink. Every
        component that turns a prop into an href should rewrite those schemes.
        """
        url_components = (
            "components/atoms/link/link.twig",
            "components/atoms/button/button.twig",
            "components/atoms/badge/badge.twig",
            "components/atoms/taxonomy/taxonomy.twig",
            "components/molecules/list-group/list-group.twig",
            "components/molecules/dynamic-responsive-image/dynamic-responsive-image.twig",
        )
        for rel in url_components:
            twig = self.theme_dir / rel
            if not twig.exists():
                continue
            text = twig.read_text(encoding="utf-8", errors="ignore").lower()
            allowlist = ('_url_allowed' in text or '_href_allowed' in text) and 'matches' in text and "'https://'" in text and "'mailto:'" in text
            if not allowlist and ("javascript:" not in text or "vbscript:" not in text):
                self.warn(
                    "Medium",
                    "sdc.url_scheme_not_sanitized",
                    f"{rel} sets href from a prop but does not neutralise dangerous URL schemes "
                    "(javascript:/data:/vbscript:). Add a scheme guard before setting href.",
                    rel,
                )

    def test_token_cleanup_deferred_not_immediate(self) -> None:
        """M-4 fix-lock: recent token files survive; expired files have real cleanup.

        Content-hashed assets stay cacheable only while old names remain valid. The
        writer must not directly delete other tokens-*.css. Themes cannot use
        hook_cron(); the throttled helper must be called by the live writer.
        """
        theme = self.read("actools_bs5.theme")
        writer = self._function_body(theme, "_actools_bs5_write_tokens_file")
        if writer is not None and "->delete(" in writer:
            self.warn(
                "Medium",
                "tokens.writer_deletes_immediately",
                "_actools_bs5_write_tokens_file() deletes superseded token files on write; HTML cached elsewhere "
                "referencing an old hash will 404. Move cleanup to hook_cron() with a retention window.",
                "actools_bs5.theme",
            )
        cleanup = self._function_body(theme, '_actools_bs5_cleanup_token_files') or ''
        sweeper = self._function_body(theme, '_actools_bs5_delete_expired_token_files') or ''
        if not writer or '_actools_bs5_cleanup_token_files(' not in writer or '604800' not in sweeper or '->acquire(' not in cleanup or 'tokens_cleanup_after' not in cleanup:
            self.fail(
                "Medium",
                "tokens.no_callable_cleanup",
                "Retained token cleanup must be called by the writer, throttled, locked and retain old files for a week.",
                "actools_bs5.theme",
            )
        if 'function actools_bs5_cron(' in theme:
            self.fail('Medium', 'tokens.inert_theme_cron', 'Theme cron handlers are not invoked by Drupal core.', 'actools_bs5.theme')

    def report(self) -> None:
        hard = [f for f in self.findings if not f.advisory]
        advisory = [f for f in self.findings if f.advisory]
        print("\nActools BS5 static audit tests")
        print("=" * 34)
        print(f"Theme: {self.theme_dir}")
        print(f"Passed checks: {len(self.passed)}")
        print(f"Hard failures: {len(hard)}")
        print(f"Advisories: {len(advisory)}")
        print(f"Skipped checks: {len(self.skipped)}")
        for skipped in self.skipped:
            print(f"SKIP: {skipped}")

        if hard:
            print("\nHARD FAILURES")
            for idx, finding in enumerate(hard, 1):
                location = f" [{finding.path}]" if finding.path else ""
                print(f"{idx}. {finding.severity} {finding.name}{location}\n   {finding.message}")
        if advisory:
            print("\nADVISORIES")
            for idx, finding in enumerate(advisory, 1):
                location = f" [{finding.path}]" if finding.path else ""
                print(f"{idx}. {finding.severity} {finding.name}{location}\n   {finding.message}")
        if not hard and (not advisory or not self.strict_advisory):
            print("\nResult: PASS (with skipped checks)" if self.skipped else "\nResult: PASS")
        else:
            print("\nResult: FAIL")

    def exit_code(self) -> int:
        for finding in self.findings:
            if not finding.advisory:
                return 1
            if finding.advisory and self.strict_advisory:
                return 1
        return 0


def main(argv: list[str]) -> int:
    parser = argparse.ArgumentParser(description="Run static automated tests for the actools_bs5 Drupal theme.")
    parser.add_argument("theme_dir", type=Path, help="Path to the actools_bs5 theme root.")
    parser.add_argument("--strict-advisory", action="store_true", help="Treat advisory findings as failures.")
    parser.add_argument("--require-php", action="store_true", help="Fail rather than skip when PHP CLI is unavailable.")
    args = parser.parse_args(argv)
    return Audit(args.theme_dir, args.strict_advisory, args.require_php).run()


if __name__ == "__main__":
    raise SystemExit(main(sys.argv[1:]))
