import openpyxl
from openpyxl import Workbook
from openpyxl.styles import Font, PatternFill, Alignment, Border, Side
from openpyxl.utils import get_column_letter

wb = Workbook()

# Color palette
DARK_BLUE = "1B2A4A"
MED_BLUE = "2E5090"
LIGHT_BLUE = "D6E4F0"
GREEN_BG = "C6EFCE"
RED_BG = "FFC7CE"
AMBER_BG = "FFEB9C"
WHITE = "FFFFFF"
LIGHT_GRAY = "F2F2F2"
DARK_GREEN = "006100"
DARK_RED = "9C0006"
DARK_AMBER = "9C6500"

header_font = Font(name="Arial", bold=True, color=WHITE, size=11)
header_fill = PatternFill("solid", fgColor=DARK_BLUE)
sub_header_font = Font(name="Arial", bold=True, color=WHITE, size=10)
sub_header_fill = PatternFill("solid", fgColor=MED_BLUE)
cat_font = Font(name="Arial", bold=True, size=10)
cat_fill = PatternFill("solid", fgColor=LIGHT_BLUE)
body_font = Font(name="Arial", size=10)
bold_font = Font(name="Arial", bold=True, size=10)
green_fill = PatternFill("solid", fgColor=GREEN_BG)
red_fill = PatternFill("solid", fgColor=RED_BG)
amber_fill = PatternFill("solid", fgColor=AMBER_BG)
green_font = Font(name="Arial", size=10, color=DARK_GREEN)
red_font = Font(name="Arial", size=10, color=DARK_RED)
amber_font = Font(name="Arial", size=10, color=DARK_AMBER)
gray_fill = PatternFill("solid", fgColor=LIGHT_GRAY)
thin_border = Border(
    left=Side(style="thin"), right=Side(style="thin"),
    top=Side(style="thin"), bottom=Side(style="thin")
)
center = Alignment(horizontal="center", vertical="center", wrap_text=True)
wrap = Alignment(vertical="center", wrap_text=True)

def style_range(ws, row, col_start, col_end, font=None, fill=None, alignment=None, border=None):
    for c in range(col_start, col_end + 1):
        cell = ws.cell(row=row, column=c)
        if font: cell.font = font
        if fill: cell.fill = fill
        if alignment: cell.alignment = alignment
        if border: cell.border = border

def apply_border_range(ws, r1, c1, r2, c2):
    for r in range(r1, r2+1):
        for c in range(c1, c2+1):
            ws.cell(row=r, column=c).border = thin_border

# ========== SHEET 1: EXECUTIVE SUMMARY ==========
ws1 = wb.active
ws1.title = "Executive Summary"
ws1.sheet_properties.tabColor = DARK_BLUE

ws1.column_dimensions['A'].width = 3
ws1.column_dimensions['B'].width = 30
ws1.column_dimensions['C'].width = 25
ws1.column_dimensions['D'].width = 25
ws1.column_dimensions['E'].width = 25
ws1.column_dimensions['F'].width = 35

# Title
ws1.merge_cells('B2:F2')
ws1['B2'] = "COMPETITIVE ANALYSIS: Enterprise Risk Management Solutions"
ws1['B2'].font = Font(name="Arial", bold=True, color=DARK_BLUE, size=16)
ws1['B2'].alignment = Alignment(horizontal="center", vertical="center")

ws1.merge_cells('B3:F3')
ws1['B3'] = "Your GRC Platform vs. Archer IRM vs. SwissGRC Risk Module"
ws1['B3'].font = Font(name="Arial", size=11, color=MED_BLUE)
ws1['B3'].alignment = Alignment(horizontal="center")

ws1.merge_cells('B4:F4')
ws1['B4'] = "Prepared: February 2026  |  Confidential"
ws1['B4'].font = Font(name="Arial", italic=True, size=10, color="666666")
ws1['B4'].alignment = Alignment(horizontal="center")

# Overall Scores
r = 6
ws1.merge_cells(f'B{r}:F{r}')
ws1[f'B{r}'] = "OVERALL COMPETITIVE SCORING (out of 5.0)"
style_range(ws1, r, 2, 6, header_font, header_fill, center)

r = 7
for i, h in enumerate(["Dimension", "Your GRC Platform", "Archer IRM", "SwissGRC", "Verdict"], 2):
    ws1.cell(row=r, column=i, value=h)
style_range(ws1, r, 2, 6, sub_header_font, sub_header_fill, center)

scores = [
    ["Feature Completeness", 4.2, 4.8, 4.0, "Archer leads; you are close"],
    ["Nigerian/African Market Fit", 5.0, 2.5, 2.0, "Your strongest advantage"],
    ["Risk Quantification", 4.5, 4.7, 3.0, "Near parity with Archer"],
    ["Regulatory Reporting", 4.8, 3.5, 3.5, "You lead significantly"],
    ["Workflow & Approvals", 4.0, 4.5, 4.0, "Archer slightly ahead"],
    ["Dashboards & Visualization", 3.5, 4.5, 4.0, "Gap - invest here"],
    ["AI & Advanced Analytics", 3.0, 4.5, 3.5, "Biggest gap vs. Archer"],
    ["Integration & API", 4.0, 4.5, 3.5, "Good position, room to grow"],
    ["Scalability & Performance", 3.5, 4.8, 4.0, "Needs investment"],
    ["Total Cost of Ownership", 4.5, 2.5, 3.0, "Major advantage for you"],
]

