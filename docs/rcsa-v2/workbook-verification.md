# Verifying against `SB_RCSA Template 2026`

The workbook arrived in `plans/` at the end of P9. It had been unavailable for
the whole build, so P0's truth table and P6's export layout were both written
from the implementation plan's prose. This is what checking them found.

**The engine was right. The document around it was not.**

---

## The calculation engine — verified, 100 of 100

§12's P0 acceptance criterion was *"100-case truth table (5 likelihood × 5
impact × 4 CE) passes against the workbook formulas, byte for byte"*. It could
not be run at the time. It now runs on every build as
`tests/Unit/Rcsa/RcsaWorkbookParityTest`.

The workbook's formulas, transcribed from `RCSA Sheet` row 3:

| Col | Formula |
|---|---|
| L | `=CHOOSE(MATCH(J3,{"Rare","Unlikely","Possible","Likely","Almost certain"},0),1,2,3,4,5) * CHOOSE(MATCH(K3,{"Very Low","Low","Medium","High","Very High"},0),1,2,3,4,5)` |
| M | `=IF(L3<=2,"VERY LOW",IF(L3<=4,"LOW",IF(L3<=9,"MEDIUM",IF(L3<=16,"HIGH","VERY HIGH"))))` |
| P | `=CHOOSE(MATCH(O3,{"Fully Achieved","Mostly Achieved","Partially Achieved","Not Achieved"},0),100,75,50,25)` |
| Q | `=L3*(1-P3/100)` |
| R | same bands as M, over Q |
| S | `=IF(R3="VERY HIGH","TREAT",IF(R3="HIGH","TREAT",IF(R3="MEDIUM","MITIGATE",…"ACCEPT")))` |
| T | `=IF(S3="ACCEPT","Within risk appetite: Continue routine monitoring",IF(S3="MITIGATE","Above risk appetite:mitigate",IF(S3="TREAT","Above risk appetite:Treat")))` |

Every one of the 100 cases agrees on all six derived columns. **Defect D2 is
confirmed as template parity, not an error**: `Fully Achieved` carries a
modifier of 100, so `L*(1−100/100)` is zero and a 5×5 risk bands VERY LOW. The
workbook does that; the engine does that. §14 Q3 remains the bank's to answer,
and `residual_floor` is where the answer goes.

The formulas are **transcribed into the test rather than parsed from the file** —
a test that opened the workbook would be testing PhpSpreadsheet, would break on
a re-save, and would pass vacuously if the file went missing.

---

## The export layout — three of five spans were wrong

P6 derived the merged group headers from §10.1's list of five names against
§4's column table. The file's actual merges are `A1:I1`, `J1:M1`, `N1:O1` and
`S1:W1`, with `R1` holding "Residual Risk" as a **single unmerged cell**.

| Group | P6 assumed | Workbook | |
|---|---|---|---|
| Process | A–I | A–I | ✅ |
| INHERENT RISK | J–M | J–M | ✅ |
| Control assessment | N–P | **N–O** | ❌ |
| Residual Risk | Q–T | **R alone** | ❌ |
| RISK TREATMENT PLAN | U–W | **S–W** | ❌ |

Two consequences look like mistakes and are not:

- **P and Q sit under no banner at all.** `C.E modifier` and `Residual risk` are
  ungrouped in the source, and reproducing that is the point.
- **RISK TREATMENT PLAN starts at S**, taking `Risk Treatment` and `Risk
  appetite alignment` into the treatment block rather than leaving them with the
  residual figures — the more sensible of the two arrangements, and the
  workbook's.

Fixed, and `ExportTest` now asserts the **column each banner starts at**. It
previously used `assertContains`, which passed happily while three spans were
wrong — a weak assertion is how a layout bug survives a green suite.

---

## The importer rejected the bank's own workbook

The most serious finding, and not cosmetic.

`Sheet1` lists the thirteen categories with a `" Risk"` suffix — **"Operational
Risk"** where this module stores **"Operational"**. Twelve of the thirteen were
rejected by the import normaliser.

**A bank completing the `SB_RCSA Template 2026` they already use and uploading
it would have failed validation on every row.** The one document the module
exists to be compatible with was the one document it would not accept.

Fixed by aliasing, not by adopting: the stored vocabulary stays unsuffixed —
"Operational Risk" reads as a tautology in a column headed Risk Category, and
the suffix would follow into every filter, dashboard grouping and export from
here on. What matters is that the file's spelling is understood.

All **41** vocabulary values the workbook offers now import, pinned by
`every_value_the_workbook_offers_is_accepted_by_the_importer`.

---

## Where the workbook contradicts itself

Two places. **The formula wins in both, because the formula is what
calculates** — and the importer accepts either spelling, because a user will
meet either.

| | Reference sheet says | Formula matches on |
|---|---|---|
| Impact level 3 | `Moderate` | `Medium` |
| Control ratings | `Fully achieved` (lower case) | `Fully Achieved` |

---

## Left alone, deliberately — for the bank to decide

These are visible in an exported document, and none is a defect. Each is a case
where the workbook's own text is untidy and matching it byte-for-byte would mean
reproducing a typo.

| | Workbook | Ours |
|---|---|---|
| Appetite, mitigate | `Above risk appetite:mitigate` | `Above risk appetite: Mitigate` |
| Appetite, treat | `Above risk appetite:Treat` | `Above risk appetite: Treat` |
| Column I header | `Others (Others (If there are more than one Risk Category applicable ,specify)` | `If more than one risk category is applicable, specify` |
| Category | `Financial  Risk` (two spaces) | `Financial` |
| Column headers generally | `RISK SCORE`, `Residual risk `, `Implementation date` | Title Case, trimmed |

§2's design rule is that an export should *"look and calculate identically"*.
It calculates identically, and the structure now matches. Whether it should also
reproduce an unbalanced parenthesis and a missing space after a colon is a
judgement about what the regulator is handed — **the bank's call, not the
build's**. Changing any of them is a one-line edit to
`RcsaWorkbookWriter::COLUMNS` or the methodology seed.
