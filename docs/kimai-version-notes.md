# Kimai API notes: 2.40.0 vs 2.65.0

Investigation record for the Pro Rata Time Export plugin. Satisfies
spec §55 ("inspect the actual target Kimai version before selecting APIs") and
§66 items 2–6.

**Verdict: no breaking difference on any extension point this plugin uses.**
One codebase targets both versions. No version-detection or version-branching
code is needed anywhere.

Sources: the public `kimai/kimai` repository at tags `2.40.0` (commit `190b1dd4`)
and `2.65.0` (commit `fd8989e9`), and the official reference plugin
[`Keleo/DemoBundle`](https://github.com/Keleo/DemoBundle) (`composer.json`
`type: kimai-plugin`).

| | 2.40.0 | 2.65.0 |
|---|---|---|
| `App\Constants::VERSION_ID` | `24000` | `26500` |
| Required PHP | `8.1.*\|\|8.2.*\|\|8.3.*\|\|8.4.*` | `>=8.2` |
| Symfony | `^6.0` | `^6.0` |

Intersection of the PHP constraints is `>=8.2`, which is what `composer.json`
declares.

---

## 1. `Timesheet` entity (spec §3.1, §9, §30)

`src/Entity/Timesheet.php` — the public getter surface is **identical** between
the two tags. Every field the spec's immutability list (§30) and reconciliation
output (§23) name is available on both:

| Getter | Signature | Notes for this plugin |
|---|---|---|
| `getId()` | `?int` | Source record identity, spec §29 |
| `getBegin()` | `?DateTime` | Timezone-aware; equivalent interval start, spec §11 |
| `getEnd()` | `?DateTime` | `null` while running |
| `isRunning()` | `bool` | Drives the exclude-and-warn path, spec §15 |
| `getDuration(bool $calculate = true)` | `?int` | **Seconds.** The authoritative recorded duration (spec §9) |
| `getCalculatedDuration()` | `?int` | Raw `end - begin` in seconds, ignoring Kimai's stored/rounded duration |
| `getBreak()` | `int` | |
| `getUser()` | `?User` | Preserved per record for team exports, spec §18 |
| `getProject()` / `getActivity()` | `?Project` / `?Activity` | Customer reached via `getProject()->getCustomer()` |
| `getRate()` | `float` | Total money value stored on the record |
| `getHourlyRate()` | `?float` | **The effective hourly rate stored on the record** — see §2 |
| `getFixedRate()` | `?float` | Set instead of `hourlyRate` for fixed-rate records |
| `getInternalRate()` | `?float` | Cost rate, not the compensation rate — do not use |
| `isExported()` | `bool` | Must be left untouched, spec §25 |
| `isBillable()` / `getBillableMode()` | `bool` / `?string` | |
| `getTimezone()` | `?string` | Source timezone semantics, spec §17 |
| `getDescription()`, `getTags()`, `getTagsAsArray()`, `getMetaField()` | | Detail/audit export fields |

### `getDuration()` vs `getCalculatedDuration()`

Spec §9 requires distinguishing recorded duration from the wall-clock
`begin`/`end` difference. Kimai models exactly that split:

- `getDuration()` returns the **stored** duration, which Kimai may have rounded
  (`kimai.timesheet.rounding` config).
- `getCalculatedDuration()` recomputes from `begin`/`end`.

Use `getDuration()` for the compensation conversion (spec §9) and surface the
disagreement when the two differ, rather than hiding it.

Both are **seconds**, not minutes. All internal duration math should stay in
integer seconds (spec §27) and convert to minutes only at the rounding step.

### The one entity difference (irrelevant here)

2.45 deprecated the `Timesheet::WORK` / `HOLIDAY` / `SICKNESS` / `PARENTAL` /
`OVERTIME` category constants and removed the allow-list validation in
`setCategory()`. `getCategory(): string` is unchanged and still available. This
plugin only reads the category (for the audit export, if at all) and never
writes it, so nothing is affected. Do not reference the deprecated constants.

