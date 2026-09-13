# 🧠 AI Module Implementation Prompt — IBM Granite LLM Integration

## For: GRC Risk Management — Enterprise & Operational Risk Management Platform
## Date: 2026-03-26 | Version: 1.0
## Classification: Engineering Implementation Brief

---

## MISSION

Replace the current placeholder AI Intelligence module with a production-grade AI engine powered by **IBM Granite LLM models**. This engine must ingest ALL data across the risk platform, deliver real predictions, genuine analysis, and actionable intelligence — transforming our solution from a risk record-keeping system into an **AI-native risk intelligence platform** that no competitor in Nigeria or Africa can match.

**North Star:** Every AI feature must answer real questions with real data. No mock data. No simulated scores. Every prediction traceable to actual risk register entries, loss events, KRI measurements, and control effectiveness data.

---

## PART 1 — CURRENT STATE (What Exists Today)

### 1.1 Current AI Module Architecture

The AI module is a **fully stubbed placeholder** with no actual ML/AI integration:

**Controller:** `App\Http\Controllers\Risk\AiIntelligenceController`
- 4 endpoints: `predictive()`, `radar()`, `regulatoryPulse()`, `benchmarking()`
- All delegate to `AiDataService` which returns hardcoded/simulated data

**Service:** `App\Services\AiDataService`
- `getPredictiveAnalytics($orgId)` — Returns fake 12-month trends with random sine-wave variation, hardcoded escalation probabilities [0.87, 0.73, 0.68...], fake model metrics (accuracy: 87.3%)
- `getRiskRadar($orgId)` — Returns 8 static emerging risks (AI/ML Model Risk, eNaira Disruption, etc.) with hardcoded severity/velocity
- `getRegulatoryPulse($orgId)` — Returns 6 static regulatory updates with hardcoded compliance statuses
- `getBenchmarking($orgId)` — Mixes some real DB queries (risk counts, loss amounts) with hardcoded industry benchmarks

**Views:** 4 Blade templates with Chart.js visualizations
- `risk/ai/predictive.blade.php` — Line chart + escalation table + early warnings
- `risk/ai/radar.blade.php` — Radar chart + velocity indicators + signal cards
- `risk/ai/regulatory-pulse.blade.php` — Regulatory update cards + compliance progress
- `risk/ai/benchmarking.blade.php` — Radar comparison + metrics table + peer ranking

**Routes:**
```
GET /risk/ai/predictive          → risk.ai.predictive
GET /risk/ai/radar               → risk.ai.radar
GET /risk/ai/regulatory-pulse    → risk.ai.regulatory-pulse
GET /risk/ai/benchmarking        → risk.ai.benchmarking
```

### 1.2 Data Available for AI Consumption

The platform has rich data across 36 models. Here is every data source the AI engine must consume:

| Data Source | Model | Key Fields for AI | Volume Indicator |
|------------|-------|-------------------|-----------------|
| **Risk Register** | `Risk` | inherent/residual scores, ratings, velocity, appetite_aligned, treatment_strategy, financial_exposure_ngn, tags, identified_date, last_assessment_date | Core — all risks |
| **Risk Assessments** | `RiskAssessment` | likelihood_score, impact scores (financial/operational/reputational/regulatory/strategic), direction_of_travel, assessment_date, control_effectiveness_rating | Historical assessments per risk |
| **Controls** | `Control` | control_type, nature, frequency, automation_level, effectiveness_rating, effectiveness_pct, last_test_date, status | Control library |
| **Risk-Control Mapping** | `RiskControlMapping` | control_weight, is_key_control, mapping_rationale | Which controls mitigate which risks |
| **Treatment Plans** | `TreatmentPlan` | strategy, priority, status, progress_pct, cost_estimate_ngn, actual_cost_ngn, expected/actual_risk_reduction, target_date, completion_date | Treatment effectiveness data |
| **KRI Definitions** | `KeyRiskIndicator` | metric_formula, measurement_frequency, baseline_value, green/amber/red thresholds, current_value, current_status, trend_direction, is_automated | Leading indicators |
| **KRI Measurements** | `KriMeasurement` | measured_value, status, measured_at | Time-series indicator data |
| **Loss Events** | `LossEvent` | gross_loss_amount_kobo, recovery amounts, basel categories, cbn_risk_category, event_severity, operational_disruption_hrs, customers_affected, date_of_loss | Loss history |
| **Loss Event RCA** | `LossEventRca` | root_cause_description, immediate_causes, underlying_causes, human_factors, procedural_gaps, technical_failures | Causal analysis text |
| **Near Misses** | `NearMiss` | Similar to loss events but unrealized | Leading indicator of potential losses |
| **Issues & Findings** | `Issue` | priority, issue_status, issue_source, regulatory_reportable, remediation_due_date, actual_close_date, potential_loss_kobo | Audit findings and gaps |
| **Issue Remediation** | `IssueRemediationAction` | action, status, due_date, completed_date | Remediation tracking |
| **Risk Appetite** | `RiskAppetite` | appetite_level, tolerance_level, actual_level, breach_status | Appetite vs actual |
| **Quantification Scenarios** | `QuantificationScenario` | probability, loss_distribution, parameters | Scenario modeling inputs |
| **Simulation Results** | `SimulationRun` + `SimulationResult` | VaR_95, VaR_99, expected_shortfall, sample values | Monte Carlo outputs |
| **ICAAP** | `IcaapAssessment` | CAR, Tier 1/2 capital, pillar 2a/2b allocations | Capital adequacy |
| **Risk Categories** | `RiskCategory` | Full taxonomy hierarchy | Classification structure |
| **Scoping Entities** | `Entity` + `EntityType` | Organizational structure | Org hierarchy |
| **Business Units** | `BusinessUnit` | Departmental structure | Organizational mapping |
| **Business Processes** | `BusinessProcess` | Process catalog | Process risk context |
| **Audit Trail** | `RiskAuditTrail` | All changes to all entities with timestamps | Behavioral patterns |
| **Domain Events** | `DomainEvent` | Event sourcing log | System activity patterns |
| **Users** | `User` | roles, last_activity, department | User behavior context |

---

## PART 2 — IBM GRANITE MODEL SELECTION

### 2.1 Model Stack

Deploy a multi-model Granite stack where each model handles what it does best:

| Purpose | Model | Why This Model | Deployment |
|---------|-------|---------------|------------|
| **Core Intelligence** (RAG, analysis, report generation, risk narratives) | **Granite 3.2-8B-Instruct** or **Granite 4.0-H-Small** | 128K context window, strong reasoning with CoT toggle, enterprise-optimized for compliance text | Local via Ollama or Docker |
| **Time-Series Forecasting** (KRI trends, loss prediction, risk score trajectories) | **Granite TinyTimeMixer (TTM)** | <1M params, outperforms billion-param models on forecasting, runs on CPU, zero-shot capable | Python microservice |
| **Anomaly Detection** (KRI breaches, loss event patterns, unusual risk movements) | **Granite TSPulse** | Ultra-lightweight, purpose-built for anomaly detection and classification in time-series | Python microservice |
| **Semantic Search & RAG** (regulatory document search, policy lookup, risk similarity) | **Granite Embedding R2** (149M params) | 8192-token context, ModernBERT-based, optimized for retrieval | Local via Python |
| **Content Safety** (validate AI outputs for compliance, prevent hallucination in reports) | **Granite Guardian 3.2-3B** | Purpose-built for enterprise content safety, detects harmful/non-compliant AI outputs | Local via Ollama |

### 2.2 Why Granite Over Alternatives

| Factor | Granite | GPT-4/Claude API | Llama 3 |
|--------|---------|-------------------|---------|
| **Data Privacy** | Fully local — zero data leaves the server | Data sent to external APIs | Local possible but heavy |
| **License** | Apache 2.0 — fully commercial | Proprietary, pay-per-token | Meta custom license with restrictions |
| **Regulatory Auditability** | ISO 42001 certified, full data provenance | Black box training data | No certification |
| **Cost** | One-time GPU investment, no per-token fees | $0.01-$0.06 per 1K tokens adds up fast | Free but requires expensive GPU |
| **Nigerian Regulatory Compliance** | CBN data localization requirements met (on-prem) | Data leaves Nigeria — regulatory risk | Local deployment possible |
| **IP Indemnity** | IBM provides IP protection | Limited | None |
| **Time-Series** | Dedicated TTM/TSPulse models | General-purpose only | No dedicated TS models |
| **Size Efficiency** | 8B params matches 70B performance on enterprise tasks | 175B-1T params | 8B-405B params |

