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
  (`.github/workflows/ci.yml`) only runs `composer validate`. Until a shared dev-dependency
  set lands, run PHPUnit against a given test file via a disposable container: `composer
  require-dev`-only scratch project (outside the repo) installed with `docker run --rm -v
  <scratch>:/app -w /app composer:2 install`, plus a bootstrap script that
  `spl_autoload_register`s the `KimaiPlugin\ProRataTimeExportBundle\` prefix onto the repo
  root, run under `docker run ... php:8.2-cli php vendor/bin/phpunit --bootstrap
  bootstrap.php <path-to-test>`. Don't add `require-dev` to the plugin's own
  `composer.json` for a single service's tests — that's shared-file churn several workers
  would collide on; it belongs in the task that wires up CI test execution for real.

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