---

## 2. Rate resolution (spec §6, §40, §45)

### The service Kimai itself uses

`App\Timesheet\RateServiceInterface` / `App\Timesheet\RateService`:

```php
interface RateServiceInterface
{
    public function calculate(Timesheet $record): Rate; // App\Timesheet\Rate
}
```

`App\Timesheet\Rate` exposes `getRate()`, `getInternalRate()`, `getHourlyRate()`,
`getFixedRate()`. The interface, the `Rate` value object, and the body of
`calculate()` are identical in both versions apart from the internal-rate
handling noted below.

### **`RateService` is the wrong tool for this plugin**

`RateService::calculate()` resolves a rate for a record by walking the *current*
`CustomerRate` / `ProjectRate` / `ActivityRate` / user-preference hierarchy via
`TimesheetRepository::findMatchingRates()`. It is the write path Kimai runs
through `RateCalculator` when a timesheet is saved. Calling it on an existing
record answers "what would this record's rate be if entered today?", which is
precisely the behaviour spec §6 forbids and spec §40 makes a regression test:

> Do NOT simply query the current project rate and assume it represents the
> historical timesheet rate.

Kimai's rate hierarchy has no historical/temporal dimension — rate changes are
not versioned. The **only** record of the rate in force when a timesheet was
entered is what Kimai froze onto the record itself at save time. So:

```text
effective hourly rate = Timesheet::getHourlyRate()
```

with `Timesheet::getRate()` (the stored money total) as the cross-check for
reconciliation, and `Timesheet::getFixedRate()` identifying records that carry a
flat amount rather than an hourly rate.

`RateResolver` should therefore read the record and never call `RateService`.
Per spec §6/§45, a `null` or `<= 0` hourly rate is an error, not an invitation to
fall back to the project rate.

Fixed-rate records (`getFixedRate() !== null`, `getHourlyRate() === null`) have
no hourly rate at all. Deriving one as `rate / (duration / 3600)` is possible but
is a product decision about whether a fixed-rate record is even meaningful on a
compensation-equivalent timecard — raise it rather than deciding silently.

### 2.65-only: `App\Timesheet\RateCalculator\*`

2.65 adds `RateCalculatorMode` (interface), `ClassicRateCalculator`,
`DecimalRateCalculator` and `RateCalculatorFactory`, and injects
`RateCalculatorMode` into `RateService`'s constructor. This replaces the static
`App\Timesheet\Util::calculateRate()` used at 2.40.

- `ClassicRateCalculator::calculateRate()` is byte-for-byte the old
  `Util::calculateRate()` (`$hourlyRate * ($seconds / 3600)`, rounded to 4dp).
- `DecimalRateCalculator` is selected when the system setting
  `invoice.rounding_mode === 'decimal'`; it rounds hours to 2dp before
  multiplying.

**Not a breaking change for this plugin**, because:

1. `RateServiceInterface::calculate()` — the only rate API a plugin should
   consume — is unchanged. The constructor change is internal to `RateService`,
   which is autowired.
2. This plugin does not use `RateService` at all (above), and must not use
   `Util::calculateRate()` either — spec §27 requires decimal/integer arithmetic
   rather than inheriting Kimai's float rounding.
3. The mode only affects how Kimai *writes* rates. Values already stored on
   existing records are read back the same way in both versions.

It is worth documenting in the README that on 2.65 with
`invoice.rounding_mode: decimal`, newly recorded timesheets carry rates Kimai
rounded differently — an input-data property, not a plugin behaviour.

---

## 3. Plugin / bundle registration (spec §55)

Identical in both versions. `src/Kernel.php` is byte-for-byte the same, as is
everything under `src/Plugin/`. The single diff in that tree is a
`file_exists()` guard added to `PackageManager::findAvailablePackages()`, which
only affects the admin plugin-listing screen.

### Requirements Kimai enforces at boot

