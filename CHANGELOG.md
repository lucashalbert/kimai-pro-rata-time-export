# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

- Added the export/review surface: an HTML review renderer (`compensation-equivalent-review`) showing
  actual and compensation-equivalent values side by side plus a summary and warnings; an
  employer-facing CSV exporter (`compensation-equivalent-employer-csv`); an audit/reconciliation CSV
  exporter (`compensation-equivalent-audit-csv`); and an XLSX exporter
  (`compensation-equivalent-xlsx`) with "Employer Timecard" and "Reconciliation" worksheets. The
  review renderer, audit CSV and XLSX are gated on Kimai's own rate-viewing permission
  (`view_rate_own_timesheet` / `view_rate_other_timesheet`); the employer CSV carries no rate data
  and is not gated.
- Repository bootstrap; specification committed.
- Recorded the Kimai 2.40.0 vs 2.65.0 API investigation in `docs/kimai-version-notes.md`;
  no breaking difference on any extension point the plugin uses.
- Added the registrable plugin skeleton: bundle class, DI extension, `pro_rata_time_export.base_rate`
  configuration wiring, and compensation domain service structure.
- Added the exact-money value object used for currency calculations and display rounding.
- Implemented the rate resolver for record-stored hourly rates with fail-closed handling for missing,
  invalid, and fixed-rate records.
- Implemented `DurationScaler` with decimal arithmetic and unit coverage for minute rounding.
- Added a Docker Compose development environment (Kimai 2.40.0 by default, `KIMAI_TAG=2.65.0`
  for the newest supported version) and a `composer validate` CI workflow.
