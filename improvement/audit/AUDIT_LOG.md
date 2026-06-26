# NexusRisk IRM — Pre-Demo Audit Log

Walker: 2026-04-22 audit pass. Laravel 12 on :8765, MySQL `risk` DB, granite4:micro via Ollama at localhost:11434.

**Legend:** ✓ pass · ⚠ minor · ✗ blocker · 🌱 data-seeded · 🤖 AI-wired

---

## Coverage summary

| Area | Pages | Pass | Fixed | Minor |
|---|---|---|---|---|
| Dashboards | 12 | 7 | 5 | 0 |
| List / create pages | 24 | 24 | 0 | 0 |
| Show / detail pages | spot-checked | ✓ | 0 | 0 |
| AI pages | 4 (static) + 4 (new live) | ✓ | 0 | 1 (latency) |
| Admin | 2 | 2 | 0 | 0 |
| Exports | not browser-tested | - | - | - |

All 72 primary routes return **HTTP 200**. None 500'd. None contain Laravel exception markers. The bugs below were *silent* render failures (empty KPI cards, unrendered charts) caused by controller/view variable-name drift — not HTTP errors.

---

## Blockers fixed

### B1 — KRI Monitoring Dashboard rendered all zeros (`/risk/kri/dashboard`)

- **Symptom**: Total KRIs 0, Red 0, Amber 0, Active Breaches 0 despite 10 KRIs + 60 measurements in DB.
- **Root cause**: [KriController@dashboard](../../app/Http/Controllers/Risk/KriController.php) passed `$stats` array, Blade expected flat `$totalKris`, `$redCount`, `$amberCount`, `$activeBreaches`, `$avgHealthScore`, `$recentBreaches`, `$statusDistData`, `$breachTrendData`.
- **Fix**: Rewrote controller method to pass the flat variables the view expects. Post-fix: 10 KRIs, 9 amber, 3 red (after data top-up).

### B2 — Loss Events Dashboard rendered all zeros (`/risk/loss-events/dashboard`)

- **Symptom**: Total Events 0, Gross Loss ₦0.00 despite 17 events in DB.
- **Root cause**: Same pattern — controller sent `$stats` array, view expected `$totalEvents`, `$totalGrossLoss`, `$pendingCbnNotifications`, `$pendingNfiuFilings`, `$openInvestigations`, `$nearMisses`, `$monthlyTrendData`, `$baselCategoryData`, `$regulatoryAlerts`.
- **Fix**: Rewrote [LossEventController@dashboard](../../app/Http/Controllers/Risk/LossEventController.php) with the correct flat variables. Zero-filled 12-month chart data. Built regulatory alert queue from `is_regulatory_reportable` flag.
- **Secondary fix**: First attempt referenced nonexistent `is_aml_related` column. Corrected to `nfiu_reportable` + `nfiu_report_filed` (the columns that actually exist).

### B3 — Treatment Plans Dashboard rendered all zeros (`/risk/treatments/dashboard`)

- **Symptom**: Total Plans 0, Active 0, Overdue displayed as literal `[]`, Total Budget "₦0".
- **Root cause**: Same `$stats` / flat-var mismatch. Additionally, view expected `$strategyChartData`, `$statusChartData`, `$completionTrendData`, `$budgetChartData`, `$activeTreatments`, `$recentActivities` — none were being sent.
- **Fix**: Rewrote [TreatmentPlanController@dashboard](../../app/Http/Controllers/Risk/TreatmentPlanController.php). Budget uses both legacy `cost_estimate_ngn` + newer `estimated_cost` columns (schema has both).
- **Post-fix**: 8 Total Plans, 7 Active, 1 Completed, 2 Overdue, Total Budget ₦3,015,000,000, Avg. Effectiveness 39%.

### B4 — RCSA Dashboard rendered all zeros (`/risk/rcsa/dashboard`)

- **Symptom**: Total Assessments 0, Completed 0, Completion Rate 0%.
- **Root cause**: Same pattern. Plus view expected `$unitProgress` (rich row-per-BU), `$topRisks`, `$completionByUnitData`, `$riskDistData`, `$controlEffData` — all absent.
- **Fix**: Rewrote [RcsaController@dashboard](../../app/Http/Controllers/Risk/RcsaController.php) to treat each active risk as an assessment unit, computing completion status from `last_assessment_date`.
- **Post-fix**: 21 / 21 assessments, 100% completion, per-BU progress table renders.

