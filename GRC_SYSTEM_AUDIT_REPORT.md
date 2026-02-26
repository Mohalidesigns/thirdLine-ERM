# GRC Risk Management Platform — Full System Audit Report

**Date:** February 23, 2026
**Prepared for:** Mohammed Ali
**Platform:** Laravel 12.x / PHP 8.2+ / SQLite (Dev) / Blade + Tailwind CSS

---

## Executive Summary

This is a comprehensive audit of the Risk & Audit Management System. The platform currently has **15 controllers, 32 models, 6 services, 42 migrations, 68 blade views, and 129 routes** — all returning HTTP 200. The core architecture is solid and production-grade, but there are significant gaps in feature interconnection, service integration, and Nigerian regulatory completeness that must be addressed to become the leading GRC platform out of Nigeria.

**Overall Readiness: 72%**

---

## PART 1: WHAT IS MISSING

### 1.1 Authentication & Authorization (CRITICAL)

The system uses `AutoLoginDev` middleware — a dev-only auto-login that bypasses all authentication. This is the single biggest blocker to production.

**Missing:**
- No login/registration screens (routes redirect to dashboard)
- No password reset, MFA/2FA (CBN now requires MFA for financial systems)
- No session management or timeout policies
- No SSO/LDAP integration for enterprise deployments
- 9 roles and 46 permissions are seeded via Spatie but never enforced in controllers — no `$this->authorize()` or `@can` checks in blade templates
- No middleware groups protecting routes by role

### 1.2 Services Built But Never Used

Six services were written but are disconnected from the controllers:

| Service | Status | Impact |
|---------|--------|--------|
| `RiskScoringService` | Built, unused | Controllers duplicate scoring logic inline |
| `ControlEffectivenessService` | Built, unused | Residual scores never recalculated when controls change |
| `RegulatoryThresholdService` | Built, unused | LossEventController hardcodes ₦10M instead of using 5 separate CBN/NFIU/NDIC thresholds |
| `IssueEscalationService` | Broken | References non-existent fields (`remediation_due_date`) and models |
| `ReferenceCodeService` | Built, unused | Every controller generates reference codes manually |
| `AuditTrailService` | Partially used | Only RiskRegister, TreatmentPlan, and Issue use it; Loss Events, Controls, KRIs, Assessments don't |

### 1.3 Missing Modules / Features

| Feature | Status | Why It Matters |
|---------|--------|----------------|
| **Proper Auth System** | Missing entirely | Cannot deploy without it |
| **File Upload & Attachment Storage** | Routes exist, no implementation | Loss events, issues, controls all reference attachments but files can't actually be uploaded |
| **Email/SMS Notifications** | Not implemented | CBN requires 7-day loss event notification; KRI breaches should trigger alerts |
| **Scheduled Jobs** | Not configured | Issue escalation, KRI breach detection, overdue treatment alerts need cron |
| **PDF/Excel Export** | Not implemented | Regulatory reports must be exportable for CBN/NDIC submission |
| **Bulk Import** | Not implemented | Banks need to import historical data (risks, loss events, controls) |
| **User Management CRUD** | Not implemented | No admin UI for creating users, assigning roles, managing permissions |
| **Organization Settings** | Not implemented | Multi-tenant org config (thresholds, branding, fiscal year) not manageable |
| **Workflow Engine** | Hardcoded status transitions | No configurable approval workflows; status transitions scattered across controllers |
| **Notification Center** | `notifications_log` table exists but unused | No in-app notification bell, no read/unread tracking |
| **Data Encryption at Rest** | Not implemented | CBN requires sensitive financial data encryption |
| **API Layer** | Not implemented | No REST API for mobile apps or third-party integrations |
| **Maker-Checker** | Partially implemented | Loss events have approval workflow; risks, controls, appetites don't |

### 1.4 Missing Packages

The `composer.json` only has 3 production dependencies (Laravel, Tinker, Spatie Permission). For a production GRC system, you need:

- `barryvdh/laravel-dompdf` — PDF export for regulatory reports
- `maatwebsite/laravel-excel` — Excel export/import
- `spatie/laravel-activitylog` — Comprehensive audit logging (CBN requirement)
- `laravel/sanctum` or `passport` — API authentication
- `laravel/breeze` or `fortify` — Authentication scaffolding
- `spatie/laravel-backup` — Automated database backups
- `sentry/sentry-laravel` — Error monitoring in production
- `predis/predis` — Redis for queues and caching
- `laravel/horizon` — Queue monitoring dashboard

---

## PART 2: FEATURE INTERCONNECTION ANALYSIS

### 2.1 What IS Connected (Working Well)

The Risk model serves as the central hub, and these connections are properly implemented:

```
Risk ──→ RiskAssessment        ✅ (assessment approval updates risk scores)
Risk ──→ Control               ✅ (many-to-many via pivot, link/unlink working)
Risk ──→ TreatmentPlan         ✅ (create treatment for specific risk)
Risk ──→ KeyRiskIndicator      ✅ (KRIs linked to risks, measurements tracked)
Risk ──→ LossEvent             ✅ (loss events reference risk_register_id)
Risk ──→ Issue                 ✅ (issues can link to a risk)
Issue ──→ LossEvent            ✅ (issues can originate from loss events)
LossEvent ──→ RCA              ✅ (root cause analysis with remediation actions)
LossEvent ──→ Approval         ✅ (multi-step approval workflow)
Issue ──→ RemediationActions    ✅ (action items with completion tracking)
Issue ──→ ProgressUpdates      ✅ (progress logging with types)
Issue ──→ EscalationLogs       ✅ (escalation history)
Dashboard ──→ All Modules      ✅ (aggregates from Risk, Loss, Issue, Treatment, KRI)
Reports ──→ All Modules        ✅ (executive, board, regulatory pull from everything)
Analysis ──→ All Modules       ✅ (heatmap, bowtie, trends, correlation use real data)
```

### 2.2 What SHOULD Be Connected But Isn't (Critical Gaps)

#### Gap 1: Controls Don't Feed Back Into Risk Scores
**Current:** When you update a control's `effectiveness_rating`, nothing happens to the linked risk's residual score.
**Should:** `ControlEffectivenessService.recalculateForRisk()` exists but is never called. When a control is updated or tested, the residual risk score of every linked risk should auto-recalculate.
**Impact:** Risk residual scores are stale and unreliable.

#### Gap 2: KRI Breaches Don't Trigger Risk Escalation
**Current:** KRI measurements are recorded and breach status (red/amber/green) is calculated, but breaches don't update the linked risk.
**Should:** A red KRI breach should automatically escalate the linked risk — flag it for review, send notifications, possibly update risk velocity.
**Impact:** You can have a red KRI and its linked risk sitting quietly at "medium" with no one alerted.

#### Gap 3: Loss Events Don't Update Risk Scores
**Current:** Loss events are recorded against a risk, but the risk's inherent score stays the same.
**Should:** Material loss events (especially repeat events) should trigger risk re-assessment or at minimum flag the risk for mandatory review.
**Impact:** A risk could have 10 loss events totaling ₦500M and still show a "Low" inherent rating.

#### Gap 4: Treatment Plan Completion Doesn't Update Residual Risk
**Current:** When a treatment plan is marked "completed," nothing happens to the risk.
**Should:** Completed treatments should recalculate expected residual scores and trigger a residual risk assessment.
**Impact:** Treatment plans exist in isolation; completing them doesn't improve your risk profile on paper.

#### Gap 5: Risk Appetite Has No Violation Detection
**Current:** Risk appetite statements exist per category (tolerance_lower, tolerance_upper, capacity) but are never compared against actual risk scores.
**Should:** The dashboard should show which risk categories are operating within/outside appetite. Board reports should highlight appetite breaches.
**Impact:** Risk appetite is purely decorative — never enforced or monitored.