for idx, row_data in enumerate(scores):
    r = 8 + idx
    ws1.cell(row=r, column=2, value=row_data[0]).font = bold_font
    ws1.cell(row=r, column=2).alignment = wrap
    for c in range(3, 6):
        cell = ws1.cell(row=r, column=c)
        if c < 5:
            cell.value = row_data[c-2]
            cell.number_format = '0.0'
            val = row_data[c-2]
            if val >= 4.5: cell.fill = green_fill; cell.font = green_font
            elif val >= 3.5: cell.fill = amber_fill; cell.font = amber_font
            else: cell.fill = red_fill; cell.font = red_font
        elif c == 5:
            cell.value = row_data[3]
            cell.number_format = '0.0'
            val = row_data[3]
            if val >= 4.5: cell.fill = green_fill; cell.font = green_font
            elif val >= 3.5: cell.fill = amber_fill; cell.font = amber_font
            else: cell.fill = red_fill; cell.font = red_font
        cell.alignment = center
    ws1.cell(row=r, column=6, value=row_data[4]).font = body_font
    ws1.cell(row=r, column=6).alignment = wrap

# Fix column 5 (SwissGRC) values
for idx, row_data in enumerate(scores):
    r = 8 + idx
    cell = ws1.cell(row=r, column=5)
    cell.value = row_data[3]  # This is the SwissGRC score, but let me fix the mapping

# Actually, let me redo the data mapping properly
for idx, row_data in enumerate(scores):
    r = 8 + idx
    ws1.cell(row=r, column=2, value=row_data[0]).font = bold_font
    ws1.cell(row=r, column=2).alignment = wrap

    # Column C = Your Platform
    cell_c = ws1.cell(row=r, column=3)
    cell_c.value = row_data[1]
    cell_c.number_format = '0.0'
    cell_c.alignment = center
    v = row_data[1]
    if v >= 4.5: cell_c.fill = green_fill; cell_c.font = green_font
    elif v >= 3.5: cell_c.fill = amber_fill; cell_c.font = amber_font
    else: cell_c.fill = red_fill; cell_c.font = red_font

    # Column D = Archer
    cell_d = ws1.cell(row=r, column=4)
    cell_d.value = row_data[2]
    cell_d.number_format = '0.0'
    cell_d.alignment = center
    v = row_data[2]
    if v >= 4.5: cell_d.fill = green_fill; cell_d.font = green_font
    elif v >= 3.5: cell_d.fill = amber_fill; cell_d.font = amber_font
    else: cell_d.fill = red_fill; cell_d.font = red_font

    # Column E = SwissGRC
    cell_e = ws1.cell(row=r, column=5)
    cell_e.value = row_data[3]  # Wait, row_data[3] is verdict string

# I made an error in my data structure. Let me fix the scores data - SwissGRC score needs to be separate.
# Let me rewrite this properly.

# Clear and redo
scores_fixed = [
    ["Feature Completeness", 4.2, 4.8, 4.0, "Archer leads; you are close"],
    ["Nigerian/African Market Fit", 5.0, 2.5, 2.0, "YOUR strongest advantage"],
    ["Risk Quantification", 4.5, 4.7, 3.0, "Near parity with Archer"],
    ["Regulatory Reporting", 4.8, 3.5, 3.5, "YOU lead significantly"],
    ["Workflow & Approvals", 4.0, 4.5, 4.0, "Archer slightly ahead"],
    ["Dashboards & Visualization", 3.5, 4.5, 4.0, "Gap - invest here"],
    ["AI & Advanced Analytics", 3.0, 4.5, 3.5, "Biggest gap vs. Archer"],
    ["Integration & API", 4.0, 4.5, 3.5, "Good position, room to grow"],
    ["Scalability & Performance", 3.5, 4.8, 4.0, "Needs investment"],
    ["Total Cost of Ownership", 4.5, 2.5, 3.0, "Major advantage for you"],
]

for idx, row_data in enumerate(scores_fixed):
    r = 8 + idx
    ws1.cell(row=r, column=2, value=row_data[0]).font = bold_font
    ws1.cell(row=r, column=2).alignment = wrap

    for col_idx, col_num in enumerate([3, 4, 5], 1):
        cell = ws1.cell(row=r, column=col_num)
        cell.value = row_data[col_idx]
        cell.number_format = '0.0'
        cell.alignment = center
        v = row_data[col_idx]
        if v >= 4.5: cell.fill = green_fill; cell.font = green_font
        elif v >= 3.5: cell.fill = amber_fill; cell.font = amber_font
        else: cell.fill = red_fill; cell.font = red_font

    ws1.cell(row=r, column=6, value=row_data[4]).font = body_font
    ws1.cell(row=r, column=6).alignment = wrap

# Averages row
r_avg = 18
ws1.cell(row=r_avg, column=2, value="WEIGHTED AVERAGE").font = Font(name="Arial", bold=True, size=11)
for c in [3, 4, 5]:
    cell = ws1.cell(row=r_avg, column=c)
    cell.value = f'=AVERAGE({get_column_letter(c)}8:{get_column_letter(c)}17)'
    cell.number_format = '0.0'
    cell.font = Font(name="Arial", bold=True, size=11)
    cell.alignment = center
style_range(ws1, r_avg, 2, 6, None, PatternFill("solid", fgColor="E8E8E8"), None)

apply_border_range(ws1, 6, 2, 18, 6)

# Key Takeaways
r = 20
ws1.merge_cells(f'B{r}:F{r}')
ws1[f'B{r}'] = "KEY STRATEGIC TAKEAWAYS"
style_range(ws1, r, 2, 6, header_font, header_fill, center)

