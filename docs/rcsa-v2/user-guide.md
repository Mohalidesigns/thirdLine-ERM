# RCSA — the one-page guide

For a risk champion filling in their unit's assessment. Everything else in the
module exists to make this page enough.

---

### 1. Find your assessment

**RCSA → My Assessments.** Yours are at the top; work in progress sorts first.
Open it and every process, risk and control is already there — you are not
typing risks in, you are rating the ones your unit owns.

If the list is empty, you are not assigned to a business unit yet. The screen
says so. Ask an administrator.

### 2. Answer three questions per risk

For each row: **Likelihood**, **Impact**, **Control Effectiveness**. That is all
you enter. Everything grey is calculated as you go — risk score, residual,
treatment, appetite — and it updates in the same keystroke.

- **Grid** rates many risks quickly. Arrow keys move, typing sets a value, it
  saves itself. No Save button.
- **Guided** walks one risk at a time with the definitions beside you. Switch
  freely; it is the same data.
- Hover an Impact value to see what the workbook means by it — "High" is
  ₦15m–₦30m, not a feeling.
- Select several rows and **Apply** to set the same control rating across them.

*Sixty risks in forty-five minutes is the design target. If it is taking longer,
say so — that is a bug in the screen, not in you.*

### 3. Deal with the red ones

When a residual lands **above appetite**, the row turns red and asks for a plan.
That is not optional: **Control to be implemented**, **who owns it**, **by when**
(a future date). Add more than one if the risk needs more than one.

### 4. Submit

**Submit for review.** If anything is missing you get a precise list — *"BU-R14
is above appetite with no action plan"* — and each item is a link that jumps to
the row. Fix them and submit again.

Once submitted every row locks and the ORM takes over.

### 5. If it comes back

A returned assessment reopens **only the risks the reviewer flagged**. The rest
stay exactly as you filed them and will not accept changes — that is correct, not
a fault. Reopened rows carry the reviewer's comment: change your rating, or
reply explaining why it stands.

---

### Working offline

**Working copy** downloads your assessment as Excel. Fill it in with no
connection, then **Upload**.

Only likelihood, impact, control effectiveness, the treatment and the rationale
are read back — every grey column is recalculated here, so anything typed into
one is ignored. If a colleague changed a risk while you were away, the upload
shows you both answers and asks which to keep. Nothing is written until you
press Apply.

Do not delete columns or rows: two hidden columns on the far left are how the
upload tells your rows apart.

---

### The five things people ask

| | |
|---|---|
| *Where is the Save button?* | There isn't one. Grid mode saves each cell as you leave it. |
| *Somebody else is editing a row* | You will be told, and your change is refused rather than overwriting theirs. Reload. |
| *Why can't I edit this row?* | Either it is locked as filed, or the ORM did not reopen it. The row says which. |
| *Why is my residual 0?* | A Fully Achieved control takes the residual to zero. That is the bank's methodology, not a bug. |
| *Where did my submission go?* | RCSA → My Assessments, and the status tells you who has it. |