`Kernel::getBundleClasses()` scans `<kimai>/var/plugins/*Bundle` and for each
directory `Foo`:

1. Skips it if `Foo/.disabled` exists.
2. Requires the class `KimaiPlugin\Foo\Foo` to exist — **the bundle class name
   must equal the directory name**, in namespace `KimaiPlugin\<Name>`.
3. Requires that class to implement `App\Plugin\PluginInterface`
   (`getName()` + `getPath()`, both satisfied by extending Symfony's `Bundle`).
   `PluginInterface` carries `#[AutoconfigureTag]`.
4. Reads `Foo/composer.json` via `PluginMetadata::createFromPath()` and refuses
   to boot if `extra.kimai.require > Constants::VERSION_ID`.
5. A directory name carrying a version suffix (`*Bundle-1.2.3`) is a hard error.

`Plugin::getMetadata()` re-reads `composer.json` from `$bundle->getPath()`, i.e.
**the directory holding the bundle class**. That is why a Kimai plugin's source
sits at the repository root rather than under `src/` — the bundle class and
`composer.json` must be siblings. `Keleo/DemoBundle` is laid out exactly this
way and this repository follows it.

### `composer.json` contract

`PluginMetadata::createFromArray()` throws unless all of these are present:

```jsonc
{
  "type": "kimai-plugin",
  "extra": {
    "kimai": {
      "require": 24000,                  // REQUIRED, must be an int (Constants::VERSION_ID)
      "name": "Pro Rata Time Export",    // REQUIRED, display name in plugin admin
      "version": "0.1.0"                 // optional; falls back to root "version", else "unknown"
    }
  },
  "autoload": { "psr-4": { "KimaiPlugin\\ProRataTimeExportBundle\\": "" } }
}
```

`require` is the **minimum** Kimai version only; Kimai never enforces an upper
bound. `24000` therefore covers 2.40.0 through 2.65.0 and beyond. The supported
*range* is documented in the README, not expressible in metadata.

### DI extension and config

`App\Plugin\AbstractPluginExtension` (identical in both versions) extends
Symfony's `Extension` and adds `registerBundleConfiguration()`, which merges the
processed config into the container parameter `kimai.bundles.config`.
`AppExtension` then folds that into `kimai.config` as flat dot-notation keys —
and throws if a bundle's root key collides with a core one.

Runtime access is through `App\Configuration\SystemConfiguration::find(string $key)`
(`string|int|bool|float|null`), present and unchanged in both versions. Note
`getString()`/`getFloat()` on `SystemConfiguration` are **private**; `find()` is
the public accessor, so a plugin does its own type narrowing — which is what
`Configuration/CompensationConfiguration.php` here does.

The config root key derives from the Extension class name:
`ProRataTimeExportExtension` → alias `pro_rata_time_export`, matching the
spec §7/§26 shape:

```yaml
pro_rata_time_export:
    base_rate: 150.00
```

read back as `find('pro_rata_time_export.base_rate')`.

### Routes

`Kernel::configureRoutes()` (identical in both) imports, for every bundle
implementing `PluginInterface`, either `<bundle>/Resources/config/routes.{php,yaml}`
or `<bundle>/config/routes.{php,yaml}` — `Resources/config/` first. Application
routes load last, so a plugin cannot override core routes.

Permissions are declared by prepending to the `kimai` extension config from the
plugin's `prepend()` (see `DemoExtension::prepend()`); the permission names in
`config/packages/kimai.yaml` (`export_own_timesheet`, `export_other_timesheet`,
`create_export`, `view_rate_*`) are identical in both versions (spec §18, §31).

---

## 4. Export extension point (spec §4, §22, §23, §49)

Identical in both versions. The file list under `src/Export/` matches exactly,
and `RendererInterface`, `TimesheetExportInterface`, `ExportRepositoryInterface`
and `ExportServiceCompilerPass` are byte-for-byte the same.

### How a plugin registers an exporter

`App\Export\ExportRendererInterface` is the base contract:

```php
interface ExportRendererInterface
{
    /** @param ExportableItem[] $exportItems */
    public function render(array $exportItems, TimesheetQuery $query): Response;
    public function getId(): string;
    public function getTitle(): string;
}
```

Two marker sub-interfaces, both carrying `#[AutoconfigureTag]`, decide where the
exporter shows up:

| Implement | Registered via | Appears in |
|---|---|---|
| `App\Export\RendererInterface` | `ServiceExport::addRenderer()` | the **Export** screen |
| `App\Export\TimesheetExportInterface` | `ServiceExport::addTimesheetExporter()` | the **Timesheet** list export dropdown |

`ExportServiceCompilerPass` collects services tagged with those interface names
and wires them into `ServiceExport`. Because the interfaces are
`#[AutoconfigureTag]`-annotated, a plugin only needs `autoconfigure: true` in its
`Resources/config/services.yaml` — no manual `tags:` entry.

Implementing both interfaces on one class registers it in both places.

`ExportRendererInterface`'s docblock advertises two optional methods that
`ServiceExport` and the export UI call when present: `getType(): string` and
`isInternal(): bool`. Both versions carry them only as `@method` annotations
(2.65 merely corrects the annotation syntax and changes a `TODO` to `FIXME`);
they become real interface methods in 3.0.

### The data shape an exporter receives

`render()` gets `App\Entity\ExportableItem[]` — **not** `Timesheet[]`, though
`Timesheet` is the implementation Kimai passes. The interface is unchanged
between versions (2.65 only fixes `@method` annotation syntax) and exposes
everything §23's audit export needs: `getId()`, `getBegin()`, `getEnd()`,
`getDuration()`, `getRate()`, `getHourlyRate()`, `getFixedRate()`,
`getInternalRate()`, `getUser()`, `getProject()`, `getActivity()`,
`isExported()`, `isBillable()`, `getDescription()`, `getTagsAsArray()`,
`getMetaField()`, `getType()`, `getCategory()`, `getAmount()`.

Note `ExportableItem::getDuration(): ?int` takes no argument, unlike
`Timesheet::getDuration(bool $calculate = true)`. Type against `ExportableItem`
and call `getDuration()` with no argument.

Items reach the renderer already filtered and permission-scoped: the export
controller builds an `ExportQuery` (a `TimesheetQuery` subclass adding
`renderer` and `markAsExported`) from the user's filter form, and
`ServiceExport::getExportItems()` fans it out across the registered
`ExportRepositoryInterface` implementations. Honouring §18/§31 therefore means
*not* re-querying — use the array handed to `render()`.

Kimai marks records exported only when the controller acts on
`ExportQuery::isMarkAsExported()`; a renderer never triggers it. Satisfying
spec §25 requires nothing more than not calling `ServiceExport::setExported()`.

`TimesheetQuery` differs trivially: `setModifiedAfter()` returns `void` at 2.65
instead of `$this`, and takes `\DateTimeInterface` instead of `\DateTime`. This
plugin does not call it. `ExportQuery` itself is identical.

### Existing renderers worth reading before writing ours

`src/Export/Base/` holds `CsvRenderer`, `XlsxRenderer`,
`AbstractSpreadsheetRenderer`, `HtmlRenderer`, `PDFRenderer`,
`PdfTemplateRenderer` and `RendererTrait`; column formatting lives in
`src/Export/Package/CellFormatter/` (`RateFormatter`, `DurationFormatter`,
`TimeFormatter`, …). The file set is identical in both versions.

2.65 refactored `ServiceExport`'s own template handling (extracting
`createTemplateFromExportTemplate()`, and surfacing user-defined export
templates in the timesheet dropdown), and renamed the built-in HTML renderer's
id from `html` to `print`. All of it is internal to core's default renderers —
`addRenderer()` / `addTimesheetExporter()` / `getRendererById()` /
`getTimesheetExporterById()` are unchanged. Pick a plugin-specific renderer id
(e.g. `compensation-equivalent-csv`) and no collision is possible either way.