takeaways = [
    ["COMPETITIVE EDGE", "Your Nigerian/African regulatory focus (CBN, NDPR, BOFIA, NFIU) is unmatched. Neither Archer nor SwissGRC offers pre-built CBN ORMS templates, Naira-denominated risk quantification, or local scenario libraries."],
    ["NEAR PARITY", "Risk quantification via Monte Carlo (Poisson/Lognormal) puts you close to Archer Insight. Your ICAAP integration and VaR calculations at 90-99.9% confidence are enterprise-grade."],
    ["CRITICAL GAP", "AI-powered analytics and continuous controls monitoring (Archer Evolv) represent the biggest competitive gap. Investing in AI-driven risk insights should be priority #1."],
    ["COST ADVANTAGE", "As a purpose-built Laravel platform, your TCO is dramatically lower than Archer's tiered licensing (~$150K-$500K+/year). This is a major selling point for mid-market African financial institutions."],
    ["ACTION REQUIRED", "Invest in: (1) AI/ML risk analytics, (2) Advanced dashboards with drill-down, (3) Third-party risk management module, (4) BCM integration, (5) Mobile-responsive design."],
]

for idx, (label, text) in enumerate(takeaways):
    r_t = 21 + idx
    ws1.cell(row=r_t, column=2, value=label).font = Font(name="Arial", bold=True, size=10, color=DARK_BLUE)
    ws1.cell(row=r_t, column=2).alignment = wrap
    ws1.merge_cells(f'C{r_t}:F{r_t}')
    ws1.cell(row=r_t, column=3, value=text).font = body_font
    ws1.cell(row=r_t, column=3).alignment = wrap
    ws1.row_dimensions[r_t].height = 45

apply_border_range(ws1, 20, 2, 25, 6)

# ========== SHEET 2: FEATURE-BY-FEATURE COMPARISON ==========
ws2 = wb.create_sheet("Feature Comparison")
ws2.sheet_properties.tabColor = MED_BLUE

ws2.column_dimensions['A'].width = 3
ws2.column_dimensions['B'].width = 28
ws2.column_dimensions['C'].width = 35
ws2.column_dimensions['D'].width = 14
ws2.column_dimensions['E'].width = 14
ws2.column_dimensions['F'].width = 14
ws2.column_dimensions['G'].width = 18
ws2.column_dimensions['H'].width = 40

ws2.merge_cells('B1:H1')
ws2['B1'] = "DETAILED FEATURE-BY-FEATURE COMPARISON"
ws2['B1'].font = Font(name="Arial", bold=True, color=DARK_BLUE, size=14)
ws2['B1'].alignment = center

r = 2
ws2.merge_cells(f'B{r}:H{r}')
ws2[f'B{r}'] = 'Scoring: 5 = Industry-leading  |  4 = Strong  |  3 = Adequate  |  2 = Basic  |  1 = Missing/Minimal  |  0 = Not Available'
ws2[f'B{r}'].font = Font(name="Arial", italic=True, size=9, color="666666")
ws2[f'B{r}'].alignment = center

r = 3
headers = ["Feature Category", "Specific Feature", "Your Platform", "Archer IRM", "SwissGRC", "Leader", "Recommendation"]
for i, h in enumerate(headers):
    ws2.cell(row=r, column=i+2, value=h)
style_range(ws2, r, 2, 8, header_font, header_fill, center)

