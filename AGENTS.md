# AGENTS.md

Guidance for AI coding agents working on this repository.

## Project

PHP Server Monitor (phpservermon): a web application that checks whether websites and services
are up and sends notifications (email, SMS, Pushover, Telegram, Jabber/XMPP).

This repository is a fork (`koehnlein/phpservermon`) of the abandoned upstream project
(`phpservermon/phpservermon`, last release 3.5.2 in 2020). The fork contains a vibe-coded PHP 8
compatibility update (version 3.6.0-dev). See `CHANGELOG.rst` for what changed.

- PHP 8.2+ required, developed and tested with PHP 8.5.
- MySQL/MariaDB via PDO.
- Dependencies: Symfony 7.4 (DependencyInjection, EventDispatcher, HttpFoundation, Config,
  Filesystem), Twig 3, PHPMailer 6.
- There are no automated tests. Verification means linting plus manual testing in DDEV (see below).

## Local development with DDEV

The project ships a DDEV configuration (`.ddev/config.yaml`): PHP 8.5, Apache-FPM, MariaDB 11.8,
docroot is the repository root. The PHP image includes `intl`, `curl`, `pdo_mysql` and `xml`.

```sh
ddev start                 # https://phpservermon.ddev.site
ddev composer install      # vendor/ is not versioned
```

### config.php

`config.php` in the repository root is gitignored and belongs to the developer. **Never modify or
overwrite an existing `config.php`.** If it is missing, create it for DDEV with:

```php
<?php
define('PSM_DB_HOST', 'db');
define('PSM_DB_PORT', '');
define('PSM_DB_NAME', 'db');
define('PSM_DB_USER', 'db');
define('PSM_DB_PASS', 'db');
define('PSM_DB_PREFIX', 'psm_');
define('PSM_BASE_URL', 'https://phpservermon.ddev.site');
// define('PSM_DEBUG', true); // shows PHP notices/deprecations (error_reporting E_ALL)
```

Alternatively, without `config.php` the installer at `https://phpservermon.ddev.site/install.php`
offers to create one. Then open `install.php` once to create the tables and the first admin user.
Existing installations with an older version in the database are upgraded by the same installer.

### Useful commands

```sh
ddev launch                                  # open the app in the browser
ddev exec php cron/status.cron.php           # run the server checks (what the cronjob does)
ddev mailpit                                 # outgoing mails (PHPMailer via mail()) end up in Mailpit
ddev mysql                                   # database shell (tables use the prefix psm_)
ddev logs -f                                 # Apache/PHP-FPM error log
ddev composer <cmd>                          # composer with the container's PHP

# lint all PHP files (prints nothing but "lint done" if everything is fine)
ddev exec 'find . -name "*.php" -not -path "./vendor/*" -not -path "./.ddev/*" -print0 | xargs -0 -n1 php -l' | grep -v "^No syntax errors"; echo "lint done"
```

Reset to a fresh installation: `ddev delete -Oy && ddev start` (drops the database), then run the
installer again.

### Manual test checklist

After changes, test with `PSM_DEBUG` enabled and check `ddev logs` for notices, warnings and
deprecations:

- installer (fresh install and upgrade), login with "remember me", logout
- server list, status page with history graphs, server log, server create/edit/delete
- user add/edit/delete, profile, config page save (try English and German)
- test buttons on the config page (email goes to Mailpit; SMS/Pushover/Telegram/Jabber need
  real credentials or only show the error path)
- `ddev exec php cron/status.cron.php`

Jabber/XMPP needs an XMPP server; DDEV has none. For end-to-end tests, run e.g. a Prosody container
on the DDEV network (`ddev_default`) with a test account.

## Code structure