---

## 5. Consequences for the implementation

1. Target the common surface. No version detection, no conditional branches.
   `composer.json` declares `extra.kimai.require: 24000`; the README documents
   2.40.0–2.65.0 as tested.
2. `RateResolver` reads `Timesheet::getHourlyRate()` / `getRate()` /
   `getFixedRate()` off the record. It must not call `RateService`,
   `TimesheetRepository::findMatchingRates()`, or any `*RateRepository`.
3. Durations are integer **seconds** from `getDuration()`. Convert to minutes
   only inside `DurationScaler`, at the single documented rounding step (§10).
4. Exporters implement `TimesheetExportInterface` and/or `RendererInterface`,
   type their items as `ExportableItem`, and rely on `autoconfigure: true`.
5. Read-only means read-only: consume the `$exportItems` array `render()` is
   given, never call `ServiceExport::setExported()`, never flush the entity
   manager.
6. `getDuration()` and `getCalculatedDuration()` can legitimately disagree.
   Expose that in the reconciliation output (§9) rather than picking one
   silently.

---

## 6. Verified against both versions

The conclusions above are not desk research alone. The skeleton was booted in the
`docker-compose.yml` environment against `kimai/kimai2:apache-2.40.0` and
`kimai/kimai2:2.65.0`, with this repository mounted read-only at
`var/plugins/ProRataTimeExportBundle`. On both:

- `bin/console kimai:plugins` lists the plugin
  (`ProRataTimeExportBundle` / `Pro Rata Time Export` / `0.1.0` / requires `24000`).
- `bin/console debug:container --parameter=kimai.config` resolves
  `pro_rata_time_export.base_rate` to the value set in
  `config/packages/local.yaml`, and to `null` when unset.

One non-obvious trap surfaced while doing this, and is guarded by
`Tests/Unit/BundleStructureTest.php`: `PluginManager` collects bundles through
`#[TaggedIterator(PluginInterface::class)]`, which only sees classes registered
as **services**. Excluding the bundle class from the plugin's own
`Resources/config/services.yaml` makes the plugin vanish from Kimai's plugin
administration while the bundle still boots, routes still load and configuration
still resolves — a silent half-registration with no error anywhere.

---

## 7. Per-entity base rate override storage (spec §7 hierarchy)

Investigation for the spec §7 hierarchical base rate override (project >
customer > user > global default). Verdict: **Project and Customer support
Kimai's native meta-field mechanism identically in both versions; `User` does
not and uses a different native mechanism instead.** Confirmed against the
same `2.40.0`/`2.65.0` image sources as above.

### Project/Customer: `EntityWithMetaFields`

`App\Entity\Activity`, `Customer`, `Invoice`, `Project` and `Timesheet`
implement `EntityWithMetaFields` — byte-for-byte identical interface and
`MetaTableTypeTrait` implementation in both versions (2.65 only adds unused
`section`/`formTheme` fields, irrelevant here):

```php
interface EntityWithMetaFields
{
    public function getMetaFields(): Collection;
    public function getMetaField(string $name): ?MetaTableTypeInterface;
    public function setMetaField(MetaTableTypeInterface $meta): EntityWithMetaFields;
}
```

**Reading** a value (what `CompensationConfiguration` does) is just
`$project->getMetaField('name')?->getValue()`.

