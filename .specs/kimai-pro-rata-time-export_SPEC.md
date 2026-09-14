# Kimai Pro Rata Time Export

## Software Design & Development Specification

**Project:** Kimai Pro Rata Time Export Plugin
**Repository:** Personal GitHub repository
**Target Platform:** Self-hosted Kimai
**Primary Language:** PHP
**Framework:** Symfony / Kimai Plugin API
**Status:** Implementation specification
**Audience:** AI coding agent / human maintainer
**Priority:** Accuracy, transparency, immutability, auditability

---

# 1. Executive Summary

Build a Kimai plugin that generates a **compensation-equivalent timecard** from Kimai's recorded timesheet data.

The purpose of the plugin is to support users who perform work for multiple projects that have different hourly compensation rates but must submit a single employer timecard using a single nominal/base hourly rate.

The plugin MUST:

1. Treat Kimai's recorded timesheets as the authoritative record of actual work performed.
2. Never modify, delete, recalculate, or otherwise mutate the underlying Kimai timesheet records.
3. Read the effective rate associated with each Kimai timesheet record.
4. Convert each actual timesheet duration into an equivalent duration at a configurable employer base rate.
5. Represent the resulting equivalent duration as a start/end interval with minute precision.
6. Display both:

   * the actual recorded work
   * the compensation-equivalent work
7. Provide transparent reconciliation between actual time, actual rate, actual compensation value, and equivalent time.
8. Support multiple users.
9. Respect Kimai's existing authorization model.
10. Produce deterministic results: the same source data and configuration MUST always produce the same output.
11. Be suitable for recurring monthly reporting and review.
12. Be developed as a standalone, version-controlled Kimai plugin suitable for installation/upgrades through the normal Kimai plugin mechanism.

The plugin is a **reporting/export transformation**, not a payroll calculator and not a timesheet modification mechanism.

---

# 2. Problem Statement

The employer requires a single timecard structure with:

* start time
* end time
* minute-level granularity

The employer timecard uses a single nominal compensation rate.

An employee may work on multiple projects with different effective rates.

Example:

```text
Employer base rate: $150/hour

Project A: $150/hour
Project B: $120/hour
```

One hour worked on Project A is equivalent to:

```text
1.0 × $150 = $150
```

One hour worked on Project B is equivalent to:

```text
1.0 × $120 = $120
```

Therefore Project B requires:

```text
$120 / $150 = 0.8
```

of the duration when represented at the employer's $150/hour base rate.

Thus:

```text
Actual:
Project B
09:00 → 13:00
4:00 actual hours

Equivalent:
09:00 → 12:12
3:12 equivalent hours

4:00 × $120 = $480
3:12 × $150 = $480
```

The plugin must preserve the original 09:00–13:00 Kimai record and produce the 09:00–12:12 interval only in the derived compensation-equivalent representation.

---

# 3. Design Principles

## 3.1 Immutability

Kimai data is authoritative.

The plugin MUST NOT:

* modify `Timesheet.begin`
* modify `Timesheet.end`
* modify `Timesheet.duration`
* modify `Timesheet.rate`
* modify `Timesheet.hourlyRate`
* modify `Timesheet.fixedRate`
* modify project rates
* modify activity rates
* modify customer rates
* modify user rates
* delete timesheets
* create replacement timesheets
* mark timesheets exported merely because the compensation-equivalent report was generated

The plugin MUST operate as a read-only transformation.

A user must be able to compare:

```text
Kimai actual record
        ↓
Compensation-equivalent representation
```

without any possibility that the latter altered the former.

---

## 3.2 Transparency

Every generated compensation-equivalent value MUST be explainable from the underlying Kimai record.

A user reviewing an export should be able to determine:

```text
Actual duration
Actual effective rate
Employer base rate
Conversion factor
Equivalent duration
Actual compensation value
Equivalent compensation value
```

The following equality should hold within documented rounding tolerance:

```text
actual_duration × actual_rate
≈
equivalent_duration × employer_base_rate
```

---

## 3.3 Determinism

Given:

* the same Kimai timesheet records
* the same effective rates
* the same employer base rate
* the same rounding policy

the plugin MUST produce identical output.

No randomization, current-time dependency, or order-dependent calculation may affect the result.

---

## 3.4 No Reinterpretation of Actual Work

The plugin must never describe the equivalent interval as actual working time.

Use terminology such as:

* Actual
* Recorded
* Kimai Recorded
* Compensation Equivalent
* Scaled
* Equivalent

Avoid terminology such as:

* Actual Payroll Time
* Actual Work Time
* Corrected Time
* Adjusted Work Time

The employer-facing output is a compensation-equivalent representation.

---

# 4. Kimai Integration

Kimai is a Symfony-based application and supports plugins as Symfony bundles. Plugins can provide custom export renderers and interact with the existing timesheet/export infrastructure.

The implementation SHOULD use Kimai's supported plugin extension points rather than modifying Kimai core.

The preferred integration is a **custom timesheet export renderer/exporter**.

Kimai's export system already operates on filtered timesheet data and supports CSV, XLSX, PDF, and HTML output.

The initial implementation SHOULD provide:

1. A compensation-equivalent export action.
2. A human-readable review view.
3. CSV export.
4. XLSX export if practical within the supported Kimai export architecture.

