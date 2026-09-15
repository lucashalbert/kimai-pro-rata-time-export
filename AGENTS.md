# Project agent memory

This file is the project's committed home for project-intrinsic agent knowledge: build, test, release, architecture, and sharp-edge notes that should travel with the code.

## What this repository is

A Kimai plugin, not a standalone application. The repository root **is** the bundle
directory: `ProRataTimeExportBundle.php` and `composer.json` must stay siblings at the
root, because Kimai reads a plugin's metadata from the directory holding its bundle
class. There is no `src/`.

Authoritative requirements: `.specs/kimai-pro-rata-time-export_SPEC.md` — read it before
changing behaviour; it is unusually prescriptive about rounding, immutability and
auditability.

Kimai API details for the supported 2.40.0–2.65.0 range (entity getters, rate resolution,
registration, export extension points), and why each was chosen:
`docs/kimai-version-notes.md`. Read that instead of re-investigating Kimai's source.

## Sharp edges

- The bundle class must remain a registered **service**. Kimai's `PluginManager` finds
  plugins via `#[TaggedIterator(PluginInterface::class)]`, so excluding the bundle class
  from `Resources/config/services.yaml` makes the plugin disappear from System → Plugins
  while everything else still appears to work — no error anywhere.
- Bundle class name, namespace segment and installation directory name must all be
  `ProRataTimeExportBundle`. Renaming one without the others silently stops discovery.
- The effective rate for a timesheet comes from the record itself
  (`getHourlyRate()`/`getRate()`), never from Kimai's `RateService` or the `*RateRepository`
  classes — those resolve *current* rates and would misprice historical records. Spec §40
  makes this a regression test.
- "Pro Rata Time Export" is the plugin/product name. "Actual" vs "Compensation Equivalent"
  is the deliberate user-facing vocabulary for the calculated time (spec §3.4, §52) and is
  a separate decision — do not rename one while chasing the other.
- Kimai's meta-field mechanism (`App\Entity\EntityWithMetaFields`) covers `Project` and
  `Customer` identically across 2.40.0–2.65.0, but **not** `User` — `User` instead has its
  own separate native mechanism, `UserPreference`/`getPreferenceValue()`. Both are equally
  "native, no migration, existing admin UI"; they just live on different admin screens.
  This is why the spec §7 base-rate hierarchy's project/customer overrides are Kimai meta
  fields but the user-level override is a Kimai user preference — see
  `docs/kimai-version-notes.md` §7 for the exact APIs, the non-persisted-`type` gotcha
  (`getValue()` returns a raw scalar unless a definition-event subscriber also ran in the
  same request), and the definition-event registration pattern
  (`ProjectMetaDefinitionEvent`/`CustomerMetaDefinitionEvent`) used to make
  those overrides editable through Kimai's UI.
- Most Kimai entity setters are fluent (`Timesheet::setUser()`/`setProject()`/`setActivity()`/`setBegin()`
  etc. return the entity), but `User::setUserIdentifier(string $identifier): void` is not — it returns
  `void`. `(new User())->setUserIdentifier('alice')` silently evaluates to `null`, not the user, with no
  error until something calls a method on the resulting `null`. Construct then call separately:
  `$user = new User(); $user->setUserIdentifier('alice');`.
- PHP's `DateTimeImmutable`/`DateTime` constructor silently ignores the `DateTimeZone`
  argument whenever the parsed string already carries a UTC offset (e.g.
  `'2026-03-08T01:30:00-05:00'`) — it builds a fixed-offset zone instead, so DST rules
  never apply and later arithmetic on that instant is wrong across a transition. Any test
  or code building a tz-aware instant for a named zone (`America/New_York`, etc.) must
  construct from an offset-free string (`'2026-03-08 01:30:00'`) plus the `DateTimeZone`.
  See `Service/IntervalGenerator.php` and its test for the working pattern.