**Defining** a field — so it renders as an editable input on Kimai's own
Project/Customer edit forms, which is the "reuses Kimai's own admin UI"
property spec §7 relies on — is a separate step from reading it: a plugin
subscribes to `App\Event\ProjectMetaDefinitionEvent` /
`CustomerMetaDefinitionEvent` (dispatched while Kimai builds that entity's
edit form) and calls
`$event->getEntity()->setMetaField((new ProjectMeta())->setName(...)->setType(MoneyType::class)->addConstraint(new Assert\Positive())->...)`
on every definition event. `setMetaField()` merges by field name, preserving an
existing value while reapplying non-persisted definition metadata. This plugin
registers those fields through `EventSubscriber\OverrideFieldDefinitionSubscriber`.
They use Symfony's `MoneyType` — the parent of Kimai's own `HourlyRateType`/
`InternalRateType` — with a `currency` option taken from the same source
Kimai's rate fields use: the customer's currency for project/customer, and
`SystemConfiguration::getUserDefaultCurrency()` for the user preference (in
2.65.0 that is a deprecated alias of the customer default; Kimai's own user
hourly-rate field follows the same change). `MetaTableTypeTrait::getValue()`
only casts `NumberType`, so a `MoneyType` value always comes back as the raw
string — `CompensationConfiguration` already parses it itself.

Sharp edge: `MetaTableTypeTrait`'s `type`/`label`/`required`/`constraints`/
`options` properties carry no `#[ORM\Column]` — they are **not persisted**.
Only `name`, `value` and `visible` come back from Doctrine. So
`getMetaField()->getValue()` outside of a request that also ran the
definition-event subscriber returns the **raw string** Doctrine loaded (not
transformed by the field type, since `type` is `null` on that freshly-hydrated
object) — `CompensationConfiguration` therefore parses/validates the value
itself (`is_numeric()` + cast) rather than trusting a typed return.

### User: no `EntityWithMetaFields` — `UserPreference` instead

`App\Entity\User` does **not** implement `EntityWithMetaFields` in either
version — grep confirms only `Activity`/`Customer`/`Invoice`/`Project`/
`Timesheet` do. `User` has a structurally separate but equally native
mechanism: `App\Entity\UserPreference` (own table
`kimai2_user_preferences`, own admin surface — the user profile/preferences
screen — registered the same way via a definition event), read through
`User::getPreferenceValue(string $name, mixed $default = null, bool $allowNull = true): bool|int|float|string|null`.
Same non-persisted-`type` caveat as meta fields, with one difference: unlike
`MetaTableTypeTrait::getValue()`, `UserPreference::getValue()` casts even `null`,
so when the definition subscriber has typed it `NumberType` in the same request
(it has, during an export) an unset preference reads as `0.0`. The plugin
therefore reads an untyped clone (`CompensationConfiguration::rawPreferenceValue()`)
and validates/casts itself. Identical in 2.40.0 and 2.65.0.

This asymmetry (two entities via meta fields, one via user preferences) was
escalated to and confirmed by the captain rather than assumed — see the
`fm/kimai-base-rate-hierarchy` task history. Both mechanisms are equally
"native, no migration, existing admin UI"; they just don't share one screen.

### Consequence

`EventSubscriber\OverrideFieldDefinitionSubscriber` defines the Project and
Customer meta fields and the User preference so they appear in Kimai's native
edit forms. `CompensationConfiguration::getBaseRate(ExportableItem $item)`
reads those values, most specific first:
`$item->getProject()?->getMetaField(...)`,
`$item->getProject()?->getCustomer()?->getMetaField(...)`,
`$item->getUser()?->getPreference(...)` (read untyped, see above), then the existing global
`pro_rata_time_export.base_rate` config value. No new Kimai API beyond what's
listed above; identical across 2.40.0–2.65.0.

## Export errors

`App\Controller\ExportController::export()` calls `$renderer->render()` with no
`try`/`catch` in both 2.40.0 and 2.65.0, so any exception a renderer throws
becomes Kimai's generic 500 page. The export form submits into a new tab, so a
flash message + redirect has nowhere useful to land. The plugin's renderers
instead catch `Service\CompensationUnavailableException` (missing/unusable
effective or base rate, spec §32) and return a 422 page carrying its message
(`Export\RendersCompensationUnavailable`). Guards that must abort the request
(the mark-as-exported check) still throw: returning a Response would let the
controller go on to mark the records exported.