### 2.3 Infrastructure Requirements

```
MINIMUM DEPLOYMENT (Development / Small Institution):
- 1x Server with NVIDIA T4 GPU (16GB VRAM) or equivalent
- 32GB RAM
- 100GB SSD for models + vector store
- Ubuntu 22.04 LTS
- Ollama + Docker

RECOMMENDED DEPLOYMENT (Production / Large Institution):
- 1x Server with NVIDIA A10G (24GB VRAM) or RTX 4090
- 64GB RAM
- 500GB NVMe SSD
- Ubuntu 22.04 LTS
- Ollama + Docker + Redis (caching) + ChromaDB/Qdrant (vector store)

CPU-ONLY DEPLOYMENT (Budget-Conscious):
- Granite 3.2-2B for language tasks (slower but functional)
- TTM and TSPulse run natively on CPU
- Granite Embedding R2-47M for search
- 16GB RAM minimum
- Suitable for smaller institutions
```

---

## PART 3 — FEATURE SPECIFICATIONS

### Feature 1: Predictive Risk Intelligence

**Replace:** Current fake prediction charts with real ML-driven forecasting

**What It Must Do:**

**1A. Risk Score Trajectory Prediction**
- Ingest historical `RiskAssessment` scores for each risk (all assessments over time)
- Use **Granite TTM** to forecast next 3-6 month risk score trajectory per risk
- Display: actual historical line + predicted line with confidence intervals (80%, 95%)
- Retrain/update predictions monthly or when new assessments are submitted

**Implementation:**
```python
# Python microservice endpoint: POST /api/ai/predict-risk-trajectory
# Input: risk_id, historical_scores[], historical_dates[]
# Process: TTM zero-shot or fine-tuned forecast
# Output: predicted_scores[], confidence_upper[], confidence_lower[], dates[]
```

**1B. Risk Escalation Probability**
- For each risk in the register, calculate probability of escalation (rating increase) in next 90 days
- Features to consider:
  - Current inherent/residual score and trend (from assessments)
  - Control effectiveness trend (from control tests)
  - KRI status and trend (from measurements) — if linked KRI is trending red, escalation probability increases
  - Loss event frequency for related category (from loss events)
  - Issue count and overdue remediation actions
  - Treatment plan progress (stalled treatment = higher escalation risk)
  - Risk velocity (from risk register)
  - Time since last assessment (stale assessment = uncertainty)
- Use **Granite 3.2-8B** with structured prompting to reason about escalation likelihood given these features
- Alternatively, train a simple logistic regression/gradient boosting model on historical data where we know which risks DID escalate

**Implementation:**
```python
# POST /api/ai/escalation-probability
# Input: risk_id (or batch of risk_ids)
# Process:
#   1. Gather feature vector from all linked data
#   2. If enough historical data: use trained classifier
#   3. If insufficient data: use Granite LLM with structured prompt + CoT reasoning
# Output: { risk_id, probability, confidence, key_drivers[], recommended_actions[] }
```

**1C. Early Warning System**
- Continuously monitor all KRI measurements, loss events, and assessment scores
- When multiple signals converge (KRI breach + rising loss frequency + overdue issues), generate early warning
- Use **Granite 3.2-8B** to compose a natural-language early warning narrative explaining:
  - What signals are converging
  - What risk(s) are affected
  - Historical precedent (has this pattern occurred before?)
  - Recommended immediate actions
- Trigger notifications to risk owners and CRO

**Implementation:**
```python
# POST /api/ai/early-warning-scan
# Input: organization_id
# Process:
#   1. Query all KRIs in amber/red status
#   2. Query loss events in last 30 days
#   3. Query risks with deteriorating trend
#   4. Query overdue issues and stalled treatments
#   5. Use Granite LLM to analyze convergence and generate narrative
# Output: [{ warning_id, severity, affected_risks[], signals[], narrative, actions[] }]
```

**1D. Model Performance Tracking**
- Track actual predictions vs outcomes to measure model accuracy over time
- Store predictions in a `ai_predictions` table
- When actual outcomes are known (risk DID escalate, KRI DID breach), compare to prediction
- Display real accuracy, precision, recall, F1, AUC-ROC calculated from actual prediction history
- Show model drift detection — alert if accuracy degrades

**Database:**
```sql
CREATE TABLE ai_predictions (
    id BIGINT PRIMARY KEY,
    organization_id BIGINT,
    prediction_type VARCHAR(50),  -- 'risk_escalation', 'kri_breach', 'loss_forecast'
    entity_type VARCHAR(50),
    entity_id BIGINT,
    predicted_value DECIMAL(10,4),
    predicted_at TIMESTAMP,
    confidence DECIMAL(5,4),
    actual_value DECIMAL(10,4) NULL,
    actual_recorded_at TIMESTAMP NULL,
    was_correct BOOLEAN NULL,
    model_version VARCHAR(50),
    features_used JSON,
    created_at TIMESTAMP
);

CREATE TABLE ai_model_metrics (
    id BIGINT PRIMARY KEY,
    organization_id BIGINT,
    model_name VARCHAR(100),
    model_version VARCHAR(50),
    metric_name VARCHAR(50),  -- 'accuracy', 'precision', 'recall', 'f1', 'auc_roc'
    metric_value DECIMAL(10,6),
    sample_size INT,
    evaluated_at TIMESTAMP,
    evaluation_window_days INT
);
```

---

### Feature 2: Emerging Risk Radar (AI-Powered)

**Replace:** Current 8 hardcoded emerging risks with real-time AI-detected emerging risks

**What It Must Do:**

**2A. External Intelligence Gathering**
- Build a **RAG pipeline** that continuously ingests:
  - CBN circulars and press releases (web scraping from cbn.gov.ng)
  - NFIU advisories and directives
  - SEC Nigeria bulletins
  - NDPA enforcement actions
  - Nigerian financial news (BusinessDay, Nairametrics, ThisDay, Punch)
  - Global risk reports (WEF Global Risk Report, Basel Committee publications)
  - Industry reports (KPMG Nigeria, PwC Nigeria, Deloitte Africa risk surveys)
- Store documents in a **vector database** (ChromaDB or Qdrant) using **Granite Embedding R2**
- Index with metadata: source, date, regulator, sector, risk_category

**Implementation:**
```python
# Scheduled job: runs daily at 6 AM
# POST /api/ai/ingest-external-intelligence
# Process:
#   1. Scrape/fetch new documents from configured sources
#   2. Chunk documents into ~512 token segments
#   3. Generate embeddings using Granite Embedding R2
#   4. Store in vector database with metadata
#   5. Trigger emerging risk analysis
```

**2B. Emerging Risk Detection**
- Use **Granite 3.2-8B** with RAG to:
  - Analyze newly ingested documents against organization's risk profile
  - Identify risks mentioned in external sources that don't exist in the organization's risk register
  - Classify by: risk category, potential severity, velocity (how fast is it materializing), confidence level
  - Generate a structured emerging risk signal with:
    - Risk name and description
    - Source documents (with citations)
    - Relevance score to the organization's sector and profile
    - Potential impact areas (financial, operational, reputational, regulatory)
    - Recommended response (accept, monitor, investigate, escalate)
    - Time horizon (immediate, short-term 3m, medium-term 6m, long-term 12m+)

**Implementation:**
```python
# POST /api/ai/detect-emerging-risks
# Input: organization_id, sector, existing_risk_categories[]
# Process:
#   1. Retrieve recent documents from vector store (last 7 days)
#   2. Use Granite LLM with org context to identify new risk signals
#   3. De-duplicate against existing risk register
#   4. Score relevance to organization's sector and geography
#   5. Generate structured emerging risk objects
# Output: [{ name, description, category, severity, velocity, confidence,
#            sources[], impact_areas[], recommended_response, time_horizon }]
```

**2C. Risk Velocity Tracking**
- For each emerging risk detected, track how it evolves over time
- Measure: mention frequency trend, severity escalation, geographic spread
- Use **Granite TSPulse** for anomaly detection on mention frequency
- Alert when an emerging risk's velocity accelerates