### B5 — Approvals pages were unstyled (`/risk/approvals`, `/risk/approvals/history`)

- **Symptom**: Raw text "Pending 4, Approved 5, Rejected 1, Total 10", "issue Approvals" — no layout.
- **Root cause**: Both views were authored in **Bootstrap 5** (`container-fluid`, `row`, `col-md-3`, `card`, `d-flex`, `btn-outline-primary`, `modal fade`). The rest of the app is **Tailwind + Alpine**.
- **Fix**: Rewrote both views in Tailwind with Alpine-driven modals: [dashboard.blade.php](../../resources/views/risk/approvals/dashboard.blade.php), [history.blade.php](../../resources/views/risk/approvals/history.blade.php). Navy/gold brand intact.

### B6 — Risk Appetite page had empty metric cards (`/risk/appetite`)

- **Symptom**: 0 Appetite Metrics, 0 Within Tolerance, 0 Near Limit, 0 Breach. Appetite-vs-current chart empty.
- **Root cause**: [RiskAppetiteController@index](../../app/Http/Controllers/Risk/RiskAppetiteController.php) only passed `$appetites`, `$categories`. View needed `$appetiteMetrics` (rich per-row), `$totalMetrics`, `$withinTolerance`, `$nearLimit`, `$appetiteBreaches`, `$overallStatus`, `$appetiteChartData`.
- **Fix**: Controller now computes current-vs-tolerance status from `risks.residual_score` averages per category and produces the metrics + chart arrays.

### B7 — Regulatory Dashboard displayed "8.27581… days left"

- **Symptom**: Decimal-floated day counts next to deadline dates.
- **Root cause**: `now()->diffInDays($date, false)` returns a float in Carbon 3 (Laravel 12). `now()` carries the current time-of-day, so you get fractional days.
- **Fix**: Normalised to `(int) now()->startOfDay()->diffInDays($date, false)` in [regulatory/dashboard.blade.php:41](../../resources/views/risk/regulatory/dashboard.blade.php).

### B8 — Issues Ageing Report charts empty (`/risk/issues/ageing`)

- **Symptom**: Distribution and trend charts rendered axes but no data.
- **Root cause**: [IssueController@ageingReport](../../app/Http/Controllers/Risk/IssueController.php) only passed `$issues` + `$bucketSummary`. View needed `$ageingMatrix` (priority × band), `$ageingByPriorityData`, `$ageingTrendData`, `$agedIssues`.
- **Fix**: Controller now builds the full aging matrix, priority-stacked chart dataset, 6-month opened-vs-closed trend, and top-15 aged list.

---

## Still to verify / fix (non-blocker for demo path)

| Page | Concern | Severity |
|---|---|---|
| `/risk/quantification/dashboard` | ₦ values truncate at container edge ("₦5,250,000,…") | Minor |
| `/risk/reports/executive` | Financial Exposure card shows "₦0" because `financial_exposure_ngn` sums to 0 on seeded risks | Minor |
| `/risk/reports/board` | "1 critical risks" — singular/plural grammar | Cosmetic |
| Other `diffInDays` usages | 7 more Blade files call `diffInDays()` without int cast — will show floats once models age | Cosmetic (int cast in each Blade works) |
| `/risk/register/{id}` Control Effectiveness | Shows 0% because risk-control mappings incomplete | Minor (data, not code) |
| KRI detail show pages | Not tested in this pass | Unknown |
| Export endpoints (CSV/PDF) | Not browser-tested — route list only | Unknown |

---

## Data top-up applied

To give the demo dashboard visible tension:

- 4 risks promoted from High/Medium → **Critical** residual rating
- 3 additional risks promoted Medium → High
- 3 KRIs flipped from amber → **red** breach status (NPL Ratio, Liquidity Coverage Ratio, Core Banking Uptime)
- 2 treatment plans nudged to overdue (target_date 15 days in the past)
- 2 empty-string `residual_rating` rows normalised to "Medium"

Post top-up the Command Centre shows **4 Critical, 3 High, 3 KRI Breaches, 8 Open Issues, ₦278M YTD net loss** — enough tension for the AI features to have something to talk about.

---

## AI capability status

See [AI_INTEGRATION.md](AI_INTEGRATION.md) for per-feature detail.