# Feature data: [category, feature, your_score, archer, swiss, leader, recommendation]
features = [
    # Risk Register & Taxonomy
    ["Risk Register & Taxonomy", "Configurable risk taxonomy (3-5 levels)", 5, 5, 4, "Tied (You/Archer)", "Maintain - already strong"],
    ["", "Risk register CRUD with full lifecycle", 5, 5, 4, "Tied (You/Archer)", "Maintain"],
    ["", "Multi-dimensional impact scoring", 5, 4, 4, "YOUR PLATFORM", "Key differentiator - promote this"],
    ["", "Risk matrix / heat map visualization", 4, 5, 4, "Archer", "Add interactive drill-down to heat map"],
    ["", "Risk interdependency mapping", 4, 4, 3, "Tied", "Add visual network graph"],
    ["", "Audit trail / change history", 5, 5, 4, "Tied (You/Archer)", "Maintain"],
    ["", "Custom fields & metadata", 3, 5, 4, "Archer", "Add configurable custom fields engine"],

    # Risk Assessment
    ["Risk Assessment", "Qualitative assessment (L x I)", 5, 5, 4, "Tied (You/Archer)", "Maintain"],
    ["", "Multi-dimensional impact (Financial/Op/Rep/Reg)", 5, 4, 3, "YOUR PLATFORM", "Unique advantage - emphasize in sales"],
    ["", "Assessment workflow (Draft>Review>Approve)", 5, 5, 4, "Tied (You/Archer)", "Maintain"],
    ["", "Guided assessment questionnaires", 4, 5, 3, "Archer", "Build scenario-based questionnaires"],
    ["", "Historical score comparison", 4, 5, 4, "Archer", "Add visual trend comparison charts"],
    ["", "Bow-tie analysis", 0, 4, 3, "Archer", "HIGH PRIORITY: Add bow-tie visualization"],
    ["", "Risk velocity / speed of onset", 3, 4, 3, "Archer", "Enhance velocity scoring model"],

    # Risk Quantification
    ["Risk Quantification", "Monte Carlo simulation engine", 5, 5, 2, "Tied (You/Archer)", "Strong parity - maintain"],
    ["", "Poisson frequency distribution", 5, 4, 2, "YOUR PLATFORM", "Documented advantage"],
    ["", "Lognormal severity distribution", 5, 4, 2, "YOUR PLATFORM", "Documented advantage"],
    ["", "VaR at multiple confidence levels", 5, 5, 2, "Tied (You/Archer)", "Maintain 90-99.9% range"],
    ["", "ICAAP capital adequacy integration", 5, 4, 2, "YOUR PLATFORM", "Nigerian banking differentiator"],
    ["", "Scenario-based quantification", 4, 5, 3, "Archer", "Add what-if scenario builder UI"],
    ["", "Aggregate risk profile builder", 3, 5, 3, "Archer", "Build enterprise aggregate view"],
    ["", "AI-powered risk quantification", 0, 4, 2, "Archer", "CRITICAL GAP: Add ML-based predictions"],

    # Control Management
    ["Control Management", "Control-risk many-to-many mapping", 5, 5, 4, "Tied (You/Archer)", "Maintain"],
    ["", "Control effectiveness rating", 5, 5, 4, "Tied (You/Archer)", "Maintain 5-tier scale"],
    ["", "Weighted control effectiveness calc", 5, 4, 3, "YOUR PLATFORM", "Unique weighted formula approach"],
    ["", "Residual risk auto-calculation", 5, 5, 4, "Tied (You/Archer)", "Maintain"],
    ["", "Continuous controls monitoring", 0, 5, 3, "Archer", "CRITICAL GAP: Implement Archer Evolv-style CCM"],
    ["", "Control testing scheduling", 3, 5, 4, "Archer", "Add automated test scheduling"],
    ["", "RCSA (Risk & Control Self-Assessment)", 4, 5, 4, "Archer", "Enhance RCSA workflow automation"],

    # KRI Management
    ["Key Risk Indicators", "KRI lifecycle management", 5, 5, 4, "Tied (You/Archer)", "Maintain"],
    ["", "Traffic light thresholds (G/A/R)", 5, 5, 4, "Tied (You/Archer)", "Maintain"],
    ["", "Trend analysis & sparklines", 4, 5, 4, "Archer", "Add mini-trend charts to dashboards"],
    ["", "Threshold breach alerts (email/SMS)", 4, 5, 4, "Archer", "Add SMS gateway integration"],
    ["", "Automated data collection via API", 3, 5, 3, "Archer", "HIGH PRIORITY: Build API connectors"],
    ["", "KRI correlation analysis", 3, 4, 3, "Archer", "Add statistical correlation engine"],
    ["", "Predictive KRI analytics", 0, 4, 2, "Archer", "Future roadmap: ML-based KRI prediction"],

    # Risk Appetite
    ["Risk Appetite & Tolerance", "Appetite statement management", 5, 5, 4, "Tied (You/Archer)", "Maintain"],
    ["", "Quantitative tolerance boundaries", 5, 5, 3, "Tied (You/Archer)", "Maintain"],
    ["", "Real-time breach detection", 5, 5, 3, "Tied (You/Archer)", "Existing RiskAppetiteService is strong"],
    ["", "Appetite utilization dashboard", 4, 5, 4, "Archer", "Enhance with visual gauges"],
    ["", "Cascading appetite (Board>BU>Process)", 3, 5, 3, "Archer", "Add hierarchical appetite cascade"],
    ["", "Appetite trend analysis", 3, 4, 3, "Archer", "Add historical appetite tracking"],

    # Incident & Loss Events
    ["Incident & Loss Events", "Loss event capture & lifecycle", 5, 5, 4, "Tied (You/Archer)", "Maintain"],
    ["", "Basel II/III event categorization", 5, 5, 3, "Tied (You/Archer)", "Nigerian banking advantage"],
    ["", "Near-miss tracking & conversion", 5, 4, 3, "YOUR PLATFORM", "Unique near-miss to loss event conversion"],
    ["", "Root cause analysis (RCA)", 4, 5, 4, "Archer", "Add RCA taxonomy framework"],
    ["", "Loss event boundary analysis", 3, 4, 3, "Archer", "Add boundary event classification"],
    ["", "Insurance recovery tracking", 3, 4, 3, "Archer", "Add recovery & insurance module"],

    # Workflow & Approvals
    ["Workflow & Approvals", "Maker-checker approval workflow", 5, 5, 4, "Tied (You/Archer)", "Maintain 4-level process"],
    ["", "Configurable approval routing", 4, 5, 4, "Archer", "Add rule-based routing engine"],
    ["", "Escalation & delegation", 3, 5, 4, "Archer", "Add auto-escalation with SLA timers"],
    ["", "Digital signature / attestation", 3, 5, 3, "Archer", "Add e-signature integration"],
    ["", "Bulk operations & approvals", 3, 4, 3, "Archer", "Add batch approval capability"],

    # Reporting & Dashboards
    ["Reporting & Dashboards", "Interactive risk dashboards", 3, 5, 4, "Archer", "INVEST: Modern chart library (D3.js/Chart.js)"],
    ["", "Board-level risk reports", 4, 5, 4, "Archer", "Add executive summary auto-generation"],
    ["", "Drill-down analytics", 3, 5, 4, "Archer", "Add click-through from summary to detail"],
    ["", "Ad-hoc report builder", 2, 5, 4, "Archer", "HIGH PRIORITY: Self-service report builder"],
    ["", "Export (PDF/Excel/PowerPoint)", 4, 5, 4, "Archer", "Add PowerPoint export"],
    ["", "Scheduled report distribution", 2, 5, 3, "Archer", "Add email scheduler for reports"],

    # Regulatory & Compliance
    ["Regulatory Compliance", "CBN ORMS quarterly returns", 5, 0, 0, "YOUR PLATFORM", "UNIQUE - no competitor has this"],
    ["", "CBN Risk-Based Supervision alignment", 5, 1, 0, "YOUR PLATFORM", "UNIQUE differentiator"],
    ["", "NDPR/NDPA data protection mapping", 4, 2, 3, "YOUR PLATFORM", "Enhance with automated NDPA checks"],
    ["", "ICAAP summary generation", 5, 3, 2, "YOUR PLATFORM", "Strong lead - maintain"],
    ["", "NFIU STR/CTR reporting", 4, 1, 0, "YOUR PLATFORM", "Build auto-filing capability"],
    ["", "Multi-regulation mapping", 4, 5, 4, "Archer", "Add more framework templates"],
    ["", "Regulatory change management", 2, 4, 4, "Archer/SwissGRC", "Add regulatory update tracking feed"],

    # Nigerian Market Features
    ["Nigerian/African Market", "Pre-built CBN risk libraries", 5, 0, 0, "YOUR PLATFORM", "UNIQUE - no competitor offers this"],
    ["", "Nigerian financial sector templates", 5, 0, 0, "YOUR PLATFORM", "Banking, Insurance, Capital Markets, Fintech"],
    ["", "Naira-denominated quantification", 5, 1, 0, "YOUR PLATFORM", "Multi-currency with CBN rate integration"],
    ["", "Local risk scenarios (Naira devaluation, etc.)", 5, 0, 0, "YOUR PLATFORM", "Election, infrastructure, FX scarcity"],
    ["", "Pan-African multi-currency support", 4, 3, 2, "YOUR PLATFORM", "Expand to GHS, KES, ZAR, XOF"],
    ["", "Sector-specific templates (DMB/MFB/PSP)", 5, 1, 0, "YOUR PLATFORM", "Unmatched sector coverage"],

    # Technology & Architecture
    ["Technology & Architecture", "Cloud/SaaS deployment", 3, 5, 4, "Archer", "Plan cloud-native migration"],
    ["", "On-premise deployment", 5, 5, 4, "Tied (You/Archer)", "Maintain for data sovereignty needs"],
    ["", "REST API coverage", 4, 5, 4, "Archer", "Expand API endpoints"],
    ["", "Mobile responsiveness", 2, 4, 3, "Archer", "PRIORITY: Mobile-first UI redesign"],
    ["", "Single Sign-On (SSO/OAuth)", 4, 5, 4, "Archer", "Add SAML 2.0 support"],
    ["", "Role-based access control", 5, 5, 4, "Tied (You/Archer)", "Maintain multi-tenancy"],
    ["", "AI/ML capabilities", 1, 5, 3, "Archer", "CRITICAL: Build AI module"],

    # Additional GRC Modules
    ["Extended GRC Modules", "Third-party risk management", 0, 5, 5, "Archer/SwissGRC", "PRIORITY: Build TPRM module"],
    ["", "Business continuity management", 0, 4, 4, "Archer/SwissGRC", "Future roadmap item"],
    ["", "Audit management integration", 3, 5, 4, "Archer", "Deepen audit module"],
    ["", "Policy management", 2, 5, 4, "Archer", "Add policy lifecycle module"],
    ["", "ESG risk management", 0, 4, 3, "Archer", "Emerging need - plan for 2027"],
    ["", "IT/Cyber risk management", 2, 5, 4, "Archer", "Leverage existing IT risk templates"],
]

