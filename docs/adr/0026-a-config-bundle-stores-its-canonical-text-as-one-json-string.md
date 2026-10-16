# ADR 0026 — A configuration bundle stores its canonical text as one JSON string, and its checksum recipe is chosen by `format_version`

**Status:** Accepted · **Date:** 2026-10-05 · **Phase:** post-go-live hardening (`fix/post-golive-hardening`), platform WP-05 TASK 4 (`config_bundles`). This closes the follow-up ADR 0025 booked to platform · **Author:** architect
**Requested by:** the coordinator, post-go-live defect 1 (config bundles on MySQL 8)
**Consumers:** backend-engineer **(lead: one model accessor, one exporter change, one importer guard, two file writers, tests)** · qa-engineer · code-reviewer · reliability-engineer (read-only VPS checks) · platform (bundle format 2) · every environment that exchanges bundle files (the bank's MariaDB estate and the production VPS) · BCMS (DR evidence `content_hash`: §6 adds a third option to its booked follow-up)

## Context

### The defect, reproduced

`config_bundles.payload` is a `json` column (`2026_08_13_120004:49`) behind an `array` cast.
`ConfigurationExporter::export()` computes `checksum` over the **PHP value before the insert**.
`ConfigBundle::isIntact()` and `ConfigurationImporter::apply()` (line 77) recompute it over
the **value read back**.

MySQL 8.0.46's JSON parser does not read every double back exactly (ADR 0025 §1). I
reproduced it against the bundle code itself on 2026-10-05, using the real
`ConfigurationExporter::checksum()` and a `json` column on each local engine:

| Engine | `478/53` stored as a dimension weight reads back as | `isIntact()` |
|---|---|---|
| MySQL 8.0.46 | `9.0188679245283` | **false** |
| MariaDB 10.4.28 | `9.018867924528301` | true |

Over 3,000 unrounded ratios, 340 (11%) changed on their first trip through MySQL's parser.

### When it actually bites

I also measured a value that **has already been read back from MySQL** going through a
second time. 0 of 3,000 changed. That figure is an observation, not a property of the
parser, but it explains why production has not reported the defect yet. On a MySQL-only
estate, every exported value comes from a MySQL `json` column. It has already drifted once,
at its original write, and it re-enters stably. The defect bites when a payload holds a
double that **has never been through MySQL**:

1. **A bundle carried from the MariaDB estate to production.** This is the route the
   feature exists for. `config:import <file> --apply` checks the in-memory model, which has
   never been read back, so it succeeds. Every later use of that stored bundle **by id**
   reads the drifted value: `config:import <id>`, or the screen's Apply button, which only
   applies stored bundles. Those refuse it with *"modified since it was exported"*. Rolling
   back to its snapshot still works, because `rollback()` never checks a checksum.
2. A file edited by hand, or a value that PHP computed and never stored.
3. Any later section that exports a scalar `double` column. None does today (§5). The
   exporter reads its columns from the live schema (`ConfigurationSchema::columnsOf()`), so
   a new one would be picked up without a review.

Rejecting an honest bundle is not the only cost. A bundle that holds a neighbouring double
is also a bundle that **applies a value nobody configured**. The differ compares nested
arrays with `==`, and `9.0188679245283 == 9.018867924528301` is false, so it reports
phantom changes and phantom conflicts against the target.

### Why ADR 0025's remedy does not transfer

ADR 0025 §2a quotes every float as a JSON string at write time. That works for
`tp_audit_logs` because nothing reads a payload back as configuration. **A bundle is read
back as configuration.** `ConfigurationImporter::writeSection()` (line 218) writes each
declared json column with `json_encode($value)`. A quoted weight would therefore land in the
target's `scoring_profiles.dimension_weights` as the string `"0.35"`, and in
`object_attributes.validation` and `rating_bands` the same way. The importer cannot
unquote it, because it cannot tell a quoted float from a value that was always the string
`"0.35"`. The only way to restore the type would be a per-path type map in the bundle,
which is a second format to keep in step.

### What else the checksum gets wrong (the v1 recipe)

`checksum()` is `sha256(json_encode(canonicalise($payload), UNICODE|SLASHES))`:

- `canonicalise()` uses the default `ksort` (`SORT_REGULAR`), which is not transitive over
  mixed int and string keys (ADR 0025 §1). Input order can therefore change the digest.
- `serialize_precision` is inherited from `php.ini`. A file exported where it is `-1` and
  verified where it is `17` mismatches on every unrounded double.
- There is no `JSON_PRESERVE_ZERO_FRACTION`, so `1` and `1.0` hash alike.

None of these is the reported defect. But a fix that keeps v1 for new bundles keeps all
three.

## Decision

### 1. The payload is stored sealed: one JSON string holding the canonical text

| Path | Rule |
|---|---|
| **Write** (every write, every row) | `payload` column = `json_encode(CanonicalJson::encode($payload), JSON_UNESCAPED_SLASHES \| JSON_UNESCAPED_UNICODE \| JSON_THROW_ON_ERROR)`. The column holds a JSON **string** scalar whose content is the canonical text. |
| **Read** | Decode the column. If the result is a **string**, decode that string: it is a sealed row. If the result is an **array**, return it as it is: it is a **legacy row** written before this ADR. Anything else throws `UnexpectedValueException`. |

**Why this works on both engines.** A JSON string never passes through MySQL's number
parser. Both engines return its contents byte for byte (ADR 0025 §2a). PHP then decodes the
canonical text, and the doubles come back as the same PHP doubles that were sealed, because
`CanonicalJson::encode()` pins `serialize_precision = -1` (shortest round-trip). Over the
same 2,000 rich payloads, the sealed form changed 0 times on MySQL 8.0.46 and 0 on MariaDB
10.4. The plain object form changed 372 times on MySQL.

**Types are kept exactly.** A float stays a float and an integer stays an integer.
`JSON_PRESERVE_ZERO_FRACTION`, inside `CanonicalJson::FLAGS`, keeps `100.0` a float. The
importer therefore writes back exactly what was exported, which quoting cannot do.

**What it costs.** No JSON-path queries can run against `payload`. None exists: I grepped for
`payload->`, `JSON_EXTRACT` and raw `config_bundles` reads, and every reader goes through the
model. A raw `SELECT payload` shows an escaped string instead of an object. The column keeps
MySQL's JSON validation, because a JSON string is valid JSON.

It lives in `ConfigBundle` as an `Attribute` accessor and mutator. It is **not** a new
`app/Casts` class: no custom cast exists in this repository, and one consumer does not
justify a new directory. If the DR-evidence follow-up (§6) adopts the same technique, the
second consumer extracts it. **`CanonicalJson` does not change.** ADR 0025 keeps it a pure
canonicaliser, and sealing is a storage rule, not a canonical form.

### 2. The checksum recipe is chosen by `format_version`, never by trial

| `payload['format_version']` | Recipe | Written by |
|---|---|---|
| absent or `1` | **v1, frozen byte for byte:** `sha256(json_encode(canonicalise($p), JSON_UNESCAPED_UNICODE \| JSON_UNESCAPED_SLASHES))` | every bundle exported before this ADR |
| `2` | **v2:** `sha256('config_bundles/v2' . '\|' . CanonicalJson::encode($p))` | every bundle exported from this ADR on |
| anything else | refused: *"exported by a newer release (format N); this release reads formats 1 and 2"* | a future release |

- **`format_version` is inside the payload, so it is inside the digest.** A file that says
  2 but was sealed under 1 does not verify.
- **The tag** separates the domains as ADR 0025 §2 does. A v1 digest and a v2 digest of the
  same text cannot be equal.
- **Storage form and recipe are independent.** A v1 file imported after this ADR is stored
  *sealed* and still verifies under *v1*. That is the backward-compatibility path, and §4
  tests it.
- **v1 is kept, but only as a reader of legacy bundles.** This is not ADR 0025's
  verify-by-trial, which that ADR rejected. Here the recipe is chosen deterministically by a
  field the digest covers, and v1 is not defective for the bundles it sealed. It is
  non-portable, and those bundles were sealed under it. Deleting v1 would make every bundle
  file exported before this release unappliable, with a message that wrongly says it was
  edited.
- **The `checksum` column stays `string(64)`.** There is no prefix and no `hash_version`
  column. The schema freeze is untouched.

`canonicalise()` keeps its current body. It now belongs to v1 and to
`ConfigurationDiffer::equivalent()`. The differ compares arrays with `==`, which ignores key
order, so the non-transitive sort never decides a diff. This closes ADR 0025's booked "make
`canonicalise()` delegate to `CanonicalJson`". It is **not** done, because doing it would
silently change the v1 recipe that legacy bundles are verified under.

### 3. The two file writers become one, and it keeps the zero fraction

`ConfigurationBundleController::download()` and `ConfigExport --file` each `json_encode` a
document by hand, without `JSON_PRESERVE_ZERO_FRACTION`. Under v2, a float `100.0` in the
payload would be written to the file as `100` and read back as an int, and then the bundle
would read as altered. Both writers call one `ConfigurationExporter::document(ConfigBundle)`:

`JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR`,
with `serialize_precision` pinned to `-1` for the call (the `CanonicalJson::encode()` pattern).
The keys are `code`, `name`, `version`, `checksum`, `exported_at`, `source_environment` and
`payload`. That is the union of the two current writers. The reader (`ConfigImport`) uses
only `code`, `name`, `version`, `checksum` and `payload`.

### 4. Existing rows: no data migration, and never a re-checksum

- A **legacy row** is read through the array branch of §1 and verified under v1, exactly as
  today. Every legacy row that verifies today still verifies.
- A legacy row that **already reads as altered on MySQL** stored a drifted double at insert,
  and nothing can recover the original. The remedy is to **re-export** from the source.
  **Recomputing its checksum over what the column now holds is forbidden.** It would make
  "a bundle whose content changed" verify, which is the exact statement the checksum exists
  to prevent. This is ADR 0025 §3's re-seal prohibition, applied here.
- Converting legacy rows to the sealed form achieves nothing, because the drift has already
  happened. It is not done.

### 5. The other `json` digests and comparisons, decided

I grepped `app/` for `hash(`, `sha1(`, `md5(`, `hash_hmac(`, `crc32`, `checksum`,
`fingerprint`, and for `json_encode(...)` used in a comparison. ADR 0025's table still
stands. These are the sites it did not cover, or whose verdict changes:

| Site | What it digests or compares | Verdict |
|---|---|---|
| `ConfigBundle` / `ConfigurationExporter::checksum` | Payload of a `json` column | **Fixed by this ADR.** |
| `ConfigurationDiffer::equivalent` | Exported values, with `==` on arrays and `(float) ===` on numbers | **Safe once §1 lands.** The bundle side is exact and the current side is read from one engine. **Residual, booked to platform:** `measure_thresholds.bands` and `workflow_definitions.stages/definition/escalation_rules/trigger_config/scope_filter` are `json` columns that `ConfigurationSchema` does not declare in `json`. They export as **raw column text**, which differs between engines (MySQL's spacing and key order). So a bundle moved between engines diffs those rows as "changed" and can raise false conflicts. Declaring them is a bundle-format change with its own compatibility question (old bundles carry strings). It does not belong in a defect fix. |
| `config_bundle_applications.diff` | A `json` log of from/to values | **No digest.** A drifted double here changes only how a historic diff is displayed. No change. |
| `Bcms\Dr\DrService:618` `evidence.content_hash` | `sha256(json_encode($payload))` stored next to `raw_payload` in a `json` column | **Still write-only:** nothing reads `content_hash` (grep, 2026-10-05). It stays booked to BCMS (ADR 0025). **This ADR adds a third option** to that follow-up: store `raw_payload` sealed (§1), which keeps the provider's types where quoting would not. |
| `Bcms\Reminders\ReminderScheduleBuilder::idempotencyKey` (ADR 0005) | `sha1(json_encode($audience))`, where `$audience` embeds the definition's `default_audience_rule` decoded from a `json` column, unsorted | **Latent, booked to BCMS.** The key depends on key order: PHP's insertion order for a model that has not been re-read, and MySQL's length-then-bytes order for one that has. **It does not happen today.** Every build path reads the definition from the database: `ExerciseDefinitionController::generate` route-binds it, programme generation loads relations, and `ReadinessController` and `AarService` re-read. A database moved from MariaDB to MySQL with pending rungs, or a future caller that passes an unsaved definition, would miss every existing rung. Rungs voided and re-created are harmless churn. A rung **already sent today** would be armed again, because the "past" cut-off is the start of today. **Fix direction for BCMS:** key over `CanonicalJson::encode()` of the audience, and on the day the formula changes, match sent-today rungs by `(occurrence, offset, template, mode)`. |
| `Bcms\AudienceRule::fingerprint()` | `sha1(json_encode(normalise()))`: default `ksort`, no fixed flags | **Unused** (no caller). If it is ever wired in, it is rebuilt on `CanonicalJson` first. |
| `Dashboard::hasUnpublishedChanges` | `json_encode(tabList()) !== json_encode(publishedTabList())` | **Benign.** Both sides are read from one engine and normalised by `normaliseTabs()`. At worst, a request that has just autosaved `tabs` in PHP order shows "unpublished changes" for one response. No change. |
| `Bcms\Plans\SourceResolver::hash` | Rows read from the database, fingerprinted and compared on one engine | **Safe**, as ADR 0025 found. |

## Implementation spec (backend-engineer)

**Precondition.** Before changing any code, compute the **v1 pin literal** on the current
code (`056ec5a`): `ConfigurationExporter::checksum()` over the fixed format-1 payload in test
B1. It must be computed on the unchanged recipe, or it pins nothing.

1. **`app/Models/ConfigBundle.php`**
   - Remove `'payload' => 'array'` from `$casts`.
   - Add a `payload(): Attribute` with `get: fn (?string $raw) => self::unsealPayload($raw)`
     and `set: fn (array $value) => self::sealPayload($value)`. **Do not call
     `shouldCache()`.** Laravel caches the *caller's* array on set (`HasAttributes:1195`),
     not the decoded column, and the property under test is that an in-memory model and a
     fresh read return the same value.
   - `public static function sealPayload(array $payload): string` and
     `public static function unsealPayload(?string $raw): ?array`, exactly as §1. The docblock
     names this ADR and says why the payload is a string inside a `json` column, so nobody
     "simplifies" it back.
   - `isIntact()`: return `false` when `checksum()` throws `InvalidArgumentException`
     (an unknown format).
2. **`app/Services/Configuration/ConfigurationExporter.php`**
   - `payload()`: `'format_version' => 2`.
   - `public const CHECKSUM_TAG_V2 = 'config_bundles/v2';`
   - `checksum(array $payload)` dispatches by `(int) ($payload['format_version'] ?? 1)`,
     as in §2. Move today's body, unchanged, into `private static function checksumV1()`,
     with a comment: *frozen; legacy bundles are verified under it; ADR 0026 §2*. Throw
     `InvalidArgumentException` for any other version, with the §2 message.
   - `public function document(ConfigBundle $bundle): string`, as §3.
   - `canonicalise()`: unchanged. Correct its docblock to say it serves v1 and the differ.
3. **`app/Services/Configuration/ConfigurationImporter.php` `apply()`**: wrap the checksum
   comparison. On `InvalidArgumentException`, throw a `ValidationException` on `bundle`
   carrying that message, not the "modified since export" one.
4. **`app/Http/Controllers/Admin/ConfigurationBundleController.php` `download()`**:
   `echo $this->exporter->document($bundle);`.
   **`app/Console/Commands/ConfigExport.php`**: `--file` writes `$exporter->document($bundle)`.
5. **`app/Console/Commands/ConfigImport.php` `resolveBundle()`**: also refuse a file whose
   `payload` is not an array, with the existing "not a bundle" message. The mutator is typed
   `array`.
6. **No migration. No change** to `ConfigurationDiffer`, `ConfigurationSchema`,
   `CanonicalJson`, `DrService` or `ReminderScheduleBuilder`.

### Tests

**A. `tests/Feature/Configuration/ConfigBundleCrossEngineTest.php`** (engine detected by
`select version()`, as `AuditChainCrossEngineTest::isMariaDb()` does). Test A4 **fails on
the MySQL leg today**, and that is its purpose. Test A1 fails today on **both** legs: on
MySQL because of the drift, and on MariaDB because the `array` cast writes `100.0` as
`100`. Run both once on unchanged code and record the failures in the PR.

1. *A sealed payload survives either engine exactly.* Use
   `P = ['format_version' => 2, 'exported_from' => 'testing', 'sections' => ['scoring_profiles' => [['code' => 'x', 'name' => 'Café / Ltd', 'dimension_weights' => ['ratio' => 478/53, 'big' => 1.0e25, 'sum' => 0.1 + 0.2, 'whole' => 100.0, 'n' => 7]]]]]`.
   Run `ConfigBundle::create([... 'payload' => P, 'checksum' => checksum(P)])`, then
   `fresh()`. Assert `assertSame(P, $fresh->payload)` (strict: float stays float, `100.0`
   stays `100.0`) and `isIntact()`. Assert that `json_decode()` of the raw column
   (`DB::table`) is a **string**, on both legs.
2. *Control: the object form really drifts on MySQL.* `DB::table()->insert()` the legacy text
   `{"format_version":1,"sections":{"a":[{"big":1.0e25}]}}`, with its v1 checksum computed
   in PHP over `json_decode` of that same text. On MariaDB assert `isIntact()`. On MySQL
   assert **not** intact. Use `1.0e25`, because its drift is deterministic (ADR 0025 §4.6).
   This proves the leg exercised the parser.
3. *Legacy rows still read and verify.* Raw-insert an object-form, format-1 payload with no
   doubles and its v1 checksum. Assert `payload` is the array and `isIntact()` holds, on
   both legs.
4. *A bundle carried in a file applies by id on either engine.* Export from the source
   organisation and decode `document()`. Set one exported scoring-profile weight to
   `478/53`, keep `format_version` 2, recompute `checksum()`, and write the file. Run
   `config:import <file> --apply` into target 1. Then run `config:import <stored id>
   --apply` into target 2. Assert both succeed. Today the second run is refused on MySQL.
5. *The download keeps the zero fraction.* Take a bundle whose payload holds `100.0` and
   `1.0e25`, `GET` `admin.configuration.download`, write the body to a file, and run
   `config:import <file> --apply`. It succeeds.

**B. `tests/Unit/Configuration/BundleChecksumRecipeTest.php`** (pure PHPUnit, no database)

1. **v1 pin:** the literal from the precondition. **v2 pin:** a literal over a fixed
   format-2 payload. A failure points at this ADR.
2. v2 **differs** for `1` against `1.0`, `72.5` against `"72.5"`, `true` against `1`, and a
   re-ordered list. It is **equal** across key order, including the three orderings of
   `{10, "1a", 9}`.
3. v2 does not change with `serialize_precision = 17` set for the duration of the test.
4. `format_version` 3 throws `InvalidArgumentException`. `isIntact()` is false on such a
   model.
5. `unsealPayload(sealPayload(P)) === P` for test A1's `P`. `unsealPayload()` of legacy
   object text returns the array. `unsealPayload('42')` throws.

**C. Amend `tests/Feature/Configuration/ConfigBundleTest.php`.** In
`an_export_captures_every_section_and_checksums_the_payload`, also assert
`$bundle->fresh()->isIntact()` and `format_version === 2`.
`the_checksum_is_stable_across_key_order` gets a format-2 twin. Add
*a bundle from a newer release is refused with a message that says so*.
`a_tampered_bundle_is_refused` must pass unchanged.

### Read-only checks on the VPS (reliability-engineer, before deploy)

```sql
SELECT COUNT(*) FROM information_schema.TABLES
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'config_bundles';
-- only if 1:
SELECT COUNT(*) AS bundles, SUM(is_snapshot) AS snapshots,
       MIN(created_at) AS first_at, MAX(created_at) AS last_at FROM config_bundles;
```

Whatever the answer, the deploy needs no extra step (§4). If `bundles > 0`, then after the
deploy run, read-only, in tinker:
`ConfigBundle::withoutGlobalScopes()->get()->reject->isIntact()->pluck('id')`. Report the
ids to the operator for re-export. Nothing is rewritten.

### Standard text (for whoever holds `DEVELOPMENT_STANDARD.md`; this ADR does not edit it)

- **§15**, after "A writer must quote floats … or quantise them **before** storing.", add:
  *"Or, when the stored value is read back as configuration and its types must survive (a
  config bundle), store it **sealed**: the column holds one JSON string whose content is
  `CanonicalJson::encode()` of the value. Neither engine parses a number inside a string.
  See `ConfigBundle::sealPayload()` and ADR 0026."*
- **§8**, in the `CanonicalJson` row: *"…Quote floats before storing, as
  `Tprm\AuditLog::quoteFloats()` does, or store the value sealed, as `ConfigBundle` does
  (ADR 0026)."*
- **§11**, load-bearing guards: add `ConfigBundleCrossEngineTest` and
  `BundleChecksumRecipeTest`.

## Alternatives rejected

| Option | Verdict |
|---|---|
| **Quote floats at export (ADR 0025 §2a)** | **Rejected.** A bundle is applied as configuration, and the importer writes json columns with `json_encode`. Quoted weights, bounds and validation limits would arrive in the target as strings. A type-restoring unquote is impossible without a per-path type map. |
| **Seal after the round trip** (insert, read back, checksum what the server holds) | **Rejected.** The bundle would hold, and later apply, doubles that differ from the configuration it claims to capture. The differ would report phantom changes, and a downloaded file would carry the neighbouring values to another estate. It makes the checksum agree with corrupted content. |
| **Quantise at export** | **Rejected**, for ADR 0025 §2a(b)'s reasons. The right precision is a per-field business decision, and a value nobody configured would be applied. |
| **Change `payload` to `longText`** | **Rejected.** It is a structural migration after the freeze, and **nothing breaks without it**: §1 gets the same byte-exactness inside the existing column and keeps its validation. |
| **Retire v1 and accept only v2** | **Rejected.** Every bundle file exported before this release would become unappliable, with a message that falsely says it was edited. |
| **Verify by trial (v2, then v1)** | **Rejected.** A deterministic selector exists (`format_version`, under the digest), so trial adds nothing except the chance of verifying under a recipe that did not seal the bundle. |
| **A `checksum_version` column, or a prefix on `checksum`** | **Rejected.** Each is a schema change (`checksum` is `string(64)`) to record what `format_version` already records. |

## What this ADR deliberately does not do

- It requests **no schema change** and writes **no data migration**. It forbids
  re-checksumming any stored bundle.
- It does not change `CanonicalJson`, `ConfigurationDiffer`, `canonicalise()` or the v1
  recipe.
- It does not declare `measure_thresholds.bands` or the `workflow_definitions` json columns
  as `json` in `ConfigurationSchema` (§5, booked to platform).
- It does not fix the BCMS reminder key or DR `content_hash` (§5, booked to BCMS). It names
  the technique each could use.
- It does not make an older release read format 2. Exchanging environments must run this
  release or later. An older one refuses a format-2 file, and fails closed.

## Consequences

- A bundle verifies, and applies exactly what was exported, on both engines and across
  them, including the MariaDB estate to production route the feature exists for.
- Format-1 bundles and files keep working under the recipe that sealed them.
- A format-2 bundle given to an older release is refused. It is never half-applied.
- `payload` is no longer JSON-path-queryable. Nothing queried it.
- Two latent sites (the BCMS reminder key and DR `content_hash`) have a named fix direction,
  and one format residual (undeclared json sections) is booked, not folded in here.