**Database:**
```sql
CREATE TABLE emerging_risks (
    id BIGINT PRIMARY KEY,
    organization_id BIGINT,
    name VARCHAR(255),
    description TEXT,
    category VARCHAR(100),
    severity ENUM('critical','high','medium','low'),
    velocity ENUM('accelerating','stable','decelerating'),
    confidence DECIMAL(5,4),
    relevance_score DECIMAL(5,4),
    impact_areas JSON,
    recommended_response VARCHAR(50),
    time_horizon VARCHAR(50),
    source_documents JSON,
    first_detected_at TIMESTAMP,
    last_updated_at TIMESTAMP,
    status ENUM('active','monitoring','dismissed','converted_to_risk'),
    converted_risk_id BIGINT NULL,
    created_at TIMESTAMP
);

CREATE TABLE emerging_risk_signals (
    id BIGINT PRIMARY KEY,
    emerging_risk_id BIGINT,
    source_type VARCHAR(50),
    source_url TEXT,
    source_title VARCHAR(500),
    signal_date DATE,
    relevance_score DECIMAL(5,4),
    excerpt TEXT,
    created_at TIMESTAMP
);
```

---

### Feature 3: Regulatory Intelligence Engine

**Replace:** Current 6 static regulatory updates with real-time regulatory monitoring

**What It Must Do:**

**3A. Regulatory Document Ingestion & Analysis**
- Continuously monitor Nigerian regulatory sources:
  - **CBN:** Circulars, guidelines, monetary policy communiqués, prudential guidelines
  - **NFIU:** AML/CFT directives, STR/CTR requirements, sanctions lists
  - **SEC Nigeria:** Rules, regulations, capital market bulletins
  - **NAICOM:** Insurance directives, guidelines
  - **NDPA:** Data protection directives, enforcement actions, DPIA requirements
  - **FIRS/LIRS:** Tax compliance updates affecting risk
  - **CAMA:** Corporate governance requirements
- Ingest into vector store with Granite Embedding R2

**3B. Impact Assessment Automation**
- When a new regulatory document is ingested:
  1. Use **Granite 3.2-8B** to analyze the document and extract:
     - What is changing (new requirement, amendment, deadline)
     - Who is affected (which institution types, which departments)
     - Compliance deadline
     - Penalty for non-compliance
  2. Cross-reference against organization's existing risks and controls:
     - Which existing risks are affected?
     - Which controls need updating?
     - Are there gaps in current compliance posture?
  3. Generate a structured regulatory impact assessment:
     - Impact level: Critical / High / Medium / Low
     - Affected modules in the platform (risk register, controls, issues, etc.)
     - Required actions with suggested owners and timelines
     - Compliance status: Not Started / In Progress / Compliant / Overdue

**Implementation:**
```python
# POST /api/ai/analyze-regulatory-update
# Input: document_text, document_metadata (regulator, date, reference)
# Process:
#   1. Use Granite LLM to extract structured requirements from document
#   2. Generate embeddings and search for similar existing regulations in vector store
#   3. Cross-reference with org's risk register and control library
#   4. Identify gaps between requirements and current controls
#   5. Generate impact assessment with recommended actions
# Output: {
#   impact_level, requirements[], affected_risks[], affected_controls[],
#   compliance_gaps[], actions_required[], deadline, penalty_description
# }
```

**3C. Compliance Gap Analysis**
- Periodically (weekly) scan entire regulatory corpus against organization's risk and control register
- Identify areas where regulations exist but controls are insufficient or missing
- Rank gaps by: regulatory severity, financial penalty risk, reputational impact
- Generate compliance improvement roadmap

**3D. Regulatory Calendar Management**
- Auto-extract filing deadlines from regulatory documents
- Maintain a live regulatory calendar with:
  - Filing deadlines (CBN returns, NFIU STR/CTR, NDPA DPIA)
  - Examination periods
  - Policy review dates
  - Compliance attestation deadlines
- Send proactive reminders 30/14/7/3/1 days before deadlines

**Database:**
```sql
CREATE TABLE regulatory_documents (
    id BIGINT PRIMARY KEY,
    organization_id BIGINT,
    regulator VARCHAR(50),
    document_type VARCHAR(100),
    reference_number VARCHAR(100),
    title VARCHAR(500),
    publication_date DATE,
    effective_date DATE NULL,
    full_text TEXT,
    summary TEXT,
    embedding_id VARCHAR(100),  -- reference to vector store
    impact_level ENUM('critical','high','medium','low') NULL,
    status ENUM('new','analyzed','acknowledged','actioned'),
    ai_analysis JSON NULL,
    created_at TIMESTAMP
);

CREATE TABLE regulatory_requirements (
    id BIGINT PRIMARY KEY,
    document_id BIGINT,
    requirement_text TEXT,
    compliance_deadline DATE NULL,
    penalty_description TEXT NULL,
    affected_entity_types JSON,
    status ENUM('not_started','in_progress','compliant','overdue','not_applicable'),
    owner_id BIGINT NULL,
    notes TEXT NULL,
    created_at TIMESTAMP
);

CREATE TABLE regulatory_gap_analyses (
    id BIGINT PRIMARY KEY,
    organization_id BIGINT,
    requirement_id BIGINT,
    gap_description TEXT,
    severity ENUM('critical','high','medium','low'),
    existing_controls JSON,
    missing_controls TEXT,
    recommended_actions JSON,
    status ENUM('open','in_remediation','closed'),
    created_at TIMESTAMP
);
```

---

### Feature 4: Intelligent Benchmarking

**Replace:** Current hardcoded industry benchmarks with AI-driven comparative analysis

**What It Must Do:**

**4A. Internal Trend Benchmarking**
- Track all organization metrics over time and identify:
  - Improving trends (celebrate and reinforce)
  - Deteriorating trends (alert and investigate)
  - Stagnant metrics (identify root causes)
- Use **Granite TTM** for trend analysis and **Granite 3.2-8B** for narrative generation
- Metrics to track:
  - Risk profile distribution (% critical, high, medium, low) over time
  - Average control effectiveness over time
  - Loss event frequency and severity trends
  - Issue resolution time trends
  - KRI breach frequency trends
  - Treatment plan completion rates
  - RCSA completion rates

**4B. Sector Benchmarking (Data-Driven)**
- Build anonymized benchmark database from:
  - Public regulatory data (CBN financial stability reports, NDIC annual reports)
  - Published industry surveys
  - Anonymized aggregate data from other platform clients (with consent)
- Use **Granite 3.2-8B** to generate comparative analysis narratives
- Show where the organization stands relative to:
  - Industry averages
  - Top quartile performers
  - Regulatory minimums
  - Best practice targets

**4C. Maturity Assessment**
- Use Granite LLM with structured prompting to assess risk management maturity across dimensions:
  - Governance & Culture
  - Risk Assessment Process
  - Control Environment
  - Information & Communication
  - Monitoring & Review
  - Technology & Automation
- Score each dimension on a 5-level maturity scale
- Generate improvement recommendations per dimension
- Track maturity progression over time

**Database:**
```sql
CREATE TABLE benchmark_snapshots (
    id BIGINT PRIMARY KEY,
    organization_id BIGINT,
    snapshot_date DATE,
    metrics JSON,  -- all calculated metrics at this point in time
    ai_narrative TEXT NULL,
    maturity_scores JSON NULL,
    created_at TIMESTAMP
);

CREATE TABLE industry_benchmarks (
    id BIGINT PRIMARY KEY,
    sector VARCHAR(100),
    metric_name VARCHAR(100),
    period VARCHAR(20),
    average_value DECIMAL(15,4),
    top_quartile_value DECIMAL(15,4),
    bottom_quartile_value DECIMAL(15,4),
    sample_size INT,
    source VARCHAR(255),
    updated_at TIMESTAMP
);
```

---

### Feature 5: AI-Powered Cross-Module Intelligence

These features work ACROSS all platform modules, not in isolation.

**5A. Natural Language Risk Query**
- Allow users to ask questions in plain English about their risk data:
  - "What are our top 5 risks by financial exposure?"
  - "Which controls have failed testing in the last 3 months?"
  - "Show me the trend of operational losses this year"
  - "Which business units have the most overdue remediation actions?"
  - "What is our current CBN compliance status?"
