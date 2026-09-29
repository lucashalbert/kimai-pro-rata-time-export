# Kimai Pro Rata Time Export

A Kimai plugin that generates a compensation-equivalent timecard from recorded timesheet data. It serves users who work on projects with different hourly rates but must submit a single employer timecard at one base rate: each actual duration is converted to an equivalent duration at the employer base rate.

**Status:** Feature-complete for v1 (not yet released). Kimai's Export screen offers an HTML review/summary, an employer-facing CSV, an audit/reconciliation CSV, and an XLSX workbook. Full specification: `.specs/kimai-pro-rata-time-export_SPEC.md`.

## Immutability Guarantee

**This plugin does not modify Kimai timesheet records. It generates a compensation-equivalent representation from recorded timesheet data.** All calculations are read-only; source data is never changed.

## Compatibility

Kimai **2.40.0 through 2.65.0**, PHP 8.2+. Every extension point the plugin uses is identical across that range, so there is no version-detection code. `composer.json` declares `extra.kimai.require: 24000` (minimum version); Kimai enforces no upper bound. API comparison and rationale: [`docs/kimai-version-notes.md`](docs/kimai-version-notes.md).

## Installation

Kimai discovers plugins by directory name, which must match the bundle class name:

```
<kimai>/var/plugins/ProRataTimeExportBundle
```

Then run `bin/console kimai:reload`. The plugin appears under **System → Plugins**.

## Configuration

The employer base rate is resolved per source record, most specific first:

1. **Project** — *Employer Base Rate* field on the Project edit form.
2. **Customer** — *Employer Base Rate* field on the Customer edit form.
3. **User** — *Employer Base Rate* field in the user's Kimai preferences.

The first level with a value wins; unset levels are skipped. Fields show the currency symbol Kimai uses for that entity (the customer's currency, or the default user currency for the preference).

Every configured value must be greater than zero. An invalid value at any level is an error — it never falls through to a less specific level. There is no built-in default: if no level has a value, the export fails with "Employer base rate is not configured." rather than producing a silently wrong timecard.

## Mathematical model

For each completed source timesheet, the plugin reads the recorded duration and the hourly rate stored on that record, resolves the employer base rate, and calculates:

```text
factor = effective hourly rate / employer base rate
equivalent minutes = recorded seconds / 60 * factor
actual compensation = recorded seconds / 3600 * effective hourly rate
equivalent compensation = rounded equivalent minutes / 60 * employer base rate
rounding variance = equivalent compensation - actual compensation
```

The actual begin timestamp is kept as the equivalent start; the equivalent end is that start plus the rounded equivalent duration. Records stay independent: overlaps are flagged with warnings, not merged, deducted, or corrected.

## Rounding behavior

Equivalent durations round to the nearest whole minute, half-up: `29.4` → `29`, `29.5` → `30`, `29.6` → `30`. Actual durations stay second-precise in review and audit outputs. Currency values use the plugin's money value object, displayed at normal currency precision.

## Usage

Screenshot walkthrough: [usage guide](docs/usage-guide.md).

On **Time Tracking → Export**, filter to the reporting period (and users/projects), then choose:

- **Compensation Equivalent (Review)** — listed with the HTML exports. Every source record with actual and equivalent values side by side, plus a summary (period, actual vs. equivalent totals, rounding variance, per-user totals) and warnings (excluded running records, overlapping records, duration/timestamp disagreements). Review before downloading.
- **Compensation Equivalent Timecard (CSV)** — employer-facing rows (Date, User, Project, Start, End, using equivalent times), then summary/reconciliation totals and warnings. Also in the Timesheet list's export dropdown.
- **Compensation Equivalent Reconciliation (Audit CSV)** — full audit trail: source timesheet ID, effective rate, base rate, conversion factor, actual and equivalent start/end/duration, and actual/equivalent/rounding-difference compensation, then totals and warnings.
- **Compensation Equivalent Timecard (XLSX)** — workbook with "Employer Timecard", "Reconciliation", and "Summary" worksheets.

Exports never mark Kimai timesheets as exported or change them. Leave **Mark as exported** disabled; these exports abort when it is enabled.

CSVs are UTF-8 with deterministic column order, written through OpenSpout. When an equivalent end falls on a later date than its start, employer-facing output includes the end date so midnight-crossing records stay unambiguous.

### Permissions

All four outputs require the permission Kimai uses to gate rate visibility: `view_rate_own_timesheet` when the export is scoped to a single user, `view_rate_other_timesheet` otherwise. Without it, access is denied outright rather than redacted.

## Examples

With an employer base rate of `$150/hr`:

- A 3-hour Project A record at `$150/hr`, `09:00`–`12:00`, stays `09:00`–`12:00` and represents `$450.00`.
- A 4-hour Project B record at `$120/hr`, `13:00`–`17:00`, has factor `0.8` and becomes `13:00`–`16:12`. Actual and equivalent compensation are both `$480.00`; variance `$0.00`.
- A 37-minute record at `$120/hr` has an exact equivalent of `29.6` minutes, rounded to `30`. A `09:17` start exports as `09:17`–`09:47`: actual `$74.00`, equivalent `$75.00`, variance `$1.00`.

## Known limitations

The plugin does not submit timecards to an employer, replace Kimai's time tracking, or create, modify, delete, merge, split, or mark timesheets as exported.

Running timesheets are excluded with warnings (no final duration). Missing, zero, invalid, or fixed-only effective rates fail closed instead of falling back to current project rates. Reports reflect source data at generation time; prior reports are not kept.

## Development environment

`docker-compose.yml` provides a local Kimai + MariaDB stack with this repository mounted read-only as the plugin directory.

```bash
docker compose up -d          # Kimai 2.40.0, the oldest supported version
open http://localhost:8001    # log in as admin@kimai.local / changemeplease
```

First boot runs migrations and takes a minute or two; follow with `docker compose logs -f kimai`. To test the newest supported version: `KIMAI_TAG=2.65.0 docker compose up -d`. Copy `.env.example` to `.env` to change ports, credentials or the image tag. Tear down with `docker compose down -v`.

After editing plugin code, reload the cache:

```bash
docker compose exec kimai bin/console kimai:reload
```

Validate the manifest without a local PHP toolchain:

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 validate --strict --no-check-lock --no-check-publish
```

## Releasing

Run the **Release** workflow from the **Actions** tab and enter the semantic version without a leading `v` (e.g. `1.2.0`). It creates the annotated tag `v1.2.0` on the selected branch and a GitHub Release combining the matching `CHANGELOG.md` section with GitHub's auto-generated notes. First move the `[Unreleased]` entries under the new version heading in `CHANGELOG.md`.

## License

MIT