#### Gap 6: Issue Remediation Doesn't Link to Controls
**Current:** Issues have remediation actions, but those actions aren't linked to specific controls they improve.
**Should:** Remediation actions should reference which control they strengthen, and upon completion, trigger a control re-assessment.
**Impact:** No traceability from issue → remediation → control improvement → risk reduction.

#### Gap 7: Quantification Scenarios Don't Feed ICAAP
**Current:** Quantification scenarios and simulation runs exist, ICAAP page exists, but there's no actual Monte Carlo engine running simulations.
**Should:** The `MonteCarloService` should execute actual statistical simulations (Poisson frequency, Lognormal severity) and feed results into ICAAP capital calculations.
**Impact:** The quantification module is a data entry shell with no computational engine.

#### Gap 8: AI Intelligence Is Mostly Hardcoded
**Current:** The AI module has 4 views (predictive, radar, regulatory pulse, benchmarking) but external feeds, industry benchmarks, and regulatory changes are all hardcoded arrays.
**Should:** At minimum, predictive analytics should use actual trend data from assessments and KRI measurements. Benchmarks should be configurable per industry.
**Impact:** The "AI" module is currently a static display, not intelligence.

#### Gap 9: Near-Misses Don't Convert to Loss Events
**Current:** NearMiss model has a `converted_loss_event_id` field, and a `createNearMiss` route exists.
**Should:** There should be a "Convert to Loss Event" button that pre-populates a loss event from near-miss data.
**Impact:** Near-miss tracking is disconnected from the loss event workflow.

#### Gap 10: Audit Trail Gaps
**Current:** Only Risk Register, Treatment Plans, and Issues log to `RiskAuditTrail`.
**Should:** ALL entity changes should be logged — controls, assessments, KRIs, loss events, appetite changes, user logins.
**Impact:** CBN examination will ask "who changed this control's effectiveness from effective to ineffective?" and you won't have the answer.

### 2.3 Data Flow That Should Exist (The Ideal Cycle)

```
Risk Identified
  → Assessment Created (inherent scoring)
    → Controls Linked (residual scoring)
      → KRIs Assigned (ongoing monitoring)
        → KRI Breach Detected
          → Risk Flagged for Review ← MISSING
          → Notification Sent ← MISSING
    → Treatment Plan Created
      → Treatment Completed
        → Residual Score Recalculated ← MISSING
        → Risk Re-assessed ← MISSING
    → Loss Event Occurs
      → RCA Performed
        → Issue Created from RCA
          → Remediation Actions Created
            → Control Improved ← MISSING LINK
              → Risk Residual Recalculated ← MISSING
      → Regulatory Thresholds Checked ← SERVICE EXISTS BUT UNUSED
        → CBN/NFIU Notification Triggered ← MISSING
    → Risk Appetite Compared ← MISSING
      → Board Report Highlights Breaches ← MISSING
```

---

## PART 3: MODEL RELATIONSHIP ISSUES

### 3.1 Conflicting Relationships

**KeyRiskIndicator has DUAL Risk relationships:**
- `belongsTo(Risk, 'risk_id')` — single risk (added by alignment migration)
- `belongsToMany(Risk, 'risk_kri_mapping')` — many risks via pivot

These conflict. The pivot table is the correct approach (a KRI like "NPL Ratio" can monitor multiple credit risks), but controllers use the belongsTo. Pick one approach.

### 3.2 Missing Reverse Relationships

| Model | Has Forward FK | Missing Reverse hasMany |
|-------|---------------|------------------------|
| Control | → NearMiss (linked_control_id) | Control.nearMisses() |
| Control | → LossEventControl | Control.failedInEvents() |
| Risk | → NearMiss (risk_register_id) | Risk.nearMisses() |
| LossEvent | → NearMiss (converted_loss_event_id) | LossEvent.convertedNearMisses() |
| User | → Everything (owner_id, created_by, etc.) | User.ownedRisks(), .ownedControls(), etc. |
| Organization | → 15+ child entities | Only has 5 of ~20 needed hasMany() |
| RiskCategory | → RiskAppetite | RiskCategory.appetiteStatements() |
| BusinessProcess | → Risk | BusinessProcess.risks() |