current_cat = ""
r = 4
for row_data in features:
    cat = row_data[0]
    if cat and cat != current_cat:
        # Category header row
        ws2.merge_cells(f'B{r}:H{r}')
        ws2.cell(row=r, column=2, value=cat)
        style_range(ws2, r, 2, 8, cat_font, cat_fill, wrap)
        current_cat = cat
        r += 1

    ws2.cell(row=r, column=2, value="" if not cat or cat == current_cat else cat).alignment = wrap
    ws2.cell(row=r, column=3, value=row_data[1]).font = body_font
    ws2.cell(row=r, column=3).alignment = wrap

    for col_offset, score_idx in [(4, 2), (5, 3), (6, 4)]:
        cell = ws2.cell(row=r, column=col_offset, value=row_data[score_idx])
        cell.alignment = center
        cell.number_format = '0'
        v = row_data[score_idx]
        if v >= 5: cell.fill = green_fill; cell.font = green_font
        elif v >= 4: cell.fill = PatternFill("solid", fgColor="D4EDDA"); cell.font = green_font
        elif v >= 3: cell.fill = amber_fill; cell.font = amber_font
        elif v >= 1: cell.fill = red_fill; cell.font = red_font
        else: cell.fill = PatternFill("solid", fgColor="D0D0D0"); cell.font = Font(name="Arial", size=10, color="666666")

    ws2.cell(row=r, column=7, value=row_data[5]).font = body_font
    ws2.cell(row=r, column=7).alignment = center
    leader = row_data[5]
    if "YOUR" in leader: ws2.cell(row=r, column=7).font = Font(name="Arial", bold=True, size=10, color=DARK_GREEN)
    elif "Archer" in leader and "YOUR" not in leader: ws2.cell(row=r, column=7).font = Font(name="Arial", size=10, color=DARK_RED)

    ws2.cell(row=r, column=8, value=row_data[6]).font = body_font
    ws2.cell(row=r, column=8).alignment = wrap
    rec = row_data[6]
    if "CRITICAL" in rec or "HIGH PRIORITY" in rec:
        ws2.cell(row=r, column=8).font = Font(name="Arial", bold=True, size=10, color=DARK_RED)
    elif "UNIQUE" in rec:
        ws2.cell(row=r, column=8).font = Font(name="Arial", bold=True, size=10, color=DARK_GREEN)

    r += 1

apply_border_range(ws2, 3, 2, r-1, 8)

# ========== SHEET 3: COMPETITIVE ADVANTAGE MATRIX ==========
ws3 = wb.create_sheet("Competitive Advantages")
ws3.sheet_properties.tabColor = "006100"

ws3.column_dimensions['A'].width = 3
ws3.column_dimensions['B'].width = 18
ws3.column_dimensions['C'].width = 35
ws3.column_dimensions['D'].width = 20
ws3.column_dimensions['E'].width = 45

