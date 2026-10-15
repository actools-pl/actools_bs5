# Actools BS5 test pack

See ../TESTING.md for current commands, prerequisites and validation limits.
This pack contains static checks and a test-only Drupal helper module. Kernel
rendering tests require Drupal/PHP; Functional tests also require a test database.
Nothing under tests/ is enabled or executed by the theme at runtime.

Source checks explicitly distinguish missing tools from passes. The existing
preview-image path advisory remains documented. Do not use an old sample output
as evidence that tests ran on your server.