- Use **Granite 3.2-8B** with the platform's database schema as context
- Generate SQL queries from natural language, execute, and present results with narrative
- **Safety:** Use Granite Guardian to validate queries before execution (prevent SQL injection, limit to SELECT only)

**Implementation:**
```python
# POST /api/ai/query
# Input: { question: "What are our riskiest business units?", organization_id }
# Process:
#   1. Granite Guardian validates the question (no injection, appropriate content)
#   2. Granite 3.2-8B converts question to SQL using schema context
#   3. SQL validated (SELECT only, scoped to organization_id)
#   4. Execute query
#   5. Granite 3.2-8B generates narrative answer from results
# Output: { answer_narrative, data_table, visualization_config, sql_used }
```

**5B. Intelligent Risk Narratives**
- Auto-generate risk narratives for reports:
  - Risk description enrichment (expand terse risk descriptions into detailed narratives)
  - Assessment summary generation (convert scores into prose for board reports)
  - Loss event impact narratives (describe the business impact of loss events)
  - Treatment plan justifications (explain why a treatment strategy was chosen)
- All narratives grounded in actual platform data — no hallucination

**5C. Root Cause Analysis Assistant**
- When a loss event or issue is being investigated:
  - Analyze the event details and cross-reference with historical similar events
  - Suggest potential root causes based on patterns in historical RCA data
  - Identify if similar events have occurred in other business units
  - Recommend investigation questions
  - Draft the RCA report structure
- Use **Granite 3.2-8B** with RAG over historical RCA data

**5D. Control Recommendation Engine**
- When a new risk is identified or an existing risk's score increases:
  - Search the control library for controls that effectively mitigate similar risks
  - Recommend new controls based on industry best practices (from RAG corpus)
  - Estimate expected risk reduction based on historical data
  - Identify control gaps (risks with no or weak controls)

**5E. Smart Notifications & Digests**
- Use Granite to generate intelligent notification content:
  - Daily risk digest for CRO: "3 risks escalated, 2 KRIs breached, 1 new regulatory circular..."
  - Weekly board summary: AI-generated executive summary of risk posture changes
  - Personalized alerts: Each user gets alerts relevant to their role and risk ownership
  - Escalation narratives: When escalating, AI explains WHY it's escalating

**5F. Risk Correlation Discovery**
- Analyze the full risk register and linked data to discover hidden correlations:
  - Which risks tend to escalate together?
  - Which loss event categories are correlated?
  - Which control failures lead to which loss types?
  - Are there seasonal patterns in risk scores or loss events?
- Use a combination of statistical correlation analysis and Granite LLM interpretation
- Present as a correlation matrix with AI-generated insights

---

## PART 4 — TECHNICAL ARCHITECTURE

### 4.1 System Architecture

```
┌─────────────────────────────────────────────────────────────────┐
│                    LARAVEL APPLICATION                            │
│                                                                   │
│  ┌───────────────────────────────────────────────────────────┐   │
│  │              AI Controller Layer (Laravel)                 │   │
│  │  AiIntelligenceController (enhanced)                      │   │
│  │  AiQueryController (new — NL queries)                     │   │
│  │  AiNarrativeController (new — report generation)          │   │
│  └─────────────────────────┬─────────────────────────────────┘   │
│                            │                                      │
│  ┌─────────────────────────┼─────────────────────────────────┐   │
│  │              AI Service Layer (Laravel)                     │   │
│  │  GraniteApiService      — HTTP client to Python service    │   │
│  │  AiDataService          — Data preparation & formatting    │   │
│  │  AiPredictionService    — Prediction storage & tracking    │   │
│  │  AiCacheService         — Response caching (Redis)         │   │
│  └─────────────────────────┬─────────────────────────────────┘   │
│                            │ HTTP (internal network)              │
└────────────────────────────┼─────────────────────────────────────┘
                             │
                             ▼
┌─────────────────────────────────────────────────────────────────┐
│              GRANITE AI MICROSERVICE (Python/FastAPI)             │
│                                                                   │
│  ┌────────────────────┐  ┌────────────────────┐                  │
│  │   API Gateway      │  │   Task Queue       │                  │
│  │   (FastAPI)        │  │   (Celery/Redis)   │                  │
│  └────────┬───────────┘  └────────┬───────────┘                  │
│           │                       │                               │
│  ┌────────┴───────────────────────┴───────────────────────────┐  │
│  │                    AI Engine Modules                         │  │
│  │                                                              │  │
│  │  ┌──────────────┐  ┌──────────────┐  ┌──────────────────┐  │  │
│  │  │  Prediction   │  │   Radar      │  │   Regulatory     │  │  │
│  │  │  Engine       │  │   Engine     │  │   Intelligence   │  │  │
│  │  │  (TTM +       │  │   (Granite   │  │   (Granite LLM   │  │  │
│  │  │   Granite)    │  │   LLM + RAG) │  │   + RAG + Embed) │  │  │
│  │  └──────────────┘  └──────────────┘  └──────────────────┘  │  │
│  │                                                              │  │
│  │  ┌──────────────┐  ┌──────────────┐  ┌──────────────────┐  │  │
│  │  │  Query       │  │  Narrative   │  │  Benchmarking    │  │  │
│  │  │  Engine      │  │  Generator   │  │  Engine          │  │  │
│  │  │  (NL→SQL)    │  │  (Granite)   │  │  (TTM + Stats)   │  │  │
│  │  └──────────────┘  └──────────────┘  └──────────────────┘  │  │
│  │                                                              │  │
│  │  ┌──────────────┐  ┌──────────────┐                        │  │
│  │  │  Guardian     │  │  Correlation │                        │  │
│  │  │  Safety       │  │  Discovery   │                        │  │
│  │  │  (Guardian)   │  │  (Stats +    │                        │  │
│  │  │              │  │   Granite)   │                        │  │
│  │  └──────────────┘  └──────────────┘                        │  │
│  └──────────────────────────────────────────────────────────┘  │
│                                                                   │
│  ┌───────────────────────────────────────────────────────────┐   │
│  │                   Model Layer                              │   │
│  │  ┌──────────┐ ┌──────────┐ ┌──────────┐ ┌─────────────┐  │   │
│  │  │Granite   │ │Granite   │ │Granite   │ │Granite      │  │   │
│  │  │3.2-8B    │ │TTM      │ │TSPulse   │ │Embedding R2 │  │   │
│  │  │(Ollama)  │ │(Python) │ │(Python)  │ │(Python)     │  │   │
│  │  └──────────┘ └──────────┘ └──────────┘ └─────────────┘  │   │
│  │  ┌──────────┐                                              │   │
│  │  │Guardian  │                                              │   │
│  │  │3.2-3B   │                                              │   │
│  │  │(Ollama) │                                              │   │
│  │  └──────────┘                                              │   │
│  └───────────────────────────────────────────────────────────┘   │
│                                                                   │
│  ┌───────────────────────────────────────────────────────────┐   │
│  │                   Data Layer                               │   │
│  │  ┌─────────────────┐  ┌──────────────────────────────┐    │   │
│  │  │  Vector Store    │  │  Redis Cache                  │    │   │
│  │  │  (ChromaDB /    │  │  (Response cache + Task queue)│    │   │
│  │  │   Qdrant)       │  │                               │    │   │
│  │  └─────────────────┘  └──────────────────────────────┘    │   │
│  └───────────────────────────────────────────────────────────┘   │
│                                                                   │
└─────────────────────────────────────────────────────────────────┘
```

### 4.2 Data Flow: Laravel → Granite → Response