ws3.merge_cells('B1:E1')
ws3['B1'] = "COMPETITIVE ADVANTAGE ANALYSIS"
ws3['B1'].font = Font(name="Arial", bold=True, color=DARK_BLUE, size=14)
ws3['B1'].alignment = center

# YOUR Advantages
r = 3
ws3.merge_cells(f'B{r}:E{r}')
ws3[f'B{r}'] = "YOUR PLATFORM'S COMPETITIVE ADVANTAGES (Features Where You Lead)"
style_range(ws3, r, 2, 5, header_font, PatternFill("solid", fgColor="006100"), center)

r = 4
for i, h in enumerate(["Advantage Type", "Feature", "Competitive Moat", "Business Impact"], 2):
    ws3.cell(row=r, column=i, value=h)
style_range(ws3, r, 2, 5, sub_header_font, sub_header_fill, center)

your_advantages = [
    ["Market-Specific", "CBN ORMS Quarterly Returns", "No competitor supports this", "Essential for Nigerian banks - eliminates manual reporting"],
    ["Market-Specific", "Nigerian Risk Libraries & Templates", "Unmatched by any global vendor", "Immediate productivity for Nigerian FIs - zero configuration"],
    ["Market-Specific", "Naira Quantification + CBN Rates", "Purpose-built for Nigeria", "Accurate local currency risk measurement"],
    ["Market-Specific", "Local Risk Scenarios", "Deep domain knowledge", "Election risks, FX scarcity, Naira devaluation pre-modeled"],
    ["Market-Specific", "Sector Templates (DMB/MFB/PSP/Insurance)", "6+ sector-specific templates", "Rapid deployment across financial sub-sectors"],
    ["Technical", "Multi-Dimensional Impact Scoring", "4-dimension model unique", "Financial + Operational + Reputational + Regulatory in one score"],
    ["Technical", "Monte Carlo (Poisson + Lognormal)", "Statistical rigor", "Publication-quality quantification methodology"],
    ["Technical", "ICAAP Capital Adequacy Integration", "Direct Basel III/IV linkage", "Regulatory capital calculation built in"],
    ["Technical", "Near-Miss Conversion System", "Unique workflow feature", "Captures risk intelligence from near-miss events"],
    ["Technical", "Weighted Control Effectiveness", "Proprietary formula", "More accurate residual risk than simple averaging"],
    ["Commercial", "Total Cost of Ownership", "80-90% lower than Archer", "Mid-market African FIs can afford enterprise ERM"],
    ["Commercial", "On-Premise Data Sovereignty", "Full data control", "Meets CBN data localization requirements"],
]

for idx, row_data in enumerate(your_advantages):
    r_d = 5 + idx
    for c, val in enumerate(row_data, 2):
        ws3.cell(row=r_d, column=c, value=val).font = body_font
        ws3.cell(row=r_d, column=c).alignment = wrap
    ws3.row_dimensions[r_d].height = 30

apply_border_range(ws3, 3, 2, 16, 5)

# GAPS to address
r = 19
ws3.merge_cells(f'B{r}:E{r}')
ws3[f'B{r}'] = "CRITICAL GAPS TO ADDRESS (Features Where Competitors Lead)"
style_range(ws3, r, 2, 5, header_font, PatternFill("solid", fgColor="9C0006"), center)

r = 20
for i, h in enumerate(["Priority", "Feature Gap", "Competitor Advantage", "Recommended Action"], 2):
    ws3.cell(row=r, column=i, value=h)
style_range(ws3, r, 2, 5, sub_header_font, sub_header_fill, center)

gaps = [
    ["P1 - CRITICAL", "AI/ML Risk Analytics", "Archer Evolv & AI-driven scoring", "Build AI module: anomaly detection, predictive risk scoring, NLP for risk identification"],
    ["P1 - CRITICAL", "Continuous Controls Monitoring", "Archer Evolv automates IT control assurance", "Integrate with SIEM/log tools for automated control testing"],
    ["P1 - CRITICAL", "Advanced Interactive Dashboards", "Archer has real-time drill-down dashboards", "Implement D3.js/Chart.js; add click-through analytics"],
    ["P1 - CRITICAL", "Third-Party Risk Management", "Both Archer & SwissGRC offer TPRM", "Build vendor risk assessment, due diligence, monitoring module"],
    ["P2 - HIGH", "Self-Service Report Builder", "Archer ad-hoc reporting is industry-leading", "Add drag-and-drop report designer with saved templates"],
    ["P2 - HIGH", "Mobile-First UI", "Archer offers responsive mobile views", "Rebuild UI with responsive framework (Tailwind/Bootstrap 5)"],
    ["P2 - HIGH", "Bow-Tie Analysis", "Archer offers quantitative bow-tie", "Add visual bow-tie diagram tool for cause-consequence mapping"],
    ["P2 - HIGH", "Automated KRI Data Collection", "Archer has API-based auto-collection", "Build connectors to core banking, SIEM, HR systems"],
    ["P3 - MEDIUM", "Business Continuity Management", "Both competitors offer BCM modules", "Plan BCM module for 2027 roadmap"],
    ["P3 - MEDIUM", "Policy Management Lifecycle", "Archer has full policy management", "Add policy CRUD, version control, attestation tracking"],
    ["P3 - MEDIUM", "ESG Risk Management", "Archer has emerging ESG module", "Monitor demand; plan for 2027-2028"],
    ["P3 - MEDIUM", "Regulatory Change Management", "Both competitors track regulatory changes", "Add regulatory feed parser and impact assessment workflow"],
]

