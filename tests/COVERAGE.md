# Current coverage map

| Behavior | Check |
|---|---|
| YAML/JSON, assets, internal library/component references, settings alignment | Static audit |
| URL allow-lists, filtered HTML, summary tags/entities, native image source | ComponentRenderingRegressionTest (requires Drupal) |
| Linked cards, independent navbar targets, block headings, carousel content/IDs | ComponentRenderingRegressionTest (requires Drupal) |
| Compact author picture versus full-profile actions, retained picture, no duplicate profile title | ComponentRenderingRegressionTest (requires Drupal; added 2026-10-16, not yet run) |
| Actual token CSS, settings and retained file cleanup | Existing Kernel/Functional suites (requires Drupal) |
| Discovery and compilation of every component | ComponentIntegrityKernelTest (requires Drupal; empty discovery fails) |
| Six native section definitions, assets and region attributes | test_layout_regressions.py (static) |
| Selector reports, blocked unsafe publishing, stale/tampered candidates | Node css-compat tests |

Not established by static tests: installation success, real Drupal rendering,
editor drag/drop, visual accessibility, responsive layouts in a browser, PDF/print
pagination, full upstream/Sass parity, and deployment on BOA. The limited manual
BOA smoke results are recorded separately in FIXES-2026-10-16.md and do not
establish all of those behaviors.