```
User Request (e.g., "Show predictive analytics")
    │
    ▼
Laravel Controller (AiIntelligenceController@predictive)
    │
    ├── 1. AiDataService: Gather data from Risk, Assessment, KRI, LossEvent models
    │      (pure Laravel Eloquent queries — stays in Laravel)
    │
    ├── 2. AiCacheService: Check Redis cache for recent prediction
    │      IF cached → return cached response
    │
    ├── 3. GraniteApiService: POST to Python microservice
    │      Payload: { feature_vectors, historical_data, organization_context }
    │
    │      ┌─── Python Microservice ───┐
    │      │                           │
    │      │  FastAPI receives request  │
    │      │         │                 │
    │      │  ┌──────┴──────┐         │
    │      │  │ TTM: Forecast│         │
    │      │  │ risk scores  │         │
    │      │  └──────┬──────┘         │
    │      │         │                 │
    │      │  ┌──────┴──────┐         │
    │      │  │ Granite LLM: │         │
    │      │  │ Analyze &    │         │
    │      │  │ generate     │         │
    │      │  │ narrative    │         │
    │      │  └──────┬──────┘         │
    │      │         │                 │
    │      │  ┌──────┴──────┐         │
    │      │  │ Guardian:    │         │
    │      │  │ Validate     │         │
    │      │  │ output       │         │
    │      │  └──────┬──────┘         │
    │      │         │                 │
    │      └─────────┼─────────────────┘
    │                │
    │      ◄─────────┘ JSON response
    │
    ├── 4. AiPredictionService: Store prediction in ai_predictions table
    │
    ├── 5. AiCacheService: Cache response in Redis (TTL: 1 hour)
    │
    └── 6. Return to Blade view with real data
```

### 4.3 API Contract Between Laravel and Python Microservice

```yaml
# All endpoints prefixed with: http://granite-ai:8000/api/v1

# ─── PREDICTION ENDPOINTS ───
POST /predict/risk-trajectory
  Body: { risk_id, scores: [{date, score}], window_months: 3 }
  Response: { predictions: [{date, score, ci_lower, ci_upper}] }

POST /predict/escalation-probability
  Body: { risks: [{ risk_id, features: {current_score, trend, kri_status, ...} }] }
  Response: { predictions: [{ risk_id, probability, confidence, drivers[], actions[] }] }

POST /predict/early-warnings
  Body: { org_id, kri_breaches[], recent_losses[], deteriorating_risks[], overdue_issues[] }
  Response: { warnings: [{ severity, affected_risks[], signals[], narrative, actions[] }] }

# ─── RADAR ENDPOINTS ───
POST /radar/detect-emerging-risks
  Body: { org_id, sector, existing_risk_categories[], days_lookback: 7 }
  Response: { emerging_risks: [{ name, description, category, severity, ... }] }

POST /radar/ingest-documents
  Body: { documents: [{ url, text, source, date, metadata }] }
  Response: { ingested_count, new_signals_detected }

# ─── REGULATORY ENDPOINTS ───
POST /regulatory/analyze-document
  Body: { document_text, regulator, reference, date, org_risks[], org_controls[] }
  Response: { impact_level, requirements[], affected_risks[], gaps[], actions[] }

POST /regulatory/compliance-scan
  Body: { org_id, risk_register[], control_library[] }
  Response: { gaps: [{ regulation, gap, severity, recommended_action }] }

# ─── QUERY ENDPOINTS ───
POST /query/natural-language
  Body: { question, org_id, db_schema_context }
  Response: { sql_query, results, narrative, visualization_type }

# ─── NARRATIVE ENDPOINTS ───
POST /narrative/generate
  Body: { type: 'risk_summary'|'loss_impact'|'board_report', data, context }
  Response: { narrative_text, key_points[], tone: 'formal'|'executive' }

POST /narrative/rca-assist
  Body: { loss_event_details, historical_similar_events[], existing_controls[] }
  Response: { suggested_root_causes[], investigation_questions[], draft_report }

# ─── BENCHMARKING ENDPOINTS ───
POST /benchmark/trend-analysis
  Body: { org_metrics_history: [{date, metrics}] }
  Response: { trends: [{ metric, direction, forecast, narrative }] }

POST /benchmark/maturity-assessment
  Body: { org_id, risk_program_data }
  Response: { dimensions: [{ name, score, level, recommendations[] }], overall_score }

# ─── CORRELATION ENDPOINTS ───
POST /correlation/discover
  Body: { risk_scores_history, loss_events, kri_measurements, assessment_scores }
  Response: { correlations: [{ pair, coefficient, significance, ai_interpretation }] }

# ─── SAFETY ENDPOINTS ───
POST /safety/validate-output
  Body: { text, context }
  Response: { is_safe: bool, risk_flags[], risk_score }

POST /safety/validate-query
  Body: { sql_query }
  Response: { is_safe: bool, issues[] }
```

---

## PART 5 — IMPLEMENTATION PHASES

### Phase 1: Infrastructure & Foundation (Week 1-2)

**Goal:** Set up the Granite model infrastructure and establish the microservice communication layer.

**Tasks:**

1.1 **Set up Python microservice project**
- Create FastAPI project structure
- Configure Docker Compose for: FastAPI + Redis + ChromaDB + Ollama
- Write Dockerfile for the AI microservice
- Set up Celery for async task processing

1.2 **Deploy Granite models**
- Pull Granite 3.2-8B-Instruct via Ollama
- Pull Granite Guardian 3.2-3B via Ollama
- Install granite-tsfm Python package for TTM and TSPulse
- Install granite-embedding package for Embedding R2
- Verify all models respond correctly with test prompts

1.3 **Build Laravel ↔ Python communication layer**
- Create `GraniteApiService` in Laravel (HTTP client with retry logic, timeout handling)
- Create `AiCacheService` with Redis integration
- Configure environment variables for AI service URL, timeouts, API keys
- Add health check endpoint to verify connectivity

1.4 **Create database migrations**
- `ai_predictions` table
- `ai_model_metrics` table
- `emerging_risks` table
- `emerging_risk_signals` table
- `regulatory_documents` table
- `regulatory_requirements` table
- `regulatory_gap_analyses` table
- `benchmark_snapshots` table
- `industry_benchmarks` table
- `ai_query_logs` table (for natural language query audit trail)

1.5 **Set up vector store**
- Deploy ChromaDB (or Qdrant)
- Create collections: `regulatory_documents`, `risk_knowledge_base`, `external_intelligence`
- Build initial embeddings from existing risk data (risk descriptions, RCA text, issue descriptions)

**Deliverables:**
- Docker Compose stack running all AI services
- Laravel can send requests to Python and receive responses
- All database tables created and migrated
- Vector store operational with initial data

---

### Phase 2: Predictive Intelligence (Week 3-4)

**Goal:** Replace fake predictions with real ML-driven forecasting.

**Tasks:**

2.1 **Build data extraction pipeline**
- Create Laravel Artisan command: `php artisan ai:prepare-training-data`
- Extract historical risk assessment scores as time series
- Extract KRI measurement time series
- Extract loss event frequency and severity time series
- Format all data for TTM consumption

2.2 **Implement risk trajectory prediction**
- Build TTM prediction pipeline in Python
- Handle edge cases: insufficient history, missing data points, new risks
- Implement confidence interval calculation
- Create `/predict/risk-trajectory` endpoint

2.3 **Implement escalation probability engine**
- Build feature engineering pipeline (gather all signals per risk)
- Implement escalation scoring using Granite LLM with structured prompting
- Create prompt templates for escalation analysis
- Create `/predict/escalation-probability` endpoint

2.4 **Implement early warning system**
- Build signal convergence detection logic
- Implement Granite LLM narrative generation for warnings
- Create `/predict/early-warnings` endpoint
- Wire to Laravel notification system

2.5 **Implement prediction tracking**
- Store all predictions in `ai_predictions` table
- Build Laravel scheduled job: `php artisan ai:evaluate-predictions` (weekly)
- Calculate real accuracy metrics and store in `ai_model_metrics`

2.6 **Update Laravel controller & views**
- Refactor `AiIntelligenceController@predictive` to use real AI service
- Update `predictive.blade.php` to display real data
- Add model performance dashboard showing actual accuracy

**Deliverables:**
- Real risk score predictions based on historical assessment data
- Real escalation probabilities based on multi-signal analysis
- Early warning system detecting converging risk signals
- Prediction accuracy tracking over time

---

### Phase 3: Emerging Risk Radar (Week 5-6)

**Goal:** Replace hardcoded emerging risks with AI-detected real-time intelligence.

**Tasks:**

3.1 **Build web scraping pipeline**
- Create scrapers for Nigerian regulatory websites (CBN, NFIU, SEC, NDPA)
- Create scrapers for Nigerian financial news (BusinessDay, Nairametrics)
- Create RSS feed parsers for global risk publications
- Schedule daily ingestion via Celery