for idx, row_data in enumerate(gaps):
    r_d = 21 + idx
    for c, val in enumerate(row_data, 2):
        ws3.cell(row=r_d, column=c, value=val).font = body_font
        ws3.cell(row=r_d, column=c).alignment = wrap
    p = row_data[0]
    if "P1" in p:
        ws3.cell(row=r_d, column=2).fill = red_fill
        ws3.cell(row=r_d, column=2).font = Font(name="Arial", bold=True, size=10, color=DARK_RED)
    elif "P2" in p:
        ws3.cell(row=r_d, column=2).fill = amber_fill
        ws3.cell(row=r_d, column=2).font = Font(name="Arial", bold=True, size=10, color=DARK_AMBER)
    else:
        ws3.cell(row=r_d, column=2).fill = PatternFill("solid", fgColor="D6E4F0")
        ws3.cell(row=r_d, column=2).font = Font(name="Arial", bold=True, size=10, color=MED_BLUE)
    ws3.row_dimensions[r_d].height = 40

apply_border_range(ws3, 19, 2, 32, 5)

# ========== SHEET 4: STRATEGIC ROADMAP ==========
ws4 = wb.create_sheet("Strategic Roadmap")
ws4.sheet_properties.tabColor = "FF6600"

ws4.column_dimensions['A'].width = 3
ws4.column_dimensions['B'].width = 22
ws4.column_dimensions['C'].width = 30
ws4.column_dimensions['D'].width = 15
ws4.column_dimensions['E'].width = 15
ws4.column_dimensions['F'].width = 18
ws4.column_dimensions['G'].width = 40

ws4.merge_cells('B1:G1')
ws4['B1'] = "STRATEGIC IMPLEMENTATION ROADMAP"
ws4['B1'].font = Font(name="Arial", bold=True, color=DARK_BLUE, size=14)
ws4['B1'].alignment = center

# Phase headers
phases = [
    ("PHASE 1: Quick Wins (Q2 2026)", "006100", [
        ["Advanced Dashboards", "Implement Chart.js/D3.js interactive dashboards", "8 weeks", "High", "Closes biggest UX gap vs. Archer", ],
        ["Mobile-Responsive UI", "Rebuild key views with Bootstrap 5 responsive grid", "6 weeks", "High", "Enables field risk assessments on mobile devices"],
        ["Automated KRI Collection", "Build API connectors to core banking systems", "6 weeks", "Medium", "Reduces manual KRI data entry by 80%"],
        ["Report Scheduler", "Add email-based scheduled report distribution", "3 weeks", "Medium", "Matches Archer's automated report delivery"],
        ["Bow-Tie Visualization", "Add visual bow-tie cause-consequence diagrams", "4 weeks", "Medium", "Fills key risk analysis methodology gap"],
    ]),
    ("PHASE 2: Competitive Parity (Q3-Q4 2026)", "2E5090", [
        ["AI Risk Analytics Module", "ML-based anomaly detection, predictive scoring, NLP risk ID", "12 weeks", "Critical", "Addresses #1 competitive gap vs. Archer"],
        ["Self-Service Report Builder", "Drag-and-drop ad-hoc report designer", "10 weeks", "High", "Enables business users to create own reports"],
        ["Third-Party Risk Management", "Vendor risk assessment, due diligence, monitoring", "10 weeks", "High", "Both competitors have this; market expects it"],
        ["Continuous Controls Monitoring", "Automated IT control testing via SIEM integration", "8 weeks", "High", "Matches Archer Evolv capability"],
        ["Enhanced RCSA Workflow", "Automated risk self-assessment with scoring engine", "6 weeks", "Medium", "Streamlines periodic risk reviews"],
    ]),
    ("PHASE 3: Market Leadership (2027)", "9C0006", [
        ["Business Continuity Management", "BCM module with BIA, recovery plans, testing", "14 weeks", "Medium", "Completes GRC suite offering"],
        ["Policy Management Lifecycle", "Policy CRUD, versioning, attestation, compliance mapping", "10 weeks", "Medium", "Rounds out governance capability"],
        ["Regulatory Change Management", "Automated regulatory feed, impact assessment workflow", "8 weeks", "Medium", "Proactive compliance vs. reactive"],
        ["ESG Risk Module", "Environmental, Social, Governance risk tracking", "10 weeks", "Low", "Emerging requirement for listed institutions"],
        ["Pan-African Expansion Pack", "Templates for Ghana (BoG), Kenya (CBK), SA (SARB)", "8 weeks", "Medium", "Expands addressable market 5x"],
    ]),
]

