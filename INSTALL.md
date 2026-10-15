# Installing Actools BS5 from GitHub

The repository is a standalone Composer package. Theme files and composer.json
are at its root, without an extra actools_bs5 directory. Compiled CSS,
JavaScript, fonts and images are committed, so installation needs no Node/Sass
build. Preserve the original licence and Vardot attribution.

## Composer installation on a fresh BOA platform

Build the Drupal project in the real o1.ftp limited shell before registering it
with Aegir. The theme requires Drupal ^11.3 and the project must provide Drupal's
usual Composer installer configuration. Drupal's recommended-project template
already includes the installer and its allowed-plugin configuration.

Run each command separately from the new Drupal project's directory; stop on
any failure. Do not paste terminal prompts/output, use && chaining, run Composer
as root, or run these commands inside a platform already serving sites.

~~~bash
composer config repositories.actools-bs5 vcs https://github.com/actools-pl/actools_bs5
composer require --no-interaction --update-no-dev --prefer-dist actools/actools_bs5:dev-main
composer validate --no-check-publish
composer check-platform-reqs --no-dev
composer audit --no-dev
composer show actools/actools_bs5 --locked
~~~

With recommended-project's default installer paths, this puts the theme at
web/themes/contrib/actools_bs5. Manual uploads can instead use
web/themes/custom/actools_bs5; Drupal supports either location. Some component
preview/example image paths still assume the custom location; they are not
production image-field paths.

The first Composer build resolves main and records its exact source reference
in composer.lock. Review that reference and retain the lock file. Reproduce the
build with composer install, not composer update. For a reviewed commit chosen
in advance, replace REVIEWED_COMMIT_SHA below with the full commit ID:

~~~bash
composer require --no-interaction --update-no-dev --prefer-dist 'actools/actools_bs5:dev-main#REVIEWED_COMMIT_SHA'
~~~

There is no tagged release yet. The inherited 5.0.0-beta1 value in theme/npm
metadata describes the supplied source lineage; do not request it as a Composer
release from this repository. Treat main as a test candidate. Publish immutable
numbered releases once the remaining validation has been reviewed.

Add all other required modules, including SMTP when migrating a site that uses
it, before platform registration. Register the complete Drupal web directory in
Aegir, wait for platform Verify to pass, then rehearse site migration on a clone.
Keep Claro as the administration theme when enabling Actools BS5.

## Manual or Git installation

A repository checkout can be placed at
web/themes/custom/actools_bs5 before platform registration:

~~~bash
git clone https://github.com/actools-pl/actools_bs5.git web/themes/custom/actools_bs5
~~~

Check out a reviewed commit before registering the platform; do not track/pull
main directly in a live platform. A downloaded archive should be extracted and
its outer GitHub directory renamed to actools_bs5.

When using Bitvise's root SFTP session, upload only to the intended new
platform. Restore the uploaded theme folder's build-account ownership before
registration. The limited shell may forbid php; use the installed PHP binary
from root for syntax lint, as described in BOA's optional theme guide.

## Enable and verify

1. Open Drupal Appearance and install/set Actools BS5 as the default theme.
2. Check Block layout for Header, Content and Footer block placements.
3. Use theme settings to select containers, branding, font and colour.
4. For Layout Builder, enable core Layout Builder and Layout Discovery.
5. Create a separate test content type. Drupal 11.1+ does not add Body
   automatically; create a text field if needed.
6. On Manage display, open Default from Overview, enable Use Layout Builder,
   save, then Manage layout > Add section > Actools sections.
7. Test all required sections and content at desktop and narrow widths.
8. Clear Drupal caches after replacing theme code. On profiles, the normal
   page-title block supplies the user name; the wrapper does not duplicate it.
9. Run site Verify and make a fresh full site backup after acceptance.

The recorded test covers six section choices, 50/50 rendering/stacking/reloads,
font switching and user-heading fixes. It does not certify every layout,
account action, avatar, keyboard interaction or print output. See
FIXES-2026-10-16.md and TESTING.md.

## Updates and recovery

Build a new BOA platform with the reviewed newer theme revision, preserve
composer.json/composer.lock and custom code, then migrate/test sites. Aegir's
site Backup excludes shared platform code. Save site archives and platform
code separately off the server before a rebuild.

This custom repository does not provide Drupal.org update/security advisories.
Keep normal core/contributed-project update monitoring enabled and track this
theme's changes separately. Never commit Drupal settings, site uploads,
database dumps, SMTP passwords, Composer auth.json or server configuration.

References:

- [Composer VCS repositories](https://getcomposer.org/doc/05-repositories.md#vcs)
- [BOA platform workflow](https://docs.boa.io/using/deploying-code/building-a-platform)
- [BOA optional theme guide](https://github.com/actools-pl/boa/blob/hetzner-drupal11/hetzner/ACTOOLS_BS5.md)