3.2 **Build RAG pipeline**
- Implement document chunking (512-token chunks with 50-token overlap)
- Build embedding generation pipeline with Granite Embedding R2
- Implement ChromaDB ingestion with metadata tagging
- Build semantic search API

3.3 **Build emerging risk detection**
- Create Granite LLM prompts for risk identification from documents
- Implement relevance scoring against organization's profile
- Implement de-duplication against existing risk register
- Create `/radar/detect-emerging-risks` endpoint

3.4 **Build velocity tracking**
- Implement mention frequency tracking per emerging risk
- Use TSPulse for anomaly detection on velocity metrics
- Create velocity acceleration alerts

3.5 **Update Laravel controller & views**
- Refactor `AiIntelligenceController@radar` to use real AI service
- Update `radar.blade.php` with real emerging risk data
- Add "Convert to Risk" action (one-click add emerging risk to risk register)
- Add source document links with citation

**Deliverables:**
- Daily ingestion of Nigerian regulatory and financial news
- AI-detected emerging risks relevant to the organization
- Velocity tracking with acceleration alerts
- One-click conversion of emerging risks to risk register entries

---

### Phase 4: Regulatory Intelligence (Week 7-8)

**Goal:** Build real-time regulatory monitoring and compliance analysis.

**Tasks:**

4.1 **Build regulatory document analysis engine**
- Create Granite LLM prompts for regulatory requirement extraction
- Implement structured output parsing (requirements, deadlines, penalties)
- Create `/regulatory/analyze-document` endpoint

4.2 **Build compliance gap analysis**
- Implement cross-referencing logic (regulations vs. controls)
- Create Granite LLM prompts for gap identification
- Build gap severity scoring
- Create `/regulatory/compliance-scan` endpoint

4.3 **Build regulatory calendar**
- Auto-extract deadlines from analyzed documents
- Build calendar view in Laravel (month/week views)
- Implement reminder notifications (30/14/7/3/1 day)

4.4 **Update Laravel controller & views**
- Refactor `AiIntelligenceController@regulatoryPulse` to use real data
- Build new regulatory calendar view
- Build compliance gap dashboard
- Update `regulatory-pulse.blade.php`

**Deliverables:**
- AI-analyzed regulatory documents with extracted requirements
- Compliance gap analysis against existing controls
- Live regulatory calendar with proactive reminders
- Compliance status dashboard per regulator

---

### Phase 5: Cross-Module Intelligence (Week 9-10)

**Goal:** Build AI features that work across all platform modules.

**Tasks:**

5.1 **Natural language query engine**
- Build database schema context provider
- Create Granite LLM prompt for NL-to-SQL conversion
- Implement Guardian validation for query safety
- Build query execution sandbox (SELECT only, org-scoped)
- Build narrative response generation
- Create new `AiQueryController` with chat-style UI

5.2 **Intelligent narrative generation**
- Build narrative templates for: risk summaries, loss event impacts, board reports, RCA
- Create Granite LLM prompts grounded in actual data
- Implement narrative caching and versioning
- Add "Generate AI Narrative" buttons to relevant views (risk show, loss event show, reports)

5.3 **RCA assistant**
- Build historical RCA similarity search using embeddings
- Create Granite LLM prompts for root cause suggestion
- Integrate into loss event RCA workflow
- Add "AI Suggest Root Causes" button to RCA view

5.4 **Control recommendation engine**
- Build control similarity search
- Create Granite LLM prompts for control gap identification
- Add "AI Recommend Controls" to risk detail view

5.5 **Risk correlation discovery**
- Build statistical correlation analysis pipeline
- Create Granite LLM prompts for correlation interpretation
- Build correlation matrix visualization
- Add to Risk Analysis section

**Deliverables:**
- Natural language query interface for risk data
- AI-generated narratives throughout the platform
- RCA assistant suggesting root causes from historical data
- Control recommendation engine
- Risk correlation discovery with AI-generated insights

---

### Phase 6: Benchmarking & Maturity (Week 11-12)

**Goal:** Replace hardcoded benchmarks with real trend analysis and maturity assessment.

**Tasks:**

6.1 **Internal trend analysis**
- Build metric snapshot scheduler (monthly)
- Implement TTM trend forecasting on organizational metrics
- Create Granite LLM narrative generation for trends
- Build trend dashboard with sparklines

6.2 **Sector benchmarking**
- Seed industry benchmark database from public sources
- Build comparison engine
- Create visual comparison charts (radar, bar)
- Implement Granite LLM benchmark narrative

6.3 **Maturity assessment**
- Build maturity framework (5 dimensions, 5 levels each)
- Create Granite LLM structured assessment prompts
- Build maturity scoring from platform data
- Create maturity radar chart and improvement recommendations

6.4 **Update views**
- Refactor `AiIntelligenceController@benchmarking`
- Update `benchmarking.blade.php` with real comparisons
- Add maturity assessment view

**Deliverables:**
- Real trend analysis with AI-generated narratives
- Sector benchmarking against public data
- Automated maturity assessment with recommendations

---

### Phase 7: Smart Notifications & Digest (Week 13)

**Goal:** AI-powered notification content and intelligent digests.

**Tasks:**

7.1 **Daily CRO digest**
- Build digest data aggregation (last 24 hours: new risks, escalations, losses, breaches, issues)
- Use Granite LLM to generate executive summary narrative
- Send via email/WhatsApp at configured time

7.2 **Weekly board summary**
- Build weekly risk posture summary
- Use Granite LLM to generate board-appropriate narrative
- Include: key changes, emerging threats, compliance status, KPI movements

7.3 **Smart escalation narratives**
- When system escalates (KRI breach, overdue issue, risk deterioration):
- Use Granite LLM to generate context-rich escalation message
- Include: what happened, why it matters, historical context, recommended action

**Deliverables:**
- AI-generated daily CRO digest
- AI-generated weekly board summary
- Intelligent escalation notifications with context

---

### Phase 8: Testing, Optimization & Hardening (Week 14-16)

**Goal:** Ensure production reliability, accuracy, and performance.

**Tasks:**

8.1 **Accuracy testing**
- Validate predictions against known outcomes
- Test regulatory analysis against manually analyzed documents
- Test NL query accuracy against known queries
- Benchmark Granite outputs against GPT-4 for quality comparison

8.2 **Performance optimization**
- Implement response caching strategy (Redis TTLs per endpoint)
- Optimize batch processing for large organizations
- Load test with 1000+ risks, 10000+ assessments
- Tune Granite model parameters (temperature, top_p, max_tokens)

8.3 **Security hardening**
- Ensure all AI queries are organization-scoped (multi-tenant isolation)
- Implement rate limiting on AI endpoints
- Add audit logging for all AI interactions
- Ensure no data leakage between tenants in vector store
- Validate Granite Guardian catches all safety issues

8.4 **Fallback handling**
- Implement graceful degradation if AI service is down
- Show "AI temporarily unavailable" with last cached results
- Ensure platform remains fully functional without AI

8.5 **Documentation**
- API documentation for all AI endpoints
- Model card documentation (what each model does, limitations)
- Runbook for AI service operations
- User guide for AI features

**Deliverables:**
- Validated accuracy benchmarks
- Performance-optimized deployment
- Security-hardened multi-tenant AI
- Comprehensive documentation

---

## PART 6 — FILE STRUCTURE

### 6.1 New/Modified Laravel Files