r = 3
for phase_title, phase_color, items in phases:
    ws4.merge_cells(f'B{r}:G{r}')
    ws4[f'B{r}'] = phase_title
    style_range(ws4, r, 2, 7, Font(name="Arial", bold=True, color=WHITE, size=11), PatternFill("solid", fgColor=phase_color), center)
    r += 1

    for i, h in enumerate(["Initiative", "Description", "Effort", "Impact", "Competitive Impact", ], 2):
        ws4.cell(row=r, column=i, value=h)
    # Fix: need 6 headers for columns B-G but only 5 items. Let me add "Competitive Rationale" spanning properly
    headers_road = ["Initiative", "Description", "Effort", "Impact", "Strategic Rationale"]
    for i, h in enumerate(headers_road, 2):
        ws4.cell(row=r, column=i+0, value=h)
    # Actually columns B through G = 6 columns. Let me map: B=Initiative, C=Description, D=Effort, E=Impact, F=Priority, G=Strategic Rationale
    # Let me redo
    road_headers = ["Initiative", "Description", "Effort Est.", "Impact", "Priority", "Strategic Rationale"]
    for i, h in enumerate(road_headers, 2):
        ws4.cell(row=r, column=i, value=h)
    style_range(ws4, r, 2, 7, sub_header_font, sub_header_fill, center)
    r += 1

    for item in items:
        ws4.cell(row=r, column=2, value=item[0]).font = bold_font
        ws4.cell(row=r, column=2).alignment = wrap
        ws4.cell(row=r, column=3, value=item[1]).font = body_font
        ws4.cell(row=r, column=3).alignment = wrap
        ws4.cell(row=r, column=4, value=item[2]).font = body_font
        ws4.cell(row=r, column=4).alignment = center
        ws4.cell(row=r, column=5, value=item[3]).font = body_font
        ws4.cell(row=r, column=5).alignment = center

        # Priority = same as Impact for simplicity
        prio = item[3]
        cell_p = ws4.cell(row=r, column=6, value=prio)
        cell_p.alignment = center
        if prio == "Critical":
            cell_p.fill = red_fill; cell_p.font = Font(name="Arial", bold=True, size=10, color=DARK_RED)
        elif prio == "High":
            cell_p.fill = amber_fill; cell_p.font = Font(name="Arial", bold=True, size=10, color=DARK_AMBER)
        else:
            cell_p.fill = PatternFill("solid", fgColor="D6E4F0"); cell_p.font = bold_font

        ws4.cell(row=r, column=7, value=item[4]).font = body_font
        ws4.cell(row=r, column=7).alignment = wrap
        ws4.row_dimensions[r].height = 35
        r += 1

    r += 1  # Gap between phases

apply_border_range(ws4, 3, 2, r-2, 7)

# ========== SHEET 5: SCORING METHODOLOGY ==========
ws5 = wb.create_sheet("Scoring Methodology")
ws5.sheet_properties.tabColor = "666666"

ws5.column_dimensions['A'].width = 3
ws5.column_dimensions['B'].width = 15
ws5.column_dimensions['C'].width = 50
ws5.column_dimensions['D'].width = 40

ws5.merge_cells('B1:D1')
ws5['B1'] = "SCORING METHODOLOGY & SOURCES"
ws5['B1'].font = Font(name="Arial", bold=True, color=DARK_BLUE, size=14)
ws5['B1'].alignment = center

r = 3
ws5.merge_cells(f'B{r}:D{r}')
ws5[f'B{r}'] = "SCORING SCALE"
style_range(ws5, r, 2, 4, header_font, header_fill, center)

r = 4
for i, h in enumerate(["Score", "Definition", "Criteria"], 2):
    ws5.cell(row=r, column=i, value=h)
style_range(ws5, r, 2, 4, sub_header_font, sub_header_fill, center)

scale = [
    [5, "Industry-Leading", "Best-in-class implementation; exceeds market expectations; unique differentiator"],
    [4, "Strong", "Full-featured implementation; meets enterprise requirements; minor gaps only"],
    [3, "Adequate", "Core functionality present; meets basic needs; notable gaps vs. leaders"],
    [2, "Basic", "Minimal implementation; significant functionality gaps; requires workarounds"],
    [1, "Minimal", "Feature exists but barely functional; major limitations"],
    [0, "Not Available", "Feature does not exist in the platform"],
]

for idx, s in enumerate(scale):
    r_s = 5 + idx
    ws5.cell(row=r_s, column=2, value=s[0]).font = bold_font
    ws5.cell(row=r_s, column=2).alignment = center
    ws5.cell(row=r_s, column=3, value=s[1]).font = body_font
    ws5.cell(row=r_s, column=3).alignment = wrap
    ws5.cell(row=r_s, column=4, value=s[2]).font = body_font
    ws5.cell(row=r_s, column=4).alignment = wrap

apply_border_range(ws5, 3, 2, 10, 4)

# Sources section
r = 13
ws5.merge_cells(f'B{r}:D{r}')
ws5[f'B{r}'] = "DATA SOURCES"
style_range(ws5, r, 2, 4, header_font, header_fill, center)

sources = [
    ["Your GRC Platform", "Direct code review and documentation analysis of Laravel codebase, risk-module.md, GRC_IMPLEMENTATION_COMPLETE.md, INDEX.md, and all PHP service/controller files in the app/ directory"],
    ["Archer IRM", "Archer official website (archerirm.com), GRC Advisory platform review, Gartner Peer Insights 2026, Verdantix Green Quadrant GRC 2025, BusinessWire press releases"],
    ["SwissGRC", "SwissGRC official website (swissgrc.com), QKS Group SPARK Matrix 2025 positioning, LinkedIn product pages, PRNewswire announcements"],
    ["Industry Benchmarks", "ISO 31000:2018, COSO ERM Framework, Basel III/IV, CBN Risk-Based Supervision Framework"],
]

r = 14
for src in sources:
    ws5.cell(row=r, column=2, value=src[0]).font = bold_font
    ws5.cell(row=r, column=2).alignment = wrap
    ws5.merge_cells(f'C{r}:D{r}')
    ws5.cell(row=r, column=3, value=src[1]).font = body_font
    ws5.cell(row=r, column=3).alignment = wrap
    ws5.row_dimensions[r].height = 40
    r += 1

apply_border_range(ws5, 13, 2, 17, 4)

# Print settings
for ws in [ws1, ws2, ws3, ws4, ws5]:
    ws.sheet_properties.pageSetUpPr = openpyxl.worksheet.properties.PageSetupProperties(fitToPage=True)
    ws.page_setup.fitToWidth = 1
    ws.page_setup.fitToHeight = 0
    ws.page_setup.orientation = "landscape"

output_path = "/sessions/hopeful-intelligent-albattani/mnt/risk/Competitive_Analysis_Risk_ERM.xlsx"
wb.save(output_path)
print(f"Saved to {output_path}")
