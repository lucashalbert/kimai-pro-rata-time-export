# Kimai Pro Rata Time Export

A Kimai plugin that generates a compensation-equivalent timecard from Kimai's recorded timesheet data. The purpose of the plugin is to support users who perform work for multiple projects that have different hourly compensation rates but must submit a single employer timecard using a single nominal/base hourly rate. The plugin transparently converts each actual timesheet duration into an equivalent duration at a configurable employer base rate, while preserving the immutability of Kimai's original records.

**Status:** Partial implementation. The bundle registers, `IntervalGenerator` is implemented, and the exact-money value object is implemented; the remaining compensation domain services, exporters and UI are not implemented yet. See `.specs/kimai-pro-rata-time-export_SPEC.md` for the complete technical specification.

## Immutability Guarantee

**This plugin does not modify Kimai timesheet records. It generates a compensation-equivalent representation from recorded timesheet data.**

All calculations are read-only transformations. Source Kimai data remains unchanged in all circumstances.

## Compatibility

Targets **Kimai 2.40.0 through 2.65.0** on PHP 8.2+.

Every extension point the plugin uses — the `Timesheet` entity, the plugin/bundle registration mechanism, and the export renderer interfaces — is identical across that range, so there is no version-detection code. `composer.json` declares `extra.kimai.require: 24000` (Kimai's minimum-version metadata); Kimai does not enforce an upper bound.

The full API comparison, and the reasoning behind which extension points the plugin uses, is in [`docs/kimai-version-notes.md`](docs/kimai-version-notes.md).

## Installation

Kimai discovers plugins by directory name, which must match the bundle class name. Install into your Kimai instance as:

```
<kimai>/var/plugins/ProRataTimeExportBundle
```

then clear the cache (`bin/console kimai:reload`). The plugin appears under **System → Plugins**.

## Configuration

The employer base rate at which equivalent time is expressed, set in Kimai's `config/packages/local.yaml`:

```yaml
pro_rata_time_export:
    base_rate: 150.00
```

`base_rate` must be greater than zero. There is no default — an unconfigured instance fails with an explicit error rather than producing a silently wrong timecard.

## Usage

Coming in a later change.

## Development environment

There is a local Kimai + MariaDB stack in `docker-compose.yml`, with this repository mounted read-only as the plugin directory.

```bash
docker compose up -d          # Kimai 2.40.0, the oldest supported version
open http://localhost:8001    # log in as admin@kimai.local / changemeplease
```

First boot runs the database migrations and takes a minute or two; follow it with `docker compose logs -f kimai`.

To verify against the newest supported version instead:

```bash
KIMAI_TAG=2.65.0 docker compose up -d
```

Copy `.env.example` to `.env` to change ports, credentials or the image tag. Tear down with `docker compose down -v`.

After editing plugin code, reload Kimai's cache:

```bash
docker compose exec kimai bin/console kimai:reload
```

To validate the plugin manifest without a local PHP toolchain:

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 validate --strict --no-check-lock --no-check-publish
```

## License

MIT