```
app/
├── Http/Controllers/Risk/
│   ├── AiIntelligenceController.php    ← MODIFY (use real AI service)
│   ├── AiQueryController.php            ← NEW (natural language queries)
│   └── AiNarrativeController.php        ← NEW (narrative generation)
├── Services/
│   ├── AiDataService.php                ← MODIFY (real data preparation)
│   ├── GraniteApiService.php            ← NEW (HTTP client to Python)
│   ├── AiPredictionService.php          ← NEW (prediction storage/tracking)
│   ├── AiCacheService.php               ← NEW (Redis caching)
│   └── AiDigestService.php              ← NEW (digest generation)
├── Models/
│   ├── AiPrediction.php                 ← NEW
│   ├── AiModelMetric.php                ← NEW
│   ├── EmergingRisk.php                 ← NEW
│   ├── EmergingRiskSignal.php           ← NEW
│   ├── RegulatoryDocument.php           ← NEW
│   ├── RegulatoryRequirement.php        ← NEW
│   ├── RegulatoryGapAnalysis.php        ← NEW
│   ├── BenchmarkSnapshot.php            ← NEW
│   ├── IndustryBenchmark.php            ← NEW
│   └── AiQueryLog.php                   ← NEW
├── Console/Commands/
│   ├── AiPrepareTrainingData.php        ← NEW
│   ├── AiEvaluatePredictions.php        ← NEW
│   ├── AiIngestExternalIntelligence.php ← NEW
│   ├── AiGenerateDigest.php             ← NEW
│   └── AiBenchmarkSnapshot.php          ← NEW

database/migrations/
├── create_ai_predictions_table.php              ← NEW
├── create_ai_model_metrics_table.php            ← NEW
├── create_emerging_risks_table.php              ← NEW
├── create_emerging_risk_signals_table.php       ← NEW
├── create_regulatory_documents_table.php        ← NEW
├── create_regulatory_requirements_table.php     ← NEW
├── create_regulatory_gap_analyses_table.php     ← NEW
├── create_benchmark_snapshots_table.php         ← NEW
├── create_industry_benchmarks_table.php         ← NEW
└── create_ai_query_logs_table.php               ← NEW

resources/views/risk/ai/
├── predictive.blade.php        ← MODIFY (real prediction data)
├── radar.blade.php             ← MODIFY (real emerging risks)
├── regulatory-pulse.blade.php  ← MODIFY (real regulatory data)
├── benchmarking.blade.php      ← MODIFY (real benchmarks)
├── query.blade.php             ← NEW (NL query interface)
├── correlation.blade.php       ← NEW (correlation discovery)
├── maturity.blade.php          ← NEW (maturity assessment)
├── calendar.blade.php          ← NEW (regulatory calendar)
└── digest-settings.blade.php   ← NEW (digest configuration)

config/
└── granite.php                 ← NEW (AI service configuration)
```

### 6.2 New Python Microservice Structure

```
granite-ai-service/
├── Dockerfile
├── docker-compose.yml
├── requirements.txt
├── pyproject.toml
├── README.md
│
├── app/
│   ├── __init__.py
│   ├── main.py                          # FastAPI app entry point
│   ├── config.py                        # Environment config
│   │
│   ├── api/
│   │   ├── __init__.py
│   │   ├── prediction.py                # /predict/* endpoints
│   │   ├── radar.py                     # /radar/* endpoints
│   │   ├── regulatory.py                # /regulatory/* endpoints
│   │   ├── query.py                     # /query/* endpoints
│   │   ├── narrative.py                 # /narrative/* endpoints
│   │   ├── benchmark.py                 # /benchmark/* endpoints
│   │   ├── correlation.py               # /correlation/* endpoints
│   │   ├── safety.py                    # /safety/* endpoints
│   │   └── health.py                    # Health check endpoint
│   │
│   ├── engines/
│   │   ├── __init__.py
│   │   ├── prediction_engine.py         # TTM + escalation logic
│   │   ├── radar_engine.py              # Emerging risk detection
│   │   ├── regulatory_engine.py         # Regulatory analysis
│   │   ├── query_engine.py              # NL-to-SQL conversion
│   │   ├── narrative_engine.py          # Text generation
│   │   ├── benchmark_engine.py          # Trend + maturity analysis
│   │   ├── correlation_engine.py        # Statistical correlation
│   │   └── safety_engine.py             # Guardian validation
│   │
│   ├── models/
│   │   ├── __init__.py
│   │   ├── granite_llm.py              # Granite LLM wrapper (Ollama)
│   │   ├── granite_ttm.py              # TinyTimeMixer wrapper
│   │   ├── granite_tspulse.py          # TSPulse wrapper
│   │   ├── granite_embedding.py        # Embedding R2 wrapper
│   │   └── granite_guardian.py         # Guardian wrapper
│   │
│   ├── rag/
│   │   ├── __init__.py
│   │   ├── vector_store.py             # ChromaDB/Qdrant client
│   │   ├── document_processor.py       # Chunking + embedding
│   │   ├── retriever.py                # Semantic search
│   │   └── ingestion.py                # Document ingestion pipeline
│   │
│   ├── scrapers/
│   │   ├── __init__.py
│   │   ├── cbn_scraper.py              # CBN circulars
│   │   ├── nfiu_scraper.py             # NFIU directives
│   │   ├── sec_scraper.py              # SEC Nigeria
│   │   ├── ndpa_scraper.py             # NDPA
│   │   ├── news_scraper.py             # Nigerian financial news
│   │   └── global_risk_scraper.py      # WEF, Basel Committee
│   │
│   ├── prompts/
│   │   ├── __init__.py
│   │   ├── prediction_prompts.py       # Prompt templates for prediction
│   │   ├── radar_prompts.py            # Prompt templates for radar
│   │   ├── regulatory_prompts.py       # Prompt templates for regulatory
│   │   ├── query_prompts.py            # Prompt templates for NL→SQL
│   │   ├── narrative_prompts.py        # Prompt templates for narratives
│   │   └── system_prompts.py           # System prompts for all engines
│   │
│   ├── tasks/
│   │   ├── __init__.py
│   │   ├── celery_app.py               # Celery configuration
│   │   ├── ingestion_tasks.py          # Async document ingestion
│   │   ├── prediction_tasks.py         # Async prediction batch jobs
│   │   └── digest_tasks.py             # Async digest generation
│   │
│   └── utils/
│       ├── __init__.py
│       ├── data_formatters.py          # Data format conversion
│       ├── validators.py               # Input validation
│       └── metrics.py                  # Model performance metrics
│
├── tests/
│   ├── test_prediction.py
│   ├── test_radar.py
│   ├── test_regulatory.py
│   ├── test_query.py
│   ├── test_narrative.py
│   └── test_safety.py
│
└── scripts/
    ├── setup_models.sh                 # Download all Granite models
    ├── seed_vector_store.py            # Initial vector store population
    └── benchmark_models.py             # Performance benchmarking
```

---

## PART 7 — CONFIGURATION

### 7.1 Laravel Configuration (config/granite.php)

```php
<?php
return [
    'service_url' => env('GRANITE_AI_URL', 'http://localhost:8000'),
    'api_timeout' => env('GRANITE_API_TIMEOUT', 30),
    'api_retry_attempts' => env('GRANITE_API_RETRIES', 3),

    'cache' => [
        'enabled' => env('GRANITE_CACHE_ENABLED', true),
        'ttl_predictions' => env('GRANITE_CACHE_TTL_PREDICTIONS', 3600),
        'ttl_radar' => env('GRANITE_CACHE_TTL_RADAR', 1800),
        'ttl_regulatory' => env('GRANITE_CACHE_TTL_REGULATORY', 3600),
        'ttl_benchmarks' => env('GRANITE_CACHE_TTL_BENCHMARKS', 86400),
    ],

    'features' => [
        'predictive_enabled' => env('GRANITE_PREDICTIVE_ENABLED', true),
        'radar_enabled' => env('GRANITE_RADAR_ENABLED', true),
        'regulatory_enabled' => env('GRANITE_REGULATORY_ENABLED', true),
        'query_enabled' => env('GRANITE_QUERY_ENABLED', true),
        'narrative_enabled' => env('GRANITE_NARRATIVE_ENABLED', true),
        'benchmarking_enabled' => env('GRANITE_BENCHMARKING_ENABLED', true),
    ],

    'models' => [
        'llm' => env('GRANITE_LLM_MODEL', 'granite3.2:8b-instruct'),
        'guardian' => env('GRANITE_GUARDIAN_MODEL', 'granite-guardian3.2:3b'),
        'embedding_model' => env('GRANITE_EMBEDDING_MODEL', 'granite-embedding-r2'),
    ],

    'scheduled_jobs' => [
        'digest_time' => env('GRANITE_DIGEST_TIME', '07:00'),
        'prediction_refresh' => env('GRANITE_PREDICTION_CRON', 'daily'),
        'intelligence_ingestion' => env('GRANITE_INGESTION_CRON', '0 6 * * *'),
        'benchmark_snapshot' => env('GRANITE_BENCHMARK_CRON', 'monthly'),
    ],
];
```

### 7.2 Docker Compose

