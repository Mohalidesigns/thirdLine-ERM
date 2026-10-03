# ADR 0025 — The TPRM audit chain hashes canonical JSON, not the text the column returns

**Status:** Accepted · **Date:** 2026-10-03 · **Phase:** cross-engine defect in TPRM Phase 0 (`tp_audit_logs`). It lands **on PR #11, before merge**, because the MySQL 8 CI leg cannot go green without it · **Author:** architect
**Requested by:** the coordinator, from the first MySQL 8 CI run (37101761127 on `e68d3df`), triage item C
**Amended:** 2026-10-03, before merge, after gate 2 (code-reviewer) reproduced on MySQL 8.0.46 that the server's JSON parser does not read every double back exactly. Changed: §1 (the bigint and double bullets, numeric equivalences), §2 (the write-time float rule, §2a), §4 (tests 2, 4 and 5 amended; tests 6 and 7 new), the implementation steps, the scope check (config bundles), and `DEVELOPMENT_STANDARD.md` §8, §11 and §15. **The digest function, its tag and the recipe-pin literal do not change** (§2a explains why).
**Consumers:** backend-engineer **(lead: one support class, one model change, tests, one standard entry)** · qa-engineer · code-reviewer · reliability-engineer (the read-only VPS checks, deploy sequencing) · compliance-analyst (what the TPRM trail can claim on a supervisory export) · TPRM track · platform (config bundles: a booked follow-up) · BCMS (DR evidence `content_hash`: a booked follow-up) · ERM (`risk_audit_trail`: verdict only, no change)

## Context

### The mechanism

`App\Models\Tprm\AuditLog` seals each row in its `creating` hook with
`chainHash($row->getAttributes(), $row->previous_hash)`. It verifies a row with
`isIntact()`, which runs the same function over the attributes as read back.

At insert, `before` and `after` are already strings in `getAttributes()`. The
`array` cast encodes on assignment, and Eloquent's `asJson()` calls
`Json::encode($value, 0)`, i.e. PHP's default flags. The digest therefore covers
PHP's own text: keys in insertion order, no spaces, `\/` and `é` escaped.
The `is_array()` branch in `chainHash()` (line 140) never runs on the write path.
Its comment says arrays arrive "before the cast has run", but Eloquent has
already encoded the value by then.

The two engines read that text back differently. The columns are
`$table->json('before')` and `$table->json('after')`
(`2026_09_06_110009_create_tprm_portal_governance_tables.php:308-309`):

| Engine | What `json` becomes | What a read returns |
|---|---|---|
| MariaDB 10.4 | `LONGTEXT` + `CHECK (json_valid(...))` | The stored text, byte for byte. The digest matches. |
| MySQL 8.0.46 (the production VPS) | Native binary `JSON` | A **re-serialisation**: keys sorted (by length, then bytewise), `": "` and `", "` spacing, slashes and unicode unescaped, numbers re-printed. The digest does not match. |

**On MySQL 8, every row with a non-null `before` or `after` reads as tampered**,
and every TPRM write produces such a row. That is the failure at
`tests/Feature/Tprm/Phase0FoundationsTest.php:321`, which happens only on the
MySQL leg. A row with both payloads null still verifies. That is why the defect
looked like an intermittent test fault and not a broken design.

### Why it is worse than a red test

1. **It is silent in production.** Nothing calls `isIntact()` in production
   today. `audit:verify` (`app/Console/Commands/VerifyAuditTrail.php`) walks
   **`risk_audit_trail` only** and never reads `tp_audit_logs`. So the TPRM
   trail has never been verified anywhere except the test suite. The test suite
   ran on MariaDB until this morning.
2. **Rows sealed this way on MySQL cannot be rescued.** The v1 digest covers PHP's
   insertion order and escaping. MySQL threw both away at insert. Neither can be
   rebuilt from what the server returns, so no verifier, now or later, can check
   such a row's content.
3. **The trail's whole claim is arithmetic.** The model docblock says "here is
   the arithmetic". An arithmetic that reports every honest row as tampered is
   worse than no chain. The first person to run it would learn to ignore it.

### Other columns in the digest

`HASHED_FIELDS` has thirteen fields. I checked each column's type and cast for a
form that differs between engines:

| Field(s) | Column | Cast | Cross-engine form | Verdict |
|---|---|---|---|---|
| `before`, `after` | `json` | `array` | **Differs** (above) | **The defect** |
| `organization_id`, `auditable_id`, `actor_id` | `unsignedBigInteger` | none | Both engines return the integer. `(string)` also folds any int/string driver difference. | Safe |
| `auditable_type`, `event`, `actor_type`, `actor_label`, `ip`, `user_agent`, `correlation_id` | `varchar` | none | Both keep trailing spaces in `VARCHAR`. `strict => true` on both connections means an over-length value fails the insert and is not truncated. `user_agent` is already cut to 500 in `TprmAuditable:130`. | Safe |
| `created_at` | `timestamp` (precision 0) | `datetime` | Laravel writes `Y-m-d H:i:s` (the grammar's date format). Neither engine adds microseconds to a precision-0 column. | Safe, with **one residual** (below) |

There are no decimal or boolean top-level columns. Decimals and booleans appear
only *inside* the JSON payloads, and the canonical form below handles them.

**Residual: `TIMESTAMP` is read through the session time zone.**
`config/database.php` sets no connection `timezone`, so a `TIMESTAMP` is stored
in UTC and returned in the server's `SYSTEM` zone. Writing and reading in the
same zone round-trips. **If the production server's time zone ever changes,
every `created_at` in both chains (`tp_audit_logs` and `risk_audit_trail`) reads
back shifted, and every row reads as tampered.** That is the same hazard on both
engines. It is not fixed by this ADR. ADR 0022 already deferred the
connection-timezone decision to its own ADR. This ADR adds a read-only check of
the server's zone to the VPS checks, so the hazard is measured and not assumed.

## Decision

### 1. One canonical form, used for sealing and for verifying

Add `App\Support\CanonicalJson` (flat house layout; it is product-wide, not
TPRM's). It has three static methods:

| Method | Contract |
|---|---|
| `normalise(mixed $v): mixed` | Recursive. A **list** (`array_is_list`) keeps its order. Any other array is `ksort($a, SORT_STRING)`. Scalars and null pass through unchanged. |
| `encode(mixed $v): string` | `json_encode(normalise($v), CanonicalJson::FLAGS)`, with `serialize_precision` pinned to `-1` for the call and restored in `finally`. |
| `fromColumn(string\|array\|null $raw): ?string` | `null` → `null`. A **string** is decoded with `json_decode($raw, true, 512, JSON_THROW_ON_ERROR)`, then `encode()`d. An **array** is first passed through `json_decode(json_encode($raw, JSON_THROW_ON_ERROR), true)` (the `array` cast's own encoding), then `encode()`d. |

`FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR`.

**The principle is: decode first, then hash the value, on both paths.** The
sealing side never hashes PHP's text, and the verifying side never hashes the
server's text. Both decode to the same PHP value, and that value goes through
one encoder with fixed flags. **"Both decode to the same PHP value" holds for
strings, booleans, null, integers within int64, and the structure (keys and list
order). It does not hold for every double on MySQL 8** (the doubles bullet
below), which is why §2a keeps doubles out of the stored payload altogether. With this design, which flags are chosen matters
far less than the requirement that they are fixed. The choices below are
justified one at a time:

- **`JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE`.** This is a choice of
  readability, not of correctness. Any fixed escaping is deterministic after a
  decode. These two match the envelope `chainHash()` already uses and
  `ConfigurationExporter::checksum()`. That makes a canonical payload readable
  in a dump, and keeps one escaping convention across the product's digests.
- **`JSON_PRESERVE_ZERO_FRACTION`.** This keeps int and float apart in the
  digest, so changing `1` to `1.0` inside a payload counts as a change. It is
  safe because both engines keep the type. Eloquent writes the float `1.0` as
  `1` (no flag), so MySQL stores an integer and returns `1`, and both sides
  decode `int(1)`. Text that *does* hold `1.0` or `1e2` is stored by MySQL as a
  double and printed with a fraction, and PHP decodes it to a float on both
  sides.
- **`JSON_THROW_ON_ERROR`.** Without it, an encode failure returns `false`.
  `(string) false` is `''`, so **every row that failed to encode would get the
  same digest**. That is an empty preimage, not an error. In practice the
  `array` cast already throws `JsonEncodingException` on invalid UTF-8, so a
  value the canonicaliser rejects never reaches the insert anyway.
- **`serialize_precision = -1`, pinned.** PHP's float printing depends on an
  ini value. A server whose `php.ini` sets `17` would print `0.1` as
  `0.10000000000000001`. A row sealed on one PHP and verified on another would
  then mismatch. The canonicaliser pins the value and does not inherit it.
- **`ksort(..., SORT_STRING)`, not the default.** I checked this on this
  machine's PHP 8.4. With keys `10`, `"1a"` and `9` (`json_decode` turns numeric
  keys into ints), the default `SORT_REGULAR` comparison is not transitive. The
  three input orders produced **three different "sorted" outputs**. MySQL's
  key order is not PHP's, so the default flag would bring back the very
  dependence on input order this ADR removes. `SORT_STRING` sorts by bytes and
  gives one output for every input order.
- **Integers: exact within int64, and not beyond it.** PHP ints round-trip
  byte-exact on both engines across the whole of int64. The reviewer verified
  `PHP_INT_MIN` and `PHP_INT_MAX` on MySQL 8.0.46. MySQL also stores uint64
  exactly, and PHP decodes those digits to the same float on both sides.
  **Above `2^64` this ADR's first text was wrong.** MySQL converts the literal to
  a double with its own parser, and the reviewer measured that this double is
  not always the one PHP's `json_decode` reads from the same digits. Such a value
  can drift. No PHP value can produce one: a PHP int cannot exceed `PHP_INT_MAX`,
  and anything larger is already a float, which §2a quotes. Only raw SQL can
  write one.
- **Decode `assoc = true`, without `JSON_BIGINT_AS_STRING`.** This choice stands,
  but the reason is narrower than first stated. `BIGINT_AS_STRING` would turn a
  uint64 that MySQL kept exact into a string on one side, while the PHP-written
  text holds a float, so the flag would create a mismatch by itself. It was never
  a fix for the drift above `2^64`, and leaving it off does not make that drift
  safe. §2a does.
- **Doubles: MySQL 8.0.46 does not read every double back exactly.** This is
  measured, not inferred. `CAST('{"a":9.018867924528301}' AS JSON)` returns
  `9.0188679245283`. `1.0e25` returns `9.999999999999999e24`. PHP prints the
  shortest text that round-trips under `serialize_precision = -1`, and MySQL's
  parser reads some of those texts into a neighbouring double. A row whose
  payload holds such a value therefore reads as tampered on MySQL and verifies on
  MariaDB, which is the original defect again at a smaller scale. A payload of
  `{"v": 478/53}` written through `AuditLog` is intact on MariaDB and **not
  intact** on MySQL. The reviewer's figures, over 2,000 samples:

  | Kind of double | Mismatch rate on MySQL 8.0.46 |
  |---|---|
  | Unrounded ratios (`a/b`) | about 8–10% |
  | Large or small exponents | 25–30% |
  | Rounded to 1–2 dp, magnitude up to 10^7 | 0 observed |

  The third row is an observation over a sample, not a guarantee, and **no rule
  can be built on it** (§2a, alternative b). No canonicaliser can undo this
  drift: by the time the verifier reads the row, the server has already stored a
  different number. The value has to be kept out of MySQL's number parser before
  insert. That is §2a.

The canonical form deliberately treats some pairs as equal. These are
equivalences, not collisions, and the tests record them (§4):

| Equal under the canonical form | Why that is correct |
|---|---|
| `{}` and `[]` | `assoc = true` decodes both to `[]`. Eloquent never writes `{}` for an array, and the application reads both as `[]`. The digest covers what the application can read. |
| `{"0":"a","1":"b"}` and `["a","b"]` | They are the same PHP array. Same reasoning. |
| Key order, whitespace, `\/` against `/`, `é` against `é` | That is the bug being fixed. |
| **Numeric literals that decode to the same PHP value.** `1e2`, `1.0e2`, `100.0` and `100.00` (all `float(100)`, canonical `100.0`). `0.1` and `0.1000000000000000055511` (digits beyond double precision, the same double). `18446744073709551616` and `18446744073709551617` (both above `PHP_INT_MAX`, both collapse to `float(1.8446744073709552e19)`). | The application reads one value from each group, so a raw edit from one spelling to another changes nothing it can read. The digest covers the decoded value, so these are equal by construction. They are recorded here so nobody reports them as collisions. Through the model they cannot arise anyway: §2a stores every float as a string, so the only numbers left in a stored payload are int64 integers. |

**Not equal:** `true` and `1`, `1` and `"1"`, `1` and `1.0`, `null` and `""`,
**`72.5` and `"72.5"`**, and list order. Each of these is a real change, and a
test asserts that each one is detected. `1e2` and `100` are also not equal: the
first decodes to a float and the second to an int.

A JSON `null` literal and SQL `NULL` are also **not** equal. `fromColumn('null')`
returns the string `"null"` and `fromColumn(null)` returns `null`, so the two
digests differ. Eloquent stores SQL `NULL` for a null array, so the application
cannot produce the pair. A raw-SQL edit that turns one into the other is
therefore detected, which is the stricter and safer outcome.

### 2. The v2 recipe for `tp_audit_logs`

```
write    : before, after  →  every PHP float in the stored value becomes its
                             shortest round-trip text, as a JSON string (§2a);
                             this runs in `creating`, BEFORE the digest and the insert
digest   = sha256( "tp_audit_logs/v2|" . (previous_hash ?? "") . "|" . envelope )
envelope = json_encode(payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
payload  = HASHED_FIELDS, in their existing order, each:
             before, after  →  CanonicalJson::fromColumn(raw)        (string or null)
             created_at     →  DateTimeInterface ? format('Y-m-d H:i:s') : raw
             everything else →  null stays null, otherwise (string) raw
```

- `HASHED_FIELDS` and its order **do not change**. `id` stays excluded, for the
  reason the docblock already gives.
- The canonical payload stays a **string** inside the envelope. Nesting it as a
  structure would also work, but it would be a second change to the recipe for
  no gain.
- `"tp_audit_logs/v2|"` is a **domain-separation tag**, and it is how the recipe
  carries its version without a schema change (§3). A v1 digest and a v2 digest
  of the same row cannot be equal, so no row can verify by accident under a
  recipe that did not seal it.
- **The model's date format must stay `Y-m-d H:i:s`.** A `$dateFormat` with
  fractional seconds would break the chain on its own: MySQL **rounds** a
  fractional value inserted into `TIMESTAMP(0)` and MariaDB **truncates** it, so
  the stored second would differ from the hashed one. The new feature test
  (§4, test 2) catches this.

### 2a. A float is stored as a string (the write-time rule)

**Rule.** In `AuditLog`'s `creating` hook, before the digest is computed and
before the insert, `before` and `after` are decoded, and every PHP `float`
anywhere in the value is replaced by its JSON text under
`serialize_precision = -1` with `JSON_PRESERVE_ZERO_FRACTION`, stored as a JSON
**string**. Examples: `478/53` → `"9.018867924528301"`, `1.0e25` → `"1.0e+25"`,
`0.1 + 0.2` → `"0.30000000000000004"`, `-0.0` → `"-0.0"`. Ints, strings,
booleans, null, keys and list order are not touched. `NAN` and `INF` throw a
`JsonException`, as `CanonicalJson` already does.

**Why a string is safe on both engines.** A JSON string never passes through
MySQL's number parser. Both engines return it byte for byte, so the stored value
and the digest are deterministic. PHP ints within int64 also round-trip exactly
on both engines, so after the rule a stored payload contains no number that
either engine can re-read differently.

**What a reader gets.** A number that was a PHP float comes back as a numeric
string. `(float) "9.018867924528301"` returns the original double exactly. The
payload is a record that people read, and a quoted number loses nothing.
**Every reader of `tp_audit_logs.before/after` was checked, and none needs a
float type.** No presenter, controller, export, board pack, regulator pack, AI
prompt or screen reads the payload at all. `TprmAuditable::auditLogs()` is used
only by tests. Every application reference to `AuditLog` is a write, and no test
asserts a float inside a payload. A future reader (the booked TPRM verifier, a
supervisory export) must treat payload numbers as `int|numeric-string`.

**An integral float that Eloquent writes as an integer stays an integer.** The
`array` cast encodes with flags `0`, without `JSON_PRESERVE_ZERO_FRACTION`. So
`75.0` is already the text `75` before `creating` runs, and it decodes as
`int(75)`. That int is exact on both engines, so it needs no quoting. It stays
`75`, as it is stored today. The coordinator's draft had `75.0` → `"75.0"`. That
would need a second hook at assignment (a mutator), and `setRawAttributes()`
would bypass that hook. The rule above covers every model insert from one place,
and it is stated as a property of the stored text: **no stored payload decodes
to a PHP float.** A test can assert that on any row. An integral float large
enough for PHP to print with an exponent (`1.0e+25`) decodes as a float and is
quoted.

**Where the rule lives, and why not inside `chainHash()`.** It runs in
`creating`, so it changes what is stored. It is **not** part of the digest
function. If `chainHash()` quoted floats at verification time, it would map
`72.5` and `"72.5"` to the same preimage, and a raw edit that changes a value's
type would go undetected. That is the class of tamper §1 promises to catch. As
a consequence:

- **The tag stays `tp_audit_logs/v2`, and the recipe-pin literal does not
  change.** `chainHash()` over stored attributes is the same function. Only what
  the model stores has changed. A v2 row written before this rule is still
  verifiable on the engine it was written on. Production has no such rows (§5),
  and the code is not yet merged.
- `CanonicalJson` stays a pure canonicaliser. It does not quote. Its contract
  gains a stated limit (DEVELOPMENT_STANDARD §15): **a digest over a MySQL
  `json` column is stable only for values with no non-integral or out-of-range
  doubles, so the writer must quote or quantise floats before storing.**

**Alternatives rejected:**

| Option | Verdict |
|---|---|
| **(b) Quantise floats at the hook, or refuse them** | **Rejected.** Quantising writes a value the application never held into the one table whose purpose is to record what happened. The right precision is also a per-field business decision, which a model hook cannot make. The 1–2 dp result is an observation over 2,000 samples, not a property of MySQL's parser, and a rule built on it is a guess. Refusing is worse. `TprmAuditable::writeAuditRow()` catches every `Throwable` and logs it (deliberately, so that an audit fault never fails the business write). A refusal would therefore **silently lose the audit row while the change it records commits.** |
| **(c) Document the limit, and add a guard test that every writer rounds** | **Rejected: unenforceable.** The trait audits raw `getAttributes()` / `getChanges()`, and Laravel does not cast `decimal:N` on assignment. So `$engagement->inherent_score = 72.5` reaches the payload as a PHP float, whatever the cast says. `before` comes from `getOriginal()`, which returns *cast* values, so it also carries any float decoded from an `array` column on the audited model. A guard test would have to enumerate every assignment to every audited attribute, in code not yet written. Today's writers round to 4 dp at most, but nothing enforces that, and §1 shows that rounding is not proven safe either. |
| **Quote at verification time, inside `chainHash()`** | **Rejected** (above). It hides type tampering. |
| **A mutator or custom cast at assignment** | **Rejected.** It would keep `75.0` as `"75.0"`, which nothing needs. It adds a second place to keep in step, and `setRawAttributes()` bypasses it. |
| **Store the payload in a `text` column** | **Rejected.** It is a structural migration after the freeze, and it would also take away MySQL's validation of the column. |

### 3. Versioning: a tag in the preimage, no column, and v1 retired outright

The alternatives, with the reason each one was decided:

| Option | Verdict |
|---|---|
| **A `hash_version` column** | **Rejected.** It is a structural migration after the freeze. What would break without it is *nothing*, provided production holds no v1 rows (§5). The column would also be outside the digest unless it were added to `HASHED_FIELDS`, which is a second recipe change. |
| **A version prefix on the stored digest** (`v2:…`) | **Rejected.** `hash` and `previous_hash` are `string(64)`, exactly one sha256 in hex. A prefix means widening both columns, which is two structural changes to the shape every chain consumer reads, so that a version can be read that the tag already settles. |
| **Verify by trial, accepting v1 or v2 indefinitely** | **Rejected.** On MySQL, v1 cannot verify a payload row, so accepting it there buys nothing. On MariaDB, only developer data was ever sealed under v1. Keeping v1 forever leaves the defective recipe in the code path of every verification. It also hides the one anomaly worth seeing: a row written by old code *after* the cutover. |
| **A one-off re-seal of existing rows** | **Rejected, and forbidden.** Re-sealing computes fresh digests over whatever the rows hold *now*. It turns "nobody can verify these" into "these verify" without anybody having verified them. That is a false statement made by the operator, written into the one table whose purpose is to prevent it. On MySQL it cannot be done honestly at all, because v1 cannot check the payload rows first. **No re-seal command is to be written, by anyone.** It would also need `UPDATE` on `hash`, which the model refuses by design. |
| **The tag in the preimage, v1 deleted** | **Chosen.** No schema change. The version is fixed by the arithmetic itself. The v1 code is removed, not kept beside v2. |

**The schema freeze is untouched: no column is requested.**

**If a recipe ever has to change while live rows exist,** the boundary goes *in
the chain itself*: one `system` row per organisation records the cutover (last
old-recipe row id, its hash, and the count). A verifier checks rows up to that
id under the old tag and later rows under the new tag. That design is named
here so it is not improvised under pressure. **It is not built now**, because
nothing needs it (§5).

### 4. Tests required

1. **`tests/Unit/Support/CanonicalJsonTest.php`, the cross-engine proof.** The
   PHP-verbatim text and the MySQL-normalised text of the same value give the
   same `fromColumn()` output. Fixture pair:
   - verbatim: `{"trading_name":"Café \/ Ltd","b":1,"a":[3,1,2],"n":null,"t":true,"f":75.9,"e":[],"o":{"z":1,"y":2},"d":"12.50"}`
   - normalised: `{"a": [3, 1, 2], "b": 1, "d": "12.50", "e": [], "f": 75.9, "n": null, "o": {"y": 2, "z": 1}, "t": true, "trading_name": "Café / Ltd"}`

   Also, as separate cases:
   - the PHP **array** path gives the same output as the string path, including
     a payload that holds a `Carbon`, a backed enum and the float `1.0`;
   - the three orderings of `{10, "1a", 9}` give one output. This case **must
     fail** if `SORT_STRING` is removed, so prove that once by hand;
   - every row in the "equal" table in §1 is equal, and every pair in the "not
     equal" list differs;
   - the result does not change when `serialize_precision` is set to `17` for
     the duration of the test;
   - a value that cannot be encoded throws, and does not return `''`.
2. **A real round trip on whichever engine CI runs**, in
   `tests/Feature/Tprm/AuditChainCrossEngineTest.php`. Write an audit row
   through `TprmAuditable` with the rich payload above. Read it back fresh and
   assert `isIntact()`. **It must not be able to pass by matching nothing.**
   Read the raw `after` column with `DB::table()`, then:
   - when `SELECT VERSION()` contains `MariaDB`, assert the raw text equals
     PHP's encoding;
   - otherwise (MySQL), assert it **differs**.

   That proves each leg exercised the engine behaviour this ADR is about. Both
   CI legs use the `mysql` driver, so detect the engine by the version string,
   not by `getDriverName()`.

   **Amended for §2a.** The rich payload's `"f": 75.9` is now stored as
   `"75.9"`. The MariaDB "verbatim" expectation is PHP's encoding of the payload
   **as stored**, with `f` quoted, and not of the payload as written. The
   reformatted-payload case must build its rewrite from the stored, decoded
   column, not from the PHP fixture. Otherwise it writes back an unquoted
   `75.9`, and it would be right to report that as a type change.
3. **`Phase0FoundationsTest::the_audit_chain_detects_a_row_edited_around_the_application`**
   passes on **both** CI legs, unchanged.
4. **Tamper is still detected.** Use query-builder `UPDATE`s that bypass the
   model, and assert `isIntact()` is false after each one:
   - a value changed inside `after`;
   - a type changed inside `after`: `true`→`1`, `1`→`"1"`, `1`→`1.0`, and
     (§2a) a stored `"72.5"`→`72.5`;
   - a list re-ordered inside `after`;
   - `created_at` moved by one second;
   - `event` changed (already covered).

   Also add a **link** assertion: delete a middle row with a raw `DELETE`, and
   assert that the next row's `previous_hash` no longer equals its new
   predecessor's `hash`. This works against the chain as stored, because no
   TPRM verifier exists (see "Does not do").
5. **Recipe pin.** One test computes the digest of a fixed attribute array with
   a fixed `previous_hash`, and asserts a literal hex string. This forces any
   later change to the recipe, the flags or `HASHED_FIELDS` to be deliberate:
   it fails, and the failing assertion points at this ADR. **The literal does
   not change under §2a.** If it does, the quoting has leaked into
   `chainHash()`. Add a case to the same file asserting that `chainHash()` over
   `{"v":72.5}` and over `{"v":"72.5"}` gives **different** digests, which pins
   the rule's location.
6. **Doubles survive MySQL (§2a)**, in `AuditChainCrossEngineTest`. Write a row
   through `TprmAuditable` with `after = ['ratio' => 478/53, 'big' => 1.0e25,
   'whole' => 75.0, 'n' => 7, 'nested' => ['r' => [0.1 + 0.2]]]`. Read it back
   fresh and assert `isIntact()` **on both legs**. Then assert the raw column,
   decoded, is exactly
   `['ratio' => '9.018867924528301', 'big' => '1.0e+25', 'whole' => 75, 'n' => 7, 'nested' => ['r' => ['0.30000000000000004']]]`.
   It must not be able to pass by matching nothing. Add a **control row** that
   bypasses the rule: a `DB::table()->insert()` whose `after` is the
   *unquoted* text `{"big":1.0e25}` and whose `hash` is `chainHash()` over that
   same text. Then assert, by engine: on MariaDB the control reads intact, and
   on MySQL it does **not**. That proves the MySQL leg really drifts, and that
   only the rule keeps the honest row intact. Use `1.0e25` for the control, not
   a ratio, because its drift is deterministic (§1). A ratio drifts only about
   one time in ten.
7. **The quoting function's own cases**, in `AuditLogHashRecipeTest` (pure
   PHPUnit, no database). `478/53`, `1.0e25`, `0.1 + 0.2`, `-0.0`, `5.0e-324`,
   `1.7976931348623157e308` and `(float) PHP_INT_MAX` each give the string in
   the implementation spec. `PHP_INT_MIN`, `PHP_INT_MAX`, `0`, `true`, `false`,
   `null`, `""` and `"12.50"` pass through identical (`assertSame`). Keys and
   list order are unchanged, and nesting is recursive. Applying it twice gives
   the same result as applying it once. The result does not change with
   `serialize_precision` set to `17`, and the pin is restored afterwards. `NAN`
   and `INF` throw `JsonException`. `0.1 + 0.2` must give
   `"0.30000000000000004"` and never `"0.3"`. That case exists to catch a
   `(string)` cast, which rounds to `precision = 14`.

### 5. Production rollout (MySQL 8.0.46)

**What production is expected to hold: no `tp_audit_logs` table at all.**
Production's last successful deploy was 2026-08-04. The failed deploy on
2026-09-13 applied 3 of 122 migrations and stopped at
`2026_08_09_100004_make_risk_audit_trail_append_only`. The table is created by
`2026_09_06_110009`, which comes later. The TPRM feature flag being off is
**not** the reason to expect zero rows. `TprmAuditable` fires on model events
whatever the flag says, so a seeder or a data migration would write audit rows
with the module switched off. The table's absence is the reason, and it must be
checked, not assumed.

**Read-only checks, run on the VPS before PR #11 merges** (in the `mysql`
client, against the application's database):

```sql
-- 1. Did the migration that creates the table ever run?
SELECT migration, batch FROM migrations
 WHERE migration = '2026_09_06_110009_create_tprm_portal_governance_tables';

-- 2. Does the table exist?
SELECT COUNT(*) FROM information_schema.TABLES
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tp_audit_logs';

-- 3. Only if (2) returned 1. BEFORE is a reserved word in MySQL; keep the backticks.
SELECT COUNT(*)                                        AS rows_total,
       SUM(`before` IS NOT NULL OR `after` IS NOT NULL) AS rows_with_payload,
       COUNT(DISTINCT organization_id)                 AS organisations,
       MIN(created_at) AS first_at, MAX(created_at) AS last_at
  FROM tp_audit_logs;

-- 4. The time-zone residual (Context). Record the answer; change nothing.
SELECT @@global.time_zone, @@session.time_zone, @@system_time_zone, VERSION();
```

**What happens next depends on the answer:**

- **(1) empty and (2) = 0, the expected case.** The deploy needs **no extra
  step and no data migration**. PR #11's merge creates the table, and every row
  ever written in production is sealed under v2. The fix must therefore be
  **committed on PR #11 before it merges**. This sequencing is already forced:
  PR #11 is held until the MySQL leg is green, and the MySQL leg is red until
  this lands.
- **(3) returns `rows_total > 0`.** **Stop and do not merge.** Bring the counts
  back to the architect. Rows with `rows_with_payload = 0` still verify under
  v1. Rows with a payload were sealed on MySQL and cannot be verified by any
  recipe (Context, point 2). The remedy is the in-chain cutover row named in
  §3, recorded honestly as "sealed under a recipe this engine cannot verify". It
  is **never** a re-seal. Deleting the rows is not available either: it is an
  audit trail.

**Developer and test databases.** Test databases rebuild under
`RefreshDatabase`. The developer database `risk` (MariaDB) holds rows sealed
under v1 by the TPRM seeders. These fail `isIntact()` once v2 lands. Nothing in
the product displays a TPRM seal, so nothing visible changes. Re-seeding `risk`
is the user's call, and it is optional.

## Scope check across the product

I grepped `hash(`, `hash_hmac(`, `hash_file(`, `sha256`, `isIntact`,
`expectedHash`, `digest`, `fingerprint` and `canonicalis` across `app/`:

| Site | What it hashes or compares | Verdict |
|---|---|---|
| `Tprm\AuditLog::chainHash` | Raw text of `json` columns | **Defect.** Fixed by this ADR. |
| `RiskAuditTrail::chainHash` (ERM; consumers `audit:verify`, `Rcsa\AuditController:128`) | `old_value`/`new_value` are **`text`** (`2026_02_22_200016:21-22`). Every writer passes a string (`AuditTrailService:33`, `RiskRegisterService:494`, `RcsaAuditRecorder:168`, `TreatmentPlanService:514`). Each writer sets `changed_at` explicitly. | **Safe** on JSON: neither engine parses `text`. It shares the `TIMESTAMP` time-zone residual. **No change.** |
| `ConfigBundle::isIntact`, `ConfigurationExporter::checksum`/`canonicalise` | A payload decoded from `json`, then key-sorted. Exported rows decode their own `json` columns (`exportRow`). | **Safe** on engine key order and escaping: it already decodes first, which is the precedent this ADR follows. **Latent:** default `ksort` (`SORT_REGULAR`) is input-order dependent on mixed numeric and string keys (§1). **Not changed here.** Switching it to `SORT_STRING` could change the checksum of a stored bundle whose JSON values hold such keys, and would then report an honest bundle as altered. **Booked:** make `canonicalise()` delegate to `CanonicalJson::normalise()` once the stored bundles have been checked, with its own test. **Amended: the double limit (§1) applies here too.** `config_bundles.payload` is a `json` column, and its checksum is computed over the PHP value *before* the insert. So on MySQL, a bundle that carries an unrounded or exponent-form double (a scoring weight, a ratio) can read as altered. The booked follow-up must also quote floats at export, or show that no exported column holds one. It goes to platform under its own ADR. |
| `ConfigurationDiffer::equivalent` | Compares canonicalised arrays loosely (`==`) | Safe. It inherits the latent sort, and `==` is lenient anyway. |
| `Bcms\Dr\DrService:617` `evidence.content_hash` | `json_encode($payload)` over the inbound DR payload. It is stored **beside** `raw_payload` inside the `evidence` `json` column. | **Latent.** Write-only today, because no code re-verifies it. Re-hashing `raw_payload` as read from MySQL would never match. **Booked to BCMS:** any future verifier hashes `CanonicalJson::encode()` of the decoded payload (redefining the field, under an ADR), or hashes the raw request body. Because of the double limit (§1), the decoded-payload route also needs the payload's floats quoted before insert. The raw-body route does not. |
| `Bcms\Plans\SourceResolver::hash` / `PlanDriftDetector` | A payload built from rows read from the database, fingerprinted and compared on the same engine | **Safe.** Both sides read from the same server. A database moved between engines would flag drift once (`needs_review`), which is benign and not an integrity failure. |
| `bcms_audit_logs` | `json` before/after, **no hash chain** | **Nothing to break.** Out of scope. |
| `rcsa_line_revisions` | `json` old/new, **no hash** | **Nothing to break.** |
| `Tprm\Evidence\EvidenceService:73/203`, `Bcms\Exercises\EvidenceService:92/184` | `hash_file` over bytes on disk | **Safe.** Engine-independent. |
| `RcsaRegisterRisk:122` | sha256 over normalised name strings | **Safe** |
| `ScimToken`, `ApiToken`, `PortalInvitation`, `AuthenticateScim`, scim limiter | sha256 of a token string | **Safe** |
| `IdempotentRequest:58`, `DeliverWebhookJob:186`, `AlertWebhookController:298` | Raw request or response body bytes | **Safe** |
| `CheckInService`, `CascadeEngine`, `AlertDispatcher`, `InboundResponseHandler` HMACs | HMAC of a row id | **Safe** |

**One defect, two latent sites booked, and the rest safe.**

## Implementation steps (backend-engineer)

1. Create `app/Support/CanonicalJson.php` to the contract in §1, with a
   docblock that names this ADR and the `SORT_STRING` finding.
2. In `app/Models/Tprm/AuditLog.php`:
   - add `public const HASH_RECIPE = 'tp_audit_logs/v2';`;
   - rewrite `chainHash()` to §2: use `CanonicalJson::fromColumn()` for `before`
     and `after`, add `JSON_THROW_ON_ERROR` on the envelope, and prefix
     `HASH_RECIPE.'|'`;
   - delete the v1 `is_array` branch and its comment;
   - correct the `HASHED_FIELDS` docblock. "Means re-sealing the chain in a
     migration" becomes "means a new `HASH_RECIPE` and the in-chain cutover of
     ADR 0025 §3; a re-seal is forbidden";
   - leave `creating`, `updating`, `deleting`, `expectedHash()` and
     `isIntact()` unchanged in shape.
3. **Do not** touch `RiskAuditTrail`, `ConfigurationExporter`,
   `ConfigurationDiffer`, `DrService` or any migration.
4. Write the tests in §4, items 1, 2, 4 and 5. Item 3 already exists. Run them
   on MariaDB locally. The MySQL leg is proven by CI on PR #11.
5. Add `DEVELOPMENT_STANDARD.md` **§15**, in the same commit:
   > **A digest over a `json` column hashes the decoded value, never the column's text.**
   > MySQL 8 stores `json` as a binary type and returns it re-serialised (keys
   > re-ordered, spacing and escaping changed). MariaDB returns the stored text
   > verbatim. Seal and verify through `App\Support\CanonicalJson`. Guarded by
   > `CanonicalJsonTest` and `AuditChainCrossEngineTest`. See ADR 0025.

   Also list `CanonicalJson` under §8 (primitives to reuse), and add the two
   tests to §11's load-bearing list.
6. **Amendment (§2a).** Add `AuditLog::quoteFloats()` and call it from the
   `creating` hook, before the digest. Correct the `CanonicalJson` and
   `AuditLog::chainHash()` docblocks so they state the double limit. Write
   tests 6 and 7, add the case in test 5, and adjust test 2 as §4 says. The
   standard's §8, §11 and §15 are already amended by the architect. Nothing
   else changes: no migration, no tag, no recipe-pin literal.
7. Commit **only** those files onto `integration/bcms-remaining`, scoped by hunk.
   The working tree also holds the uncommitted PHPStan cleanup, Phase 12 docs
   and `ndpa-register.md`. None of them belongs in this commit.

## What this ADR deliberately does not do

- **It does not build a TPRM chain verifier.** The `tp_audit_logs` chain has no
  `audit:verify` equivalent and no screen that shows a seal. That is a real gap
  in what TPRM can claim to a supervisor, and it is a feature, not this defect
  fix. **Booked for the TPRM track:** either extend `audit:verify` with a
  `--trail=tprm` option or add `tprm:audit-verify`, with the same CONTENT/LINK
  distinction. Phase placement to be decided with the TPRM P11 plan, not folded
  in here.
- **It requests no schema change.** No column is added and no column is widened.
- **It does not set a connection `timezone`.** That stays deferred, as in
  ADR 0022. The VPS check measures the hazard.
- **It does not change `risk_audit_trail`'s recipe**, or the config-bundle and
  DR-evidence hashing (both booked above).
- **It does not re-seal, repair or delete any row in any environment, and it
  forbids building a tool that would.**
- **It does not quantise, round or refuse any value** (§2a, alternative b), and
  it does not quote integers. It does not teach `CanonicalJson` to quote:
  quoting is a rule about what a writer stores, not about how a value is
  hashed.
- **It does not change the digest function, the `v2` tag or the recipe-pin
  literal.**

## Consequences

- The TPRM chain verifies on both engines. A real edit, including a type change
  or a list re-order inside a payload, is still detected.
- A number that was a PHP float reads back from a TPRM audit payload as a
  numeric string, and an integral float written as an integer reads back as an
  int. No current reader is affected (§2a). Every future reader takes
  `int|numeric-string`.
- Every production `tp_audit_logs` row is sealed under one recipe from the day
  the table is created, provided the VPS checks confirm the table does not
  exist yet.
- Developer rows sealed under v1 stop verifying. Nothing displays them.
- A later recipe change has a defined path (a new tag and an in-chain cutover)
  and a test that fails until the change is made deliberately.
- One more primitive in `app/Support`, which the config-bundle and DR-evidence
  follow-ups adopt in turn, instead of a third canonicaliser being written.