Do NOT modify Kimai core files.

---

# 5. Plugin Architecture

Suggested package name:

```text
KimaiProRataTimeExportBundle
```

Suggested namespace:

```text
App\Plugin\ProRataTimeExport
```

or another namespace consistent with the final repository/package naming.

The implementation SHOULD use a modular architecture similar to:

```text
src/
├── CompensationEquivalentBundle.php
├── Configuration/
│   ├── Configuration.php
│   └── CompensationConfiguration.php
├── Controller/
│   └── ...
├── Export/
│   ├── CompensationEquivalentExporter.php
│   ├── CompensationEquivalentCsvExporter.php
│   └── CompensationEquivalentXlsxExporter.php
├── Model/
│   ├── CompensationEquivalentRecord.php
│   └── CompensationEquivalentSummary.php
├── Service/
│   ├── CompensationCalculator.php
│   ├── RateResolver.php
│   ├── DurationScaler.php
│   ├── IntervalGenerator.php
│   └── ReconciliationService.php
├── DependencyInjection/
│   └── ...
├── Resources/
│   ├── config/
│   ├── translations/
│   └── views/
└── Tests/
    ├── Unit/
    ├── Integration/
    └── Functional/
```

The exact class structure may differ if required by the target Kimai version, but the separation of concerns MUST be preserved.

---

# 6. Rate Resolution

Kimai supports rates at multiple levels and stores/calculates rate information on timesheet records. Rate changes apply to future records and do not retroactively modify existing records.

The plugin MUST therefore prefer the **effective rate associated with the individual Timesheet record**.

Do NOT simply query the current project rate and assume it represents the historical timesheet rate.

## Required rate resolution behavior

For each source timesheet:

```text
effective_rate = rate represented by the Timesheet record
```

The implementation should use Kimai's supported APIs/entities/services for determining this value.

If the effective rate is unavailable or zero:

* Do not silently assume a rate.
* Do not silently use the current project rate.
* Produce a clear error/warning.
* The export SHOULD either fail safely or exclude the record according to a documented configuration option.

Default behavior SHOULD be:

```text
FAIL THE EXPORT WITH A CLEAR ERROR
```

because silently producing an incorrect compensation-equivalent timecard is unacceptable.

---

# 7. Employer Base Rate

The plugin MUST have a configurable employer base rate: the rate at which
equivalent time is represented.

The base rate MUST NOT be confused with Kimai's customer/project billing
rates.

## 7.1 Hierarchical Override

The base rate is not a single global value. It MUST be resolvable per source
record through a four-level hierarchy, most specific wins:

```text
1. Project override   (most specific)
2. Customer override
3. User override
4. Global default      (least specific configured fallback)
```

For a given source record, the plugin resolves the record's project, the
project's customer, and the record's user, and uses the first level in that
order that has a configured value. A level with no configured value is
skipped; it does not disqualify a less specific level.

## 7.2 Storage Per Level

Levels are stored using Kimai's own native per-entity mechanisms — no
plugin-owned database table or migration:

```text
Project override    Kimai meta field on the Project entity
Customer override    Kimai meta field on the Customer entity
User override        Kimai user preference on the User entity
Global default        Plugin configuration (unchanged from the single-value design)
```

Project and Customer both implement Kimai's `EntityWithMetaFields` /
`MetaTableTypeInterface` meta-field mechanism identically across the
2.40.0–2.65.0 supported range: a plugin-defined meta field is edited through
Kimai's existing Project/Customer edit forms, with no custom controller.

`User` does **not** implement `EntityWithMetaFields` in any supported version.
Kimai gives `User` a separate, equally native mechanism instead — user
preferences (`UserPreference`, read via `User::getPreferenceValue()`) — edited
through Kimai's existing user profile/preferences screen, again with no custom
controller. The user-level override therefore uses this mechanism rather than
a meta field. This is a deliberate, investigated choice (not a limitation):
mixing the two native mechanisms across the hierarchy costs nothing in
migrations or custom UI, at the cost of the override living in two different
admin screens depending on level.