```yaml
version: '3.8'

services:
  granite-ai:
    build: ./granite-ai-service
    ports:
      - "8000:8000"
    environment:
      - OLLAMA_HOST=http://ollama:11434
      - REDIS_URL=redis://redis:6379/0
      - CHROMADB_HOST=chromadb
      - CHROMADB_PORT=8001
    depends_on:
      - ollama
      - redis
      - chromadb
    volumes:
      - ai-data:/app/data

  ollama:
    image: ollama/ollama:latest
    ports:
      - "11434:11434"
    volumes:
      - ollama-models:/root/.ollama
    deploy:
      resources:
        reservations:
          devices:
            - driver: nvidia
              count: 1
              capabilities: [gpu]

  redis:
    image: redis:7-alpine
    ports:
      - "6379:6379"

  chromadb:
    image: chromadb/chroma:latest
    ports:
      - "8001:8000"
    volumes:
      - chroma-data:/chroma/chroma

  celery-worker:
    build: ./granite-ai-service
    command: celery -A app.tasks.celery_app worker --loglevel=info
    environment:
      - OLLAMA_HOST=http://ollama:11434
      - REDIS_URL=redis://redis:6379/0
      - CHROMADB_HOST=chromadb
    depends_on:
      - redis
      - ollama

  celery-beat:
    build: ./granite-ai-service
    command: celery -A app.tasks.celery_app beat --loglevel=info
    depends_on:
      - redis

volumes:
  ollama-models:
  chroma-data:
  ai-data:
```

---

## PART 8 — KEY PROMPT TEMPLATES

### 8.1 Risk Escalation Analysis Prompt

```
<system>
You are a senior enterprise risk analyst for a Nigerian financial institution.
You analyze risk data to predict whether a risk is likely to escalate in the next 90 days.
You must base your analysis ONLY on the provided data. Do not speculate beyond what the data shows.
Respond in structured JSON format.
</system>

<user>
Analyze the following risk for escalation probability:

RISK: {risk_title}
Code: {risk_code}
Category: {risk_category}
Current Inherent Score: {inherent_score}/25
Current Residual Score: {residual_score}/25
Target Rating: {target_rating}
Risk Velocity: {risk_velocity}
Appetite Aligned: {appetite_aligned}

ASSESSMENT HISTORY (last 6 assessments):
{assessment_history_table}

LINKED CONTROLS ({control_count} controls):
- Average effectiveness: {avg_effectiveness}%
- Controls in 'Ineffective' status: {ineffective_count}
- Controls overdue for testing: {overdue_test_count}

LINKED KRIs ({kri_count} indicators):
- KRIs in Red: {red_count}
- KRIs in Amber: {amber_count}
- KRI trend: {kri_trend}

RELATED LOSS EVENTS (last 12 months):
- Count: {loss_count}
- Total gross loss: ₦{total_loss}
- Trend: {loss_trend}

OPEN ISSUES:
- Total open: {open_issues}
- Overdue remediation actions: {overdue_actions}

TREATMENT PLANS:
- Active plans: {active_treatments}
- Stalled plans (no progress in 30 days): {stalled_treatments}

Based on this data, provide:
1. escalation_probability (0.0 to 1.0)
2. confidence (0.0 to 1.0)
3. key_drivers (top 3 factors driving your assessment)
4. recommended_actions (top 3 actions to take)
5. reasoning (2-3 sentence explanation)
</user>
```

### 8.2 Emerging Risk Detection Prompt

```
<system>
You are a risk intelligence analyst specializing in the Nigerian financial sector.
Your task is to identify emerging risks from recent regulatory and news documents
that may affect the organization.
Only identify risks that are NEW and not already in the organization's risk register.
Each risk must be directly supported by evidence from the provided documents.
</system>

<user>
ORGANIZATION PROFILE:
- Sector: {sector}
- Institution Type: {institution_type}
- Regulators: {regulators}
- Key Business Lines: {business_lines}

EXISTING RISK CATEGORIES IN REGISTER:
{existing_risk_categories}

RECENT DOCUMENTS (last 7 days):
{documents_with_excerpts}

Identify emerging risks that:
1. Are NOT already covered by the existing risk register categories above
2. Are relevant to a {institution_type} in Nigeria
3. Have evidence in the provided documents

For each emerging risk provide:
- name: Short risk name
- description: 2-3 sentence description
- category: Risk category
- severity: critical/high/medium/low
- velocity: accelerating/stable/decelerating
- confidence: 0.0 to 1.0
- evidence: Direct quotes from source documents
- impact_areas: [financial, operational, reputational, regulatory, strategic]
- recommended_response: accept/monitor/investigate/escalate
- time_horizon: immediate/short-term/medium-term/long-term
</user>
```

### 8.3 Natural Language Query Prompt

```
<system>
You are a database query assistant for a GRC Risk Management platform.
Convert natural language questions into safe SQL queries.
RULES:
- Only generate SELECT statements. Never INSERT, UPDATE, DELETE, DROP, or ALTER.
- Always include WHERE organization_id = {org_id} for tenant isolation.
- Use the exact table and column names from the schema provided.
- If the question cannot be answered from the available data, say so.
- Format monetary values from kobo to Naira (divide by 100).
</system>

<user>
DATABASE SCHEMA:
{schema_context}

USER QUESTION: {question}

Generate:
1. sql_query: The safe SQL query
2. explanation: What this query does in plain English
3. visualization_type: table/bar_chart/line_chart/pie_chart/heatmap/number
</user>
```

---

## PART 9 — SUCCESS METRICS

### 9.1 How We Measure AI Module Success

| Metric | Target | Measurement Method |
|--------|--------|-------------------|
| Prediction Accuracy | >75% for risk escalation at 90 days | Track predictions vs actual outcomes |
| Emerging Risk Lead Time | >30 days before risk materializes | Time between AI detection and event occurrence |
| Regulatory Coverage | >90% of new CBN/NFIU/SEC circulars detected within 48 hours | Compare AI-detected vs manually identified |
| NL Query Accuracy | >85% of queries return correct results | User feedback + manual validation |
| User Adoption | >60% of CROs use AI features weekly | Usage analytics |
| Narrative Quality | >80% user satisfaction | In-app feedback ratings |
| System Uptime | >99.5% | Monitoring |
| Response Time | <5 seconds for predictions, <10 seconds for narratives | Latency monitoring |
| Guardian Safety | 0 harmful/hallucinated outputs reaching users | Guardian validation logs |

### 9.2 Competitive Benchmarks

| Capability | Archer | Our Solution (Post-AI) |
|-----------|--------|----------------------|
| Predictive Risk Scoring | Not available | Real ML predictions with accuracy tracking |
| Emerging Risk Detection | Manual process | AI-automated with Nigerian news/regulatory monitoring |
| Regulatory Intelligence | Manual document review | Automated ingestion, analysis, and gap detection |
| Natural Language Queries | Not available | Ask questions in plain English about risk data |
| Auto-Generated Reports | Template-based only | AI-generated narratives grounded in real data |
| Risk Correlation | Manual analysis | AI-discovered hidden correlations |
| Maturity Assessment | Manual checklist | AI-scored maturity with improvement recommendations |

---

## PART 10 — RISK MITIGATION FOR AI IMPLEMENTATION

| Risk | Mitigation |
|------|-----------|
| Granite model accuracy insufficient | Fallback to ensemble approach (Granite + statistical models); fine-tune on domain data |
| GPU not available at client site | Provide CPU-only deployment option with smaller models (Granite 2B + TTM) |
| Hallucination in narratives | Granite Guardian validates all outputs; all narratives cite specific data points |
| Data privacy concerns | All models run locally; zero external API calls; data never leaves the server |
| Model drift over time | Weekly prediction evaluation job; automatic alerts when accuracy drops below threshold |
| Regulatory website structure changes | Modular scrapers with fallback parsing; manual ingestion option as backup |
| Performance degradation under load | Redis caching (1-hour TTL); batch processing for heavy operations; async task queue |
| Multi-tenant data leakage | Organization-scoped vector store collections; all queries filtered by org_id; penetration testing |

---

*This prompt document serves as the complete engineering specification for implementing IBM Granite-powered AI intelligence into the GRC Risk Management platform. Every feature described here replaces mock data with real ML-driven intelligence, positioning our platform as the most AI-advanced risk management solution in the African market.*