- `composer.json` has no `require-dev` yet (no PHPUnit/PHPStan/CS fixer wired up), and CI
  (`.github/workflows/ci.yml`) only runs `composer validate`. The simplest way to run the
  suite: bring up `docker-compose.yml`'s Kimai container (on a non-default `KIMAI_PORT` if
  another worktree already holds 8001 — check with `docker ps`), then inside it
  `composer install` (Kimai's own `composer.json` already declares `phpunit/phpunit`,
  `symfony/phpunit-bridge`, etc. as `require-dev`; the prod image just ships without them).
  No bootstrap file is needed: Kimai's own `composer.json` autoload maps both `App\` → `src/`
  and `KimaiPlugin\` → `var/plugins/` (where this repo is mounted), so `vendor/autoload.php`
  already covers this plugin's namespace. Run with
  `docker compose exec -w /opt/kimai kimai vendor/bin/phpunit --bootstrap vendor/autoload.php
  var/plugins/ProRataTimeExportBundle/Tests/`. Don't add `require-dev` to the plugin's own
  `composer.json` for this — that's shared-file churn several workers would collide on; it
  belongs in the task that wires up CI test execution for real. Prefer reading Kimai source
  from an *already-running* container of a peer worktree (`docker ps` for another
  `*-kimai-1`) over starting your own when you only need to `cat` files — it's the same
  image and read-only inspection doesn't affect the other task.
- Kimai's `App\Export\ColumnConverter`/`TemplateInterface`/`AbstractSpreadsheetRenderer::writeSpreadsheet()`
  machinery (used by the built-in CSV/XLSX/HTML renderers) assumes a user-configurable column
  set resolved via entity getters (`Column::getValue($exportItem)`). It does not fit an
  exporter whose fields are computed/derived values with no corresponding getter (equivalent
  start/end, conversion factor, rounding difference, ...) — bypass it and use OpenSpout's
  writers directly (same library Kimai's own renderers use), reusing only
  `AbstractSpreadsheetRenderer::getFileResponse()` for the download response. See `Export/`.
- OpenSpout's `CSV\Writer` adds a UTF-8 BOM by default; Kimai's own `CsvRenderer` explicitly
  sets `(new CSV\Options())->SHOULD_ADD_BOM = false`, and any plugin CSV exporter should do
  the same or `str_getcsv`/other consumers choke on the leading BOM byte before a quoted
  first field. OpenSpout's XLSX writer additionally *drops* any row consisting only of
  empty-string cells when the file is read back — a literal blank "spacer" row silently
  disappears and shifts every later row index by one. Use a single-space cell (`' '`) instead
  of `''` for an intentionally blank row.
- A plugin's `Resources/views/` directory is auto-registered as a Twig namespace by
  `Symfony\Bundle\TwigBundle\DependencyInjection\TwigExtension::getBundleTemplatePaths()`,
  named after the bundle class with its `Bundle` suffix stripped — `ProRataTimeExportBundle`
  → `@ProRataTimeExport`. No plugin-side registration code needed; confirmed via
  `bin/console debug:twig` inside a booted container. A plugin's own HTML export view should
  be a self-contained document (own `<style>`), not `{% extends %}` Kimai's
  `export/layout.html.twig` — that app-level template pulls in front-end macros/asset loaders
  meant for Kimai's own configurable export templates.
- Views/exports that disclose rates or compensation values should gate on the same
  permission Kimai's own export code uses for that
  (`App\Export\ColumnConverter::isRenderRate()`): `view_rate_own_timesheet` when the export
  is scoped to a single user, `view_rate_other_timesheet` otherwise, via the
  `Symfony\Bundle\SecurityBundle\Security` service's `isGranted()`/`getUser()`. Don't invent a
  new permission for this.

## Commands

There is no local PHP toolchain assumption; everything runs through Docker.

```bash
docker compose up -d                 # Kimai 2.40.0 (oldest supported) at localhost:8001
KIMAI_TAG=2.65.0 docker compose up -d  # newest supported
docker compose exec kimai bin/console kimai:plugins   # confirm the plugin registers
docker run --rm -v "$PWD":/app -w /app composer:2 validate --strict --no-check-lock --no-check-publish
```

See README.md "Development environment" for credentials and teardown.

## Maintaining this file

Keep this file for knowledge useful to almost every future agent session in this project.
Do not repeat what the codebase already shows; point to the authoritative file or command instead.
Prefer rewriting or pruning existing entries over appending new ones.
When updating this file, preserve this bar for all agents and keep entries concise.
