# Nigerian Financial Services GRC Platform
## Technical Specification: Gap Module Integration
### Loss Event Management · Issues & Findings Tracker · Risk Quantification Engine

---

> **Document Version:** 1.0.0  
> **Date:** February 2026  
> **Scope:** API Endpoints, Database Schemas, Nigerian Regulatory Alignment, Integration Architecture, Implementation Guide  
> **Regulatory Frameworks:** CBN Risk-Based Supervision Framework · BOFIA 2020 · NDPA 2023 · AML/CFT (Prevention of Money Laundering) Act 2022 · NFIU Regulations · NDIC Act 2023 · SEC Nigeria Rules · CBN ORMS Guidelines · Basel III/IV  
> **Currency:** All monetary values in Nigerian Naira (NGN / ₦) unless explicitly stated

---

## Table of Contents

1. [Architecture Overview](#1-architecture-overview)
2. [Global Standards & Conventions](#2-global-standards--conventions)
3. [Gap 1: Loss Event Management](#3-gap-1-loss-event-management)
   - 3.1 Database Schema
   - 3.2 API Endpoints
   - 3.3 Nigerian Regulatory Fields Reference
4. [Gap 2: Issues & Findings Tracker](#4-gap-2-issues--findings-tracker)
   - 4.1 Database Schema
   - 4.2 API Endpoints
   - 4.3 Nigerian Regulatory Fields Reference
5. [Gap 3: Risk Quantification Engine](#5-gap-3-risk-quantification-engine)
   - 5.1 Database Schema
   - 5.2 API Endpoints
   - 5.3 Nigerian Regulatory Fields Reference
6. [Cross-Module Integration](#6-cross-module-integration)
7. [Regulatory Reporting Endpoints](#7-regulatory-reporting-endpoints)
8. [Technical Requirements](#8-technical-requirements)
9. [Security & Compliance](#9-security--compliance)
10. [Implementation Guide](#10-implementation-guide)

---

## 1. Architecture Overview

```
┌──────────────────────────────────────────────────────────────────────────────┐
│                   NIGERIAN GRC PLATFORM — MODULE INTEGRATION                  │
│                                                                                │
│  ┌─────────────────┐   ┌─────────────────┐   ┌─────────────────────────────┐ │
│  │  Risk Register   │◄──│  Loss Event Mgmt│──►│   Issues & Findings Tracker │ │
│  │  (Existing)      │   │  (GAP 1)        │   │   (GAP 2)                   │ │
│  └────────┬─────────┘   └────────┬────────┘   └────────────┬────────────────┘ │
│           │                      │                           │                  │
│           │              ┌───────▼───────┐                  │                  │
│           └─────────────►│  Risk Quant.  │◄─────────────────┘                  │
│                          │  Engine       │                                      │
│                          │  (GAP 3)      │                                      │
│                          └───────┬───────┘                                      │
│                                  │                                               │
│          ┌───────────────────────▼────────────────────────┐                    │
│          │           REGULATORY REPORTING LAYER           │                    │
│          │  CBN ORMS · NFIU STR/CTR · NDIC · SEC Nigeria  │                    │
│          └────────────────────────────────────────────────┘                    │
└──────────────────────────────────────────────────────────────────────────────┘

Base URL (Production):  https://api.grc-platform.ng/v1
Base URL (Staging):     https://staging-api.grc-platform.ng/v1
Auth:                   Bearer JWT (OAuth 2.0) — see Section 9
```

### Module Prefixes

| Module | Prefix | Version |
|--------|--------|---------|
| Risk Register (existing) | `/risk-register` | v1 |
| **Loss Event Management (Gap 1)** | `/loss-events` | v1 |
| **Issues & Findings Tracker (Gap 2)** | `/issues` | v1 |
| **Risk Quantification Engine (Gap 3)** | `/quantification` | v1 |
| Audit Management (existing) | `/audit` | v1 |
| Regulatory Reporting | `/regulatory` | v1 |
| Users & Permissions | `/users` | v1 |

---

## 2. Global Standards & Conventions

### 2.1 Request/Response Format

All API requests and responses use `application/json`. All timestamps are ISO 8601 in UTC (`2025-01-15T09:30:00Z`). Nigerian date display format for UI: `DD/MM/YYYY`.

### 2.2 Currency Handling

```json
{
  "amount": {
    "value": 4250000.00,
    "currency": "NGN",
    "formatted": "₦4,250,000.00",
    "in_millions": 4.25
  }
}
```

> **Rule:** All monetary storage is in kobo (integer) to avoid floating-point errors.  
> `value_kobo: 425000000` = ₦4,250,000.00  
> The API serialiser converts to Naira on output.

### 2.3 Nigerian Institutional Identifiers

```json
{
  "institution": {
    "cbn_institution_code": "NGN/COM/0089",
    "ndic_member_number": "NDIC-COM-0089-2024",
    "sec_registration": "RC-SEC-2019-00134",
    "nfiu_reporting_entity_id": "NFIU-RE-0089",
    "rc_number": "RC 123456",
    "tin": "12345678-0001"
  }
}
```

### 2.4 Pagination

```json
{
  "data": [...],
  "pagination": {
    "page": 1,
    "per_page": 25,
    "total": 89,
    "total_pages": 4,
    "has_next": true,
    "has_prev": false
  }
}
```

### 2.5 Standard Error Format

```json
{
  "error": {
    "code": "REGULATORY_THRESHOLD_BREACH",
    "message": "Loss event amount ₦18,750,000 exceeds CBN mandatory reporting threshold of ₦5,000,000. Automatic escalation initiated.",
    "regulatory_reference": "CBN BSD/DIR/GEN/LAB/07/014",
    "field": "gross_loss_amount_kobo",
    "severity": "CRITICAL",
    "action_required": "Submit CBN notification within 7 days"
  }
}
```

### 2.6 Audit Trail Header (all mutating requests)

Every POST/PUT/PATCH must include:

```http
X-User-ID: usr_chukwuemeka_obi_001
X-Institution-Code: NGN/COM/0089
X-Client-IP: 196.46.3.12
X-Session-ID: sess_20250118143200_abc123
X-Request-ID: req_20250118_143200_xyz
```

---

## 3. Gap 1: Loss Event Management

### 3.1 Database Schema

#### Table: `loss_events`

```sql
CREATE TABLE loss_events (
  -- Primary Identification
  id                          UUID            PRIMARY KEY DEFAULT gen_random_uuid(),
  event_reference             VARCHAR(20)     NOT NULL UNIQUE, -- LEM-2025-0047
  institution_id              UUID            NOT NULL REFERENCES institutions(id),
  cbn_institution_code        VARCHAR(20)     NOT NULL, -- NGN/COM/0089

  -- Event Details
  title                       VARCHAR(500)    NOT NULL,
  description                 TEXT            NOT NULL,
  initial_root_cause          TEXT,
  date_of_loss                DATE            NOT NULL,
  date_discovered             DATE            NOT NULL,
  date_reported               DATE            NOT NULL DEFAULT CURRENT_DATE,
  time_of_loss                TIME,
  discovery_lag_hours         INTEGER         GENERATED ALWAYS AS
                                (EXTRACT(EPOCH FROM (date_discovered::timestamp -
                                  date_of_loss::timestamp))/3600) STORED,

  -- Organisational Context
  business_unit_id            UUID            NOT NULL REFERENCES business_units(id),
  department                  VARCHAR(200),
  branch_id                   UUID            REFERENCES branches(id),
  branch_name                 VARCHAR(200),
  responsible_officer_id      UUID            REFERENCES users(id),
  responsible_officer_name    VARCHAR(200),

  -- Basel III Classification (BCBS 196)
  basel_l1_category           VARCHAR(50)     NOT NULL CHECK (basel_l1_category IN (
                                'INTERNAL_FRAUD',
                                'EXTERNAL_FRAUD',
                                'EMPLOYMENT_PRACTICES_WORKPLACE_SAFETY',
                                'CLIENTS_PRODUCTS_BUSINESS_PRACTICES',
                                'DAMAGE_TO_PHYSICAL_ASSETS',
                                'BUSINESS_DISRUPTION_SYSTEMS_FAILURES',
                                'EXECUTION_DELIVERY_PROCESS_MANAGEMENT'
                              )),
  basel_l2_category           VARCHAR(100)    NOT NULL,
  basel_l3_detail             VARCHAR(200),

  -- CBN ORMS Classification (CBN Operational Risk Framework 2016)
  cbn_risk_category           VARCHAR(100)    NOT NULL CHECK (cbn_risk_category IN (
                                'TECHNOLOGY_RISK',
                                'CREDIT_RISK',
                                'MARKET_RISK',
                                'LIQUIDITY_RISK',
                                'STRATEGIC_RISK',
                                'COMPLIANCE_RISK',
                                'REPUTATIONAL_RISK',
                                'LEGAL_RISK',
                                'PEOPLE_RISK',
                                'PROCESS_RISK'
                              )),
  cbn_orms_event_type         VARCHAR(100),   -- Maps to CBN ORMS BSD template field 3.1
  cbn_product_line            VARCHAR(100),   -- CBN ORMS field 3.2

  -- Financial Impact (stored in kobo to avoid float errors)
  gross_loss_amount_kobo      BIGINT          NOT NULL DEFAULT 0,
  net_loss_amount_kobo        BIGINT          GENERATED ALWAYS AS
                                (gross_loss_amount_kobo - COALESCE(insurance_recovery_kobo, 0)
                                  - COALESCE(other_recovery_kobo, 0)) STORED,
  insurance_recovery_kobo     BIGINT          DEFAULT 0,
  other_recovery_kobo         BIGINT          DEFAULT 0,
  pending_recovery_kobo       BIGINT          DEFAULT 0,
  actual_recovery_kobo        BIGINT          DEFAULT 0,
  provision_amount_kobo       BIGINT,
  cost_centre                 VARCHAR(100),
  gl_account_code             VARCHAR(50),
  loss_category               VARCHAR(30)     NOT NULL CHECK (loss_category IN (
                                'DIRECT_LOSS', 'CONTINGENT_LOSS', 'NEAR_MISS'
                              )),

  -- Insurance Details
  insurance_covered           BOOLEAN         DEFAULT FALSE,
  insurance_policy_ref        VARCHAR(200),
  insured_amount_kobo         BIGINT,
  insurance_provider          VARCHAR(200),
  insurance_claim_status      VARCHAR(50)     CHECK (insurance_claim_status IN (
                                'NOT_FILED', 'FILED', 'UNDER_REVIEW',
                                'APPROVED', 'REJECTED', 'PARTIALLY_PAID', 'FULLY_PAID'
                              )),

  -- Severity & Status
  event_severity              VARCHAR(20)     NOT NULL CHECK (event_severity IN (
                                'MINOR', 'MODERATE', 'SIGNIFICANT', 'CRITICAL'
                              )),
  current_status              VARCHAR(30)     NOT NULL DEFAULT 'NEW' CHECK (current_status IN (
                                'NEW', 'UNDER_INVESTIGATION', 'PENDING_APPROVAL',
                                'APPROVED', 'ESCALATED_TO_CBN', 'CLOSED', 'REOPENED'
                              )),

  -- Nigerian Regulatory Reporting Flags
  -- CBN (Central Bank of Nigeria)
  cbn_reportable              BOOLEAN         NOT NULL DEFAULT FALSE,
  cbn_reporting_deadline      DATE,           -- Auto-set: 7 days for fraud events per CBN BSD/DIR/GEN/LAB/07/014
  cbn_notification_sent       BOOLEAN         DEFAULT FALSE,
  cbn_notification_date       TIMESTAMP,
  cbn_notification_ref        VARCHAR(200),   -- CBN acknowledgement reference
  cbn_orms_submission_id      VARCHAR(100),   -- Reference from CBN ORMS portal
  cbn_orms_submitted          BOOLEAN         DEFAULT FALSE,
  cbn_orms_submitted_date     TIMESTAMP,
  cbn_examination_ref         VARCHAR(100),   -- If raised during CBN examination

  -- NFIU (Nigerian Financial Intelligence Unit)
  -- Per AML/CFT (Prevention of Money Laundering) Act 2022 & NFIU Regulations 2024
  nfiu_reportable             BOOLEAN         NOT NULL DEFAULT FALSE,
  nfiu_report_type            VARCHAR(10)     CHECK (nfiu_report_type IN ('STR', 'CTR', 'BOTH')),
  nfiu_str_reference          VARCHAR(100),   -- Suspicious Transaction Report ref
  nfiu_ctr_reference          VARCHAR(100),   -- Currency Transaction Report ref
  nfiu_report_filed           BOOLEAN         DEFAULT FALSE,
  nfiu_report_date            TIMESTAMP,
  nfiu_threshold_triggered    VARCHAR(30)     CHECK (nfiu_threshold_triggered IN (
                                'CASH_5M_NGN',        -- Single transaction ≥ ₦5M cash
                                'AGGREGATE_10M_NGN',  -- Aggregate daily ≥ ₦10M
                                'SUSPICIOUS_PATTERN', -- Any amount, suspicious activity
                                'PEP_RELATED',        -- Politically Exposed Person
                                'MULTIPLE'
                              )),

  -- BOFIA 2020 Reporting (Banks and Other Financial Institutions Act)
  bofia_section_applicable    VARCHAR(100),   -- e.g. 'SECTION_24_RELATED_PARTY', 'SECTION_14_CAPITAL'
  bofia_reportable            BOOLEAN         DEFAULT FALSE,
  bofia_report_ref            VARCHAR(100),

  -- NDIC (Nigeria Deposit Insurance Corporation)
  ndic_reportable             BOOLEAN         DEFAULT FALSE,
  ndic_report_ref             VARCHAR(100),
  ndic_threshold_triggered    BOOLEAN         DEFAULT FALSE, -- ₦500K deposit exposure

  -- Law Enforcement
  law_enforcement_notified    BOOLEAN         DEFAULT FALSE,
  police_report_ref           VARCHAR(200),   -- e.g. NG/LOS/2025/ATM/00441
  police_report_date          DATE,
  efcc_reported               BOOLEAN         DEFAULT FALSE,  -- Economic Financial Crimes Commission
  efcc_ref                    VARCHAR(200),
  icpc_reported               BOOLEAN         DEFAULT FALSE,  -- Independent Corrupt Practices Commission

  -- Indirect Impact Assessment
  customer_impact_rating      VARCHAR(20)     CHECK (customer_impact_rating IN (
                                'NONE', 'LOW', 'MEDIUM', 'HIGH', 'CRITICAL'
                              )),
  customers_affected_count    INTEGER,
  reputational_impact_rating  VARCHAR(20),
  regulatory_impact_rating    VARCHAR(20),
  operational_disruption_hrs  DECIMAL(10,2),
  indirect_cost_kobo          BIGINT          DEFAULT 0,

  -- Linkages to Other Modules
  risk_register_id            UUID            REFERENCES risk_register(id),
  risk_register_ref           VARCHAR(30),    -- RR-2024-089
  audit_finding_id            UUID            REFERENCES audit_findings(id),
  audit_finding_ref           VARCHAR(30),
  rcsa_id                     UUID            REFERENCES rcsa_assessments(id),

  -- Workflow & Approval
  assigned_to_id              UUID            REFERENCES users(id),
  risk_officer_id             UUID            REFERENCES users(id),
  head_of_risk_id             UUID            REFERENCES users(id),
  exco_approver_id            UUID            REFERENCES users(id),
  current_approver_id         UUID            REFERENCES users(id),
  current_approval_stage      VARCHAR(50)     CHECK (current_approval_stage IN (
                                'RISK_OFFICER_REVIEW', 'HEAD_OF_RISK_APPROVAL',
                                'EXCO_APPROVAL', 'CBN_NOTIFICATION', 'CLOSED'
                              )),
  days_open                   INTEGER         GENERATED ALWAYS AS
                                (CURRENT_DATE - date_reported) STORED,

  -- Metadata
  created_by                  UUID            NOT NULL REFERENCES users(id),
  created_at                  TIMESTAMP       NOT NULL DEFAULT NOW(),
  updated_by                  UUID            REFERENCES users(id),
  updated_at                  TIMESTAMP       NOT NULL DEFAULT NOW(),
  deleted_at                  TIMESTAMP,      -- Soft delete only — regulatory records must not be hard-deleted

  CONSTRAINT chk_discovery_after_loss CHECK (date_discovered >= date_of_loss),
  CONSTRAINT chk_report_after_discovery CHECK (date_reported >= date_discovered),
  CONSTRAINT chk_cbn_deadline CHECK (
    cbn_reportable = FALSE OR cbn_reporting_deadline IS NOT NULL
  )
);

-- Indexes
CREATE INDEX idx_loss_events_institution ON loss_events(institution_id);
CREATE INDEX idx_loss_events_status ON loss_events(current_status);
CREATE INDEX idx_loss_events_date_loss ON loss_events(date_of_loss);
CREATE INDEX idx_loss_events_basel_l1 ON loss_events(basel_l1_category);
CREATE INDEX idx_loss_events_cbn_reportable ON loss_events(cbn_reportable, cbn_notification_sent);
CREATE INDEX idx_loss_events_nfiu_reportable ON loss_events(nfiu_reportable, nfiu_report_filed);
CREATE INDEX idx_loss_events_business_unit ON loss_events(business_unit_id);
CREATE INDEX idx_loss_events_created_at ON loss_events(created_at DESC);
```

#### Table: `loss_event_controls`

```sql
CREATE TABLE loss_event_controls (
  id              UUID    PRIMARY KEY DEFAULT gen_random_uuid(),
  loss_event_id   UUID    NOT NULL REFERENCES loss_events(id) ON DELETE CASCADE,
  control_id      UUID    NOT NULL REFERENCES controls(id),
  control_ref     VARCHAR(30),   -- CTRL-445
  failure_type    VARCHAR(50)    CHECK (failure_type IN (
                    'DESIGN_FAILURE', 'OPERATING_FAILURE', 'OVERRIDE', 'NOT_IN_PLACE', 'PARTIAL_FAILURE'
                  )),
  failure_description TEXT,
  created_at      TIMESTAMP  NOT NULL DEFAULT NOW()
);
```

#### Table: `loss_event_attachments`

```sql
CREATE TABLE loss_event_attachments (
  id              UUID          PRIMARY KEY DEFAULT gen_random_uuid(),
  loss_event_id   UUID          NOT NULL REFERENCES loss_events(id) ON DELETE CASCADE,
  file_name       VARCHAR(500)  NOT NULL,
  file_size_bytes INTEGER,
  file_type       VARCHAR(50),
  storage_path    TEXT          NOT NULL,   -- S3 / object storage path
  document_type   VARCHAR(50)   CHECK (document_type IN (
                    'POLICE_REPORT', 'INSURANCE_CLAIM', 'TRANSACTION_LOG', 'CUSTOMER_COMPLAINT',
                    'SYSTEM_LOG', 'INVESTIGATION_REPORT', 'NFIU_FILING', 'CBN_NOTIFICATION',
                    'EFCC_REPORT', 'AUDIT_EVIDENCE', 'OTHER'
                  )),
  is_regulatory   BOOLEAN       DEFAULT FALSE,  -- Cannot be deleted once marked regulatory
  uploaded_by     UUID          NOT NULL REFERENCES users(id),
  created_at      TIMESTAMP     NOT NULL DEFAULT NOW()
);
```

#### Table: `loss_event_rca` (Root Cause Analysis)

```sql
CREATE TABLE loss_event_rca (
  id                  UUID    PRIMARY KEY DEFAULT gen_random_uuid(),
  loss_event_id       UUID    NOT NULL UNIQUE REFERENCES loss_events(id) ON DELETE CASCADE,
  rca_status          VARCHAR(30) DEFAULT 'IN_PROGRESS' CHECK (rca_status IN (
                        'NOT_STARTED', 'IN_PROGRESS', 'COMPLETED', 'APPROVED'
                      )),
  methodology         VARCHAR(50) DEFAULT '5_WHYS' CHECK (methodology IN (
                        '5_WHYS', 'FISHBONE', 'FAULT_TREE', 'COMBINED'
                      )),

  -- 5-Whys structured capture
  why_1_question      TEXT,
  why_1_answer        TEXT,
  why_2_question      TEXT,
  why_2_answer        TEXT,
  why_3_question      TEXT,
  why_3_answer        TEXT,
  why_4_question      TEXT,
  why_4_answer        TEXT,
  why_5_question      TEXT,
  why_5_answer        TEXT,
  root_cause_statement TEXT   NOT NULL,

  -- Contributory Factors (stored as JSONB for flexibility)
  contributory_factors JSONB  DEFAULT '[]'::jsonb,
  -- Schema: [{ "category": "PEOPLE|PROCESS|TECHNOLOGY|EXTERNAL|REGULATORY",
  --            "description": "...", "severity": "LOW|MEDIUM|HIGH",
  --            "linked_control_id": "uuid" }]

  completed_by        UUID    REFERENCES users(id),
  completed_at        TIMESTAMP,
  approved_by         UUID    REFERENCES users(id),
  approved_at         TIMESTAMP,
  created_at          TIMESTAMP NOT NULL DEFAULT NOW(),
  updated_at          TIMESTAMP NOT NULL DEFAULT NOW()
);
```

#### Table: `rca_remediation_actions`

```sql
CREATE TABLE rca_remediation_actions (
  id                  UUID    PRIMARY KEY DEFAULT gen_random_uuid(),
  rca_id              UUID    NOT NULL REFERENCES loss_event_rca(id) ON DELETE CASCADE,
  loss_event_id       UUID    NOT NULL REFERENCES loss_events(id),
  action_number       INTEGER NOT NULL,
  description         TEXT    NOT NULL,
  owner_id            UUID    REFERENCES users(id),
  owner_name          VARCHAR(200),
  department          VARCHAR(200),
  priority            VARCHAR(20) CHECK (priority IN ('CRITICAL','HIGH','MEDIUM','LOW')),
  target_date         DATE    NOT NULL,
  actual_close_date   DATE,
  status              VARCHAR(30) DEFAULT 'NOT_STARTED' CHECK (status IN (
                        'NOT_STARTED', 'IN_PROGRESS', 'COMPLETED', 'OVERDUE', 'CANCELLED'
                      )),
  completion_notes    TEXT,
  verified_by         UUID    REFERENCES users(id),
  created_at          TIMESTAMP NOT NULL DEFAULT NOW(),
  updated_at          TIMESTAMP NOT NULL DEFAULT NOW()
);
```

#### Table: `near_misses`

```sql
CREATE TABLE near_misses (
  id                        UUID    PRIMARY KEY DEFAULT gen_random_uuid(),
  reference                 VARCHAR(20) NOT NULL UNIQUE, -- NM-2025-0023
  institution_id            UUID    NOT NULL REFERENCES institutions(id),
  title                     VARCHAR(500) NOT NULL,
  description               TEXT    NOT NULL,
  date_occurred             DATE    NOT NULL,
  date_reported             DATE    NOT NULL DEFAULT CURRENT_DATE,
  business_unit_id          UUID    REFERENCES business_units(id),
  branch_id                 UUID    REFERENCES branches(id),
  potential_loss_kobo       BIGINT, -- Estimated potential loss had it occurred
  severity                  VARCHAR(20) NOT NULL CHECK (severity IN (
                              'LOW', 'MEDIUM', 'HIGH', 'CRITICAL'
                            )),
  control_gap_identified    BOOLEAN DEFAULT FALSE,
  control_gap_description   TEXT,
  linked_control_id         UUID    REFERENCES controls(id),
  status                    VARCHAR(30) DEFAULT 'OPEN' CHECK (status IN (
                              'OPEN', 'UNDER_INVESTIGATION', 'CLOSED', 'CONVERTED_TO_LOSS_EVENT'
                            )),
  converted_loss_event_id   UUID    REFERENCES loss_events(id),
  investigator_id           UUID    REFERENCES users(id),
  investigation_deadline    DATE,   -- CBN requires investigation within 5 working days
  risk_register_id          UUID    REFERENCES risk_register(id),
  reported_by               UUID    NOT NULL REFERENCES users(id),
  created_at                TIMESTAMP NOT NULL DEFAULT NOW(),
  updated_at                TIMESTAMP NOT NULL DEFAULT NOW()
);
```

#### Table: `loss_event_approval_workflow`

```sql
CREATE TABLE loss_event_approval_workflow (
  id              UUID    PRIMARY KEY DEFAULT gen_random_uuid(),
  loss_event_id   UUID    NOT NULL REFERENCES loss_events(id) ON DELETE CASCADE,
  stage           VARCHAR(50) NOT NULL CHECK (stage IN (
                    'RISK_OFFICER_REVIEW', 'HEAD_OF_RISK_APPROVAL',
                    'EXCO_APPROVAL', 'CBN_NOTIFICATION', 'CLOSED'
                  )),
  action          VARCHAR(30) NOT NULL CHECK (action IN (
                    'APPROVED', 'RETURNED_FOR_INFO', 'ESCALATED', 'CBN_NOTIFIED', 'REJECTED'
                  )),
  decision        VARCHAR(30) CHECK (decision IN (
                    'APPROVE', 'APPROVE_WITH_CONDITIONS', 'RETURN', 'ESCALATE'
                  )),
  comments        TEXT,
  conditions      TEXT,
  nfiu_str_ref    VARCHAR(100),
  cbn_notified    BOOLEAN DEFAULT FALSE,
  actioned_by     UUID    NOT NULL REFERENCES users(id),
  actioned_at     TIMESTAMP NOT NULL DEFAULT NOW(),
  days_in_stage   INTEGER
);
```

---

### 3.2 API Endpoints — Loss Event Management

#### 3.2.1 Loss Events

```
GET    /v1/loss-events
POST   /v1/loss-events
GET    /v1/loss-events/:id
PUT    /v1/loss-events/:id
PATCH  /v1/loss-events/:id/status
DELETE /v1/loss-events/:id          (soft delete — audit trail preserved)
```

##### `GET /v1/loss-events`

**Query Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `status` | string | `NEW`, `UNDER_INVESTIGATION`, `PENDING_APPROVAL`, `APPROVED`, `ESCALATED_TO_CBN`, `CLOSED` |
| `basel_l1_category` | string | Basel Level 1 category code |
| `cbn_risk_category` | string | CBN ORMS risk category |
| `business_unit_id` | UUID | Filter by business unit |
| `branch_id` | UUID | Filter by branch |
| `date_from` | date | Loss date range start (YYYY-MM-DD) |
| `date_to` | date | Loss date range end |
| `amount_min` | integer | Minimum gross loss in kobo |
| `amount_max` | integer | Maximum gross loss in kobo |
| `cbn_reportable` | boolean | Filter CBN-reportable events |
| `nfiu_reportable` | boolean | Filter NFIU-reportable events |
| `overdue_cbn` | boolean | CBN notification deadline overdue |
| `financial_year` | string | Nigerian FY e.g. `2024-2025` (Jul–Jun) |
| `page` | integer | Page number (default: 1) |
| `per_page` | integer | Results per page (default: 25, max: 100) |
| `sort` | string | `date_of_loss`, `gross_loss`, `days_open`, `created_at` |
| `order` | string | `asc`, `desc` |

**Response `200 OK`:**

```json
{
  "data": [
    {
      "id": "a1b2c3d4-e5f6-7890-abcd-ef1234567890",
      "event_reference": "LEM-2025-0047",
      "institution": {
        "id": "inst_uuid",
        "cbn_institution_code": "NGN/COM/0089",
        "name": "First City Financial Bank PLC"
      },
      "title": "Fraudulent ATM withdrawals via cloned cards — Lagos HO",
      "date_of_loss": "2025-01-14",
      "date_discovered": "2025-01-14",
      "date_reported": "2025-01-15",
      "discovery_lag_hours": 6,
      "business_unit": {
        "id": "bu_uuid",
        "name": "Retail Banking",
        "code": "RB"
      },
      "branch": {
        "id": "br_uuid",
        "name": "Lagos Head Office",
        "cbn_branch_code": "01",
        "state": "Lagos",
        "lga": "Lagos Island"
      },
      "responsible_officer": {
        "id": "user_uuid",
        "name": "Fatima Al-Hassan",
        "role": "Retail Risk Officer",
        "staff_id": "FCB/2018/0234"
      },
      "classification": {
        "basel_l1_category": "EXTERNAL_FRAUD",
        "basel_l1_label": "External Fraud",
        "basel_l2_category": "EF2 - Systems Security",
        "basel_l3_detail": "Hacking damage",
        "cbn_risk_category": "TECHNOLOGY_RISK",
        "cbn_orms_event_type": "Fraud — Electronic",
        "cbn_product_line": "Retail Banking — Cards"
      },
      "financial_impact": {
        "gross_loss": {
          "value_kobo": 425000000,
          "value_ngn": 4250000.00,
          "formatted": "₦4,250,000.00"
        },
        "net_loss": {
          "value_kobo": 245000000,
          "value_ngn": 2450000.00,
          "formatted": "₦2,450,000.00"
        },
        "insurance_recovery": {
          "value_kobo": 180000000,
          "value_ngn": 1800000.00,
          "formatted": "₦1,800,000.00",
          "policy_ref": "AIG-2024-XLT-00234",
          "status": "FILED"
        },
        "loss_category": "DIRECT_LOSS",
        "cost_centre": "GL-7720",
        "gl_account_code": "7720-OPR-LOSS"
      },
      "severity": "HIGH",
      "status": "PENDING_APPROVAL",
      "regulatory": {
        "cbn_reportable": false,
        "cbn_reporting_deadline": null,
        "cbn_notification_sent": false,
        "nfiu_reportable": false,
        "nfiu_report_type": null,
        "nfiu_threshold_triggered": null,
        "nfiu_report_filed": false,
        "bofia_reportable": false,
        "bofia_section_applicable": null,
        "ndic_reportable": false,
        "law_enforcement_notified": true,
        "police_report_ref": "NG/LOS/2025/ATM/00441",
        "efcc_reported": false
      },
      "linkages": {
        "risk_register_ref": "RR-2024-089",
        "risk_register_id": "rr_uuid",
        "audit_finding_ref": null,
        "controls_failed": ["CTRL-445", "CTRL-312"]
      },
      "workflow": {
        "assigned_to": "Fatima Al-Hassan",
        "current_approval_stage": "RISK_OFFICER_REVIEW",
        "days_open": 4,
        "days_to_cbn_deadline": null
      },
      "metadata": {
        "created_by": "Fatima Al-Hassan",
        "created_at": "2025-01-15T08:30:00Z",
        "updated_at": "2025-01-15T14:22:00Z"
      }
    }
  ],
  "summary": {
    "total_events": 47,
    "total_gross_loss_kobo": 28465000000,
    "total_gross_loss_formatted": "₦284,650,000.00",
    "total_net_loss_formatted": "₦201,320,000.00",
    "pending_cbn_notifications": 2,
    "pending_nfiu_filings": 1,
    "overdue_investigations": 3
  },
  "pagination": { "page": 1, "per_page": 25, "total": 47, "total_pages": 2 }
}
```

##### `POST /v1/loss-events`

**Request Body:**

```json
{
  "title": "Internet Banking OTP bypass — 47 customer accounts compromised",
  "description": "Phishing campaign successfully harvested OTPs from 47 customers over 72 hours. Fraudulent transfers executed via internet banking portal to 12 beneficiary accounts.",
  "date_of_loss": "2025-01-10",
  "date_discovered": "2025-01-13",
  "date_reported": "2025-01-14",
  "business_unit_id": "bu_digital_banking_uuid",
  "branch_id": "br_lagos_ho_uuid",
  "responsible_officer_id": "user_chukwuemeka_uuid",
  "classification": {
    "basel_l1_category": "EXTERNAL_FRAUD",
    "basel_l2_category": "EF2 - Systems Security",
    "basel_l3_detail": "Hacking damage",
    "cbn_risk_category": "TECHNOLOGY_RISK",
    "cbn_orms_event_type": "Fraud — Electronic",
    "cbn_product_line": "Digital Banking — Internet Banking"
  },
  "financial_impact": {
    "gross_loss_kobo": 1875000000,
    "loss_category": "DIRECT_LOSS",
    "cost_centre": "GL-7720",
    "insurance_covered": false
  },
  "severity": "CRITICAL",
  "regulatory": {
    "law_enforcement_notified": true,
    "police_report_ref": "NG/LOS/2025/IB/00889",
    "nfiu_threshold_triggered": "SUSPICIOUS_PATTERN"
  },
  "linkages": {
    "risk_register_id": "rr_cybersecurity_uuid",
    "controls_failed": ["ctrl_otp_delivery_uuid", "ctrl_fraud_detection_uuid"]
  },
  "initial_root_cause": "OTP SMS template did not include beneficiary account name — customers could not detect mismatch"
}
```

**Response `201 Created`:**

```json
{
  "id": "new_event_uuid",
  "event_reference": "LEM-2025-0048",
  "status": "NEW",
  "regulatory_alerts": [
    {
      "type": "NFIU_REPORTING_REQUIRED",
      "message": "Loss event involves suspicious transaction pattern. NFIU STR must be filed within 24 hours per AML/CFT Act 2022 Section 6(1).",
      "deadline": "2025-01-15T14:00:00Z",
      "regulatory_ref": "AML/CFT Act 2022 §6(1); NFIU Regulations 2024 §4.2"
    },
    {
      "type": "CBN_REPORTING_REQUIRED",
      "message": "Gross loss ₦18,750,000 exceeds CBN mandatory reporting threshold of ₦5,000,000. CBN notification required within 7 days.",
      "deadline": "2025-01-21T23:59:59Z",
      "regulatory_ref": "CBN BSD/DIR/GEN/LAB/07/014"
    }
  ],
  "created_at": "2025-01-14T14:00:00Z"
}
```

##### `PATCH /v1/loss-events/:id/status`

```json
{
  "status": "UNDER_INVESTIGATION",
  "comment": "Fraud team engaged. Digital forensics commenced. Account freeze applied to 12 beneficiary accounts.",
  "assigned_to_id": "user_fraud_team_lead_uuid"
}
```

#### 3.2.2 Root Cause Analysis

```
GET    /v1/loss-events/:id/rca
POST   /v1/loss-events/:id/rca
PUT    /v1/loss-events/:id/rca
PATCH  /v1/loss-events/:id/rca/approve
```

##### `POST /v1/loss-events/:id/rca`

```json
{
  "methodology": "5_WHYS",
  "why_1_question": "Why did the loss occur?",
  "why_1_answer": "Customers' internet banking credentials were compromised and used to initiate fraudulent transfers",
  "why_2_question": "Why were credentials compromised?",
  "why_2_answer": "A phishing campaign successfully harvested OTPs from 47 customers over 72 hours",
  "why_3_question": "Why was the phishing attack successful?",
  "why_3_answer": "The OTP delivery SMS did not include the destination account name — customers could not detect mismatch",
  "why_4_question": "Why was account name not included?",
  "why_4_answer": "The core banking SMS template did not support beneficiary name field — a known gap from the 2023 system upgrade",
  "why_5_question": "Why was this gap not remediated?",
  "why_5_answer": "Remediation action from audit finding AUD-2023-019 was marked closed without full verification",
  "root_cause_statement": "Incomplete verification of IT audit finding closure (AUD-2023-019) allowed a known SMS template gap to persist, enabling successful phishing attacks",
  "contributory_factors": [
    {
      "category": "TECHNOLOGY",
      "description": "Outdated SMS notification template missing beneficiary name",
      "severity": "HIGH",
      "linked_control_id": "ctrl_sms_template_uuid"
    },
    {
      "category": "PROCESS",
      "description": "Incomplete audit finding closure verification protocol",
      "severity": "HIGH",
      "linked_control_id": "ctrl_audit_closure_uuid"
    }
  ],
  "remediation_actions": [
    {
      "action_number": 1,
      "description": "Update SMS template to include beneficiary name and account number",
      "owner_id": "user_it_infra_uuid",
      "priority": "CRITICAL",
      "target_date": "2025-01-31"
    },
    {
      "action_number": 2,
      "description": "Re-verify all open IT audit finding closures (last 24 months)",
      "owner_id": "user_internal_audit_uuid",
      "priority": "HIGH",
      "target_date": "2025-02-28"
    },
    {
      "action_number": 3,
      "description": "Implement real-time velocity limit on internet banking transfers",
      "owner_id": "user_digital_banking_uuid",
      "priority": "HIGH",
      "target_date": "2025-01-31"
    },
    {
      "action_number": 4,
      "description": "Launch customer phishing awareness campaign (SMS, email, branch notices)",
      "owner_id": "user_marketing_uuid",
      "priority": "MEDIUM",
      "target_date": "2025-03-15"
    }
  ]
}
```

#### 3.2.3 Near-Misses

```
GET    /v1/loss-events/near-misses
POST   /v1/loss-events/near-misses
GET    /v1/loss-events/near-misses/:id
PUT    /v1/loss-events/near-misses/:id
PATCH  /v1/loss-events/near-misses/:id/convert-to-loss-event
```

#### 3.2.4 Approval Workflow

```
GET    /v1/loss-events/:id/approvals
POST   /v1/loss-events/:id/approvals
GET    /v1/loss-events/pending-approvals          (returns all events pending current user's action)
POST   /v1/loss-events/:id/cbn-notify             (trigger CBN notification)
POST   /v1/loss-events/:id/nfiu-file              (trigger NFIU STR/CTR filing)
```

##### `POST /v1/loss-events/:id/approvals`

```json
{
  "stage": "HEAD_OF_RISK_APPROVAL",
  "decision": "APPROVE",
  "comments": "RCA comprehensive. Insurance claim filed. CBN notification sent. Recommend closure after full recovery confirmed.",
  "cbn_notified": true,
  "cbn_notification_ref": "CBN/RB/2025/ORM/001892",
  "nfiu_str_ref": "NFIU-STR-2025-00234"
}
```

---

### 3.3 Nigerian Regulatory Fields Reference — Loss Event Management

| Field | Regulation | Requirement | Threshold/Deadline |
|-------|-----------|-------------|-------------------|
| `cbn_reportable` | CBN BSD/DIR/GEN/LAB/07/014 | Report all losses exceeding threshold | ₦5,000,000 gross loss |
| `cbn_reporting_deadline` | CBN ORM Framework 2016 §5.3 | Notification within 7 days of discovery | T + 7 calendar days |
| `cbn_orms_submitted` | CBN ORMS Portal | Monthly ORMS return includes all events | By 10th of following month |
| `nfiu_reportable` (STR) | AML/CFT Act 2022 §6(1) | Suspicious transaction regardless of amount | Within 24 hours of suspicion |
| `nfiu_threshold_triggered` (CTR) | NFIU Regulations 2024 §3.1 | Cash transactions ≥ ₦5M single / ₦10M daily aggregate | Within 48 hours |
| `bofia_section_applicable` | BOFIA 2020 | Various sections depending on event type | See BOFIA Schedule 2 |
| `ndic_reportable` | NDIC Act 2023 §41 | Events affecting insured deposits | ₦500,000 deposit exposure |
| `efcc_reported` | EFCC Act 2004 (as amended) | Economic and financial crimes | Mandatory for fraud > ₦1M |
| `law_enforcement_notified` | CBN Cyber Security Framework 2021 | All cyber-enabled fraud events | Within 24 hours |
| `near_miss` investigation_deadline | CBN ORM Framework 2016 §6.4 | Near-miss investigation | 5 working days |

---

## 4. Gap 2: Issues & Findings Tracker

### 4.1 Database Schema

#### Table: `issues`

```sql
CREATE TABLE issues (
  -- Primary Identification
  id                          UUID        PRIMARY KEY DEFAULT gen_random_uuid(),
  issue_reference             VARCHAR(20) NOT NULL UNIQUE, -- ISS-2025-0090
  institution_id              UUID        NOT NULL REFERENCES institutions(id),

  -- Issue Details
  title                       VARCHAR(500) NOT NULL,
  description                 TEXT        NOT NULL,
  observation                 TEXT,       -- Specific evidence observed
  criteria_violated           TEXT        NOT NULL, -- Which policy/regulation breached

  -- Source Classification
  issue_source                VARCHAR(50) NOT NULL CHECK (issue_source IN (
                                'INTERNAL_AUDIT',
                                'EXTERNAL_AUDIT',
                                'RISK_ASSESSMENT',
                                'RCSA',
                                'CBN_EXAMINATION',         -- CBN Risk-Based Supervision
                                'CBN_SPOT_CHECK',          -- Unscheduled CBN visit
                                'NDIC_REVIEW',             -- Nigeria Deposit Insurance Corporation
                                'SEC_NIGERIA_EXAMINATION', -- Securities & Exchange Commission Nigeria
                                'NFIU_REVIEW',             -- Nigerian Financial Intelligence Unit
                                'CAC_COMPLIANCE',          -- Corporate Affairs Commission
                                'FIRS_AUDIT',              -- Federal Inland Revenue Service
                                'BOFIA_COMPLIANCE_REVIEW', -- BOFIA 2020 compliance check
                                'NDPA_ASSESSMENT',         -- Nigeria Data Protection Act 2023
                                'SELF_IDENTIFIED',
                                'WHISTLEBLOWER',
                                'LOSS_EVENT',
                                'NEAR_MISS',
                                'CUSTOMER_COMPLAINT',
                                'MANAGEMENT_REVIEW'
                              )),

  -- For audit-sourced issues
  audit_engagement_id         UUID        REFERENCES audit_engagements(id),
  audit_engagement_ref        VARCHAR(30), -- AUD-2025-004
  audit_finding_ref           VARCHAR(30), -- FIND-2025-004-012
  audit_finding_id            UUID        REFERENCES audit_findings(id),

  -- For regulatory examination issues
  examination_ref             VARCHAR(200), -- CBN examination reference number
  examination_date            DATE,
  regulator_contact_name      VARCHAR(200),
  regulator_contact_email     VARCHAR(200),

  -- Classification
  issue_category              VARCHAR(50) NOT NULL CHECK (issue_category IN (
                                'POLICY_BREACH',
                                'REGULATORY_NON_COMPLIANCE',
                                'CONTROL_FAILURE',
                                'PROCESS_GAP',
                                'SYSTEM_WEAKNESS',
                                'DATA_QUALITY',
                                'GOVERNANCE_GAP',
                                'AML_CFT_BREACH',        -- AML/CFT Act 2022
                                'NDPA_BREACH',           -- Nigeria Data Protection Act 2023
                                'BOFIA_BREACH',          -- BOFIA 2020
                                'FRAUD_RELATED',
                                'CAPITAL_ADEQUACY',      -- CBN Prudential Guidelines
                                'LIQUIDITY_BREACH',      -- CBN Liquidity Regulations
                                'OTHER'
                              )),
  priority                    VARCHAR(20) NOT NULL CHECK (priority IN (
                                'CRITICAL', 'HIGH', 'MEDIUM', 'LOW'
                              )),
  issue_status                VARCHAR(30) NOT NULL DEFAULT 'OPEN' CHECK (issue_status IN (
                                'OPEN', 'IN_PROGRESS', 'OVERDUE', 'AWAITING_CLOSURE',
                                'CLOSED', 'ESCALATED', 'WITHDRAWN'
                              )),

  -- Organisational Context
  business_unit_id            UUID        REFERENCES business_units(id),
  department                  VARCHAR(200),
  branch_id                   UUID        REFERENCES branches(id),
  process_function            VARCHAR(200),
  responsible_process_owner_id UUID       REFERENCES users(id),

  -- Nigerian Regulatory Reporting
  -- Identifies which Nigerian regulators must be notified of this issue
  regulatory_reportable       BOOLEAN     DEFAULT FALSE,

  -- CBN Reporting
  cbn_reportable              BOOLEAN     DEFAULT FALSE,
  cbn_examination_finding     BOOLEAN     DEFAULT FALSE,  -- Raised during formal CBN examination
  cbn_examination_ref         VARCHAR(200),
  cbn_response_required       BOOLEAN     DEFAULT FALSE,
  cbn_response_deadline       DATE,
  cbn_response_submitted      BOOLEAN     DEFAULT FALSE,
  cbn_response_date           TIMESTAMP,
  cbn_response_ref            VARCHAR(200),
  cbn_portal_notified         BOOLEAN     DEFAULT FALSE,  -- CBN Compliance Portal notification

  -- BOFIA 2020 Specific (Banks and Other Financial Institutions Act)
  bofia_section               VARCHAR(100), -- e.g. 'SECTION_14', 'SECTION_24', 'SECTION_65'
  bofia_reportable            BOOLEAN     DEFAULT FALSE,
  bofia_report_submitted      BOOLEAN     DEFAULT FALSE,

  -- NDPA 2023 (Nigeria Data Protection Act) — for data-related issues
  ndpa_breach_type            VARCHAR(100) CHECK (ndpa_breach_type IN (
                                'PERSONAL_DATA_BREACH', 'UNLAWFUL_PROCESSING',
                                'CROSS_BORDER_TRANSFER', 'CONSENT_VIOLATION',
                                'DATA_SUBJECT_RIGHTS', 'RETENTION_VIOLATION', NULL
                              )),
  ndpa_reportable             BOOLEAN     DEFAULT FALSE,
  ndpc_notification_required  BOOLEAN     DEFAULT FALSE,  -- Nigeria Data Protection Commission
  ndpc_notification_deadline  DATE,       -- 72 hours for severe breaches per NDPA 2023 §40
  ndpc_notified               BOOLEAN     DEFAULT FALSE,
  ndpc_case_ref               VARCHAR(200),
  data_subjects_affected      INTEGER,    -- Number of individuals affected

  -- AML/CFT Act 2022 Specific
  aml_cft_applicable          BOOLEAN     DEFAULT FALSE,
  nfiu_reportable             BOOLEAN     DEFAULT FALSE,
  nfiu_report_ref             VARCHAR(200),

  -- NDIC (Nigeria Deposit Insurance Corporation)
  ndic_reportable             BOOLEAN     DEFAULT FALSE,
  ndic_report_ref             VARCHAR(200),

  -- SEC Nigeria (Securities & Exchange Commission)
  sec_reportable              BOOLEAN     DEFAULT FALSE,
  sec_report_ref              VARCHAR(200),

  -- Remediation
  owner_id                    UUID        NOT NULL REFERENCES users(id), -- Remediation Lead
  owner_name                  VARCHAR(200),
  management_response_due     DATE,       -- Typically 5–10 days from issue date
  remediation_due_date        DATE        NOT NULL,
  actual_close_date           DATE,
  escalation_path             VARCHAR(50) CHECK (escalation_path IN (
                                'LINE_MANAGER', 'HEAD_OF_BU', 'CRO', 'EXCO', 'BOARD_RISK_COMMITTEE'
                              )),
  current_escalation_level    VARCHAR(50),
  last_escalation_date        DATE,
  management_response         TEXT,
  action_plan                 TEXT,
  interim_controls            TEXT,       -- Compensating controls while remediation in progress

  -- Days tracking
  days_open                   INTEGER     GENERATED ALWAYS AS
                                (CASE WHEN actual_close_date IS NULL
                                  THEN CURRENT_DATE - date_raised
                                  ELSE actual_close_date - date_raised
                                END) STORED,
  days_overdue                INTEGER     GENERATED ALWAYS AS
                                (CASE WHEN issue_status NOT IN ('CLOSED', 'WITHDRAWN')
                                  AND remediation_due_date < CURRENT_DATE
                                  THEN CURRENT_DATE - remediation_due_date
                                  ELSE 0
                                END) STORED,
  is_overdue                  BOOLEAN     GENERATED ALWAYS AS
                                (issue_status NOT IN ('CLOSED', 'WITHDRAWN')
                                  AND remediation_due_date < CURRENT_DATE) STORED,

  -- Financial Exposure
  potential_loss_kobo         BIGINT,     -- Estimated financial exposure if issue not remediated
  actual_loss_kobo            BIGINT,     -- Actual loss if materialised

  -- Linkages
  risk_register_id            UUID        REFERENCES risk_register(id),
  risk_register_ref           VARCHAR(30),
  loss_event_id               UUID        REFERENCES loss_events(id),
  loss_event_ref              VARCHAR(30),
  near_miss_id                UUID        REFERENCES near_misses(id),
  rcsa_id                     UUID        REFERENCES rcsa_assessments(id),

  date_raised                 DATE        NOT NULL DEFAULT CURRENT_DATE,
  created_by                  UUID        NOT NULL REFERENCES users(id),
  created_at                  TIMESTAMP   NOT NULL DEFAULT NOW(),
  updated_by                  UUID        REFERENCES users(id),
  updated_at                  TIMESTAMP   NOT NULL DEFAULT NOW(),
  deleted_at                  TIMESTAMP   -- Soft delete only

  CONSTRAINT chk_remediation_due_after_raised CHECK (remediation_due_date >= date_raised),
  CONSTRAINT chk_ndpc_deadline CHECK (
    ndpa_reportable = FALSE OR ndpc_notification_deadline IS NOT NULL
  )
);

-- Indexes
CREATE INDEX idx_issues_institution ON issues(institution_id);
CREATE INDEX idx_issues_status ON issues(issue_status);
CREATE INDEX idx_issues_priority ON issues(priority);
CREATE INDEX idx_issues_source ON issues(issue_source);
CREATE INDEX idx_issues_is_overdue ON issues(is_overdue) WHERE is_overdue = TRUE;
CREATE INDEX idx_issues_cbn_exam ON issues(cbn_examination_finding) WHERE cbn_examination_finding = TRUE;
CREATE INDEX idx_issues_owner ON issues(owner_id);
CREATE INDEX idx_issues_due_date ON issues(remediation_due_date);
CREATE INDEX idx_issues_risk_register ON issues(risk_register_id);
CREATE INDEX idx_issues_days_overdue ON issues(days_overdue DESC);
```

#### Table: `issue_remediation_actions`

```sql
CREATE TABLE issue_remediation_actions (
  id                  UUID    PRIMARY KEY DEFAULT gen_random_uuid(),
  issue_id            UUID    NOT NULL REFERENCES issues(id) ON DELETE CASCADE,
  action_number       INTEGER NOT NULL,
  description         TEXT    NOT NULL,
  owner_id            UUID    REFERENCES users(id),
  owner_name          VARCHAR(200),
  target_date         DATE    NOT NULL,
  actual_close_date   DATE,
  status              VARCHAR(30) DEFAULT 'NOT_STARTED' CHECK (status IN (
                        'NOT_STARTED', 'IN_PROGRESS', 'COMPLETED', 'OVERDUE', 'CANCELLED'
                      )),
  completion_evidence TEXT,
  verified_by         UUID    REFERENCES users(id),
  verified_at         TIMESTAMP,
  created_at          TIMESTAMP NOT NULL DEFAULT NOW(),
  updated_at          TIMESTAMP NOT NULL DEFAULT NOW()
);
```

#### Table: `issue_progress_updates`

```sql
CREATE TABLE issue_progress_updates (
  id              UUID    PRIMARY KEY DEFAULT gen_random_uuid(),
  issue_id        UUID    NOT NULL REFERENCES issues(id) ON DELETE CASCADE,
  update_text     TEXT    NOT NULL,
  update_type     VARCHAR(30) CHECK (update_type IN (
                    'PROGRESS_UPDATE', 'ESCALATION', 'EXTENSION_REQUEST',
                    'EVIDENCE_ADDED', 'STATUS_CHANGE', 'REGULATORY_ACTION',
                    'CBN_RESPONSE', 'NDPC_NOTIFICATION', 'CLOSURE_REQUEST'
                  )),
  status_at_time  VARCHAR(30), -- Issue status when update was made
  new_target_date DATE,        -- If extension granted
  updated_by      UUID    NOT NULL REFERENCES users(id),
  created_at      TIMESTAMP NOT NULL DEFAULT NOW()
);
```

#### Table: `issue_attachments`

```sql
CREATE TABLE issue_attachments (
  id              UUID          PRIMARY KEY DEFAULT gen_random_uuid(),
  issue_id        UUID          NOT NULL REFERENCES issues(id) ON DELETE CASCADE,
  file_name       VARCHAR(500)  NOT NULL,
  file_size_bytes INTEGER,
  file_type       VARCHAR(50),
  storage_path    TEXT          NOT NULL,
  document_type   VARCHAR(50)   CHECK (document_type IN (
                    'EVIDENCE', 'MANAGEMENT_RESPONSE', 'ACTION_PLAN', 'CBN_CORRESPONDENCE',
                    'NDPC_NOTIFICATION', 'NFIU_FILING', 'AUDIT_WORKPAPER',
                    'POLICY_DOCUMENT', 'REGULATORY_CIRCULAR', 'CLOSURE_EVIDENCE', 'OTHER'
                  )),
  is_regulatory   BOOLEAN       DEFAULT FALSE,
  uploaded_by     UUID          NOT NULL REFERENCES users(id),
  created_at      TIMESTAMP     NOT NULL DEFAULT NOW()
);
```

#### Table: `issue_escalation_log`

```sql
CREATE TABLE issue_escalation_log (
  id                  UUID    PRIMARY KEY DEFAULT gen_random_uuid(),
  issue_id            UUID    NOT NULL REFERENCES issues(id) ON DELETE CASCADE,
  escalation_level    VARCHAR(50) NOT NULL,
  escalated_to_id     UUID    REFERENCES users(id),
  escalated_to_name   VARCHAR(200),
  escalation_reason   TEXT    NOT NULL,
  days_overdue_at_escalation INTEGER,
  auto_escalated      BOOLEAN DEFAULT FALSE,
  acknowledged        BOOLEAN DEFAULT FALSE,
  acknowledged_at     TIMESTAMP,
  escalated_by        UUID    NOT NULL REFERENCES users(id),
  created_at          TIMESTAMP NOT NULL DEFAULT NOW()
);
```

#### Escalation Automation Rules

```sql
-- Auto-escalation trigger (configurable per institution)
CREATE TABLE issue_escalation_rules (
  id                  UUID    PRIMARY KEY DEFAULT gen_random_uuid(),
  institution_id      UUID    NOT NULL REFERENCES institutions(id),
  priority            VARCHAR(20) NOT NULL,
  days_to_escalate_hod    INTEGER NOT NULL DEFAULT 7,   -- Escalate to Head of BU
  days_to_escalate_cro    INTEGER NOT NULL DEFAULT 14,  -- Escalate to CRO
  days_to_escalate_exco   INTEGER NOT NULL DEFAULT 30,  -- Escalate to EXCO
  days_to_escalate_board  INTEGER NOT NULL DEFAULT 60,  -- Board Risk Committee
  -- CBN examination findings have accelerated escalation per CBN RBS Framework
  cbn_exam_days_to_cro    INTEGER NOT NULL DEFAULT 3,
  cbn_exam_days_to_exco   INTEGER NOT NULL DEFAULT 7
);
```

---

### 4.2 API Endpoints — Issues & Findings Tracker

```
GET    /v1/issues
POST   /v1/issues
GET    /v1/issues/:id
PUT    /v1/issues/:id
PATCH  /v1/issues/:id/status
DELETE /v1/issues/:id                       (soft delete)

GET    /v1/issues/:id/remediation-actions
POST   /v1/issues/:id/remediation-actions
PUT    /v1/issues/:id/remediation-actions/:action_id
PATCH  /v1/issues/:id/remediation-actions/:action_id/complete

GET    /v1/issues/:id/progress-updates
POST   /v1/issues/:id/progress-updates

POST   /v1/issues/:id/request-closure
POST   /v1/issues/:id/approve-closure
POST   /v1/issues/:id/reject-closure

GET    /v1/issues/ageing-report
GET    /v1/issues/overdue
GET    /v1/issues/pending-my-action

POST   /v1/issues/import-from-audit         (bulk import audit findings)
GET    /v1/issues/cbn-examination            (filter: CBN exam issues only)
```

##### `GET /v1/issues`

**Key Query Parameters:**

| Parameter | Type | Values |
|-----------|------|--------|
| `status` | string | `OPEN`, `IN_PROGRESS`, `OVERDUE`, `AWAITING_CLOSURE`, `CLOSED`, `ESCALATED` |
| `priority` | string | `CRITICAL`, `HIGH`, `MEDIUM`, `LOW` |
| `source` | string | Any `issue_source` value |
| `is_overdue` | boolean | Filter overdue issues |
| `cbn_examination_finding` | boolean | CBN examination issues only |
| `days_overdue_min` | integer | Minimum days overdue |
| `business_unit_id` | UUID | — |
| `owner_id` | UUID | Assigned remediation owner |
| `escalation_level` | string | Current escalation level |
| `financial_year` | string | `2024-2025` |
| `regulatory_reportable` | boolean | — |

**Response `200 OK` (summary fields):**

```json
{
  "data": [
    {
      "id": "iss_uuid",
      "issue_reference": "ISS-2024-0041",
      "title": "ATM reconciliation controls — daily variance above tolerance not flagged",
      "issue_source": "INTERNAL_AUDIT",
      "source_label": "Internal Audit",
      "audit_engagement_ref": "AUD-2024-008",
      "issue_category": "CONTROL_FAILURE",
      "priority": "CRITICAL",
      "status": "OVERDUE",
      "business_unit": { "name": "Operations", "code": "OPS" },
      "branch": { "name": "Lagos Head Office" },
      "owner": {
        "name": "Fatima Al-Hassan",
        "role": "Head of Operations",
        "staff_id": "FCB/2015/0089"
      },
      "dates": {
        "date_raised": "2024-11-02",
        "management_response_due": "2024-11-10",
        "remediation_due": "2024-11-25",
        "actual_close_date": null,
        "days_open": 87,
        "days_overdue": 84,
        "is_overdue": true
      },
      "regulatory": {
        "cbn_examination_finding": false,
        "regulatory_reportable": false,
        "bofia_reportable": false,
        "ndpa_reportable": false
      },
      "financial_exposure": {
        "potential_loss_kobo": 234000000,
        "potential_loss_formatted": "₦2,340,000.00"
      },
      "escalation": {
        "current_level": "EXCO",
        "last_escalation_date": "2024-12-02",
        "auto_escalated": true
      },
      "linkages": {
        "risk_register_ref": "RR-2024-067",
        "loss_event_ref": null
      },
      "progress": {
        "total_actions": 4,
        "completed_actions": 1,
        "completion_percentage": 25
      }
    }
  ],
  "summary": {
    "total_open": 89,
    "overdue": 23,
    "due_this_week": 12,
    "critical_priority": 14,
    "cbn_examination_open": 8,
    "total_potential_exposure_formatted": "₦142,300,000.00"
  },
  "pagination": { "page": 1, "per_page": 25, "total": 89, "total_pages": 4 }
}
```

##### `POST /v1/issues`

```json
{
  "title": "AML transaction monitoring thresholds not updated since 2022",
  "description": "During Q4 2024 RCSA, it was identified that the FiRST AML system transaction monitoring thresholds have not been updated to reflect NFIU 2023 revised guidelines. Current thresholds (₦2M single cash) are below the new regulatory requirement (₦5M single cash) creating over-reporting burden and potential NFIU regulatory non-compliance.",
  "observation": "System configuration reviewed on 20 Dec 2024. STR threshold set at ₦2,000,000. NFIU 2023 Regulations §3.1 requires ₦5,000,000 for single cash transactions.",
  "criteria_violated": "NFIU STR/CTR Reporting Regulations 2023 §3.1; CBN AML/CFT Framework 2022 §4.2; AML/CFT (Prevention of Money Laundering) Act 2022 §6",
  "issue_source": "RCSA",
  "audit_engagement_ref": null,
  "issue_category": "AML_CFT_BREACH",
  "priority": "HIGH",
  "business_unit_id": "bu_compliance_uuid",
  "process_function": "AML Transaction Monitoring",
  "responsible_process_owner_id": "user_head_compliance_uuid",
  "regulatory": {
    "regulatory_reportable": true,
    "nfiu_reportable": true,
    "aml_cft_applicable": true,
    "cbn_reportable": true,
    "cbn_response_deadline": "2025-01-31"
  },
  "remediation": {
    "owner_id": "user_ngozi_eze_uuid",
    "management_response_due": "2024-12-25",
    "remediation_due_date": "2025-01-25",
    "escalation_path": "CRO",
    "action_plan": "Update FiRST AML system thresholds to NFIU 2023 standards. Test and validate. Update AML policy document. Notify NFIU of correction.",
    "interim_controls": "Manual review of all STRs between ₦2M–₦5M range pending system update"
  },
  "linkages": {
    "risk_register_id": "rr_aml_compliance_uuid"
  }
}
```

##### `GET /v1/issues/ageing-report`

**Response `200 OK`:**

```json
{
  "generated_at": "2025-01-18T10:00:00Z",
  "as_of_date": "2025-01-18",
  "institution": "First City Financial Bank PLC",
  "ageing_distribution": {
    "0_7_days": { "count": 18, "by_priority": { "CRITICAL": 2, "HIGH": 5, "MEDIUM": 8, "LOW": 3 } },
    "8_14_days": { "count": 24, "by_priority": { "CRITICAL": 3, "HIGH": 9, "MEDIUM": 7, "LOW": 5 } },
    "15_30_days": { "count": 19, "by_priority": { "CRITICAL": 4, "HIGH": 7, "MEDIUM": 6, "LOW": 2 } },
    "31_60_days": { "count": 16, "by_priority": { "CRITICAL": 3, "HIGH": 8, "MEDIUM": 4, "LOW": 1 } },
    "over_60_days": { "count": 12, "by_priority": { "CRITICAL": 2, "HIGH": 6, "MEDIUM": 4, "LOW": 0 } }
  },
  "by_business_unit": [
    {
      "name": "Operations",
      "code": "OPS",
      "totals": { "0_7": 2, "8_14": 4, "15_30": 5, "31_60": 8, "over_60": 9 },
      "total_open": 28
    }
  ],
  "escalation_matrix": {
    "CRITICAL": { "total": 14, "not_escalated": 0, "to_hod": 2, "to_cro": 8, "to_exco": 4 },
    "HIGH": { "total": 31, "not_escalated": 12, "to_hod": 11, "to_cro": 7, "to_exco": 1 },
    "MEDIUM": { "total": 28, "not_escalated": 26, "to_hod": 2, "to_cro": 0, "to_exco": 0 },
    "LOW": { "total": 16, "not_escalated": 16, "to_hod": 0, "to_cro": 0, "to_exco": 0 }
  },
  "top_overdue": [
    {
      "issue_reference": "ISS-2024-0041",
      "title": "ATM reconciliation control gap",
      "business_unit": "Operations",
      "priority": "CRITICAL",
      "days_overdue": 84,
      "owner": "Fatima Al-Hassan",
      "escalation_level": "EXCO"
    }
  ],
  "average_days_to_close_by_source": {
    "INTERNAL_AUDIT": 28,
    "CBN_EXAMINATION": 52,
    "RISK_ASSESSMENT": 19,
    "RCSA": 24,
    "SELF_IDENTIFIED": 15
  }
}
```

---

### 4.3 Nigerian Regulatory Fields Reference — Issues & Findings

| Field | Regulation | Requirement | Deadline |
|-------|-----------|-------------|----------|
| `cbn_examination_finding` | CBN Risk-Based Supervision Framework | Management response to CBN examination findings | 30 days from report |
| `cbn_response_deadline` | CBN BSD/DIR/GEN/CIR/05/019 | Written response with action plan | Per CBN letter |
| `cbn_portal_notified` | CBN Compliance Portal | Post-examination action plan submission | Within 30 days |
| `bofia_section` | BOFIA 2020 | Section-specific remediation requirements | Varies by section |
| `ndpa_breach_type` | Nigeria Data Protection Act 2023 §40 | Notify NDPC of personal data breaches | 72 hours (severe) / 7 days (others) |
| `ndpc_notification_deadline` | NDPA 2023 §40(3) | Data breach notification to Commission | 72 hours for high-risk |
| `aml_cft_applicable` | AML/CFT Act 2022 | AML control weaknesses — internal escalation | Per AML/CFT Act §16 |
| `nfiu_reportable` | NFIU Regulations 2024 | AML/CFT systemic issues — NFIU notification | 7 days from discovery |
| `ndic_reportable` | NDIC Act 2023 §41 | Deposit insurance risk issues | 14 days from discovery |
| `sec_reportable` | SEC Nigeria Rules 2013 (as amended) | Capital market operator compliance issues | Per SEC circular |

---

## 5. Gap 3: Risk Quantification Engine

### 5.1 Database Schema

#### Table: `quantification_scenarios`

```sql
CREATE TABLE quantification_scenarios (
  id                      UUID        PRIMARY KEY DEFAULT gen_random_uuid(),
  scenario_reference      VARCHAR(20) NOT NULL UNIQUE,  -- SCN-2025-001
  institution_id          UUID        NOT NULL REFERENCES institutions(id),
  name                    VARCHAR(500) NOT NULL,
  description             TEXT,
  risk_register_id        UUID        REFERENCES risk_register(id),
  risk_register_ref       VARCHAR(30),
  risk_category           VARCHAR(50) NOT NULL CHECK (risk_category IN (
                            'OPERATIONAL_RISK', 'CREDIT_RISK', 'MARKET_RISK',
                            'LIQUIDITY_RISK', 'COMPLIANCE_REGULATORY_RISK',
                            'STRATEGIC_RISK', 'REPUTATIONAL_RISK', 'IT_RISK',
                            'THIRD_PARTY_RISK', 'ESG_RISK'
                          )),
  business_unit_ids       UUID[],     -- Array of business unit IDs
  scenario_horizon        INTEGER     NOT NULL DEFAULT 1 CHECK (scenario_horizon IN (1, 3, 5)),
  horizon_unit            VARCHAR(10) DEFAULT 'YEARS',
  scenario_status         VARCHAR(20) DEFAULT 'DRAFT' CHECK (scenario_status IN (
                            'DRAFT', 'ACTIVE', 'ARCHIVED', 'UNDER_REVIEW'
                          )),
  scenario_source         VARCHAR(50) CHECK (scenario_source IN (
                            'CUSTOM',
                            'CBN_PREBUILT',      -- CBN ICAAP Guidance Note 2023
                            'NDIC_INDUSTRY',     -- NDIC Annual Report loss data
                            'BASEL_REFERENCE',   -- BCBS operational risk database
                            'PEER_BANK',         -- Anonymised peer bank data
                            'HISTORICAL_INTERNAL' -- Bank's own historical losses
                          )),

  -- Frequency Distribution Parameters
  frequency_method        VARCHAR(30) NOT NULL CHECK (frequency_method IN (
                            'EMPIRICAL', 'EXPERT_JUDGMENT', 'REGULATORY_REFERENCE'
                          )),
  frequency_distribution  VARCHAR(30) NOT NULL CHECK (frequency_distribution IN (
                            'POISSON', 'NEGATIVE_BINOMIAL', 'BERNOULLI',
                            'FIXED', 'UNIFORM'
                          )),
  frequency_lambda        DECIMAL(10,6),  -- Poisson λ = expected events per year
  frequency_mean          DECIMAL(10,6),
  frequency_variance      DECIMAL(10,6),
  frequency_data_source   TEXT,

  -- Severity Distribution Parameters
  severity_method         VARCHAR(30) NOT NULL,
  severity_distribution   VARCHAR(30) NOT NULL CHECK (severity_distribution IN (
                            'LOGNORMAL', 'WEIBULL', 'PARETO', 'GAMMA',
                            'NORMAL', 'UNIFORM', 'EMPIRICAL'
                          )),
  severity_min_kobo       BIGINT,      -- Minimum loss in kobo
  severity_max_kobo       BIGINT,      -- Maximum loss in kobo
  severity_mean_kobo      BIGINT,      -- Mean/expected severity
  severity_std_dev_kobo   BIGINT,      -- Standard deviation
  severity_mu             DECIMAL(20,10), -- LogNormal μ parameter
  severity_sigma          DECIMAL(20,10), -- LogNormal σ parameter
  severity_data_source    TEXT,

  -- Correlation Configuration
  correlations            JSONB DEFAULT '[]'::jsonb,
  -- Schema: [{ "scenario_id": "uuid", "scenario_ref": "SCN-2025-002",
  --            "correlation_coefficient": 0.6, "method": "EXPERT" }]

  -- Assumptions & Governance
  assumptions_text        TEXT,
  data_sources            TEXT[],     -- Array of data source descriptions
  parameter_confidence    INTEGER     CHECK (parameter_confidence BETWEEN 0 AND 100), -- %
  last_validated_at       TIMESTAMP,
  validated_by            UUID        REFERENCES users(id),
  validation_notes        TEXT,
  model_risk_rating       VARCHAR(20) CHECK (model_risk_rating IN ('LOW','MEDIUM','HIGH')),

  -- Nigerian Regulatory References
  cbn_icaap_section       VARCHAR(100), -- Which ICAAP section this feeds
  cbn_stress_scenario     VARCHAR(50)  CHECK (cbn_stress_scenario IN (
                            'BASELINE', 'ADVERSE_SCENARIO_A', 'SEVERE_SCENARIO_B',
                            'REVERSE_STRESS', 'CUSTOM', NULL
                          )),
  ndic_loss_category_ref  VARCHAR(100), -- NDIC loss category reference
  bcbs_event_type_ref     VARCHAR(100), -- Basel event type reference

  created_by              UUID        NOT NULL REFERENCES users(id),
  created_at              TIMESTAMP   NOT NULL DEFAULT NOW(),
  updated_by              UUID        REFERENCES users(id),
  updated_at              TIMESTAMP   NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_scenarios_institution ON quantification_scenarios(institution_id);
CREATE INDEX idx_scenarios_risk_category ON quantification_scenarios(risk_category);
CREATE INDEX idx_scenarios_status ON quantification_scenarios(scenario_status);
CREATE INDEX idx_scenarios_risk_register ON quantification_scenarios(risk_register_id);
```

#### Table: `simulation_runs`

```sql
CREATE TABLE simulation_runs (
  id                      UUID        PRIMARY KEY DEFAULT gen_random_uuid(),
  simulation_reference    VARCHAR(20) NOT NULL UNIQUE, -- SIM-2025-007
  institution_id          UUID        NOT NULL REFERENCES institutions(id),
  name                    VARCHAR(500) NOT NULL,
  description             TEXT,

  -- Configuration
  iterations              INTEGER     NOT NULL DEFAULT 100000,
  horizon_years           INTEGER     NOT NULL DEFAULT 1,
  correlation_method      VARCHAR(30) NOT NULL CHECK (correlation_method IN (
                            'INDEPENDENCE', 'GAUSSIAN_COPULA', 'T_COPULA', 'FRANK_COPULA'
                          )),
  confidence_levels       DECIMAL[]   NOT NULL DEFAULT ARRAY[0.90, 0.95, 0.99, 0.999],
  include_diversification BOOLEAN     DEFAULT TRUE,
  random_seed             INTEGER,
  currency                CHAR(3)     NOT NULL DEFAULT 'NGN',

  -- Scenarios included
  scenario_ids            UUID[]      NOT NULL,

  -- Stress scenarios
  include_cbn_adverse     BOOLEAN     DEFAULT FALSE,
  include_cbn_severe      BOOLEAN     DEFAULT FALSE,
  custom_stress_params    JSONB,

  -- Status & Timing
  run_status              VARCHAR(20) DEFAULT 'PENDING' CHECK (run_status IN (
                            'PENDING', 'RUNNING', 'COMPLETED', 'FAILED', 'CANCELLED'
                          )),
  started_at              TIMESTAMP,
  completed_at            TIMESTAMP,
  runtime_seconds         INTEGER,
  error_message           TEXT,

  -- Results (stored as JSONB for flexibility; structured tables also populated)
  raw_results_path        TEXT,       -- S3 path to full simulation output

  -- ICAAP Usage
  icaap_period            VARCHAR(20), -- e.g. '2024-2025'
  icaap_approved          BOOLEAN     DEFAULT FALSE,
  icaap_approved_by       UUID        REFERENCES users(id),
  icaap_approved_at       TIMESTAMP,

  -- Governance
  run_by                  UUID        NOT NULL REFERENCES users(id),
  approved_by             UUID        REFERENCES users(id),
  created_at              TIMESTAMP   NOT NULL DEFAULT NOW()
);
```

#### Table: `simulation_results`

```sql
CREATE TABLE simulation_results (
  id                      UUID        PRIMARY KEY DEFAULT gen_random_uuid(),
  simulation_run_id       UUID        NOT NULL REFERENCES simulation_runs(id) ON DELETE CASCADE,
  institution_id          UUID        NOT NULL REFERENCES institutions(id),
  result_type             VARCHAR(30) NOT NULL CHECK (result_type IN (
                            'AGGREGATE', 'BY_RISK_CATEGORY', 'BY_SCENARIO', 'STRESS_ADVERSE', 'STRESS_SEVERE'
                          )),
  scenario_id             UUID        REFERENCES quantification_scenarios(id), -- NULL for aggregate
  risk_category           VARCHAR(50),

  -- Core Results (all in kobo)
  mean_loss_kobo          BIGINT,     -- Expected Annual Loss (EAL)
  median_loss_kobo        BIGINT,     -- P50
  std_dev_kobo            BIGINT,
  min_loss_kobo           BIGINT,
  max_loss_kobo           BIGINT,

  -- Value at Risk (various confidence levels)
  var_90_kobo             BIGINT,
  var_95_kobo             BIGINT,     -- Primary regulatory VaR
  var_99_kobo             BIGINT,
  var_99_5_kobo           BIGINT,
  var_99_9_kobo           BIGINT,     -- Pillar 2 capital basis
  var_99_95_kobo          BIGINT,

  -- Expected Shortfall / CVaR
  es_95_kobo              BIGINT,
  es_99_kobo              BIGINT,
  es_99_9_kobo            BIGINT,

  -- Diversification
  undiversified_var_95_kobo BIGINT,
  diversification_benefit_kobo BIGINT,
  diversification_pct     DECIMAL(5,2),

  -- Capital Adequacy (CBN/Basel)
  pillar2_capital_requirement_kobo BIGINT, -- From P99.9 VaR
  available_capital_kobo  BIGINT,
  capital_surplus_kobo    BIGINT,
  capital_adequacy_ratio  DECIMAL(6,4),    -- As decimal e.g. 0.164 = 16.4%
  cbn_minimum_car         DECIMAL(6,4)     DEFAULT 0.10, -- 10% per CBN
  car_buffer_pp           DECIMAL(6,4),    -- Percentage points above minimum
  passes_cbn_minimum      BOOLEAN,

  -- Risk Contribution (by scenario — only populated for AGGREGATE result type)
  risk_contributions      JSONB,
  -- Schema: [{ "scenario_id": "uuid", "scenario_ref": "SCN-001",
  --            "marginal_var_kobo": 284000000000,
  --            "contribution_pct": 33.7 }]

  -- Percentile distribution for chart rendering (sampled)
  percentile_distribution JSONB,
  -- Schema: { "percentiles": [1,5,10,...,99], "values_kobo": [..] }

  created_at              TIMESTAMP   NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_sim_results_run ON simulation_results(simulation_run_id);
CREATE INDEX idx_sim_results_type ON simulation_results(result_type);
```

#### Table: `icaap_assessments`

```sql
CREATE TABLE icaap_assessments (
  id                          UUID        PRIMARY KEY DEFAULT gen_random_uuid(),
  institution_id              UUID        NOT NULL REFERENCES institutions(id),
  period                      VARCHAR(20) NOT NULL, -- '2024-2025' (Nigerian FY Jul-Jun)
  submission_due_date         DATE        NOT NULL, -- Typically 31 March each year per CBN
  submission_status           VARCHAR(30) DEFAULT 'NOT_STARTED' CHECK (submission_status IN (
                                'NOT_STARTED', 'IN_PROGRESS', 'BOARD_REVIEW',
                                'SUBMITTED_TO_CBN', 'CBN_ACCEPTED', 'CBN_RETURNED'
                              )),
  submission_date             TIMESTAMP,
  cbn_reference               VARCHAR(200), -- CBN acknowledgement reference
  cbn_response_date           TIMESTAMP,
  cbn_feedback                TEXT,

  -- Capital Position
  total_qualifying_capital_kobo  BIGINT,
  cet1_capital_kobo           BIGINT,     -- Common Equity Tier 1
  tier1_capital_kobo          BIGINT,
  tier2_capital_kobo          BIGINT,
  total_rwa_kobo              BIGINT,     -- Total Risk-Weighted Assets
  credit_rwa_kobo             BIGINT,
  market_rwa_kobo             BIGINT,
  operational_rwa_kobo        BIGINT,

  -- Pillar 1 Capital Requirements
  pillar1_minimum_car         DECIMAL(6,4) NOT NULL DEFAULT 0.10,  -- 10% CBN minimum
  pillar1_car_actual          DECIMAL(6,4),
  conservation_buffer         DECIMAL(6,4) DEFAULT 0.025,          -- 2.5% Basel III
  dsib_surcharge              DECIMAL(6,4) DEFAULT 0.0,             -- D-SIB surcharge if applicable
  dsib_applicable             BOOLEAN     DEFAULT FALSE,

  -- Pillar 2A Add-ons (internal model results)
  pillar2a_credit_addon_kobo  BIGINT      DEFAULT 0,  -- Concentration, model risk
  pillar2a_market_addon_kobo  BIGINT      DEFAULT 0,  -- FX, IRRBB
  pillar2a_operational_addon_kobo BIGINT  DEFAULT 0,  -- Above Pillar 1 floor
  pillar2a_other_addon_kobo   BIGINT      DEFAULT 0,
  pillar2a_total_kobo         BIGINT      GENERATED ALWAYS AS
                                (pillar2a_credit_addon_kobo + pillar2a_market_addon_kobo +
                                  pillar2a_operational_addon_kobo + pillar2a_other_addon_kobo) STORED,

  -- Pillar 2B (Stress Testing Buffer)
  pillar2b_buffer_kobo        BIGINT      DEFAULT 0,

  -- Linked Simulation
  primary_simulation_run_id   UUID        REFERENCES simulation_runs(id),

  -- ICAAP Sections Completion
  section_capital_base_complete       BOOLEAN DEFAULT FALSE,
  section_pillar1_complete            BOOLEAN DEFAULT FALSE,
  section_pillar2a_complete           BOOLEAN DEFAULT FALSE,
  section_pillar2b_complete           BOOLEAN DEFAULT FALSE,
  section_stress_testing_complete     BOOLEAN DEFAULT FALSE,
  section_capital_plan_complete       BOOLEAN DEFAULT FALSE,
  section_governance_complete         BOOLEAN DEFAULT FALSE,
  section_board_approval_complete     BOOLEAN DEFAULT FALSE,

  -- Governance
  prepared_by                 UUID        REFERENCES users(id),
  reviewed_by_cro             UUID        REFERENCES users(id),
  cro_review_date             TIMESTAMP,
  board_approved_by           UUID        REFERENCES users(id),
  board_approval_date         TIMESTAMP,
  created_at                  TIMESTAMP   NOT NULL DEFAULT NOW(),
  updated_at                  TIMESTAMP   NOT NULL DEFAULT NOW()
);
```

#### Table: `quantification_settings`

```sql
CREATE TABLE quantification_settings (
  id                          UUID    PRIMARY KEY DEFAULT gen_random_uuid(),
  institution_id              UUID    NOT NULL UNIQUE REFERENCES institutions(id),

  -- Simulation Defaults
  default_iterations          INTEGER NOT NULL DEFAULT 100000,
  default_horizon_years       INTEGER NOT NULL DEFAULT 1,
  default_correlation_method  VARCHAR(30) DEFAULT 'GAUSSIAN_COPULA',
  default_confidence_levels   DECIMAL[] DEFAULT ARRAY[0.90, 0.95, 0.99, 0.999],
  default_diversification     BOOLEAN DEFAULT TRUE,
  model_version               VARCHAR(20) DEFAULT '2.4.1',

  -- CBN Regulatory Parameters
  cbn_minimum_car             DECIMAL(6,4) NOT NULL DEFAULT 0.10,   -- 10%
  conservation_buffer         DECIMAL(6,4) NOT NULL DEFAULT 0.025,  -- 2.5%
  icaap_submission_deadline_month  INTEGER DEFAULT 3,  -- March
  icaap_submission_deadline_day    INTEGER DEFAULT 31,

  -- Loss Thresholds (kobo)
  cbn_mandatory_report_threshold_kobo   BIGINT  DEFAULT 500000000,  -- ₦5,000,000
  nfiu_str_threshold_kobo               BIGINT  DEFAULT 500000000,  -- ₦5,000,000 single cash
  nfiu_ctr_threshold_kobo               BIGINT  DEFAULT 1000000000, -- ₦10,000,000 daily aggregate
  ndic_threshold_kobo                   BIGINT  DEFAULT 50000000,   -- ₦500,000 deposit exposure

  -- Distribution Defaults by Risk Category (JSONB)
  distribution_defaults       JSONB DEFAULT '{
    "OPERATIONAL_RISK":  {"frequency": "POISSON",   "severity": "LOGNORMAL", "data_source": "NDIC Industry Loss Data 2023"},
    "CREDIT_RISK":       {"frequency": "BINOMIAL",  "severity": "BETA",      "data_source": "CBN Credit Risk Guideline 2023"},
    "MARKET_RISK":       {"frequency": "NORMAL",    "severity": "NORMAL",    "data_source": "CBN MAS Returns Historical"},
    "LIQUIDITY_RISK":    {"frequency": "BERNOULLI", "severity": "LOGNORMAL", "data_source": "CBN Liquidity Ratio Data"},
    "COMPLIANCE_REGULATORY_RISK": {"frequency": "POISSON", "severity": "LOGNORMAL", "data_source": "CBN Enforcement Record 2019-2024"}
  }'::jsonb,

  -- CBN Reference Rate
  cbn_mpr                     DECIMAL(6,4) DEFAULT 0.275,  -- CBN Monetary Policy Rate (27.5%)
  mpr_last_updated            DATE,

  -- Institution Info for Reports
  institution_name_report     VARCHAR(500),
  cbn_institution_code        VARCHAR(20),
  ndic_member_number          VARCHAR(50),
  cbn_reporting_entity_code   VARCHAR(50),

  updated_by                  UUID    REFERENCES users(id),
  updated_at                  TIMESTAMP NOT NULL DEFAULT NOW()
);
```

---

### 5.2 API Endpoints — Risk Quantification Engine

```
GET    /v1/quantification/scenarios
POST   /v1/quantification/scenarios
GET    /v1/quantification/scenarios/:id
PUT    /v1/quantification/scenarios/:id
DELETE /v1/quantification/scenarios/:id

GET    /v1/quantification/simulations
POST   /v1/quantification/simulations
GET    /v1/quantification/simulations/:id
GET    /v1/quantification/simulations/:id/status
GET    /v1/quantification/simulations/:id/results
GET    /v1/quantification/simulations/:id/results/aggregate
GET    /v1/quantification/simulations/:id/results/by-scenario
GET    /v1/quantification/simulations/:id/results/stress
PATCH  /v1/quantification/simulations/:id/approve-icaap

GET    /v1/quantification/icaap
GET    /v1/quantification/icaap/:period
POST   /v1/quantification/icaap
PUT    /v1/quantification/icaap/:period
PATCH  /v1/quantification/icaap/:period/submit-to-cbn

GET    /v1/quantification/scenarios/library
GET    /v1/quantification/settings
PUT    /v1/quantification/settings
GET    /v1/quantification/dashboard
```

##### `POST /v1/quantification/simulations`

```json
{
  "name": "Q4 2024 Full Portfolio Risk Quantification",
  "description": "Full portfolio Monte Carlo for ICAAP Q4 2024. Covers 33 quantified risks across all categories.",
  "iterations": 100000,
  "horizon_years": 1,
  "correlation_method": "GAUSSIAN_COPULA",
  "confidence_levels": [0.90, 0.95, 0.99, 0.999],
  "include_diversification": true,
  "random_seed": 42,
  "scenario_ids": [
    "scn_cybersecurity_uuid",
    "scn_credit_concentration_uuid",
    "scn_aml_compliance_uuid",
    "scn_liquidity_stress_uuid",
    "scn_core_banking_uuid"
  ],
  "stress_scenarios": {
    "include_cbn_adverse": true,
    "include_cbn_severe": true
  },
  "icaap_period": "2024-2025"
}
```

**Response `202 Accepted`** (simulation runs asynchronously):

```json
{
  "simulation_run_id": "sim_run_uuid",
  "simulation_reference": "SIM-2025-007",
  "status": "PENDING",
  "estimated_runtime_seconds": 45,
  "status_url": "/v1/quantification/simulations/sim_run_uuid/status",
  "results_url": "/v1/quantification/simulations/sim_run_uuid/results"
}
```

##### `GET /v1/quantification/simulations/:id/results/aggregate`

```json
{
  "simulation_reference": "SIM-2025-007",
  "institution": {
    "name": "First City Financial Bank PLC",
    "cbn_institution_code": "NGN/COM/0089"
  },
  "run_config": {
    "iterations": 100000,
    "horizon_years": 1,
    "scenarios_included": 33,
    "correlation_method": "GAUSSIAN_COPULA",
    "completed_at": "2025-01-18T14:32:17Z",
    "runtime_seconds": 43
  },
  "results": {
    "expected_annual_loss": {
      "value_kobo": 183000000000,
      "value_ngn": 1830000000.00,
      "formatted": "₦1,830,000,000.00",
      "in_billions": 1.83
    },
    "value_at_risk": {
      "var_90": { "value_kobo": 462000000000, "formatted": "₦4,620,000,000.00" },
      "var_95": { "value_kobo": 842000000000, "formatted": "₦8,420,000,000.00", "label": "Regulatory VaR" },
      "var_99": { "value_kobo": 1210000000000, "formatted": "₦12,100,000,000.00" },
      "var_99_9": { "value_kobo": 1470000000000, "formatted": "₦14,700,000,000.00", "label": "Pillar 2 Capital Basis" }
    },
    "diversification": {
      "undiversified_var_95_kobo": 1025000000000,
      "diversified_var_95_kobo": 842000000000,
      "benefit_kobo": 183000000000,
      "benefit_formatted": "₦1,830,000,000.00",
      "benefit_pct": 17.85
    },
    "capital_adequacy": {
      "pillar2_requirement_kobo": 1470000000000,
      "available_capital_kobo": 2210000000000,
      "capital_surplus_kobo": 740000000000,
      "capital_surplus_formatted": "₦7,400,000,000.00",
      "car_actual": 0.164,
      "car_formatted": "16.4%",
      "cbn_minimum_car": 0.10,
      "car_buffer_pp": 0.064,
      "passes_cbn_minimum": true
    }
  },
  "stress_results": {
    "cbn_adverse_scenario_a": {
      "description": "Adverse macroeconomic: 10% NPL increase, 15% naira depreciation",
      "var_95_kobo": 1210000000000,
      "var_99_9_kobo": 1980000000000,
      "post_stress_car": 0.131,
      "passes_cbn_minimum": true,
      "car_formatted": "13.1%"
    },
    "cbn_severe_scenario_b": {
      "description": "Severe recession: 20% NPL, 30% FX depreciation, liquidity stress",
      "var_95_kobo": 1740000000000,
      "var_99_9_kobo": 2860000000000,
      "post_stress_car": 0.089,
      "passes_cbn_minimum": false,
      "car_formatted": "8.9%",
      "alert": "SEVERE STRESS CAR 8.9% — BELOW CBN MINIMUM 10%. Capital enhancement action required.",
      "capital_shortfall_kobo": 74900000000,
      "capital_shortfall_formatted": "₦749,000,000.00"
    }
  },
  "risk_contributions": [
    { "scenario_ref": "SCN-2025-003", "name": "Credit Concentration Risk", "marginal_var_95_kobo": 284000000000, "contribution_pct": 33.7 },
    { "scenario_ref": "SCN-2025-001", "name": "Cybersecurity & Digital Fraud", "marginal_var_95_kobo": 163000000000, "contribution_pct": 19.4 },
    { "scenario_ref": "SCN-2025-004", "name": "AML/CFT Compliance Failure", "marginal_var_95_kobo": 112000000000, "contribution_pct": 13.3 }
  ],
  "percentile_distribution": {
    "percentiles": [1, 5, 10, 25, 50, 75, 90, 95, 99, 99.5, 99.9, 99.95],
    "values_ngn_billions": [0.12, 0.28, 0.45, 0.71, 0.89, 1.84, 4.62, 8.42, 12.10, 13.40, 14.70, 18.20]
  }
}
```

##### `GET /v1/quantification/icaap/:period`

```json
{
  "period": "2024-2025",
  "institution": "First City Financial Bank PLC",
  "cbn_institution_code": "NGN/COM/0089",
  "submission_due": "2025-03-31",
  "days_remaining": 72,
  "status": "IN_PROGRESS",
  "completion_percentage": 68,
  "capital_position": {
    "total_qualifying_capital_formatted": "₦112,400,000,000.00",
    "cet1_formatted": "₦88,200,000,000.00",
    "total_rwa_formatted": "₦685,200,000,000.00",
    "car_actual": 0.164,
    "car_formatted": "16.4%",
    "cbn_minimum": "10.0%",
    "buffer_pp": "6.4 percentage points"
  },
  "pillar2_requirements": {
    "pillar2a_total_formatted": "₦8,200,000,000.00",
    "pillar2b_buffer_formatted": "₦6,100,000,000.00",
    "total_internal_requirement_formatted": "₦82,800,000,000.00",
    "available_capital_formatted": "₦112,400,000,000.00",
    "surplus_formatted": "₦29,600,000,000.00"
  },
  "sections": {
    "capital_base": { "complete": true, "last_updated": "2025-01-10" },
    "pillar1": { "complete": true, "last_updated": "2025-01-10" },
    "pillar2a": { "complete": true, "last_updated": "2025-01-10" },
    "pillar2b": { "complete": false, "last_updated": "2025-01-05" },
    "stress_testing": { "complete": true, "last_updated": "2025-01-18" },
    "capital_plan": { "complete": false },
    "governance": { "complete": false },
    "board_approval": { "complete": false }
  },
  "linked_simulation": {
    "reference": "SIM-2025-007",
    "run_date": "2025-01-18",
    "icaap_approved": false
  }
}
```

---

### 5.3 Nigerian Regulatory Fields Reference — Risk Quantification

| Field | Regulation | Requirement |
|-------|-----------|-------------|
| `cbn_minimum_car` = 10% | CBN Capital Adequacy Framework 2013 (revised 2023) | Minimum CAR for commercial banks |
| `dsib_surcharge` | CBN D-SIB Framework 2014 | Additional 1–3.5% for systemically important banks |
| `pillar2a_operational_addon_kobo` | CBN ICAAP Guidance Note 2023 §3.2 | Operational risk Pillar 2 add-on above SMA floor |
| `cbn_stress_scenario` | CBN ICAAP Guidance Note 2023 §4.1 | Mandatory adverse and severe scenarios |
| `icaap_submission_deadline` | CBN BSD/DIR/GEN/CIR/05/019 | ICAAP due annually by 31 March |
| `ndic_loss_category_ref` | NDIC Annual Report loss taxonomy | Aligns simulation categories with NDIC industry data |
| `cbn_mpr` (27.5%) | CBN Monetary Policy Rate | Discount rate for loss present-value calculations |
| `nfiu_ctr_threshold_kobo` | NFIU Regulations 2024 | ₦10M daily aggregate triggers quantification input |
| `conservation_buffer` = 2.5% | Basel III / CBN Capital Framework | Adds to Pillar 1 minimum (total 12.5% practical floor) |

---

## 6. Cross-Module Integration

### 6.1 Integration Event Schema (Event Bus / Webhooks)

All inter-module communications use domain events. Implement using PostgreSQL `LISTEN/NOTIFY` or a message broker (Redis Streams / RabbitMQ).

```sql
CREATE TABLE domain_events (
  id              UUID        PRIMARY KEY DEFAULT gen_random_uuid(),
  event_type      VARCHAR(100) NOT NULL,
  source_module   VARCHAR(50) NOT NULL,
  source_id       UUID        NOT NULL,
  source_ref      VARCHAR(30),
  payload         JSONB       NOT NULL,
  processed       BOOLEAN     DEFAULT FALSE,
  processed_at    TIMESTAMP,
  institution_id  UUID        NOT NULL REFERENCES institutions(id),
  created_at      TIMESTAMP   NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_domain_events_unprocessed ON domain_events(processed) WHERE processed = FALSE;
CREATE INDEX idx_domain_events_type ON domain_events(event_type);
```

#### 6.1.1 Event Types & Cross-Module Actions

```
LOSS_EVENT_CREATED
  → Issues module: Auto-raise control failure issue if failed controls identified
  → Risk Register: Update inherent risk rating if threshold exceeded
  → Quantification: Flag scenario for re-run if loss > P95 VaR

LOSS_EVENT_CBN_THRESHOLD_BREACHED
  → Regulatory module: Initiate CBN notification workflow
  → Issues module: Create issue "CBN Mandatory Notification Required"

LOSS_EVENT_RCA_COMPLETED
  → Issues module: Bulk-create issues from remediation actions
  → Audit module: Flag for follow-up in next engagement

ISSUE_OVERDUE
  → Escalation engine: Trigger next escalation level
  → Risk Register: Increase residual risk rating for linked risk
  → Notification service: Email/SMS to escalation recipient

ISSUE_CBN_EXAM_FINDING_CREATED
  → Regulatory module: Track CBN response deadline
  → Escalation engine: Apply accelerated escalation rules

ISSUE_CLOSED
  → Risk Register: Trigger residual risk re-assessment
  → Audit module: Update finding status

SIMULATION_COMPLETED
  → ICAAP module: Auto-populate capital adequacy section
  → Dashboard: Refresh aggregate VaR metrics
  → Risk Register: Update quantified exposure for linked risks

ICAAP_STRESS_CAR_BELOW_MINIMUM
  → Regulatory module: Alert CRO and Board Risk Committee
  → Issues module: Auto-create critical issue "Capital Adequacy — CBN Stress Test Failure"

NEAR_MISS_CRITICAL_SEVERITY
  → Risk Register: Flag for appetite monitoring
  → Issues module: Create issue if control gap identified
```

### 6.2 Cross-Module Linking Endpoints

```
POST /v1/loss-events/:id/link-issue          (link loss event to existing issue)
POST /v1/issues/:id/link-loss-event          (link issue to existing loss event)
POST /v1/issues/:id/link-risk                (link issue to risk register entry)
POST /v1/quantification/scenarios/:id/link-risk   (link scenario to risk register)
POST /v1/loss-events/:id/link-scenario       (link loss event to quantification scenario)

GET  /v1/risk-register/:id/linked-items      (returns all loss events, issues, scenarios linked to a risk)
GET  /v1/audit-findings/:id/linked-issues    (returns issues raised from audit finding)
```

### 6.3 Shared Reference Tables

```sql
-- Business units (shared across all modules)
CREATE TABLE business_units (
  id          UUID    PRIMARY KEY DEFAULT gen_random_uuid(),
  institution_id UUID NOT NULL REFERENCES institutions(id),
  name        VARCHAR(200) NOT NULL,
  code        VARCHAR(20)  NOT NULL,
  parent_id   UUID    REFERENCES business_units(id),
  cbn_line_of_business VARCHAR(100),  -- CBN ORMS prescribed line of business
  is_active   BOOLEAN DEFAULT TRUE
);

-- Nigerian bank branches
CREATE TABLE branches (
  id                  UUID    PRIMARY KEY DEFAULT gen_random_uuid(),
  institution_id      UUID    NOT NULL REFERENCES institutions(id),
  name                VARCHAR(200) NOT NULL,
  cbn_branch_code     VARCHAR(10),  -- CBN assigned branch code
  state               VARCHAR(50),  -- Nigerian state
  lga                 VARCHAR(100), -- Local Government Area
  branch_type         VARCHAR(30)   CHECK (branch_type IN (
                        'HEAD_OFFICE', 'BRANCH', 'SUBSIDIARY', 'AGENCY',
                        'CASH_CENTRE', 'E_BANKING_UNIT'
                      )),
  is_active           BOOLEAN DEFAULT TRUE
);
```

---

## 7. Regulatory Reporting Endpoints

These endpoints generate structured outputs for direct submission to Nigerian regulators.

```
GET  /v1/regulatory/cbn-orms-monthly        (CBN ORMS monthly return — BSD template)
GET  /v1/regulatory/cbn-orms-monthly/export (Download as CBN-prescribed Excel format)
POST /v1/regulatory/cbn-orms-monthly/submit (Submit to CBN ORMS portal via API)

GET  /v1/regulatory/nfiu-str-pack           (NFIU STR filing pack for pending reports)
POST /v1/regulatory/nfiu-str/:id/file       (Mark STR as filed, record reference)
GET  /v1/regulatory/nfiu-ctr-pack           (NFIU CTR filing pack)

GET  /v1/regulatory/ndic-report             (NDIC quarterly operational risk report)
GET  /v1/regulatory/sec-compliance          (SEC Nigeria compliance report for cap-market ops)

GET  /v1/regulatory/icaap-export/:period    (Full ICAAP PDF/Excel in CBN format)
POST /v1/regulatory/icaap/:period/submit    (Submit to CBN supervisor portal)

GET  /v1/regulatory/bofia-returns           (BOFIA 2020 compliance returns)
GET  /v1/regulatory/ndpa-breach-register    (NDPA 2023 data breach log for NDPC)
POST /v1/regulatory/ndpa-breach/:id/notify  (Submit NDPC notification)

GET  /v1/regulatory/dashboard               (Regulatory compliance dashboard — all pending items)
```

##### `GET /v1/regulatory/dashboard`

```json
{
  "institution": "First City Financial Bank PLC",
  "cbn_institution_code": "NGN/COM/0089",
  "as_of": "2025-01-18T10:00:00Z",
  "regulatory_items": {
    "cbn": {
      "pending_notifications": 2,
      "overdue_notifications": 1,
      "orms_monthly_due": "2025-01-31",
      "orms_status": "DRAFT_READY",
      "examination_open_issues": 8,
      "icaap_due": "2025-03-31",
      "icaap_days_remaining": 72,
      "icaap_status": "IN_PROGRESS"
    },
    "nfiu": {
      "pending_str": 2,
      "pending_ctr": 0,
      "str_overdue": 1,
      "str_oldest_hours": 38
    },
    "ndpc": {
      "open_breach_notifications": 0,
      "pending_filings": 0
    },
    "ndic": {
      "pending_reports": 0,
      "next_quarterly_due": "2025-03-31"
    },
    "bofia_2020": {
      "open_compliance_issues": 3,
      "critical_sections": ["SECTION_24_RELATED_PARTY"]
    }
  },
  "critical_alerts": [
    {
      "type": "NFIU_STR_OVERDUE",
      "message": "STR for LEM-2025-0046 is 38 hours overdue. NFIU requires filing within 24 hours of suspicion per AML/CFT Act 2022 §6(1).",
      "regulatory_ref": "AML/CFT Act 2022 §6(1)",
      "hours_overdue": 38,
      "action_url": "/v1/regulatory/nfiu-str-pack"
    },
    {
      "type": "CBN_NOTIFICATION_OVERDUE",
      "message": "CBN notification for LEM-2025-0038 (₦18.75M) is 3 days overdue. CBN BSD circular requires notification within 7 days.",
      "regulatory_ref": "CBN BSD/DIR/GEN/LAB/07/014",
      "days_overdue": 3
    }
  ]
}
```

---

## 8. Technical Requirements

### 8.1 Runtime & Infrastructure

| Requirement | Specification |
|------------|---------------|
| **Backend Runtime** | Node.js 20 LTS or Python 3.11+ (FastAPI) |
| **Database** | PostgreSQL 15+ with TimescaleDB extension (time-series for KRI trends) |
| **Cache** | Redis 7.x (session, rate limiting, simulation queue) |
| **Object Storage** | AWS S3 or compatible (Wasabi for Nigerian data residency) |
| **Message Broker** | Redis Streams or RabbitMQ 3.12 (domain events) |
| **Simulation Engine** | Python (NumPy, SciPy) worker process, async queue |
| **API Documentation** | OpenAPI 3.1 (Swagger UI at `/docs`) |
| **Authentication** | OAuth 2.0 + JWT, PKCE for SPA clients |
| **TLS** | TLS 1.3 minimum, enforce HSTS |

### 8.2 Performance Requirements

| Metric | Target |
|--------|--------|
| API p95 response time | < 200ms (read operations) |
| API p99 response time | < 500ms |
| Loss event list load | < 300ms (100 records) |
| Issues ageing report | < 2s (full calculation) |
| Simulation (10K iterations) | < 10s |
| Simulation (100K iterations) | < 60s |
| Simulation (500K iterations) | < 5 minutes |
| Regulatory report generation | < 30s |
| Database query p95 | < 50ms |
| Concurrent users | 500 simultaneous |

### 8.3 Data Residency (Nigerian Regulatory Requirement)

Per **NDPA 2023 §44** and **CBN Data Localisation Policy 2023**:

- All customer PII and financial transaction data must be stored on servers located within Nigeria or in jurisdictions approved by NITDA.
- Cross-border data transfer requires adequate safeguards per NDPA 2023 §43.
- Cloud providers must confirm Nigerian data centre availability (AWS Lagos af-south-1, Azure South Africa + Nigeria regions, or local providers such as MainOne, Galaxy Backbone).

```env
# Required environment configuration
DATABASE_HOST=postgres.grc-platform.ng         # Nigeria-hosted
STORAGE_REGION=af-south-1                       # AWS Lagos
STORAGE_BUCKET=grc-platform-ng-prod
ENCRYPTION_KEY_REGION=af-south-1               # KMS keys must be in-region
NDPA_DATA_RESIDENCY_CONFIRMED=true
NITDA_REGISTRATION_NUMBER=NITDA/2024/GRC/00234
```

### 8.4 Availability & Recovery

| Metric | Target |
|--------|--------|
| Uptime SLA | 99.9% (excluding scheduled maintenance) |
| Scheduled maintenance window | 00:00–04:00 WAT Sundays |
| RTO (Recovery Time Objective) | 4 hours |
| RPO (Recovery Point Objective) | 15 minutes |
| Database backups | Every 6 hours, retained 90 days |
| Audit log retention | 7 years (CBN regulatory requirement) |
| Loss event record retention | 10 years (BOFIA 2020 Schedule 3) |

### 8.5 Numerical Precision — Currency

```javascript
// MANDATORY: All monetary storage in kobo (integer)
// Prevents floating-point errors in financial calculations

const KOBO_MULTIPLIER = 100;

function ngnToKobo(ngnValue: number): number {
  return Math.round(ngnValue * KOBO_MULTIPLIER);
}

function koboToNgn(koboValue: number): number {
  return koboValue / KOBO_MULTIPLIER;
}

function formatNgn(koboValue: number): string {
  const ngn = koboToNgn(koboValue);
  return new Intl.NumberFormat('en-NG', {
    style: 'currency',
    currency: 'NGN',
    minimumFractionDigits: 2
  }).format(ngn); // → "₦4,250,000.00"
}
```

### 8.6 Simulation Engine Architecture

```
                ┌──────────────────────────────────┐
                │         API Layer (Node.js)        │
                └────────────────┬─────────────────┘
                                 │ POST /simulations
                                 ▼
                ┌──────────────────────────────────┐
                │     Redis Queue (simulation jobs)  │
                └────────────────┬─────────────────┘
                                 │
                    ┌────────────▼──────────────┐
                    │  Python Worker Process     │
                    │  (NumPy / SciPy)           │
                    │                            │
                    │  1. Load scenarios from DB  │
                    │  2. Sample frequency dist.  │
                    │  3. Sample severity dist.   │
                    │  4. Apply copula for corr.  │
                    │  5. Aggregate losses        │
                    │  6. Calculate percentiles   │
                    │  7. Persist results to DB   │
                    └────────────────────────────┘

# Python simulation core (pseudocode)
import numpy as np
from scipy import stats
from scipy.stats import norm, lognorm

def run_monte_carlo(scenarios, iterations=100000, correlation_matrix=None):
    n_scenarios = len(scenarios)

    # Generate correlated uniform samples via Gaussian copula
    if correlation_matrix is not None:
        L = np.linalg.cholesky(correlation_matrix)
        z = np.random.standard_normal((iterations, n_scenarios))
        correlated_z = z @ L.T
        u = norm.cdf(correlated_z)  # Uniform [0,1] correlated samples
    else:
        u = np.random.uniform(0, 1, (iterations, n_scenarios))

    aggregate_losses = np.zeros(iterations)

    for i, scenario in enumerate(scenarios):
        # Sample frequency (Poisson)
        freq_lambda = scenario['frequency_lambda']
        frequencies = np.random.poisson(freq_lambda, iterations)

        # Sample severity (LogNormal) per occurrence
        mu = scenario['severity_mu']
        sigma = scenario['severity_sigma']
        total_scenario_loss = np.zeros(iterations)

        for j in range(iterations):
            if frequencies[j] > 0:
                severities = lognorm.ppf(u[j, i], s=sigma, scale=np.exp(mu))
                total_scenario_loss[j] = severities * frequencies[j]  # simplified

        aggregate_losses += total_scenario_loss

    return {
        'mean': np.mean(aggregate_losses),
        'var_95': np.percentile(aggregate_losses, 95),
        'var_99': np.percentile(aggregate_losses, 99),
        'var_99_9': np.percentile(aggregate_losses, 99.9),
        'percentiles': np.percentile(aggregate_losses, range(1, 100))
    }
```

---

## 9. Security & Compliance

### 9.1 Authentication & Authorisation

```yaml
# Role-Based Access Control (RBAC) Matrix

roles:
  - SYSTEM_ADMIN
  - CRO                           # Chief Risk Officer
  - HEAD_OF_RISK
  - RISK_ANALYST
  - RISK_OFFICER
  - HEAD_OF_COMPLIANCE
  - COMPLIANCE_OFFICER
  - INTERNAL_AUDIT_HEAD
  - INTERNAL_AUDITOR
  - EXCO_MEMBER
  - BOARD_MEMBER                  # Read-only, board reports
  - BUSINESS_UNIT_RISK_OWNER      # Own BU data only
  - REGULATOR_VIEW                # CBN supervisor read-only portal access

permissions:
  loss_events:
    create:        [RISK_OFFICER, RISK_ANALYST, HEAD_OF_RISK, CRO]
    read_own_bu:   [BUSINESS_UNIT_RISK_OWNER, RISK_OFFICER]
    read_all:      [HEAD_OF_RISK, CRO, INTERNAL_AUDIT_HEAD, INTERNAL_AUDITOR, EXCO_MEMBER, BOARD_MEMBER]
    approve:       [HEAD_OF_RISK, CRO, EXCO_MEMBER]
    cbn_notify:    [HEAD_OF_RISK, CRO]
    nfiu_file:     [HEAD_OF_COMPLIANCE, COMPLIANCE_OFFICER, CRO]
    delete:        []              # No deletion — soft delete only, CRO + SYSTEM_ADMIN

  issues:
    create:        [RISK_OFFICER, RISK_ANALYST, HEAD_OF_RISK, CRO, INTERNAL_AUDITOR, COMPLIANCE_OFFICER]
    update_own:    [BUSINESS_UNIT_RISK_OWNER]
    read_all:      [HEAD_OF_RISK, CRO, INTERNAL_AUDIT_HEAD, EXCO_MEMBER, BOARD_MEMBER]
    close:         [HEAD_OF_RISK, CRO, INTERNAL_AUDIT_HEAD]
    escalate:      [HEAD_OF_RISK, CRO]
    regulatory_file: [HEAD_OF_COMPLIANCE, CRO]

  quantification:
    view_results:  [RISK_ANALYST, HEAD_OF_RISK, CRO, INTERNAL_AUDIT_HEAD, EXCO_MEMBER, BOARD_MEMBER]
    build_scenarios: [RISK_ANALYST, HEAD_OF_RISK, CRO]
    run_simulation: [HEAD_OF_RISK, CRO]
    edit_parameters: [CRO]         # CRO approval required for parameter changes
    approve_icaap:  [CRO]          # CRO approves for ICAAP submission
    submit_cbn:     [CRO]          # CRO submits to CBN
    edit_settings:  [SYSTEM_ADMIN] # System admin only for threshold changes
```

### 9.2 NDPA 2023 Data Protection Compliance

Per **Nigeria Data Protection Act 2023** requirements:

```typescript
// PII Field Encryption at Rest
const PII_FIELDS = [
  'responsible_officer_name',
  'owner_name',
  'police_report_ref',
  'customer_names_affected'    // If storing affected customer data
];

// Encryption: AES-256-GCM using institution-specific KMS key
// Key rotation: every 365 days per NDPA 2023 §21

// Data Subject Rights Implementation (NDPA 2023 §34–39)
// Note: Loss event records and regulatory filings are exempt from
// right-to-erasure per NDPA 2023 §35(3)(b) — regulatory purposes

// Consent logging for non-regulatory data processing
CREATE TABLE data_processing_records (
  id              UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  institution_id  UUID NOT NULL,
  processing_purpose VARCHAR(200) NOT NULL,
  legal_basis     VARCHAR(50)  CHECK (legal_basis IN (
                    'REGULATORY_OBLIGATION',   -- CBN, BOFIA, AML/CFT — no consent needed
                    'LEGITIMATE_INTEREST',
                    'CONSENT',
                    'VITAL_INTERESTS'
                  )),
  data_categories TEXT[],
  retention_period_years INTEGER,
  nitda_registration VARCHAR(100),   -- NITDA data processor registration
  created_at      TIMESTAMP NOT NULL DEFAULT NOW()
);
```

### 9.3 Audit Trail Requirements

Every mutating API call must log to the immutable audit table:

```sql
CREATE TABLE audit_trail (
  id              BIGSERIAL   PRIMARY KEY,  -- Sequential for tamper detection
  institution_id  UUID        NOT NULL,
  table_name      VARCHAR(100) NOT NULL,
  record_id       UUID        NOT NULL,
  record_ref      VARCHAR(30),
  action          VARCHAR(20) NOT NULL CHECK (action IN (
                    'INSERT', 'UPDATE', 'DELETE', 'VIEW_SENSITIVE', 'EXPORT', 'REGULATORY_SUBMIT'
                  )),
  changed_fields  JSONB,      -- {field: {old: val, new: val}}
  old_values      JSONB,
  new_values      JSONB,
  user_id         UUID        NOT NULL,
  user_name       VARCHAR(200),
  user_role       VARCHAR(50),
  ip_address      INET,
  session_id      VARCHAR(200),
  request_id      VARCHAR(200),
  user_agent      TEXT,
  regulatory_action BOOLEAN   DEFAULT FALSE, -- Flag CBN/NFIU/NDPC submissions
  created_at      TIMESTAMP   NOT NULL DEFAULT NOW()
);

-- Append-only: no updates or deletes on audit_trail
CREATE RULE no_audit_update AS ON UPDATE TO audit_trail DO INSTEAD NOTHING;
CREATE RULE no_audit_delete AS ON DELETE TO audit_trail DO INSTEAD NOTHING;
```

**Retention:** 7 years minimum per CBN requirements. NDPC breach records: 5 years per NDPA 2023.

### 9.4 API Rate Limiting

```
Standard endpoints:     100 requests/minute per user
Simulation runs:        5 concurrent per institution
Regulatory exports:     10 requests/hour per institution
Bulk imports:           5 requests/hour per user
```

---

## 10. Implementation Guide

### 10.1 Prerequisites Checklist

Before beginning implementation, ensure the following are in place:

```
Infrastructure:
  ☐ PostgreSQL 15+ instance provisioned in Nigerian data centre (af-south-1 or equivalent)
  ☐ Redis 7.x instance configured for queue and cache
  ☐ Object storage bucket configured (Nigerian region)
  ☐ KMS encryption keys created in-region
  ☐ Python 3.11+ worker environment for simulation engine
  ☐ SSL/TLS certificates issued (production domain)

Regulatory:
  ☐ NITDA data processor registration obtained
  ☐ CBN institution code confirmed (NGN/XXX/XXXX format)
  ☐ NDIC member number confirmed
  ☐ NFIU reporting entity ID registered
  ☐ NDPA 2023 Data Protection Impact Assessment (DPIA) completed
  ☐ Data processing records documented per NDPA 2023 §26

Integration (Existing Modules):
  ☐ Risk Register API v1 accessible and tested
  ☐ Audit Management API v1 accessible and tested
  ☐ Users & Permissions module configured with new RBAC roles
  ☐ Business units and branches seeded with CBN codes
  ☐ Institutions table populated with regulatory identifiers
```

### 10.2 Phase 1: Loss Event Management (Weeks 1–6)

#### Week 1–2: Database & Core API

```bash
# Step 1: Run migrations (in order)
psql -h $DB_HOST -U $DB_USER -d $DB_NAME \
  -f migrations/001_create_loss_events.sql \
  -f migrations/002_create_loss_event_controls.sql \
  -f migrations/003_create_loss_event_attachments.sql \
  -f migrations/004_create_loss_event_rca.sql \
  -f migrations/005_create_rca_remediation_actions.sql \
  -f migrations/006_create_near_misses.sql \
  -f migrations/007_create_loss_event_approval_workflow.sql \
  -f migrations/008_create_domain_events.sql

# Step 2: Seed Basel L1/L2 category reference data
psql -f seeds/001_basel_categories.sql
psql -f seeds/002_cbn_risk_categories.sql
psql -f seeds/003_nfiu_thresholds.sql

# Step 3: Configure escalation rules per institution
psql -f seeds/004_default_escalation_rules.sql

# Step 4: Run tests
npm run test:loss-events -- --coverage
```

#### Nigerian Regulatory Auto-Detection Logic

Implement as a service called on every `POST /v1/loss-events`:

```typescript
// services/regulatoryClassifier.ts

interface RegulatoryFlags {
  cbn_reportable: boolean;
  cbn_reporting_deadline: Date | null;
  nfiu_reportable: boolean;
  nfiu_report_type: 'STR' | 'CTR' | 'BOTH' | null;
  nfiu_threshold_triggered: string | null;
  bofia_reportable: boolean;
  bofia_section_applicable: string | null;
  ndic_reportable: boolean;
}

function classifyRegulatoryRequirements(event: LossEventInput): RegulatoryFlags {
  const flags: RegulatoryFlags = {
    cbn_reportable: false,
    cbn_reporting_deadline: null,
    nfiu_reportable: false,
    nfiu_report_type: null,
    nfiu_threshold_triggered: null,
    bofia_reportable: false,
    bofia_section_applicable: null,
    ndic_reportable: false
  };

  // CBN Reporting: Loss ≥ ₦5M gross (500000000 kobo)
  // Reference: CBN BSD/DIR/GEN/LAB/07/014
  if (event.gross_loss_kobo >= 500000000) {
    flags.cbn_reportable = true;
    // 7 calendar days from date of discovery
    flags.cbn_reporting_deadline = addDays(event.date_discovered, 7);
  }

  // NFIU STR: Any fraud/suspicious activity regardless of amount
  // Reference: AML/CFT Act 2022 §6(1); NFIU Regulations 2024 §4.2
  if (['INTERNAL_FRAUD', 'EXTERNAL_FRAUD'].includes(event.basel_l1_category)) {
    flags.nfiu_reportable = true;
    flags.nfiu_report_type = 'STR';
    flags.nfiu_threshold_triggered = 'SUSPICIOUS_PATTERN';
  }

  // NFIU CTR: Cash transaction ≥ ₦5M single / ₦10M daily aggregate
  // Reference: NFIU Regulations 2024 §3.1
  if (event.involves_cash && event.gross_loss_kobo >= 500000000) {
    flags.nfiu_reportable = true;
    flags.nfiu_report_type = flags.nfiu_report_type === 'STR' ? 'BOTH' : 'CTR';
    flags.nfiu_threshold_triggered = 'CASH_5M_NGN';
  }

  // BOFIA 2020 — Section 24: Related-party exposure
  // Reference: BOFIA 2020 §24 — aggregate credit to related parties ≤ 5% capital
  if (event.involves_related_party) {
    flags.bofia_reportable = true;
    flags.bofia_section_applicable = 'SECTION_24_RELATED_PARTY';
  }

  // NDIC: Deposit insurance threshold (₦500K exposure to insured deposits)
  // Reference: NDIC Act 2023 §41
  if (event.gross_loss_kobo >= 50000000 &&
      event.basel_l1_category === 'EXECUTION_DELIVERY_PROCESS_MANAGEMENT') {
    flags.ndic_reportable = true;
  }

  return flags;
}
```

#### Week 3–4: RCA & Near-Miss Modules

```typescript
// Automated near-miss → issue conversion
// Triggered when near_miss severity = 'CRITICAL' and control_gap_identified = true

async function convertNearMissToIssue(nearMissId: string): Promise<Issue> {
  const nearMiss = await getNearMiss(nearMissId);

  return await createIssue({
    title: `Near-Miss Control Gap: ${nearMiss.title}`,
    description: nearMiss.description,
    criteria_violated: 'Near-miss investigation revealed unmitigated control weakness per CBN ORM Framework 2016 §6.4',
    issue_source: 'NEAR_MISS',
    issue_category: 'CONTROL_FAILURE',
    priority: nearMiss.severity as Priority,
    near_miss_id: nearMissId,
    potential_loss_kobo: nearMiss.potential_loss_kobo,
    remediation_due_date: addDays(new Date(), 30)
  });
}
```

#### Week 5–6: Approval Workflow & CBN Reporting

```typescript
// Automated CBN deadline monitoring (cron: every 6 hours)
async function checkCbnDeadlines(): Promise<void> {
  const overdueNotifications = await db.query(`
    SELECT id, event_reference, cbn_reporting_deadline, title,
           gross_loss_amount_kobo
    FROM loss_events
    WHERE cbn_reportable = TRUE
      AND cbn_notification_sent = FALSE
      AND cbn_reporting_deadline <= CURRENT_DATE + INTERVAL '1 day'
      AND deleted_at IS NULL
  `);

  for (const event of overdueNotifications.rows) {
    // Create critical issue
    await createIssue({
      title: `URGENT: CBN Notification Overdue — ${event.event_reference}`,
      description: `Loss event ${event.event_reference} (${formatNgn(event.gross_loss_amount_kobo)}) requires CBN notification per BSD/DIR/GEN/LAB/07/014. Deadline: ${event.cbn_reporting_deadline}.`,
      issue_source: 'LOSS_EVENT',
      issue_category: 'REGULATORY_NON_COMPLIANCE',
      priority: 'CRITICAL',
      loss_event_id: event.id,
      cbn_reportable: true,
      remediation_due_date: event.cbn_reporting_deadline
    });

    // Escalate to CRO
    await escalateIssue(event.id, 'CRO', 'CBN notification deadline breach');

    // Send notification to CRO and Head of Compliance
    await notificationService.sendUrgent({
      template: 'CBN_DEADLINE_BREACH',
      recipients: ['CRO', 'HEAD_OF_COMPLIANCE'],
      data: event
    });
  }
}
```

---

### 10.3 Phase 2: Issues & Findings Tracker (Weeks 5–9)

#### Week 5–6: Core Schema & CRUD

```bash
# Migrations
psql -f migrations/009_create_issues.sql
psql -f migrations/010_create_issue_remediation_actions.sql
psql -f migrations/011_create_issue_progress_updates.sql
psql -f migrations/012_create_issue_attachments.sql
psql -f migrations/013_create_issue_escalation_log.sql
psql -f migrations/014_create_issue_escalation_rules.sql
psql -f seeds/005_default_issue_escalation_rules.sql
psql -f seeds/006_nigerian_regulatory_sources.sql
```

#### Week 7: Auto-Escalation Engine

```typescript
// Cron: every 30 minutes
async function runEscalationEngine(): Promise<void> {
  const rules = await getEscalationRules(); // Per institution, per priority

  const overdueIssues = await db.query(`
    SELECT i.id, i.issue_reference, i.priority, i.days_overdue,
           i.issue_source, i.current_escalation_level, i.institution_id
    FROM issues i
    WHERE i.issue_status NOT IN ('CLOSED', 'WITHDRAWN')
      AND i.is_overdue = TRUE
      AND i.deleted_at IS NULL
  `);

  for (const issue of overdueIssues.rows) {
    const rule = rules[issue.priority];

    // CBN examination findings get accelerated escalation
    const isCbnExam = issue.issue_source === 'CBN_EXAMINATION';

    let targetLevel: string | null = null;

    if (isCbnExam) {
      if (issue.days_overdue >= rule.cbn_exam_days_to_exco) targetLevel = 'EXCO';
      else if (issue.days_overdue >= rule.cbn_exam_days_to_cro) targetLevel = 'CRO';
    } else {
      if (issue.days_overdue >= rule.days_to_escalate_board) targetLevel = 'BOARD_RISK_COMMITTEE';
      else if (issue.days_overdue >= rule.days_to_escalate_exco) targetLevel = 'EXCO';
      else if (issue.days_overdue >= rule.days_to_escalate_cro) targetLevel = 'CRO';
      else if (issue.days_overdue >= rule.days_to_escalate_hod) targetLevel = 'HEAD_OF_BU';
    }

    if (targetLevel && targetLevel !== issue.current_escalation_level) {
      await escalateIssue(issue.id, targetLevel, `Auto-escalated: ${issue.days_overdue} days overdue`);
    }
  }
}
```

#### Week 8–9: NDPA 2023 Breach Notification Flow

```typescript
// NDPA 2023 §40 — mandatory breach notification to NDPC
// Severe breach: 72 hours; Others: 7 days

async function handleNdpaBreachIssue(issueId: string, breachData: NdpaBreachData): Promise<void> {
  const { breach_type, data_subjects_affected, severity } = breachData;

  // Calculate NDPC notification deadline per NDPA 2023 §40(3)
  const deadlineHours = severity === 'HIGH_RISK' ? 72 : 168; // 72h or 7 days
  const ndpc_deadline = addHours(new Date(), deadlineHours);

  await updateIssue(issueId, {
    ndpa_reportable: true,
    ndpa_breach_type: breach_type,
    ndpc_notification_required: true,
    ndpc_notification_deadline: ndpc_deadline,
    data_subjects_affected,
    regulatory_reportable: true
  });

  // Create sub-issue for NDPC notification if not already created
  await createIssue({
    title: `NDPA 2023 §40 — NDPC Breach Notification Required (${breach_type})`,
    description: `Personal data breach affecting ${data_subjects_affected} data subjects. NDPC must be notified by ${ndpc_deadline.toISOString()}.`,
    issue_category: 'NDPA_BREACH',
    priority: severity === 'HIGH_RISK' ? 'CRITICAL' : 'HIGH',
    issue_source: 'NDPA_ASSESSMENT',
    cbn_reportable: data_subjects_affected > 1000, // CBN notification for large-scale breaches
    ndpc_notification_deadline: ndpc_deadline,
    remediation_due_date: ndpc_deadline
  });
}
```

---

### 10.4 Phase 3: Risk Quantification Engine (Weeks 8–14)

#### Week 8–9: Schema, Scenario Builder

```bash
# Migrations
psql -f migrations/015_create_quantification_scenarios.sql
psql -f migrations/016_create_simulation_runs.sql
psql -f migrations/017_create_simulation_results.sql
psql -f migrations/018_create_icaap_assessments.sql
psql -f migrations/019_create_quantification_settings.sql

# Seed CBN pre-built scenarios (ICAAP Guidance Note 2023)
psql -f seeds/007_cbn_prebuilt_scenarios.sql
psql -f seeds/008_ndic_industry_scenarios.sql
psql -f seeds/009_default_quantification_settings.sql
```

#### Week 10–11: Monte Carlo Simulation Worker

```bash
# Python worker setup
pip install numpy scipy pandas psycopg2-binary redis celery

# Start worker
celery -A simulation_worker worker --loglevel=info --concurrency=4 \
  --queues=simulation_high,simulation_standard
```

#### Week 12: ICAAP Integration & CBN Export

```typescript
// ICAAP auto-populate from simulation results
async function populateIcaapFromSimulation(
  icaapPeriod: string,
  simulationRunId: string
): Promise<void> {
  const results = await getSimulationResults(simulationRunId, 'AGGREGATE');

  await updateIcaap(icaapPeriod, {
    // Pillar 2 requirement from 99.9th percentile VaR
    pillar2a_operational_addon_kobo: Math.max(
      0,
      results.var_99_9.value_kobo - (await getPillar1OperationalRwa() * 0.10)
    ),
    pillar2b_buffer_kobo: results.stress_results.cbn_severe_scenario_b
      ? Math.max(0, results.stress_results.cbn_severe_scenario_b.var_99_9_kobo - results.var_99_9.value_kobo)
      : 0,
    primary_simulation_run_id: simulationRunId,
    section_pillar2a_complete: true,
    section_stress_testing_complete: true
  });
}
```

#### Week 13–14: Integration Testing & UAT

```bash
# Integration test suite
npm run test:integration -- \
  --testPathPattern="loss-events|issues|quantification" \
  --coverage \
  --coverageThreshold='{"global":{"lines":85}}'

# Key integration scenarios to test:
# 1. Loss event created → CBN notification auto-triggered → Issue auto-created
# 2. Loss event RCA → Remediation actions → Issues auto-created
# 3. Issue CBN exam finding → Accelerated escalation → EXCO alert
# 4. Near-miss Critical → Control gap → Issue auto-raised
# 5. Simulation complete → ICAAP sections populated → Capital adequacy alert
# 6. Stress test CAR < 10% → Critical issue auto-created → CRO notified
# 7. NFIU STR overdue → Escalation → Regulatory dashboard alert
# 8. NDPA breach → 72h deadline → NDPC notification workflow
```

---

### 10.5 Nigerian Regulatory Go-Live Checklist

Before production deployment at any CBN-regulated institution:

```
CBN Compliance:
  ☐ CBN ORMS portal API credentials obtained
  ☐ CBN notification email/portal endpoint configured
  ☐ ORMS monthly return template validated against latest CBN BSD template
  ☐ CBN institution code embedded in all regulatory outputs
  ☐ ICAAP template validated against CBN ICAAP Guidance Note 2023
  ☐ CBN examination finding escalation rules reviewed with CRO

NFIU / AML/CFT:
  ☐ NFIU reporting entity ID configured
  ☐ STR threshold (₦5M) and CTR threshold (₦10M) validated
  ☐ AML/CFT Act 2022 §6(1) 24-hour STR deadline automated
  ☐ NFIU portal integration tested (or manual filing workflow confirmed)

NDPA 2023:
  ☐ NITDA registration number recorded in system
  ☐ Data Processing Impact Assessment (DPIA) completed for each module
  ☐ 72-hour NDPC notification automation tested
  ☐ Data residency confirmed (all data in Nigeria)
  ☐ PII field encryption verified (AES-256-GCM)

BOFIA 2020:
  ☐ BOFIA section mapping reviewed with legal counsel
  ☐ Section 24 (related-party) monitoring thresholds configured
  ☐ Section 14 (capital) breach auto-detection tested

NDIC:
  ☐ NDIC member number configured
  ☐ NDIC reporting thresholds validated
  ☐ Quarterly NDIC report format tested

Operational:
  ☐ All user roles configured per RBAC matrix
  ☐ Escalation rules reviewed and approved by CRO
  ☐ Audit trail retention policy confirmed (7 years)
  ☐ Backup and recovery tested (RPO 15min confirmed)
  ☐ Staff training completed for all three modules
  ☐ Parallel running period agreed (minimum 1 month with existing processes)
```

---

### 10.6 Environment Configuration

```env
# ── Application ────────────────────────────────────────────────
NODE_ENV=production
API_BASE_URL=https://api.grc-platform.ng/v1
FRONTEND_URL=https://app.grc-platform.ng

# ── Database ────────────────────────────────────────────────────
DATABASE_URL=postgresql://grc_user:****@postgres.grc-platform.ng:5432/grc_prod
DATABASE_POOL_MIN=5
DATABASE_POOL_MAX=30
DATABASE_REGION=af-south-1

# ── Redis ────────────────────────────────────────────────────────
REDIS_URL=redis://redis.grc-platform.ng:6379
REDIS_TLS=true

# ── Storage (Nigerian Region) ────────────────────────────────────
AWS_REGION=af-south-1
AWS_S3_BUCKET=grc-platform-ng-prod
AWS_KMS_KEY_ID=arn:aws:kms:af-south-1:ACCOUNT:key/KEY-ID

# ── Authentication ───────────────────────────────────────────────
JWT_SECRET=<512-bit-secret>
JWT_EXPIRY=8h
REFRESH_TOKEN_EXPIRY=7d
MFA_REQUIRED=true

# ── Nigerian Regulatory ──────────────────────────────────────────
CBN_INSTITUTION_CODE=NGN/COM/0089
CBN_ORMS_API_URL=https://orms.cbn.gov.ng/api/v1
CBN_ORMS_API_KEY=<cbn-orms-key>
CBN_REPORTING_THRESHOLD_KOBO=500000000

NFIU_REPORTING_ENTITY_ID=NFIU-RE-0089
NFIU_PORTAL_URL=https://goaml.nfiu.gov.ng/api
NFIU_API_KEY=<nfiu-key>
NFIU_STR_THRESHOLD_KOBO=500000000
NFIU_CTR_THRESHOLD_KOBO=1000000000

NDIC_MEMBER_NUMBER=NDIC-COM-0089-2024
NITDA_REGISTRATION=NITDA/2024/GRC/00234
NDPA_DATA_RESIDENCY_CONFIRMED=true

# ── Simulation Engine ────────────────────────────────────────────
SIMULATION_WORKER_URL=http://simulation-worker:8001
SIMULATION_QUEUE=simulation_standard
SIMULATION_MAX_CONCURRENT=5
CBN_MINIMUM_CAR=0.10
CBN_MPR=0.275

# ── Notifications ────────────────────────────────────────────────
SMTP_HOST=smtp.grc-platform.ng
SMS_PROVIDER_URL=https://api.termii.com/api    # Nigerian SMS provider
SMS_API_KEY=<termii-key>
NOTIFICATION_FROM_EMAIL=noreply@grc-platform.ng
```

---

### 10.7 Database Migration Strategy

```sql
-- Migration versioning table
CREATE TABLE schema_migrations (
  version     VARCHAR(20) PRIMARY KEY,  -- e.g. '001', '002'
  description VARCHAR(500),
  applied_at  TIMESTAMP NOT NULL DEFAULT NOW(),
  applied_by  VARCHAR(200)
);

-- Migration 001: Loss Events core
-- Migration 002: Loss Event Controls
-- Migration 003: Loss Event Attachments
-- Migration 004: RCA
-- Migration 005: Remediation Actions
-- Migration 006: Near Misses
-- Migration 007: Approval Workflow
-- Migration 008: Domain Events
-- Migration 009: Issues core
-- Migration 010: Issue Remediation Actions
-- Migration 011: Issue Progress Updates
-- Migration 012: Issue Attachments
-- Migration 013: Issue Escalation Log
-- Migration 014: Escalation Rules
-- Migration 015: Quantification Scenarios
-- Migration 016: Simulation Runs
-- Migration 017: Simulation Results
-- Migration 018: ICAAP Assessments
-- Migration 019: Quantification Settings
-- Migration 020: Audit Trail
-- Migration 021: Domain Events indexes
-- Migration 022: Regulatory reporting views
```

---

*End of Technical Specification*

---

**Document:** GRC_Gap_Modules_Technical_Spec.md  
**Version:** 1.0.0  
**Platform:** Nigerian Financial Services GRC Solution — Risk Management Module  
**Regulatory Frameworks Covered:** CBN · BOFIA 2020 · NDPA 2023 · AML/CFT Act 2022 · NFIU · NDIC · SEC Nigeria · Basel III/IV  
**Currency:** Nigerian Naira (NGN / ₦) throughout  
**February 2026**
