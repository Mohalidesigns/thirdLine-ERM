# Enterprise & Operational Risk Management — Comprehensive Upgrade Plan

**Date:** 2026-03-26
**Version:** 1.0
**Classification:** Strategic Product Brief

---

## TABLE OF CONTENTS

1. [Current State Summary](#1-current-state-summary)
2. [Archer Feature Map](#2-archer-feature-map)
3. [Gap Analysis Table](#3-gap-analysis-table)
4. [Upgrade Plan](#4-upgrade-plan)
5. [Solution Architecture & Data Flow](#5-solution-architecture--data-flow)
6. [Differentiation Strategy](#6-differentiation-strategy-for-african-enterprise-market)

---

## 1. CURRENT STATE SUMMARY

### 1.1 Platform Overview

Our GRC Risk Management platform is a Laravel 11 application with 36 Eloquent models, 22+ controllers, 111 Blade templates, 50 database migrations, and 13 service classes. It covers a substantial portion of the enterprise risk management lifecycle.

**Tech Stack:** Laravel 11 | Blade + Tailwind CSS | SQLite (dev) / MySQL/PostgreSQL (prod) | Spatie Permissions | Laravel Events & Queues

### 1.2 Existing Modules (13 Modules)

| # | Module | Status | Maturity |
|---|--------|--------|----------|
| 1 | **Scoping & Entity Management** | Functional | Medium — entity hierarchy exists but lacks deep organizational modeling |
| 2 | **Risk Register** | Functional | High — comprehensive fields including velocity, appetite alignment, regulatory mapping |
| 3 | **Risk Assessment** | Functional | Medium — supports inherent/residual scoring but lacks project-based and campaign-based assessments |
| 4 | **Control Library** | Functional | Medium — control CRUD and risk linking exist, but no testing workflows or evidence management |
| 5 | **Treatment Plans** | Functional | High — full lifecycle with approval workflow, milestones, cost tracking |
| 6 | **KRI Monitoring** | Functional | Medium — thresholds, breaches, measurements exist but no automated data collection |
| 7 | **Risk Appetite** | Functional | Low — basic appetite statements by category, no cascading tolerances |
| 8 | **Loss Event Management** | Functional | High — Basel III classification, CBN ORMS, NFIU reporting, RCA, near-miss tracking |
| 9 | **Issues & Findings** | Functional | High — escalation rules, remediation tracking, ageing analysis, regulatory flags |
| 10 | **RCSA** | Functional | Medium — worksheet, matrix, controls views exist but no campaign management |
| 11 | **Risk Analysis** | Functional | Medium — heatmap, bowtie, trends, correlation exist but are largely static |
| 12 | **Quantification** | Functional | High — Monte Carlo simulation, ICAAP, scenario library, VaR/ES calculations |
| 13 | **AI Risk Intelligence** | Stub | Low — controllers exist but return placeholder/demo data |

### 1.3 Supporting Infrastructure

| Component | Status | Assessment |
|-----------|--------|------------|
| Authentication & MFA | Functional | Solid — login, MFA setup/verify, password reset, account lockout |
| Role-Based Access (9 roles) | Functional | Good — Spatie permissions with granular role structure |
| Multi-Tenancy | Functional | Basic — organization_id scoping, needs deeper isolation |
| Approval Workflows | Functional | Basic — generic approval table, needs configurable multi-stage workflows |
| Audit Trail | Functional | Good — comprehensive event-driven audit logging |
| Notifications | Partial | Notification model exists but no real delivery channels configured |
| Event Architecture | Good | 10 domain events, 7 listeners — solid foundation for reactive risk management |
| Reporting | Functional | Templates exist for executive, board, regulatory, custom — but data is largely mocked |
| Export | Functional | CSV exports for all modules + regulatory formats (CBN ORMS, Basel, NFIU) |
| Regulatory Compliance | Good | CBN, Basel III, NFIU, BOFIA, NDIC, NDPA — Nigerian regulatory focus is strong |

### 1.4 Honest Assessment of Weaknesses

1. **AI Intelligence Module is a stub** — controllers return demo/placeholder data, no actual ML pipeline
2. **No campaign/project-based assessments** — assessments are ad-hoc, not organized into projects or campaigns
3. **RCSA lacks self-assessment campaign workflow** — no ability to launch, assign, track, and close RCSA campaigns
4. **No questionnaire engine** — no ability to create custom questionnaires with question libraries
5. **Control testing is absent** — controls exist but there's no formal control testing/effectiveness workflow
6. **Risk Catalog hierarchy missing** — risks are flat, no three-level roll-up (granular → intermediate → enterprise)
7. **No dashboard interactivity** — dashboards are server-rendered static views, not interactive/drillable
8. **Notification system incomplete** — model exists but no email/SMS/in-app push delivery
9. **No workflow engine** — approval is basic single-stage, no configurable multi-step workflow routing
10. **No data import capability** — no bulk import from CSV/Excel for migration scenarios
11. **No API layer** — entirely web-based, no REST API for integrations
12. **Reports are template-based** — no dynamic report builder or ad-hoc query capability
13. **No external loss event database** — no integration with industry loss databases
14. **No scenario analysis beyond Monte Carlo** — missing stress testing, sensitivity analysis, what-if modeling
15. **No risk taxonomy management** — categories are seeded, no UI for managing taxonomies
16. **No document/evidence management** — file attachments exist but no structured evidence repository

---

## 2. ARCHER FEATURE MAP

### 2.1 Archer Module Breakdown (From Competitive Analysis)

#### Module A: Enterprise Risk Management
**Purpose:** Consolidated risk and control management across the organization

| Feature | Description |
|---------|-------------|
| Consolidated Risk & Control View | Single view of all risks and internal controls across the organization |
| Risk-to-Process Mapping | Map risks to business processes, controls, higher-level risk statements and scenarios |
| Scenario Library | Library of targeted risk scenarios with ability to perform assessments on selected scenarios |
| Dual Assessment (Qualitative + Monetary) | Qualitative and monetary assessments of inherent and residual risk |
| Appetite Monitoring | Monitor risks against established tolerances and risk appetite |
| Consistent Taxonomy | Enforce consistent terminology, risk assessment methodology and rating scales |
| Issue Escalation & Remediation | Organized managed process to escalate, approve and remediate issues |
| Delegated Authorities & Auto-Routing | Delegated authorities for approving risk with automatic routing to authorized individuals |
| Predefined Reports & Dashboards | Visibility into risk and control inventory via predefined reports and dashboards |

#### Module B: Loss Event Management
**Purpose:** Capture, analyze, and manage operational loss events

| Feature | Description |
|---------|-------------|
| Consolidated Loss Catalog | Catalog including actual losses, near misses, and collated external loss events |
| Business Unit Assignment | Assignment of loss events by business unit and named individuals |
| Root Cause Analysis | Integrated RCA capability |
| Stakeholder Approval | Review and approval of loss events by key stakeholders |
| Aggregate Visibility | Visibility into aggregate losses by type, source, and area of ownership |
| Drill-Down Detail | Ability to drill into specific loss events for greater detail |
| Remediation Plans | Consolidated list of remediation plans tied to loss events |
| Cross-Module Correlation | Correlation of loss events with risk and control registers for risk assessments and Monte Carlo |
| Top-Down Integration | Loss events evaluated as part of top-down risk assessments and self-assessments |
| External Monte Carlo Export | Export to external Monte Carlo engines (Palisade @Risk) |

#### Module C: Key Indicator Management
**Purpose:** Establish, monitor, and act on key risk/control/performance indicators

| Feature | Description |
|---------|-------------|
| Multi-Entity KI Association | Associate KIs with business units and named individuals for accountability |
| Broad KI Scope | KIs for risk, control, strategies, objectives, products, services, and business processes |
| Data Collection Governance | Governance to ensure timely collection of indicator data |
| Threshold Calculation | Consistent approach to calculating indicator boundaries, limits, and stakeholder notification |
| Predefined KI Dashboards | Visibility to KI metrics and remediation plans via pre-defined reports and dashboards |
| Objective Linkage | Link indicators to established objectives |
| Trend Awareness | Early awareness of adverse trends and emerging problems |
| Accountability Tracking | Greater accountability for monitoring indicators |

#### Module D: Operational Risk Management (Integrated Use Case)
**Purpose:** Unified operational risk program combining all core use cases

| Feature | Description |
|---------|-------------|
| Integrated Use Cases | Combines: Top-Down Risk Assessment, Bottom-Up Risk Assessment, Loss Events, KI Management, Risk Catalog, Issues Management |
| Business Process Catalog | Catalog business processes and sub-processes with linked risks and controls |
| Dual Assessment Approach | First-line self-assessments (bottom-up) + second-line targeted assessments (top-down) |
| Self-Assessment Campaigns | Efficient management of self-assessment campaigns by second line of defense |
| Cross-Module Correlation | Consolidated view across processes, risks, controls, loss events, KIs, and issues |
| Inherent-Residual Tracking | Understand and observe changes in calculated residual risk over time |
| Program Management | Monitor key risk and control indicator program management |
| Consolidated Issue Management | Clear understanding of status of all open remediation plans and exceptions |
| Operational Risk Dashboards | Predefined reports, dashboards, workflows, and notifications |

#### Module E: Risk Assessment Management
**Purpose:** Project-based and campaign-based risk assessments

| Feature | Description |
|---------|-------------|
| Project-Based Assessments | Engage teams via targeted project risk assessments |
| Broad Project Scope | Projects for: fraud assessment, new product/service, business process, merger, acquisition |
| Custom Questionnaires | Projects documented with custom questionnaires and questions |
| Question Library | Extensive library of thousands of out-of-the-box questions from Archer |
| Risk Treatment Tracking | Risk treatments and remediation plans documented and tracked per project |
| Assessment Oversight | Oversight and management of all risk assessments in process |
| Prioritized Treatments | Consolidated list of prioritized risk treatments and remediation plans |
| Assessment Dashboards | Visibility into assessment progress, risk treatments, and remediation via dashboards |
| Ownership & Accountability | Ownership and accountability for assessments and remediation plans |

#### Module F: Risk Catalog
**Purpose:** Centralized risk taxonomy with hierarchical roll-up

| Feature | Description |
|---------|-------------|
| Three-Level Risk Hierarchy | Granular risks → Intermediate risk statements → Enterprise risk statements |
| Accountability by Line of Defense | First line and second line of defense managers assigned |
| Qualitative Drop-Down Assessment | Inherent and residual risk via drop-down qualitative approach |
| Value Roll-Up | Assessed values rolling up to intermediate and enterprise risk statements |
| Consistent Documentation | Consistent approach to documenting risk, assigning ownership and assessing risks |
| Central Risk Repository | Ownership and management of all risks in a central location |
| Granular-to-Enterprise Traceability | Ability to understand granular risks that are driving enterprise risk statements |
| Prioritized Risk Statements | Consolidated list of prioritized risk statements |

---

## 3. GAP ANALYSIS TABLE

### Priority Rating Scale
- **P0 — Critical:** Must have for enterprise credibility; blocking competitive positioning
- **P1 — High:** Strong differentiator; expected by sophisticated buyers
- **P2 — Medium:** Valuable enhancement; improves UX and adoption
- **P3 — Low:** Nice-to-have; future roadmap consideration

### 3.1 Features We Have But Need to Improve

| # | Feature Area | Current State | Gap vs Archer | Required Improvement | Priority |
|---|-------------|---------------|---------------|---------------------|----------|
| 1 | **Risk Register** | Flat risk list with comprehensive fields | Archer has 3-level hierarchy (granular → intermediate → enterprise) with value roll-up | Implement hierarchical risk catalog with roll-up scoring from granular to enterprise level | P0 |
| 2 | **Risk Assessment** | Ad-hoc assessments linked to individual risks | Archer supports project-based assessments with questionnaires, campaigns, and broad scope (fraud, M&A, new products) | Add project/campaign-based assessment framework with questionnaire engine | P0 |
| 3 | **RCSA** | Basic worksheet and matrix views | Archer has full self-assessment campaign management with 1st/2nd line workflow | Build RCSA campaign engine: create, assign, track, close campaigns with 1st/2nd line roles | P0 |
| 4 | **KRI Monitoring** | Thresholds and manual measurements | Archer has automated data collection governance, multi-entity association, objective linkage | Add automated data collection, link KRIs to objectives/strategies, campaign-style data gathering | P1 |
| 5 | **Risk Appetite** | Basic statements by category | Archer monitors against established tolerances with cascading thresholds | Implement cascading appetite/tolerance framework with automatic breach detection at all levels | P1 |
| 6 | **Control Library** | CRUD and risk linking only | Archer has control testing, effectiveness assessment, and evidence management | Add control testing workflow, test scheduling, evidence collection, effectiveness scoring | P0 |
| 7 | **Dashboard** | Static server-rendered views | Archer has interactive dashboards with drill-down, filtering, real-time status | Rebuild dashboards with interactive charts, drill-down navigation, real-time updates | P1 |
| 8 | **Approval Workflows** | Single-stage generic approvals | Archer has delegated authorities with automatic routing to authorized individuals | Implement configurable multi-stage workflow engine with delegation and auto-routing | P1 |
| 9 | **Reports** | Template-based static reports | Archer has predefined reports with drill-down and ad-hoc capabilities | Add dynamic report builder, scheduled report delivery, and drill-down capability | P1 |
| 10 | **Notifications** | Model exists, no delivery channels | Archer has workflows, notifications integrated across all modules | Implement email, SMS, in-app push notifications with configurable triggers | P1 |
| 11 | **Loss Event Correlation** | Loss events linked to risks | Archer correlates loss events with risk and control registers for Monte Carlo | Deepen cross-module correlation: auto-link loss events to controls, KRIs, issues | P2 |
| 12 | **Scoping / Entity Management** | Basic entity hierarchy | Archer catalogs business processes and sub-processes with linked risks and controls | Enhance to full business process catalog with sub-processes and automatic risk/control linking | P2 |

### 3.2 Features We Are Missing Entirely

| # | Missing Feature | Archer Has It? | Description | Priority |
|---|----------------|----------------|-------------|----------|
| 1 | **Risk Catalog (3-Level Hierarchy)** | Yes | Granular → Intermediate → Enterprise risk statements with value roll-up | P0 |
| 2 | **Questionnaire Engine** | Yes | Custom questionnaires with question library (Archer has thousands of OOB questions) | P0 |
| 3 | **Self-Assessment Campaign Manager** | Yes | Launch, assign, track, and close RCSA/assessment campaigns | P0 |
| 4 | **Control Testing & Effectiveness Workflow** | Yes | Formal control testing with scheduling, evidence, and effectiveness rating | P0 |
| 5 | **Top-Down + Bottom-Up Assessment Framework** | Yes | Separate workflows for 1st line (self-assessment) and 2nd line (targeted assessment) | P0 |
| 6 | **Configurable Workflow Engine** | Yes | Multi-stage approval routing with delegation and escalation rules | P1 |
| 7 | **External Loss Event Database** | Yes | Collated external loss events from industry databases | P1 |
| 8 | **Data Import/Migration Tools** | No | Bulk import from CSV/Excel/legacy systems | P1 |
| 9 | **REST API Layer** | No | API for third-party integrations and mobile apps | P1 |
| 10 | **Automated KRI Data Collection** | Yes | Automated indicator data gathering from connected systems | P2 |
| 11 | **Risk Taxonomy Management UI** | Yes | UI for managing risk categories, types, and classification hierarchies | P2 |
| 12 | **Document/Evidence Repository** | Partial | Structured evidence management linked to controls, assessments, and compliance | P2 |
| 13 | **Scenario Analysis (Stress/Sensitivity)** | Partial | Stress testing, sensitivity analysis, what-if modeling beyond Monte Carlo | P2 |
| 14 | **KI for Strategies & Objectives** | Yes | Key indicators linked to strategic objectives, not just risks | P2 |

### 3.3 Opportunities Archer Has Missed (Our Differentiators)

| # | Opportunity | Why Archer Misses It | Our Advantage | Priority |
|---|------------|---------------------|---------------|----------|
| 1 | **Nigerian Regulatory-First Design** | Archer is US/global-centric; Nigerian regs are afterthoughts | We already have CBN ORMS, NFIU, BOFIA, NDIC, NDPA built into the data model — deepen this | P0 |
| 2 | **AI-Powered Risk Intelligence** | Archer has no native AI/ML capabilities | Build real predictive analytics, emerging risk detection, regulatory change monitoring | P0 |
| 3 | **Naira-Denominated Financial Modeling** | Archer doesn't account for NGN currency dynamics, inflation, FX risk | Build NGN-native financial exposure modeling with CBN monetary policy integration | P1 |
| 4 | **African Regulatory Multi-Jurisdiction** | Archer treats Africa as one market | Support CAMA, SEC Nigeria, NAICOM, NCC alongside CBN — expandable to Ghana (BoG), Kenya (CBK), SA (SARB) | P1 |
| 5 | **Sector-Specific Risk Libraries** | Archer's OOB questions are Western-market focused | Build risk libraries for Nigerian banking, insurance, telecoms, oil & gas, fintech | P1 |
| 6 | **Mobile-First Field Risk Capture** | Archer is desktop-only enterprise software | Build mobile-responsive risk capture for field officers, branch managers | P2 |
| 7 | **WhatsApp/SMS Alert Integration** | Archer uses email-centric notifications | Integrate with WhatsApp Business API and African SMS gateways (Africa's Talking, Termii) | P2 |
| 8 | **Offline-Capable Data Collection** | Archer requires constant connectivity | PWA with offline data capture for areas with unreliable internet | P2 |
| 9 | **Natural Language Risk Entry** | No competitor offers this | Allow users to describe risks in plain English/Pidgin and auto-classify using AI | P3 |
| 10 | **Integrated Compliance Calendar** | Archer requires separate compliance module | Built-in regulatory deadline tracker with CBN, NFIU, NDPA filing dates | P1 |

---

## 4. UPGRADE PLAN

### Phase 1: Foundation Strengthening (Weeks 1-6)
*Goal: Fix structural gaps that undermine enterprise credibility*

#### 1.1 Risk Catalog — Hierarchical Risk Taxonomy (P0)
**Effort:** 2 weeks

**What to build:**
- Three-level risk hierarchy: Granular Risks → Risk Themes (Intermediate) → Enterprise Risk Statements
- Parent-child relationships with `parent_risk_id` on the `risks` table
- Automatic score roll-up: enterprise score = weighted aggregate of child risk scores
- Hierarchical tree view in UI with expand/collapse navigation
- Drill-down from enterprise statement to individual granular risks
- Risk taxonomy management UI: create, edit, reorder taxonomy nodes

**Database changes:**
- Add `parent_risk_id`, `risk_level` (granular/intermediate/enterprise), `hierarchy_path`, `roll_up_weight` to `risks` table
- Add `risk_taxonomies` table for managing classification trees

**Files to modify:**
- `Risk` model — add hierarchy relationships, roll-up calculations
- `RiskRegisterController` — add tree view, roll-up endpoints
- New views: `risk/register/tree.blade.php`, `risk/taxonomy/index.blade.php`

#### 1.2 Control Testing & Effectiveness Framework (P0)
**Effort:** 2 weeks

**What to build:**
- Control testing workflow: Plan → Schedule → Execute → Report
- Test types: design effectiveness, operating effectiveness, walkthrough
- Test scheduling with auto-reminders
- Evidence upload and linking to test results
- Effectiveness scoring: Effective, Partially Effective, Ineffective
- Control testing dashboard with overdue tests, pass/fail rates

**Database changes:**
- New `control_tests` table (control_id, test_type, tester_id, scheduled_date, completed_date, result, evidence_refs, findings, status)
- New `control_test_evidence` table (test_id, file_path, description, uploaded_by)
- Add `last_test_result`, `tests_passed_count`, `tests_failed_count` to `controls` table

**Files to create:**
- `ControlTest` model
- `ControlTestController`
- Views: `risk/controls/tests/`, `risk/controls/testing-dashboard.blade.php`

#### 1.3 Self-Assessment Campaign Engine (P0)
**Effort:** 2 weeks

**What to build:**
- Campaign lifecycle: Draft → Active → In Progress → Under Review → Closed
- Campaign types: RCSA, Fraud Risk, Compliance, Custom
- Assign campaigns to business units with designated respondents
- First-line self-assessment with second-line review/challenge workflow
- Campaign progress tracking dashboard
- Bulk assignment and follow-up reminders
- Campaign templates for reuse

**Database changes:**
- New `assessment_campaigns` table (title, type, status, start_date, end_date, created_by, template_id)
- New `campaign_assignments` table (campaign_id, business_unit_id, respondent_id, reviewer_id, status, due_date)
- New `campaign_responses` table (assignment_id, questionnaire_data JSON, submitted_at, reviewed_at, reviewer_notes)

**Files to create:**
- `AssessmentCampaign`, `CampaignAssignment`, `CampaignResponse` models
- `CampaignController`
- Views: `risk/campaigns/` (dashboard, create, manage, respond, review)

### Phase 2: Assessment & Questionnaire Engine (Weeks 7-10)
*Goal: Match Archer's assessment depth and exceed it*

#### 2.1 Questionnaire Engine (P0)
**Effort:** 2 weeks

**What to build:**
- Visual questionnaire builder (drag-and-drop question ordering)
- Question types: Multiple choice, Likert scale, Yes/No, Free text, Numeric, File upload, Matrix
- Question library with categories and tags (seed with 500+ risk-relevant questions)
- Conditional logic (show/hide questions based on answers)
- Questionnaire templates for common assessment types
- Versioning — track changes across questionnaire versions
- Scoring rules per question with automatic risk rating calculation

**Database changes:**
- New `questionnaires` table (title, description, version, status, scoring_method, created_by)
- New `questionnaire_sections` table (questionnaire_id, title, order, description)
- New `questions` table (section_id, type, text, options JSON, scoring_rules JSON, is_required, order, conditional_logic JSON)
- New `question_library` table (category, text, type, options, tags, usage_count)

#### 2.2 Project-Based Risk Assessment (P0)
**Effort:** 2 weeks

**What to build:**
- Assessment projects: New Product, M&A, Fraud, Process Change, Regulatory Change, Custom
- Project lifecycle: Initiation → Assessment → Review → Approval → Closed
- Link questionnaires to projects
- Multiple assessors per project with consolidated scoring
- Project risk register (auto-populate main risk register on approval)
- Assessment comparison across projects
- Treatment plan generation from assessment findings

**Database changes:**
- New `assessment_projects` table (title, type, description, status, business_unit_id, lead_assessor_id, start_date, target_completion)
- New `project_assessors` table (project_id, user_id, role, status)
- New `project_assessments` table (project_id, questionnaire_id, assessor_id, responses JSON, scores JSON, status)

### Phase 3: Workflow & Integration Engine (Weeks 11-14)
*Goal: Enterprise-grade workflow automation and notification*

#### 3.1 Configurable Workflow Engine (P1)
**Effort:** 2 weeks

**What to build:**
- Visual workflow designer (or config-based to start)
- Multi-stage approval chains with parallel and sequential steps
- Delegation rules (auto-delegate if approver unavailable for X days)
- Escalation on SLA breach
- Workflow templates for common scenarios: Risk Approval, Loss Event Approval, Treatment Approval, Assessment Sign-off
- Audit trail of all workflow actions
- Apply workflows to any entity type

**Database changes:**
- New `workflow_definitions` table (name, entity_type, stages JSON, escalation_rules JSON, is_active)
- New `workflow_instances` table (definition_id, entity_type, entity_id, current_stage, status, started_at)
- New `workflow_actions` table (instance_id, stage, actor_id, action, comments, acted_at)
- Refactor existing `approval_requests` to use workflow engine

#### 3.2 Notification Engine (P1)
**Effort:** 1 week

**What to build:**
- Multi-channel delivery: Email, SMS (Termii/Africa's Talking), In-App, WhatsApp Business API
- Notification preferences per user (channel, frequency, quiet hours)
- Notification templates with variable substitution
- Digest mode (daily/weekly summary instead of individual notifications)
- Critical alerts bypass quiet hours
- Notification center in UI with read/unread tracking

**Database changes:**
- Enhance `notification_logs` with channel, delivery_status, read_at
- New `notification_preferences` table (user_id, channel, event_type, enabled, digest_mode)
- New `notification_templates` table (event_type, channel, subject_template, body_template)

#### 3.3 REST API Layer (P1)
**Effort:** 1 week

**What to build:**
- API routes mirroring all web routes with token authentication (Laravel Sanctum)
- API versioning (v1)
- Rate limiting
- API documentation (auto-generated from routes)
- Webhook support for key events (risk created, assessment completed, KRI breach, etc.)

### Phase 4: Intelligence & Analytics (Weeks 15-18)
*Goal: Leap beyond Archer with AI-powered risk intelligence*

#### 4.1 Real AI Risk Intelligence (P0)
**Effort:** 2 weeks

**What to build:**
- **Predictive Risk Scoring:** ML model trained on historical assessments, loss events, and KRI data to predict risk trajectory
- **Emerging Risk Radar:** NLP analysis of news feeds, regulatory publications, industry reports to detect emerging risks relevant to the organization's sector
- **Regulatory Change Monitor:** Automated monitoring of CBN circulars, NFIU directives, SEC bulletins, NDPA updates — flag relevant changes and link to affected risks
- **Anomaly Detection:** Statistical anomaly detection on KRI measurements and loss event patterns
- **Risk Correlation Discovery:** AI-driven identification of previously unknown risk correlations
- **Natural Language Risk Entry:** Users describe risks in plain text; AI auto-classifies category, assigns initial rating, suggests controls

**Integration approach:**
- Use Claude API / OpenAI API for NLP tasks (regulatory monitoring, risk classification)
- Use statistical models (Prophet, scikit-learn via Python microservice) for time-series prediction
- RSS/API feeds for news and regulatory monitoring
- Build as separate microservice that the Laravel app calls via internal API

#### 4.2 Interactive Analytics Dashboard (P1)
**Effort:** 2 weeks

**What to build:**
- Replace static Blade dashboards with interactive JavaScript charts (Chart.js / ApexCharts / D3.js)
- Drill-down navigation: click a heatmap cell → see underlying risks → click risk → see details
- Real-time KRI monitoring with WebSocket updates
- Custom dashboard builder: users drag widgets to create personal dashboards
- Risk trend sparklines, velocity indicators, direction-of-travel arrows
- Executive view vs Operational view toggle
- PDF export of any dashboard view

#### 4.3 Advanced Quantification (P2)
**Effort:** 1 week

**What to build:**
- Stress testing scenarios with configurable stress parameters
- Sensitivity analysis (tornado diagrams)
- What-if modeling: change a control effectiveness → see impact on residual risk
- Reverse stress testing: what conditions would cause a specified loss level
- Capital allocation optimization

### Phase 5: African Enterprise Differentiators (Weeks 19-22)
*Goal: Features no competitor offers for the African market*

#### 5.1 Nigerian Regulatory Compliance Engine (P0)
**Effort:** 2 weeks

**What to build:**
- **Regulatory Calendar:** Auto-populated calendar with CBN, NFIU, SEC, NAICOM, NDPA filing deadlines
- **CBN Circular Tracker:** Log all CBN circulars, link to affected risks and controls, track compliance status
- **Automated Regulatory Reports:** One-click generation of CBN ORMS reports, NFIU STR/CTR, NDPA DPIA
- **Regulatory Examination Prep:** Pre-examination checklist generator based on known CBN examination areas
- **Multi-Regulator Dashboard:** Single view of compliance status across all Nigerian regulators

**Database changes:**
- New `regulatory_deadlines` table (regulator, report_type, deadline_date, frequency, status, responsible_id)
- New `regulatory_circulars` table (regulator, circular_ref, title, date_issued, summary, affected_risk_ids, compliance_status)
- New `regulatory_filings` table (deadline_id, filing_date, filed_by, status, document_ref)

#### 5.2 Sector-Specific Risk Libraries (P1)
**Effort:** 1 week

**What to build:**
- Pre-built risk libraries for Nigerian sectors:
  - **Banking:** Credit concentration, FX risk, CBN policy risk, POS/ATM fraud, mobile money risks
  - **Insurance:** Underwriting risk, claims fraud, NAICOM compliance, reinsurance risk
  - **Fintech:** Regulatory sandbox risks, agent network risks, settlement risks, data privacy
  - **Oil & Gas:** HSE risks, community relations, JV risks, crude theft, pipeline vandalism
  - **Telecoms:** Spectrum risk, tower infrastructure, SIM registration compliance, NCC penalties
- Each library includes: pre-defined risks, suggested controls, KRI templates, assessment questionnaires
- Selectable during organization setup

#### 5.3 Mobile & Offline Capability (P2)
**Effort:** 2 weeks

**What to build:**
- Progressive Web App (PWA) with service worker for offline capability
- Mobile-optimized risk capture form
- Offline data sync when connectivity restored
- Mobile KRI data entry for field officers
- Push notifications via mobile
- QR code scanning for asset/process identification during field assessments

#### 5.4 Communication Integrations (P2)
**Effort:** 1 week

**What to build:**
- WhatsApp Business API integration for alerts and approvals
- SMS alerts via Termii or Africa's Talking
- Microsoft Teams / Slack integration for risk notifications
- Email integration with Nigerian enterprise email providers

### Phase 6: Platform Maturity (Weeks 23-26)
*Goal: Enterprise-grade reliability and usability*

#### 6.1 Data Import/Migration Tools (P1)
**Effort:** 1 week

**What to build:**
- Bulk CSV/Excel import for: risks, controls, loss events, issues, KRIs
- Import mapping wizard (map source columns to system fields)
- Validation and error reporting before import commit
- Duplicate detection
- Historical data migration support

#### 6.2 Document & Evidence Management (P2)
**Effort:** 1 week

**What to build:**
- Structured evidence repository linked to controls, tests, assessments
- Document versioning
- Document categorization and tagging
- Full-text search across documents
- Retention policies with auto-archive

#### 6.3 Risk Taxonomy Manager (P2)
**Effort:** 1 week

**What to build:**
- UI for managing risk categories, types, and classification hierarchies
- Drag-and-drop taxonomy tree editor
- Import/export taxonomy definitions
- Map to standard frameworks (Basel, COSO, ISO 31000)
- Version control on taxonomy changes

#### 6.4 External Loss Event Database (P1)
**Effort:** 1 week

**What to build:**
- Integration with ORX (Operational Riskdata eXchange) or similar
- Curated Nigerian loss event database (from public sources: EFCC, NDIC reports)
- Industry benchmarking based on external loss data
- Scenario generation from external events

---

## 5. SOLUTION ARCHITECTURE & DATA FLOW

### 5.1 End-to-End Data Flow

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                        UNIFIED RISK INTELLIGENCE LAYER                       │
│  ┌──────────┐  ┌──────────┐  ┌───────────┐  ┌──────────┐  ┌─────────────┐ │
│  │Predictive│  │ Emerging │  │ Regulatory│  │ Anomaly  │  │ Correlation │ │
│  │ Scoring  │  │Risk Radar│  │  Monitor  │  │Detection │  │  Discovery  │ │
│  └────┬─────┘  └────┬─────┘  └─────┬─────┘  └────┬─────┘  └──────┬──────┘ │
│       └──────────────┴──────────────┴──────────────┴───────────────┘        │
└───────────────────────────────────┬─────────────────────────────────────────┘
                                    │ Feeds insights to all modules
                                    ▼
┌─────────────────────── DATA COLLECTION LAYER ───────────────────────────────┐
│                                                                              │
│  ┌──────────────┐    ┌───────────────┐    ┌──────────────┐                  │
│  │   Scoping    │───▶│ Risk Catalog  │───▶│  Assessment  │                  │
│  │  (Entities,  │    │  (3-Level     │    │  (Campaigns, │                  │
│  │  Processes)  │    │   Hierarchy)  │    │  Projects,   │                  │
│  └──────┬───────┘    └───────┬───────┘    │  RCSA, Q&A)  │                  │
│         │                    │             └──────┬───────┘                  │
│         │  Org structure     │  Risk universe     │  Assessment results     │
│         ▼                    ▼                    ▼                          │
│  ┌──────────────────────────────────────────────────────┐                   │
│  │              RISK REGISTER (Central Hub)              │                   │
│  │  Inherent Risk │ Controls │ Residual Risk │ Target   │                   │
│  └──────┬────────────────┬───────────────┬──────────────┘                   │
│         │                │               │                                   │
└─────────┼────────────────┼───────────────┼──────────────────────────────────┘
          │                │               │
          ▼                ▼               ▼
┌─────── MONITORING & RESPONSE LAYER ─────────────────────────────────────────┐
│                                                                              │
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐  ┌────────────────┐  │
│  │   Control    │  │    KRI       │  │ Loss Event   │  │   Issues &     │  │
│  │   Library    │  │  Monitoring  │  │  Management  │  │   Findings     │  │
│  │  + Testing   │  │  + Automated │  │  + RCA       │  │  + Escalation  │  │
│  │  + Evidence  │  │    Collection│  │  + External  │  │  + Remediation │  │
│  └──────┬───────┘  └──────┬───────┘  └──────┬───────┘  └───────┬────────┘  │
│         │                 │                  │                   │           │
│         │  Effectiveness  │  Breach alerts   │  Loss data       │  Actions  │
│         ▼                 ▼                  ▼                   ▼           │
│  ┌──────────────────────────────────────────────────────────────────────┐   │
│  │              TREATMENT & ACTION MANAGEMENT                           │   │
│  │  Treatment Plans │ Remediation Actions │ Workflow Engine │ Approvals │   │
│  └──────────────────────────────────────────────────────────────────────┘   │
│                                                                              │
└──────────────────────────────────┬──────────────────────────────────────────┘
                                   │
                                   ▼
┌─────────── ANALYSIS & REPORTING LAYER ──────────────────────────────────────┐
│                                                                              │
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐  ┌────────────────┐  │
│  │   Risk       │  │Quantification│  │  Regulatory   │  │   Executive    │  │
│  │  Analysis    │  │  Engine      │  │  Compliance   │  │   Reporting    │  │
│  │  Heatmap     │  │  Monte Carlo │  │  Calendar     │  │   Board Pack   │  │
│  │  Bowtie      │  │  Stress Test │  │  CBN/NFIU     │  │   Custom       │  │
│  │  Trends      │  │  VaR/ES      │  │  Auto-Filing  │  │   Dashboards   │  │
│  │  Correlation │  │  ICAAP       │  │  Multi-Reg    │  │   Ad-hoc       │  │
│  └──────────────┘  └──────────────┘  └──────────────┘  └────────────────┘  │
│                                                                              │
└──────────────────────────────────┬──────────────────────────────────────────┘
                                   │
                                   ▼
┌─────────── INTEGRATION & DELIVERY LAYER ────────────────────────────────────┐
│                                                                              │
│  ┌────────┐  ┌─────────┐  ┌────────┐  ┌──────────┐  ┌──────┐  ┌────────┐  │
│  │REST API│  │WhatsApp │  │  SMS   │  │  Email   │  │ PWA  │  │Webhook │  │
│  │  v1    │  │Business │  │Termii  │  │  SMTP    │  │Mobile│  │  Push   │  │
│  └────────┘  └─────────┘  └────────┘  └──────────┘  └──────┘  └────────┘  │
│                                                                              │
└─────────────────────────────────────────────────────────────────────────────┘
```

### 5.2 Module Interconnection Map

Every module feeds data to and pulls context from other modules. No silos.

```
SCOPING ──────────────────► RISK CATALOG ◄──────────────── ASSESSMENT CAMPAIGNS
  │ (entities, processes)       │ (risk universe)            │ (findings → risks)
  │                             │                            │
  ▼                             ▼                            ▼
RISK REGISTER ◄────────────── CONTROLS ──────────────────► CONTROL TESTING
  │ (linked controls)          │ (effectiveness)             │ (test results)
  │                            │                             │
  │ ┌──────────────────────────┼─────────────────────────────┘
  │ │                          │
  ▼ ▼                          ▼
TREATMENT PLANS           KRI MONITORING ◄─────────── AUTOMATED DATA FEEDS
  │ (actions)                  │ (breaches)                │ (external systems)
  │                            │                           │
  ▼                            ▼                           │
WORKFLOW ENGINE ◄──────── LOSS EVENTS ────────────────► ISSUES & FINDINGS
  │ (approvals)            │ (RCA → issues)               │ (remediation)
  │                        │                              │
  └────────────┬───────────┴──────────────────────────────┘
               │
               ▼
  ┌─────────────────────────────┐
  │   QUANTIFICATION ENGINE     │ ◄── Loss data, KRI data, assessment scores
  │   Monte Carlo + Stress Test │
  │   VaR / ES / ICAAP          │
  └──────────────┬──────────────┘
                 │
                 ▼
  ┌─────────────────────────────┐
  │   AI INTELLIGENCE LAYER     │ ◄── All module data feeds AI models
  │   Predictions + Radar +     │
  │   Regulatory Monitor        │
  └──────────────┬──────────────┘
                 │
                 ▼
  ┌─────────────────────────────┐
  │  REPORTING & DASHBOARDS     │ ◄── Aggregated views from all modules
  │  Executive │ Board │ Reg    │
  │  Custom │ Scheduled │ API   │
  └─────────────────────────────┘
```

### 5.3 Key Data Flow Scenarios

#### Scenario 1: Risk Identification → Assessment → Treatment → Monitoring
```
1. SCOPING: Business unit mapped with processes
2. RISK CATALOG: New granular risk identified, linked to process
3. ASSESSMENT: RCSA campaign launched → respondents assess risk
4. RISK REGISTER: Inherent score calculated from assessments
5. CONTROLS: Existing controls linked, effectiveness tested
6. RISK REGISTER: Residual score calculated (inherent - control effect)
7. APPETITE: Residual score compared to appetite → breach detected
8. TREATMENT: Treatment plan created with milestones
9. WORKFLOW: Treatment plan routed for approval
10. KRI: Key indicator assigned to monitor treatment effectiveness
11. DASHBOARD: Risk appears on executive heatmap with treatment status
12. AI: Predictive model updates risk trajectory forecast
```

#### Scenario 2: Loss Event → RCA → Control Enhancement → Risk Update
```
1. LOSS EVENT: Event captured with Basel classification
2. LOSS EVENT: CBN ORMS / NFIU reporting triggers evaluated
3. RCA: Root cause analysis performed → identifies control gaps
4. ISSUES: Issue created from RCA findings
5. CONTROLS: Control enhancement identified, new control created
6. CONTROL TESTING: New control added to test schedule
7. RISK REGISTER: Related risk residual score recalculated
8. QUANTIFICATION: Loss data feeds Monte Carlo model update
9. KRI: Relevant KRI thresholds reviewed based on loss
10. AI: Anomaly detection flags if event pattern is emerging
11. REPORTING: Loss event appears in regulatory reports
12. EXTERNAL DB: Event compared against industry loss benchmarks
```

#### Scenario 3: KRI Breach → Escalation → Action → Resolution
```
1. KRI MONITORING: Automated data collection detects threshold breach
2. NOTIFICATION: Alert sent via WhatsApp/SMS/Email to risk owner
3. RISK REGISTER: Linked risk flagged for attention
4. WORKFLOW: Escalation workflow triggered based on breach severity
5. ISSUES: Issue auto-created if breach persists beyond tolerance period
6. TREATMENT: Emergency treatment plan created
7. DASHBOARD: Executive dashboard shows breach with trend
8. AI: Predictive model assesses if breach indicates systemic issue
9. REPORTING: Breach included in next board risk report
10. KRI: Post-resolution, thresholds reviewed and adjusted if needed
```

### 5.4 Integration Architecture

```
┌────────────────────────────────────────────────────┐
│                 GRC RISK PLATFORM                    │
│                                                      │
│  ┌──────────────────────────────────────────────┐   │
│  │           Laravel Application Core            │   │
│  │  Routes │ Controllers │ Services │ Models     │   │
│  └─────────────────────┬────────────────────────┘   │
│                        │                             │
│  ┌─────────────────────┼────────────────────────┐   │
│  │          Internal Service Bus (Events)         │   │
│  │  Domain Events │ Listeners │ Queue Workers    │   │
│  └─────────────────────┬────────────────────────┘   │
│                        │                             │
│  ┌─────────────────────┼────────────────────────┐   │
│  │              API Gateway (Sanctum)             │   │
│  │  REST API v1 │ Webhooks │ Rate Limiting       │   │
│  └─────────────────────┬────────────────────────┘   │
│                        │                             │
└────────────────────────┼────────────────────────────┘
                         │
          ┌──────────────┼──────────────┐
          │              │              │
          ▼              ▼              ▼
┌──────────────┐ ┌────────────┐ ┌──────────────┐
│  AI/ML       │ │  External  │ │Communication │
│  Microservice│ │  Data      │ │  Channels    │
│  (Python)    │ │  Sources   │ │              │
│  - Predict   │ │  - ORX     │ │  - SMTP      │
│  - NLP       │ │  - News    │ │  - WhatsApp  │
│  - Anomaly   │ │  - CBN     │ │  - SMS       │
│  - Classify  │ │  - NFIU    │ │  - Teams     │
└──────────────┘ └────────────┘ └──────────────┘
```

---

## 6. DIFFERENTIATION STRATEGY FOR AFRICAN ENTERPRISE MARKET

### 6.1 Why We Will Surpass Archer in Africa

| Dimension | Archer | Our Platform |
|-----------|--------|-------------|
| **Regulatory Focus** | US/EU-centric, African regs as customization | Nigerian regulatory requirements built into the data model from day one |
| **Deployment** | Heavy enterprise on-prem or SaaS | Cloud-native, mobile-responsive, offline-capable |
| **AI/Intelligence** | None — purely manual workflows | AI-powered predictive analytics, emerging risk radar, regulatory monitoring |
| **Cost** | $250K-$1M+ license + implementation | Affordable SaaS pricing for African enterprise budgets |
| **Implementation** | 6-18 month implementation cycles | Pre-configured for Nigerian sectors, weeks not months |
| **Communication** | Email-only notifications | WhatsApp, SMS, Email — channels Africans actually use |
| **Connectivity** | Requires constant high-speed internet | PWA with offline capability for unreliable network areas |
| **Risk Libraries** | Western-market risk taxonomies | Pre-built libraries for Nigerian banking, fintech, oil & gas, telecoms |
| **Language** | English only | English with ability to add Pidgin, Hausa, Yoruba, Igbo for field capture |
| **Support** | US/India timezone support | Local Nigerian support team, same timezone |

### 6.2 Unique Value Propositions

1. **"Compliance-Ready Out of the Box"** — No other platform ships pre-configured for CBN ORMS, NFIU STR/CTR, BOFIA, NDIC, NDPA, SEC, NAICOM, and CAMA compliance requirements.

2. **"AI That Understands Nigerian Risk"** — Predictive models trained on Nigerian market dynamics, regulatory patterns, and sector-specific risk profiles. Not a Western model adapted for Africa.

3. **"Risk Management That Works Without Fiber"** — PWA with offline data capture means field officers in rural branches can conduct risk assessments without internet, syncing when connected.

4. **"From Branch to Board in One Platform"** — WhatsApp alerts for field staff, mobile risk capture for branch managers, interactive dashboards for CROs, automated regulatory reports for compliance officers. One platform, every stakeholder.

5. **"The Only Risk Platform That Speaks Your Regulator's Language"** — Auto-generated CBN examination-ready reports, NFIU filing-ready STR/CTR documents, NDPA-compliant DPIA templates.

### 6.3 Market Expansion Path

```
Phase 1 (Now):     Nigeria — Banking, Insurance, Fintech
Phase 2 (6 months): Nigeria — Telecoms, Oil & Gas, Public Sector
Phase 3 (12 months): West Africa — Ghana (BoG), Senegal (BCEAO)
Phase 4 (18 months): East Africa — Kenya (CBK), Tanzania (BoT), Rwanda (BNR)
Phase 5 (24 months): Southern Africa — South Africa (SARB/PA), Botswana
Phase 6 (36 months): Pan-African — AfCFTA compliance, continental risk benchmarking
```

---

## APPENDIX A: COMPLETE FEATURE COMPARISON MATRIX

| Feature | Archer | Our Current | Our Upgraded | Advantage |
|---------|--------|-------------|-------------|-----------|
| Risk Register | Yes | Yes | Yes (enhanced) | Parity+ |
| 3-Level Risk Hierarchy | Yes | No | Yes | Parity |
| Risk-to-Process Mapping | Yes | Partial | Yes | Parity |
| Inherent/Residual Assessment | Yes | Yes | Yes | Parity |
| Monetary + Qualitative Assessment | Yes | Partial | Yes | Parity |
| Risk Appetite Monitoring | Yes | Basic | Enhanced | Exceeds |
| Scenario Library | Yes | Yes | Yes (sector-specific) | Exceeds |
| Control Library | Yes | Yes | Yes | Parity |
| Control Testing Workflow | Yes | No | Yes | Parity |
| Control Evidence Management | Partial | No | Yes | Exceeds |
| RCSA Campaign Management | Yes | No | Yes | Parity |
| Questionnaire Engine | Yes (extensive) | No | Yes | Parity |
| Question Library | Yes (thousands) | No | Yes (500+ seeded) | Approaching |
| Project-Based Assessments | Yes | No | Yes | Parity |
| Top-Down + Bottom-Up Assessments | Yes | Partial | Yes | Parity |
| Loss Event Management | Yes | Yes | Yes (enhanced) | Exceeds |
| External Loss Events | Yes | No | Yes | Parity |
| Root Cause Analysis | Yes | Yes | Yes | Parity |
| Near Miss Tracking | Yes | Yes | Yes | Parity |
| Monte Carlo Simulation | Via export (Palisade) | Built-in | Built-in (enhanced) | **Exceeds** |
| KRI Monitoring | Yes | Yes | Yes (enhanced) | Exceeds |
| Automated KRI Collection | Partial | No | Yes | **Exceeds** |
| KI for Strategies/Objectives | Yes | No | Yes | Parity |
| Issues Management | Yes | Yes | Yes | Parity |
| Configurable Workflows | Yes | Basic | Yes (full engine) | Parity |
| Delegated Authorities | Yes | No | Yes | Parity |
| Predefined Reports | Yes | Yes | Yes (enhanced) | Parity |
| Custom Report Builder | Yes | No | Yes | Parity |
| Interactive Dashboards | Yes | No | Yes | Parity |
| API Integrations | Yes | No | Yes (REST + Webhooks) | Parity |
| Nigerian Regulatory (CBN/NFIU) | No | Yes | Yes (deep) | **Pioneer** |
| AI Predictive Analytics | No | Stub | Yes (real) | **Pioneer** |
| Emerging Risk Radar | No | Stub | Yes | **Pioneer** |
| WhatsApp/SMS Alerts | No | No | Yes | **Pioneer** |
| Offline/PWA Capability | No | No | Yes | **Pioneer** |
| Sector-Specific Risk Libraries | Partial (Western) | No | Yes (Nigerian) | **Pioneer** |
| Regulatory Calendar | No | No | Yes | **Pioneer** |
| Mobile Risk Capture | No | No | Yes | **Pioneer** |
| ICAAP Built-in | No | Yes | Yes (enhanced) | **Exceeds** |
| Stress Testing | External | Partial | Yes (built-in) | **Exceeds** |
| Natural Language Risk Entry | No | No | Yes | **Pioneer** |
| Multi-African Jurisdiction | No | No | Yes (roadmap) | **Pioneer** |

**Score Summary:**
- Features at Parity with Archer: 26
- Features Exceeding Archer: 7
- Features Pioneering (Archer doesn't have): 10
- **Total upgraded feature count: 43**

---

## APPENDIX B: IMPLEMENTATION TIMELINE SUMMARY

| Phase | Weeks | Focus | Key Deliverables |
|-------|-------|-------|-----------------|
| **Phase 1** | 1-6 | Foundation | Risk Catalog hierarchy, Control Testing, Campaign Engine |
| **Phase 2** | 7-10 | Assessments | Questionnaire Engine, Project-Based Assessments |
| **Phase 3** | 11-14 | Workflows | Workflow Engine, Notification Engine, REST API |
| **Phase 4** | 15-18 | Intelligence | AI Risk Intelligence, Interactive Dashboards, Advanced Quantification |
| **Phase 5** | 19-22 | Differentiation | Nigerian Regulatory Engine, Sector Libraries, Mobile/PWA, WhatsApp/SMS |
| **Phase 6** | 23-26 | Maturity | Data Import, Evidence Management, Taxonomy Manager, External Loss DB |

**Total estimated timeline: 26 weeks (6.5 months)**

---

*This document serves as the engineering and product brief for upgrading GRC Risk Management into the premier Enterprise & Operational Risk Management platform for the African enterprise market.*
