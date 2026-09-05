# Kimai Compensation Equivalent Timecard

A Kimai plugin that generates a compensation-equivalent timecard from Kimai's recorded timesheet data. The purpose of the plugin is to support users who perform work for multiple projects that have different hourly compensation rates but must submit a single employer timecard using a single nominal/base hourly rate. The plugin transparently converts each actual timesheet duration into an equivalent duration at a configurable employer base rate, while preserving the immutability of Kimai's original records.

**Status:** Pre-implementation. See `.specs/kimai-pro-rata-time-export_SPEC.md` for the complete technical specification.

## Immutability Guarantee

**This plugin does not modify Kimai timesheet records. It generates a compensation-equivalent representation from recorded timesheet data.**

All calculations are read-only transformations. Source Kimai data remains unchanged in all circumstances.

## Installation

Coming in a later change.

## Configuration

Coming in a later change.

## Usage

Coming in a later change.

## License

MIT