Defining the meta fields/preference (so they are visible and editable in
Kimai's admin UI) is implemented separately from resolving them; see
`docs/kimai-version-notes.md` §7 for the exact registration APIs.

The global default keeps its existing single-value configuration:

```yaml
pro_rata_time_export:
    base_rate: 150.00
```

The configuration mechanism should follow current Kimai plugin configuration
conventions.

## 7.3 Validation

The plugin MUST validate, independently at **every** level that has a
configured value:

```text
value > 0
```

An invalid or non-positive value at any level (zero, negative, or
non-numeric) is an error and MUST be reported immediately. It MUST NOT be
silently skipped in favor of a less specific level — a bad override must
surface to whoever configured it, not quietly resolve to a different number
that happens to look plausible.

If no level has any configured value at all, the plugin fails with the
existing "employer base rate is not configured" error (spec §32).

---

# 8. Conversion Formula

For each source timesheet:

```text
actual_minutes =
    recorded duration in minutes

effective_rate =
    Kimai effective hourly compensation rate

base_rate =
    configured employer base rate

factor =
    effective_rate / base_rate

equivalent_minutes_exact =
    actual_minutes × factor
```

Example:

```text
actual = 240 minutes
effective rate = $120
base rate = $150

factor = 120 / 150
       = 0.8

equivalent = 240 × 0.8
           = 192 minutes
           = 3:12
```

---

# 9. Duration Source

The plugin MUST use the authoritative duration represented by the Kimai Timesheet record.

Do not independently recompute duration from `begin` and `end` if doing so would contradict Kimai's stored/rounded duration semantics.

Kimai documents that the duration of a timesheet record can be rounded and may not always equal the raw difference between `begin` and `end`.

Therefore the plugin MUST distinguish:

```text
Recorded duration
```

from:

```text
Wall-clock begin/end difference
```

The actual recorded duration is the value used for compensation conversion.

If the source record's duration and timestamps disagree because of Kimai rounding, the export MUST expose that fact rather than silently hiding it.

---

# 10. Minute Rounding

Employer timecards require minute-level granularity.

The plugin MUST therefore convert the exact equivalent duration to an integer number of minutes.

Default rounding policy:

```text
nearest whole minute
```

using a clearly documented deterministic rounding rule.

Recommended:

```text
0.0–0.499999 minutes → round down
0.5–0.999999 minutes → round up
```

Examples:

```text
29.4 → 29 minutes
29.5 → 30 minutes
29.6 → 30 minutes
```

The rounding behavior MUST be centralized in a dedicated service:

```text
DurationScaler
```

and MUST have unit tests.

---

# 11. Start/End Transformation

The employer requires start and end timestamps.

For each source timesheet:

```text
equivalent_start = actual_begin

equivalent_end =
    actual_begin + equivalent_duration
```

Example:

```text
Actual:
13:00 → 17:00
240 minutes

Equivalent:
13:00 → 16:12
192 minutes
```

The plugin MUST NOT alter the actual start time.

The default interval transformation policy is:

> Preserve the actual start time and shorten the interval toward the end.

This policy MUST be encapsulated in a service so that another policy can be introduced later without rewriting the compensation calculation.

Potential future policies may include:

* preserve end
* center-compress
* distribute equivalent duration across a sequence

but these are explicitly out of scope for the initial release.

---

# 12. Multiple Timesheets

Each Kimai timesheet MUST produce an independent derived compensation-equivalent record.

Example:

```text
Actual:

09:00–10:30 Project A
10:30–12:00 Project B
13:00–17:00 Project B
```

Equivalent:

```text
09:00–10:30 Project A
10:30–11:42 Project B
13:00–16:12 Project B
```

The plugin MUST NOT merge records by default.

Preserving the source-record identity is critical for auditability.

Each derived record SHOULD include:

```text
source_timesheet_id
```

in the detailed/reconciliation output.

---

# 13. Overlapping Source Records

Kimai may contain overlapping timesheet records.

The plugin MUST NOT attempt to "fix" overlaps.

Each source record is independently transformed.

Example:

```text
A: 09:00–11:00
B: 10:00–12:00
```

The plugin produces:

```text
A equivalent interval
B equivalent interval
```

without modifying either source record.

The review/export output MUST make it clear that source records overlap.

A warning SHOULD be displayed:

```text
Warning: source timesheet records overlap.
Compensation-equivalent values were calculated independently.
No source records were modified.
```

Do not silently deduplicate or subtract overlapping time.

---

# 14. Zero-Duration Records

If:

```text
actual_duration = 0
```

the plugin SHOULD preserve the record in the detailed reconciliation output but MUST NOT generate an invalid negative or malformed interval.

Equivalent duration:

```text
0 minutes
```

Equivalent end:

```text
equivalent_start
```

The record may be excluded from the employer-facing export if the target format cannot represent zero-duration entries.

This behavior MUST be documented and tested.

---

# 15. Running Timesheets

A currently running timesheet does not have a final duration.

The plugin MUST NOT silently export a running record.

Default behavior:

```text
exclude running records and display a warning
```

Alternative configuration may support rejecting the entire export.

Recommended default:

```text
Export completes
Running records are excluded
A prominent warning identifies them
```

This avoids generating a compensation-equivalent timecard from incomplete data.

---

# 16. Midnight-Crossing Records

The plugin MUST correctly handle records crossing midnight.

Example:

```text
Actual:
23:00 → 02:00
```

The equivalent interval must be calculated using the actual timestamp/date context.

Do NOT assume that `end` occurs on the same calendar date as `begin`.

Tests MUST include:

```text
23:00 → 01:00
22:30 → 00:30
23:59 → 00:01
```

---

# 17. Daylight Saving Time

The plugin MUST handle timezone-aware timestamps correctly.

Do not perform naive arithmetic on formatted local time strings.

Calculations MUST use timezone-aware date/time objects.

Tests MUST cover:

1. Spring-forward DST transition.
2. Fall-back DST transition.
3. Records spanning a DST transition.
4. Users in different timezones.

Kimai uses the user's configured timezone when recording timesheet entries.

The plugin MUST preserve the source record's intended timezone semantics.

---

# 18. User Isolation and Permissions

The plugin will be used by multiple team members.

It MUST respect Kimai's authorization model.

A user exporting their own data MUST NOT gain access to:

* other users' timesheets
* other users' rates
* other users' projects
* other users' compensation data

unless their Kimai permissions already permit access to those records.

Kimai has distinct permissions for exporting own and other users' timesheets and for viewing rates.

The plugin MUST NOT bypass these permissions.

For team/admin exports, the plugin MUST preserve the user identity associated with each source record.

---

# 19. Review UI

The plugin SHOULD provide an intermediate review view before download.

The review view is important because transparency and accuracy are primary requirements.

For each source record display:

| Field               | Description                        |
| ------------------- | ---------------------------------- |
| User                | Source Kimai user                  |
| Date                | Source date                        |
| Customer            | Kimai customer                     |
| Project             | Kimai project                      |
| Activity            | Kimai activity                     |
| Actual Start        | Original Kimai start               |
| Actual End          | Original Kimai end                 |
| Actual Duration     | Original Kimai recorded duration   |
| Effective Rate      | Rate associated with source record |
| Base Rate           | Employer compensation rate         |
| Factor              | Rate ÷ base rate                   |
| Equivalent Start    | Derived start                      |
| Equivalent End      | Derived end                        |
| Equivalent Duration | Derived duration                   |
| Actual Value        | Actual duration × effective rate   |
| Equivalent Value    | Equivalent duration × base rate    |
| Difference          | Rounding discrepancy               |
| Source ID           | Kimai timesheet ID                 |

The UI SHOULD visually distinguish:

```text
ACTUAL / RECORDED
```

from:

```text
COMPENSATION EQUIVALENT
```

Do not visually imply that the equivalent times are actual working times.

---

# 20. Summary View

The plugin SHOULD provide a summary before export.

Example:

```text
Compensation Equivalent Summary

Reporting Period:
September 1–30, 2026

Users:
12

Recorded Work:
1,247h 36m

Actual Compensation Value:
$178,420.00

Employer Base Rate:
$150.00/hr

Compensation Equivalent:
1,189h 28m

Rounding Difference:
$0.17
```

The summary SHOULD additionally provide per-user totals.

Example:

```text
User             Actual       Actual Value    Equivalent
---------------------------------------------------------
Alice            164:32       $24,580.00       163:52
Bob              151:10       $22,740.00       146:32
Charlie          172:44       $25,921.00       169:48
```

This feature is particularly important for monthly all-hands reporting.

---

# 21. Reconciliation Requirements

For every export:

```text
sum(actual compensation)
```

MUST be compared with:

```text
sum(equivalent minutes) × base_rate
```

The difference MUST be reported.

Example:

```text
Actual compensation:
$9,742.00

Equivalent compensation:
$9,741.25

Rounding variance:
-$0.75
```

The plugin MUST NOT hide the rounding difference.

The summary SHOULD include:

```text
Rounding variance
```

both in absolute currency and, where useful, equivalent minutes.

---

# 22. Employer-Facing Export

The employer-facing export MUST be intentionally simple.

Minimum fields:

```text
Date
User
Project
Start
End
```

Additional fields may be configurable.

The employer-facing representation MUST contain the **scaled/compensation-equivalent start and end times**, not the actual times.

Example:

```text
Date       Project       Start    End
09/03/26   Project A     09:00    12:00
09/03/26   Project B     13:00    16:12
```

---

# 23. Audit/Reconciliation Export

The plugin SHOULD provide a second, detailed export.

Recommended fields:

```text
Source Timesheet ID
User
Date
Customer
Project
Activity

Actual Start
Actual End
Actual Duration

Effective Rate
Employer Base Rate
Conversion Factor

Equivalent Start
Equivalent End
Equivalent Duration

Actual Compensation
Equivalent Compensation
Rounding Difference
```

This export is the authoritative audit trail for how the employer-facing timecard was derived.

---

# 24. Monthly Reporting

The plugin MUST support arbitrary date filtering so that a monthly period can be selected.

The plugin SHOULD support:

```text
Current month
Previous month
Custom date range
```

Monthly reporting MUST aggregate from the underlying immutable Kimai records.

The plugin MUST NOT maintain a separate duplicate database of timesheet data unless absolutely required by Kimai's architecture.

The preferred model is:

```text
Kimai database
     ↓
filtered query
     ↓
read-only transformation
     ↓
report
```

This ensures the monthly report always reflects the source of truth.

---

# 25. Export State

Generating this report MUST NOT automatically mark Kimai timesheets as exported.

Kimai's standard export functionality can mark timesheets as exported and lock them.

The compensation-equivalent report is a derived reporting operation and must not have side effects.

Therefore:

```text
Generate compensation report
    ≠
Mark Kimai records as exported
```

If future requirements demand an explicit "processed" workflow, it must be implemented as a separate opt-in feature and clearly documented.

---

# 26. Configuration

Minimum configuration:

```yaml
pro_rata_time_export:
    base_rate: 150.00
```

Future configuration SHOULD support:

```yaml
pro_rata_time_export:
    base_rate: 150.00

    rounding:
        mode: nearest
        precision: minute

    interval:
        strategy: preserve_start

    running_timesheets:
        behavior: warn_and_exclude

    missing_rates:
        behavior: fail

    output:
        include_actual: true
        include_reconciliation: true
```

Configuration names are illustrative; adapt them to Kimai/Symfony conventions.

---

# 27. Precision and Currency

Rates MUST use appropriate decimal arithmetic.

Do not use binary floating-point arithmetic for monetary calculations if avoidable.

Use:

* integer cents
* decimal arithmetic
* or an appropriate money/value-object abstraction

for currency calculations.

Duration calculations SHOULD use integer seconds/minutes wherever possible.

The implementation MUST avoid cumulative floating-point drift.

---

# 28. Rounding Strategy and Compensation Accuracy

There are two separate rounding domains:

### Duration rounding

Equivalent durations are rounded to whole minutes because the employer requires minute granularity.

### Monetary reconciliation

Actual compensation should be calculated using appropriate monetary precision.

The plugin MUST retain enough precision internally to avoid unnecessary cumulative errors.

The plugin MUST NOT round intermediate calculations unnecessarily.

Recommended calculation flow:

```text
source duration
    ↓
exact rate factor
    ↓
exact equivalent duration
    ↓
minute rounding
    ↓
equivalent monetary value
    ↓
currency rounding for display
```

---

# 29. Source Record Identity

Every derived record MUST retain the source Kimai Timesheet ID internally.

Example:

```text
source_timesheet_id = 18472
```

This is essential for auditability.

If the source record is later changed, a newly generated report may change.

The plugin MUST NOT attempt to maintain stale copies.

The detailed export SHOULD expose the source ID.

---

# 30. Immutability Verification

The test suite MUST explicitly verify that generating reports produces no database mutations.

At minimum:

1. Capture the source Timesheet entity state.
2. Generate the report.
3. Reload the source Timesheet from persistence.
4. Assert that all relevant fields remain identical.

Fields MUST include at minimum:

```text
begin
end
duration
rate
hourlyRate
fixedRate
user
customer
project
activity
description
tags
billable
exported
```

No database UPDATE/DELETE/INSERT operations against Timesheet records should occur during normal report generation.

Where practical, integration tests SHOULD assert this at the persistence/query level.

---

# 31. Security

The plugin MUST follow Kimai's existing authentication and authorization.

Do not create an alternative authentication mechanism.

Do not expose an unrestricted endpoint for compensation data.

Compensation data is sensitive.

Exports containing rates SHOULD require appropriate permissions.

Do not expose effective rates to users who cannot normally view rates.

Do not allow a user to manipulate query parameters to retrieve another user's data.

All user-provided filter values MUST be validated through Kimai's existing query/filter mechanisms.

---

# 32. Error Handling

Errors MUST be explicit.

Examples:

### Missing base rate

```text
Unable to generate compensation-equivalent report:
Employer base rate is not configured.
```

### Missing effective rate

```text
Unable to generate compensation-equivalent report:
Timesheet #18472 has no effective hourly rate.
```

### Invalid rate

```text
Timesheet #18472 has an invalid effective rate.
```

### Running timesheet

```text
Timesheet #18472 is currently running and was excluded.
```

Errors MUST NOT silently result in incorrect compensation values.

---

# 33. Logging

Normal report generation SHOULD NOT generate excessive logs.

Warnings SHOULD be logged for:

* excluded running records
* missing rates
* invalid rates
* overlapping records
* unusual duration/timestamp inconsistencies

Logs MUST NOT unnecessarily contain sensitive compensation information.

Do not log:

* full compensation reports
* unnecessary user compensation details
* authentication credentials
* API tokens

---

# 34. Testing Strategy

The plugin MUST have automated tests.

Minimum required test categories:

```text
Unit tests
Integration tests
Functional tests
Regression tests
```

---

# 35. Unit Tests — Core Mathematics

Test:

```text
$150 → $150
factor = 1.0
```

Expected:

```text
60 min → 60 min
```

Test:

```text
$120 → $150
factor = 0.8
```

Expected:

```text
60 min → 48 min
120 min → 96 min
180 min → 144 min
240 min → 192 min
```

Test:

```text
$135 → $150
factor = 0.9
```

Expected:

```text
60 → 54
```

Test a rate greater than base rate:

```text
$180 → $150
factor = 1.2
60 → 72
```

The implementation MUST support factors greater than 1.

---

# 36. Unit Tests — Rounding

Test:

```text
37 × .8 = 29.6 → 30
```

Test:

```text
36 × .8 = 28.8 → 29
```

Test exact half-minute behavior.

Test:

```text
1 × .8 = .8 → 1
```

Test zero.

Test very large durations.

---

# 37. Unit Tests — Interval Generation

Test:

```text
09:00 + 192 minutes = 12:12
```

Test:

```text
23:00 + 90 minutes = 00:30 next day
```

Test:

```text
23:59 + 2 minutes = 00:01 next day
```

Verify the date component changes correctly.

---

# 38. DST Tests

At minimum test:

### Spring forward

A record crossing the DST boundary.

### Fall back

A record crossing the repeated-hour boundary.

Verify elapsed duration is correct.

Do not rely on local clock subtraction.

---

# 39. Multiple User Tests

Create test users with:

```text
User A
User B
```

Give them different projects/timesheets.

Verify:

* User A cannot retrieve User B's records without permission.
* Admin/team users can retrieve permitted records.
* User identity is preserved in exports.

---

# 40. Rate History Tests

Create:

```text
Project A = $120
```

Create a timesheet.

Change Project A to:

```text
$150
```

Create another timesheet.

Verify:

```text
old timesheet → $120
new timesheet → $150
```

The plugin MUST use the effective rate associated with each source record rather than the current project rate.

This is a critical regression test.

---

# 41. Immutability Tests

Before export capture:

```text
Timesheet #1
Timesheet #2
...
```

After export verify:

```text
begin unchanged
end unchanged
duration unchanged
rate unchanged
project unchanged
activity unchanged
user unchanged
export state unchanged
```

The test SHOULD verify that no Timesheet persistence mutation occurred.

---

# 42. Overlap Tests

Input:

```text
A 09:00–11:00
B 10:00–12:00
```

Verify both are independently transformed.

Verify no automatic subtraction or deduplication.

Verify a warning is generated/displayed.

---

# 43. Running Record Tests

Input:

```text
A 09:00–[running]
```

Verify:

* record is not converted
* record is not mutated
* report warning is generated
* behavior follows configuration

---

# 44. Zero Duration Tests

Input:

```text
09:00–09:00
duration = 0
```

Verify no negative duration.

Verify export behavior is deterministic.

---

# 45. Missing Rate Tests

Input:

```text
Timesheet effective rate = null/zero
```

Verify the default behavior is a clear error.

Verify no fallback to the current project rate unless explicitly configured.

---

# 46. End-to-End Example Test

Given:

```text
Employer base rate: $150

Timesheet 1:
Project A
09:00–12:00
180 minutes
$150/hr

Timesheet 2:
Project B
13:00–17:00
240 minutes
$120/hr
```

Expected actual:

```text
Project A:
180 min × $150 / 60 = $450

Project B:
240 min × $120 / 60 = $480

Total:
$930
```

Expected equivalent:

```text
Project A:
180 minutes

Project B:
240 × .8 = 192 minutes
```

Expected employer representation:

```text
Project A:
09:00–12:00

Project B:
13:00–16:12
```

Total equivalent:

```text
372 minutes
6h12m
```

Reconciliation:

```text
372 min × $150 / 60
= $930
```

Expected variance:

```text
$0.00
```

---

# 47. Another End-to-End Example With Rounding

Given:

```text
Base rate: $150

Project B:
Actual duration = 37 minutes
Rate = $120
```

Exact equivalent:

```text
37 × .8
= 29.6 minutes
```

Rounded:

```text
30 minutes
```

If actual start:

```text
09:17
```

Expected equivalent:

```text
09:17–09:47
```

The report MUST disclose the rounding difference.

Actual value:

```text
37 × $120 / 60
= $74.00
```

Equivalent displayed value:

```text
30 × $150 / 60
= $75.00
```

Variance:

```text
+$1.00
```

The user must be able to see this rather than having the discrepancy hidden.

---

# 48. Monthly All-Hands Reporting

The plugin SHOULD support a high-level monthly summary suitable for reporting during an all-hands meeting.

The summary SHOULD provide:

```text
Reporting period
Number of users
Number of source records
Actual total time
Equivalent total time
Actual compensation value
Equivalent compensation value
Rounding variance
```

Per-user breakdown:

```text
User
Actual hours
Equivalent hours
Actual compensation
```

Optional future functionality:

```text
Per-project totals
Per-team totals
Rate distribution
Month-over-month comparison
```

These should not be implemented at the expense of the core correctness requirements.

---

# 49. Export Formats

## Phase 1

Required:

```text
CSV
```

Preferred:

```text
XLSX
```

## Phase 2

Potential:

```text
PDF
HTML
```

The implementation should leverage Kimai's existing export infrastructure where practical rather than building an entirely separate export subsystem.

---

# 50. CSV Requirements

CSV MUST:

* use UTF-8
* properly escape fields
* use a deterministic column order
* include a header
* use ISO-compatible dates where appropriate
* use minute-resolution times
* preserve source identity in audit exports

Example:

```csv
Date,User,Project,Start,End
2026-09-03,Alice,Project A,09:00,12:00
2026-09-03,Alice,Project B,13:00,16:12
```

---

# 51. XLSX Requirements

If implemented:

Worksheet 1:

```text
Employer Timecard
```

Worksheet 2:

```text
Reconciliation
```

Worksheet 3:

```text
Summary
```

The workbook SHOULD be readable without knowledge of the plugin.

Include explanatory notes:

```text
"Actual" values represent source Kimai records.
"Equivalent" values represent compensation-equivalent time at the configured employer base rate.
Source Kimai timesheets are not modified by this report.
```

---

# 52. UI Terminology

Use consistent terminology throughout.

Preferred:

```text
Actual Time
Recorded Time
Effective Rate
Employer Base Rate
Conversion Factor
Equivalent Time
Compensation Equivalent
Reconciliation
Rounding Variance
```

Avoid:

```text
Adjusted Time
Corrected Time
Fake Time
Payroll Time
Modified Time
```

The UI should make the distinction between actual and equivalent data immediately obvious.

---

# 53. Documentation Requirements

The repository MUST contain:

```text
README.md
LICENSE
CHANGELOG.md
SPEC.md
```

README MUST explain:

1. Purpose.
2. Installation.
3. Configuration.
4. Usage.
5. Mathematical model.
6. Immutability guarantee.
7. Rounding behavior.
8. Permissions.
9. Export formats.
10. Examples.
11. Known limitations.

The README MUST explicitly state:

> This plugin does not modify Kimai timesheet records. It generates a compensation-equivalent representation from recorded timesheet data.

---

# 54. Version Control

The plugin will be maintained in a personal GitHub repository.

The repository MUST be self-contained and suitable for public/private version-controlled development.

Do not commit:

* production database credentials
* API keys
* passwords
* local environment configuration containing secrets
* generated exports containing personal/team compensation data

Provide:

```text
.env.example
```

if environment configuration is required.

---

# 55. Composer / Kimai Compatibility

The plugin MUST declare its supported Kimai version range.

The `composer.json` MUST contain appropriate Kimai plugin metadata according to the target Kimai release.

Kimai's plugin system uses plugin metadata including a minimum required Kimai version.

The implementation agent MUST inspect the **actual target Kimai version installed in the development environment** before selecting APIs/classes.

Do not blindly implement against old Kimai documentation.

If a documented extension point differs between versions, use the APIs appropriate for the installed target version.

---

# 56. Development Environment

The implementation SHOULD include a reproducible development environment.

Preferred:

```text
Docker Compose
```

with:

```text
Kimai
Database
Plugin mounted into Kimai
```

The test environment SHOULD contain representative:

* users
* customers
* projects
* activities
* rates
* timesheets

Tests should be runnable without access to the production Kimai installation.

---

# 57. Static Analysis and Code Quality

The plugin MUST use appropriate PHP tooling.

Recommended:

```text
PHPUnit
PHPStan
PHP-CS-Fixer or PHP_CodeSniffer
Composer
```

The exact tooling should align with the target Kimai version.

CI SHOULD execute:

```text
composer validate
composer test
static analysis
coding standards
```

---

# 58. CI/CD

The GitHub repository SHOULD contain a GitHub Actions workflow.

Minimum CI:

```text
Install dependencies
Run unit tests
Run integration/functional tests
Run static analysis
Run coding standards
```

The plugin MUST NOT be considered release-ready if CI fails.

---

# 59. Release Strategy

Use semantic versioning:

```text
MAJOR.MINOR.PATCH
```

Examples:

```text
0.1.0
0.2.0
1.0.0
1.0.1
```

Before `1.0.0`, API/UI behavior may evolve.

After `1.0.0`, changes affecting exported calculations MUST be treated as potentially breaking behavior and documented in `CHANGELOG.md`.

---

# 60. Calculation Versioning

Because this plugin may be used for compensation reporting, future calculation changes need to be auditable.

The export SHOULD include:

```text
Calculation Version
```

Example:

```text
Calculation Version: 1
```

If the rounding or scaling algorithm changes in the future, increment the calculation version.

Example:

```text
Calculation Version: 2
```

This allows historical reports to be understood correctly.

---

# 61. Report Metadata

Every generated report SHOULD include:

```text
Plugin version
Calculation version
Generation timestamp
Reporting period
Employer base rate
User who generated report
Number of source records
```

The report metadata SHOULD NOT alter the source Kimai records.

---

# 62. Auditability Requirement

A reviewer must be able to answer all of the following from the detailed export:

```text
What actual Kimai record generated this row?

What did Kimai say the user actually worked?

What rate was associated with that record?

What employer base rate was used?

What conversion factor was applied?

How was the equivalent duration calculated?

What start/end interval was generated?

What rounding occurred?

Does the compensation reconcile?
```

If any of these questions cannot be answered, the implementation is incomplete.

---

# 63. Non-Goals

The first version MUST NOT attempt to:

* replace Kimai's normal time tracking
* modify Kimai timesheets
* act as a payroll system
* submit timecards directly to an employer
* infer employee compensation
* infer rates from project names
* automatically correct bad timesheets
* resolve overlapping work
* merge source timesheets
* fabricate missing time
* create replacement timesheets
* modify Kimai's rate calculation system
* override Kimai permissions

---

# 64. Future Extension Points

The architecture SHOULD permit future support for:

### Additional employer base rate scopes

Future versions may add scopes beyond the current project/customer/user/global
hierarchy, such as team-level defaults or activity-level overrides.

### Employer project mapping

```text
Kimai Project A → Employer Project X
```

### Employer-specific export formats

```text
CSV
XLSX
JSON
API
```

### Monthly dashboards

```text
Actual vs equivalent
per user
per project
per month
```

### Alternative interval transformation strategies

```text
preserve_start
preserve_end
center
distributed
```

These are future extensions and SHOULD NOT unnecessarily complicate version 1.

---

# 65. Acceptance Criteria

The plugin is considered complete when all of the following are true.

## Core functionality

* [ ] Plugin installs successfully on the target Kimai version.
* [ ] Plugin appears in Kimai's plugin management.
* [ ] Employer base rate can be configured.
* [ ] Compensation-equivalent export is available from the appropriate Kimai UI.
* [ ] Export respects existing Kimai filters.
* [ ] Actual Kimai records remain unchanged.
* [ ] Effective per-record rates are used.
* [ ] Equivalent durations are calculated correctly.
* [ ] Equivalent intervals have minute precision.
* [ ] Actual and equivalent values are both visible.
* [ ] Reconciliation is provided.
* [ ] Rounding variance is visible.

## Safety

* [ ] No Timesheet records are modified.
* [ ] No Timesheet records are deleted.
* [ ] No Timesheet records are created.
* [ ] Export generation does not change export state.
* [ ] Existing Kimai permissions are respected.
* [ ] Users cannot access unauthorized compensation data.

## Accuracy

* [ ] $150 → $150 produces 1:1.
* [ ] $120 → $150 produces 0.8×.
* [ ] Rates above the base rate work.
* [ ] Fractional-minute rounding is deterministic.
* [ ] Midnight crossing works.
* [ ] DST transitions work.
* [ ] Historical rate behavior works.
* [ ] Running records are handled explicitly.
* [ ] Missing rates are handled explicitly.
* [ ] Overlapping records are not silently altered.

## Auditability

* [ ] Every derived record has a source Timesheet ID.
* [ ] Detailed export shows actual values.
* [ ] Detailed export shows equivalent values.
* [ ] Conversion factor is visible.
* [ ] Rounding variance is visible.
* [ ] Plugin version is recorded.
* [ ] Calculation version is recorded.

## Quality

* [ ] Unit tests pass.
* [ ] Integration tests pass.
* [ ] Functional tests pass.
* [ ] Static analysis passes.
* [ ] Coding standards pass.
* [ ] CI passes.
* [ ] README is complete.
* [ ] CHANGELOG exists.
* [ ] No secrets are committed.

---

# 66. Implementation Instructions for Coding Agent

Before writing implementation code:

1. Inspect the repository.
2. Determine the target Kimai version.
3. Read the current Kimai plugin/developer documentation applicable to that version.
4. Identify the correct current extension point for a custom timesheet exporter.
5. Inspect the actual Kimai `Timesheet` entity and rate-related services available in that version.
6. Do not assume APIs based solely on older examples.
7. Create the plugin skeleton.
8. Implement the domain calculation logic independently of UI/export code.
9. Write unit tests for the calculation engine before implementing the UI.
10. Implement the read-only export integration.
11. Implement the review/reconciliation output.
12. Implement CSV.
13. Implement XLSX if supported cleanly.
14. Implement permissions.
15. Implement integration and immutability tests.
16. Add CI.
17. Update README and CHANGELOG.
18. Run the complete test suite.
19. Perform a manual end-to-end test using representative Kimai data.
20. Only then consider the implementation complete.

Do NOT modify Kimai core.

Do NOT implement the functionality as a Timesheet calculator.

Do NOT mutate source Timesheet entities.

Do NOT silently substitute current project rates for historical/effective Timesheet rates.

Do NOT silently discard records with calculation problems.

When uncertain about a Kimai API, inspect the installed Kimai source and current documentation rather than guessing.

---

# 67. Final Architectural Requirement

The fundamental architecture MUST remain:

```text
                 KIMAI
                   │
                   │
                   │  Immutable source data
                   ▼
        ┌───────────────────────┐
        │ Compensation Domain   │
        │                       │
        │ Rate Resolver         │
        │ Duration Scaler       │
        │ Interval Generator    │
        │ Reconciliation        │
        └───────────┬───────────┘
                    │
             Derived representation
                    │
          ┌─────────┴─────────┐
          ▼                   ▼
   Review / Summary       Employer Export
          │                   │
          │                   │
   Actual + Equivalent   Equivalent only
```

The plugin MUST preserve the following invariant:

> **Kimai records represent what actually happened. The plugin represents what that work is worth when expressed at the employer's base compensation rate.**

This distinction is the central design principle of the system.

---

# 68. Example Final User Experience

A user selects:

```text
Time Tracking → Export
Date:
September 1–30, 2026

User:
Alice

Projects:
All
```

They choose:

```text
Compensation Equivalent
```

The plugin displays:

```text
Compensation Equivalent Timecard
September 1–30, 2026

Employer Base Rate: $150/hr

                    ACTUAL             EQUIVALENT

Project A           82:30              82:30
Project B           41:15              33:00
Project C           12:30              11:15

Total               136:15             126:45

Actual Compensation:       $19,012.50
Equivalent @ $150/hr:      $19,012.50
Rounding Variance:              $0.00
```

The user can expand individual records:

```text
Project B

ACTUAL
Start:       09:00
End:         13:00
Recorded:    04:00
Rate:        $120/hr
Value:       $480.00

COMPENSATION EQUIVALENT
Start:       09:00
End:         12:12
Equivalent:  03:12
Base Rate:   $150/hr
Value:       $480.00

Factor:      0.800000
```

The user then downloads:

```text
Employer Timecard.xlsx
```

which contains the compensation-equivalent representation.

The detailed reconciliation remains available for audit and internal review.

---

# 69. Definition of Done

Version 1.0 is complete when a team member can:

1. Record their actual work normally in Kimai.
2. Select the appropriate reporting period.
3. Generate a compensation-equivalent report.
4. Review actual versus equivalent time.
5. Review rate and calculation details.
6. Verify reconciliation.
7. Export the employer-facing start/end timecard.
8. Submit that timecard to the employer.
9. Return to Kimai later and find that every original timesheet is unchanged.

The system should make it **hard to make a mistake, easy to understand what happened, and easy to prove how every submitted minute was derived.**

Accuracy and transparency take precedence over convenience.