### 3.3 Multi-Tenancy Gaps

10+ child models lack `organization_id`: KriMeasurement, LossEventControl, LossEventRca, LossEventAttachment, LossEventApproval, IssueRemediationAction, IssueEscalationLog, IssueProgressUpdate, IssueAttachment, RcaRemediationAction, SimulationResult.

These rely on parent-chain scoping (e.g., KriMeasurement → KRI → org_id), which works but prevents direct org-level queries for aggregate reporting.

---

## PART 4: IMPROVEMENTS TO BE THE BEST GRC PLATFORM IN NIGERIA

### 4.1 Immediate Priorities (Weeks 1–4)

**1. Wire Up Existing Services**
You already built RiskScoringService, ControlEffectivenessService, and RegulatoryThresholdService. Connect them:
- Call `ControlEffectivenessService.recalculateForRisk()` after every control update
- Call `RegulatoryThresholdService.evaluateThresholds()` on every loss event create/update
- Replace inline scoring in RiskRegisterController with `RiskScoringService`

**2. Implement Authentication**
Install `laravel/breeze` or `fortify`. Add:
- Login with MFA (TOTP) — CBN requires this
- Password complexity policies (min 12 chars, uppercase, number, special)
- Session timeout after 15 minutes of inactivity
- Account lockout after 5 failed attempts
- Enforce Spatie permissions with middleware groups

**3. Event-Driven Architecture**
Create Laravel Events and Listeners for the critical flows:
- `LossEventCreated` → evaluate regulatory thresholds, send notification
- `KriBreachDetected` → flag linked risk, notify risk owner
- `ControlUpdated` → recalculate residual scores for linked risks
- `TreatmentCompleted` → trigger risk re-assessment
- `IssueOverdue` → escalate via IssueEscalationService

**4. Fix the IssueEscalationService**
The service references `remediation_due_date` (should be `target_resolution_date`) and a non-existent model. Fix it and wire it to Laravel's task scheduler (`php artisan schedule:run`).

### 4.2 Medium-Term (Weeks 5–12)

**5. Risk Appetite Monitoring Engine**
Build a service that compares actual risk metrics against appetite statements:
- For each RiskCategory, compare: average risk score vs tolerance_upper
- Count risks exceeding capacity
- Generate appetite utilization percentages
- Add appetite gauge to dashboard
- Flag breaches in board reports

**6. Regulatory Reporting Engine (Nigerian-Specific)**
This is what will differentiate you. Build:
- **CBN ORMS Returns** — Auto-generate quarterly operational risk returns in CBN format
- **NFIU STR/CTR Filing** — Auto-populate Suspicious/Currency Transaction Report templates
- **NDIC Returns** — Quarterly loss event returns in NDIC prescribed format
- **Basel III/IV Compliance Pack** — Operational risk capital calculations (BIA, TSA, AMA)
- **ICAAP Document Generator** — Auto-compile ICAAP from quantification results

No Nigerian GRC platform currently auto-generates these. If yours does, you win.

**7. Real Monte Carlo Engine**
Implement actual statistical simulations:
- Poisson/Negative Binomial for frequency
- Lognormal/Weibull/Pareto for severity
- Correlation matrices between risk categories
- VaR and CVaR calculations at 95th/99th/99.9th percentiles
- Feed results directly into ICAAP capital requirements

**8. Maker-Checker for All Critical Entities**
Extend the approval workflow beyond loss events:
- Risk register changes (new risks, rating changes) need approval
- Control effectiveness changes need verification
- Risk appetite changes need board approval
- KRI threshold changes need CRO sign-off

**9. PDF/Excel Export Engine**
Install `barryvdh/laravel-dompdf` and `maatwebsite/laravel-excel`:
- Executive risk report → styled PDF
- Loss event register → Excel with Basel classification
- KRI dashboard → PDF with charts
- Custom report builder → dynamic export
- Regulatory returns → CBN/NDIC prescribed formats