```
index.php, install.php     entry points (web UI, installer)
cron/status.cron.php       entry point for the cronjob (CLI, or web with PSM_CRON_ALLOW / PSM_WEBCRON_KEY)
src/bootstrap.php          loads config.php, defines paths, sets up error reporting and the autoloader
src/config/services.php    Symfony DI container configuration (PHP, replaces the old services.xml)
src/includes/              psmconfig.inc.php (constants, PSM_VERSION), functions.inc.php (global psm_* helpers)
src/lang/*.lang.php        translations (one array per language, key 'locale' used for date formatting)
src/psm/                   namespace psm\ (PSR-4)
  Router.php               builds the container and Twig environment, dispatches ?mod=<module>_<controller>
  Module/<Name>/           modules (Config, Error, Install, Server, User) with controllers and events
  Service/                 Database (PDO wrapper), User (session/auth)
  Txtmsg/                  one class per SMS gateway
  Util/Server/             updaters (status checks, notifications), archivers, history graph
  Util/Xmpp/XmppClient.php built-in minimal XMPP client used for Jabber notifications
  Util/Pushover/Pushover.php built-in minimal Pushover API client
src/templates/default/     Twig templates and static assets
```

## Things to know before changing code

- **Twig 3:** global variables cannot be added after the first template was rendered. Globals set
  by controllers (`subtitle`, `csrf_key`, `auto_refresh`, `auto_refresh_seconds`) are
  pre-registered in `Router::buildTwigEnvironment()`; add new ones there.
- **Symfony 7:** `ContainerAwareTrait` no longer exists; controllers get the container via their
  own `setContainer()`. Events extend `Symfony\Contracts\EventDispatcher\Event` and are dispatched
  as `dispatch($event, $eventName)`.
- **Container config:** `src/config/services.php`. The DB constants are undefined before
  `config.php` exists, hence the `$constant()` helper; `%` in values must be escaped as `%%`.
- **Dates:** use `psm_date()`, `psm_timespan()` or `psm_strftime()`, never `strftime()` (deprecated).
  `psm_strftime()` uses `IntlDateFormatter` and falls back to `date()` (English names) without intl.
- **Jabber:** JAXL was removed because it is broken on PHP 8. `psm_jabber_send_message()` uses
  `XmppClient` (STARTTLS or direct TLS on port 5223, SASL PLAIN/X-OAUTH2, errors in `logs/jabber.log`).
- **composer:** `config.platform.php` is pinned to 8.2.0 so the lock file stays installable on the
  minimum version. Keep it when updating dependencies.
- Don't call `curl_close()` (deprecated in PHP 8.5). Cast possibly-null values before passing them
  to string functions (`htmlentities`, `str_replace`, `setcookie`, ...).
- **Pushover:** the abandoned php-pushover library was replaced by `psm\Util\Pushover\Pushover`
  (JSON API, TLS verification enabled), created via `psm_build_pushover()`.
- With `PSM_DEBUG`, `bootstrap.php` enables `E_ALL` before the Composer autoloader runs, so any
  deprecation in a `files`-autoloaded vendor file is printed before the headers. Check new
  dependencies for that.
- `src/includes/password_compatibility_library.inc.php` is unused legacy code.
- `puphpet/` and `Vagrantfile` are the outdated upstream dev environment; use DDEV instead.

## Conventions

- Match the existing style: 4 spaces, PSR-2/PSR-12-like formatting, `array()` syntax, GPL license
  header in every PHP file, docblocks on methods.
- User-facing texts go into `src/lang/en_US.lang.php` (and other languages where possible), not
  into templates or PHP code.
- Update `CHANGELOG.rst` (section `v3.6.0 (not yet released)`) for user-visible changes.
- The version is defined in `src/includes/psmconfig.inc.php` (`PSM_VERSION`) and `README.rst`.
- Requirements are documented in `README.rst` and `docs/requirements.rst`; keep both in sync.

## Git

- Remotes: `origin` = upstream `phpservermon/phpservermon` (read-only), `koehnlein` = the fork.
- Branches: `develop` is the main development branch, `production` reflects releases.
  The PHP 8 work lives on `feature/php8-compatibility`.
- Don't commit or push unless asked. Never commit `config.php`, `vendor/` or files in `logs/`.
