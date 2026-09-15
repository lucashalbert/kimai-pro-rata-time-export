# Kimai Pro Rata Time Export

A Kimai plugin that generates a compensation-equivalent timecard from Kimai's recorded timesheet data. The purpose of the plugin is to support users who perform work for multiple projects that have different hourly compensation rates but must submit a single employer timecard using a single nominal/base hourly rate. The plugin transparently converts each actual timesheet duration into an equivalent duration at a configurable employer base rate, while preserving the immutability of Kimai's original records.

**Status:** Feature-complete for v1. The bundle registers, configuration and override-field definitions are wired, the core compensation domain services are implemented (rate resolution, duration scaling, interval generation, exact-money arithmetic, compensation calculation, and reconciliation summaries), and the export surface — an HTML review/summary screen, an employer-facing CSV, an audit/reconciliation CSV, and an XLSX workbook — is available from Kimai's Export screen. See `.specs/kimai-pro-rata-time-export_SPEC.md` for the complete technical specification.

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

The employer base rate at which equivalent time is expressed is resolved per
source record. Configure the global fallback in Kimai's
`config/packages/local.yaml`:

```yaml
pro_rata_time_export:
    base_rate: 150.00
```

Project and customer overrides are available on Kimai's existing
Project/Customer edit forms. User overrides are available through Kimai user
preferences. The resolution order is project, customer, user, then global
fallback.

Every configured value must be greater than zero. There is no default — an
unconfigured instance fails with an explicit error rather than producing a
silently wrong timecard.

## Usage

From Kimai's **Time Tracking → Export** screen, filter to the reporting period (and users/projects) you want, then choose one of:

- **Compensation Equivalent (Review)** — an HTML page showing every source record with both its actual/recorded values and its compensation-equivalent values side by side, plus a summary (reporting period, actual vs. equivalent totals, rounding variance, per-user totals) and any warnings (excluded running records, overlapping source records, duration/timestamp disagreements). Review this before downloading a file.
- **Compensation Equivalent Timecard (CSV)** — the minimal employer-facing file: Date, User, Project, Start, End, using the compensation-equivalent start/end times. Also available from the Timesheet list's export dropdown.
- **Compensation Equivalent Reconciliation (Audit CSV)** — the full audit trail behind the employer-facing timecard: source timesheet ID, effective rate, employer base rate, conversion factor, actual and equivalent start/end/duration, and actual/equivalent/rounding-difference compensation values.
- **Compensation Equivalent Timecard (XLSX)** — one workbook with an "Employer Timecard" worksheet (the CSV's fields) and a "Reconciliation" worksheet (the audit CSV's fields).

Generating any of these never marks the underlying Kimai timesheets as exported and never changes them — see Immutability Guarantee above.

### Permissions

The review screen, the audit CSV, and the XLSX workbook all disclose rates and compensation values, so they require the same permission Kimai itself uses to gate rate visibility elsewhere: `view_rate_own_timesheet` when the export is scoped to a single user, `view_rate_other_timesheet` otherwise. A user without that permission is denied outright rather than shown a redacted view. The employer-facing CSV carries no rate data and is not gated.

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
