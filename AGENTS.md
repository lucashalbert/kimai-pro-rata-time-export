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