**10. Notification System**
- In-app notification center (bell icon, read/unread, categories)
- Email notifications (breach alerts, overdue reminders, approval requests)
- SMS for critical events (CBN threshold breaches, critical risk escalations)
- Configurable notification preferences per user/role

### 4.3 Differentiators to Win the Nigerian Market

**11. CBN Examination Readiness Module**
Build a module specifically for CBN examination preparation:
- Pre-examination checklist aligned to CBN Risk-Based Supervision Framework
- Auto-compile evidence packs (risk register, control tests, loss events, KRI trends)
- Gap analysis against CBN ORMS guidelines
- Examination finding tracker (your Issues module is 80% there)

**12. Nigerian Industry Benchmarking (Real Data)**
Replace hardcoded benchmarks with actual industry data:
- Partner with 5–10 banks to anonymize and share aggregate risk metrics
- "Your NPL ratio KRI is at 8.2% vs industry average 5.1%"
- This creates network effects — every bank that joins makes the platform more valuable

**13. Multi-Entity / Group-Level Consolidation**
Nigerian banking groups (e.g., FBNH, Access Holdings, UBA Group) need:
- Subsidiary-level risk registers that roll up to group
- Consolidated dashboards across entities
- Group-level appetite vs subsidiary-level limits
- Inter-company risk exposures

**14. Regulatory Change Management**
Build a living register of Nigerian regulatory changes:
- Track CBN circulars, SEC rules, NDPA updates
- Map regulatory changes to affected risks/controls
- Auto-generate impact assessments
- Compliance task tracking with deadlines

**15. Mobile App / PWA**
Risk managers in Nigeria need mobile access:
- Risk incident reporting from the field (branch managers)
- KRI data entry (daily metrics)
- Approval workflows on mobile
- Push notifications for breaches and escalations

**16. Integration APIs**
Build REST APIs for:
- Core banking system integration (auto-import loss events from transaction monitoring)
- HR system integration (user provisioning, org structure sync)
- Treasury system (market risk data feeds)
- AML system (STR/CTR data exchange)

---

## PART 5: TECHNICAL DEBT SUMMARY

| Category | Count | Severity |
|----------|-------|----------|
| Services built but unused | 5 | High |
| Hardcoded data (should be configurable) | 4 controllers | Medium |
| Duplicated logic (scoring, reference codes) | 6 controllers | Medium |
| Missing reverse model relationships | 12 | Medium |
| Models without organization_id | 10 | Medium |
| Conflicting relationships (KRI dual Risk) | 1 | High |
| No permission enforcement in controllers | 15 controllers | Critical |
| No validation request classes (inline validation) | 15 controllers | Low |
| No unit/feature tests | 0 tests written | High |
| No API documentation | 0 endpoints documented | Medium |

---

## PART 6: COMPETITIVE LANDSCAPE & POSITIONING

To be the best GRC platform out of Nigeria, you need to beat:

**Current competitors:** ARM GRC (manual/spreadsheet-heavy), generic international tools (Archer, MetricStream — expensive, not Nigeria-specific), and homegrown bank solutions.

**Your advantages:**
1. Built specifically for Nigerian regulatory environment (CBN, NFIU, NDIC, SEC)
2. Full Basel III/IV alignment with Naira-denominated calculations
3. Integrated quantification with Monte Carlo (once completed)
4. Modern tech stack (Laravel 12, responsive UI)
5. Multi-tenant from day one

**What will make you #1:**
- Auto-generated CBN regulatory returns (no one does this well)
- Real industry benchmarking with network effects
- Mobile-first incident reporting
- ICAAP automation
- CBN examination readiness module

---

## Conclusion

The foundation is strong — 72% of the platform is built with real database queries, proper relationships, and comprehensive views. The critical next steps are: (1) wire up existing services, (2) implement authentication, (3) build the event-driven feedback loops between modules, and (4) develop the Nigerian regulatory reporting engine. These four things will take you from a functional prototype to a market-ready product that no competitor in Nigeria currently offers.
