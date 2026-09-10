# GRC Platform - Developer Quick Start Guide

## Quick File Locations

### Services (Reusable Business Logic)
```
app/Services/
├── RiskAppetiteService.php          # Appetite monitoring & breach detection
├── RegulatoryReportService.php      # Regulatory compliance reporting
├── ApprovalService.php              # Approval workflow logic
├── MonteCarloService.php            # Quantification simulations
└── FileUploadService.php            # File upload & validation
```

### Models (Database Entities)
```
app/Models/
└── ApprovalRequest.php              # Approval workflow records
```

### Controllers (Request Handlers)
```
app/Http/Controllers/Risk/
├── ApprovalController.php           # Approval dashboard & actions
├── DashboardController.php          # (Modified) Added appetite data
├── ReportController.php             # (Modified) Added regulatory reports
├── QuantificationController.php     # (Modified) Monte Carlo integration
└── LossEventController.php          # (Modified) Near-miss conversion
```

### Views (User Interface)
```
resources/views/risk/approvals/
├── dashboard.blade.php              # Pending approvals dashboard
└── history.blade.php                # Approval history & audit trail
```

### Database
```
database/migrations/
└── 2026_02_24_000003_create_approval_requests_table.php
```

---

## Frequently Used Code Snippets

### 1. Check Risk Appetite Compliance

```php
use App\Services\RiskAppetiteService;

$appetiteService = new RiskAppetiteService();
$appetiteData = $appetiteService->getDashboardData($orgId);

// Access results
echo $appetiteData['within_appetite'];      // Count within appetite
echo $appetiteData['exceeds_capacity'];     // Count exceeding capacity
foreach ($appetiteData['categories'] as $cat) {
    echo $cat['category_name'];             // Category name
    echo $cat['utilization_pct'];           // % of tolerance used
    echo $cat['status'];                    // within_appetite, exceeds_tolerance, etc
}
```

### 2. Generate Regulatory Reports

```php
use App\Services\RegulatoryReportService;

$reportService = new RegulatoryReportService();

// CBN ORMS Return
$ormsData = $reportService->generateCbnOrmsReturn($orgId, 'Q1', 2026);
// Returns: total_events, total_loss_kobo, categories with breakdowns

// Loss Event Summary
$lossSummary = $reportService->generateLossEventSummary($orgId);
// Returns: summary stats, Basel/CBN categorization, monthly trends

// Control Effectiveness
$controlReport = $reportService->generateControlEffectivenessSummary($orgId);
// Returns: effectiveness distribution, overall rate %

// KRI Status
$kriReport = $reportService->generateKriStatusReport($orgId);
// Returns: KRI status distribution, breach counts

// ICAAP Summary
$icaap = $reportService->generateIcaapSummary($orgId);
// Returns: VaR metrics, capital requirements
```

### 3. Request Approval for a Risk

```php
use App\Services\ApprovalService;
use App\Models\Risk;

$risk = Risk::find(123);
$approvalService = new ApprovalService();

$approval = $approvalService->requestApproval(
    $risk,
    'update',
    ['inherent_score' => 20, 'status' => 'active'],
    auth()->id()
);

// Later, retrieve pending approvals
$pending = $approvalService->getPendingApprovals($orgId);

// Approve
$approvalService->approve($approval, auth()->id(), "Looks good");

// Or reject
$approvalService->reject($approval, auth()->id(), "Score too high");
```

### 4. Run Monte Carlo Simulation

```php
use App\Services\MonteCarloService;
use App\Models\SimulationRun;

// Create a simulation run
$run = SimulationRun::create([
    'organization_id' => $orgId,
    'run_code' => 'SIM-2026-001',
    'simulation_type' => 'monte_carlo',
    'scenario_ids' => [1, 2, 3],
    'num_iterations' => 10000,
    'confidence_levels' => [0.95, 0.99],
    'status' => 'queued',
    'initiated_by' => auth()->id(),
]);

// Execute simulation
$service = new MonteCarloService();
$completed = $service->runSimulation($run, [1, 2, 3]);

// Get results
foreach ($completed->results as $result) {
    if ($result->result_type === 'aggregate') {
        echo $result->expected_annual_loss_kobo;  // EAL
        echo $result->var_99_kobo;                // VaR @ 99%
        echo $result->var_999_kobo;               // VaR @ 99.9%
    }
}
```

### 5. Upload File to Loss Event

```php
use App\Services\FileUploadService;

$uploadService = new FileUploadService();

try {
    $result = $uploadService->upload(
        $request->file('attachment'),
        'LossEvent',
        $lossEvent->id
    );

    // Store in database
    $lossEvent->attachments()->create([
        'file_name' => $result['file_name'],
        'storage_path' => $result['storage_path'],
        'file_size_bytes' => $result['file_size_bytes'],
    ]);

    return back()->with('success', 'File uploaded');
} catch (\InvalidArgumentException $e) {
    return back()->with('error', $e->getMessage());
}
```

### 6. Convert Near-Miss to Loss Event

```php
// In LossEventController or via route
POST /risk/loss-events/convert-near-miss/{nearMiss->id}

// Or programmatically
$lossEvent = (new LossEventController)->convertNearMiss($nearMiss);
```

---

## Common Routes

### Approvals
```
GET  /risk/approvals                      # Dashboard
POST /risk/approvals/{id}/approve         # Approve
POST /risk/approvals/{id}/reject          # Reject
GET  /risk/approvals/history              # View history
```

### Reports
```
GET /risk/reports/executive               # Executive summary
GET /risk/reports/board                   # Board report (with appetites)
GET /risk/reports/regulatory              # Regulatory (with CBN/ICAAP data)
GET /risk/reports/custom                  # Custom report builder
```

### Quantification
```
GET  /risk/quantification/dashboard       # Dashboard
GET  /risk/quantification/scenarios       # List scenarios
POST /risk/quantification/simulate        # Run simulation
GET  /risk/quantification/results         # View results
```

---

## Database Query Tips

### Find Appetite Breaches
```php
use App\Models\RiskAppetite;

$breaches = RiskAppetite::with('riskCategory')
    ->where('organization_id', $orgId)
    ->get()
    ->filter(function($appetite) {
        $avgScore = $appetite->riskCategory->risks()
            ->where('status', 'active')
            ->avg('inherent_score');
        return $avgScore > ($appetite->max_tolerance ?? 25);
    });
```

### Get Pending Approvals for User
```php
use App\Models\ApprovalRequest;

$myApprovals = ApprovalRequest::where('organization_id', $orgId)
    ->pending()
    ->orderByDesc('requested_at')
    ->get();
```

### Get Latest Simulation Results
```php
use App\Models\SimulationRun;

$latest = SimulationRun::where('organization_id', $orgId)
    ->where('status', 'completed')
    ->orderByDesc('completed_at')
    ->with('results')
    ->first();

$aggregate = $latest->results()->where('result_type', 'aggregate')->first();
echo $aggregate->var_99_kobo;  // VaR @ 99%
```

---

## Troubleshooting

### Monte Carlo Not Running
- Check SimulationRun has valid scenario_ids
- Ensure QuantificationScenarios exist and belong to org
- Verify frequency_params_json and severity_params_json are set
- Check num_iterations is between 1000 and 1000000

### Approval Request Not Appearing
- Verify ApprovalRequest record exists in database
- Check organization_id matches current user's org
- Ensure status is 'pending'
- Check for soft deletes (deleted_at is null)

### Regulatory Report Empty
- Ensure LossEvents exist for the period
- Check basel_l1_category and cbn_risk_category are populated
- Verify date_of_loss is within the quarter range

### Appetite Comparison Shows No Data
- Verify RiskAppetite records exist
- Check Risk records have category_id set
- Ensure status is 'active' for comparison
- Check inherent_score is populated

---

## Testing

### Test Risk Appetite
```php
// Create test data
$appetite = RiskAppetite::create([
    'organization_id' => $orgId,
    'risk_category_id' => 1,
    'max_tolerance' => 20,
    'capacity' => 25,
]);

// Create risks in same category
Risk::create([
    'organization_id' => $orgId,
    'category_id' => 1,
    'inherent_score' => 15,
    'status' => 'active',
]);

// Test
$service = new RiskAppetiteService();
$results = $service->compareAgainstActual($orgId);
dd($results);
```

### Test Approval Workflow
```php
// Create risk
$risk = Risk::create([
    'organization_id' => $orgId,
    'title' => 'Test Risk',
    'inherent_score' => 10,
    'status' => 'draft',
    'created_by' => auth()->id(),
]);

// Request approval
$service = new ApprovalService();
$approval = $service->requestApproval(
    $risk,
    'update',
    ['status' => 'active'],
    auth()->id()
);

// Verify pending
$pending = $service->getPendingApprovals($orgId);
dd($pending->first()->entity_id); // Should be $risk->id
```

---

## Performance Notes

- RiskAppetiteService uses efficient grouping, O(n) complexity
- RegulatoryReportService uses database aggregation functions
- MonteCarloService: O(iterations * scenarios) - runs in-process
- ApprovalService uses database transactions for consistency
- FileUploadService validates before storage to save I/O

For large-scale simulations (100k+ iterations), consider:
- Moving Monte Carlo to a queued job
- Running in background with progress tracking
- Breaking into chunks per scenario

---

## Common Errors & Solutions

| Error | Cause | Solution |
|-------|-------|----------|
| "File type not allowed" | Extension not in allowedTypes | Check $allowedTypes in FileUploadService |
| "Unauthorized access" | org_id mismatch | Ensure user's org_id matches record's org_id |
| "Approval not pending" | Already approved/rejected | Check approval status before action |
| "Scenario not found" | Invalid scenario_ids | Verify scenarios exist and belong to org |
| "No data" in reports | Empty date range or no records | Check date_of_loss, status fields |

---

## Next Development Tasks

1. **Approval UI Enhancement**
   - Add approval request buttons to risk/loss event forms
   - Show approval history on entity detail pages
   - Real-time pending approval notifications

2. **Monte Carlo Enhancements**
   - Add copula correlation support
   - Implement stress testing scenarios
   - Generate efficiency frontier charts

3. **Report Exports**
   - PDF export for regulatory reports
   - Excel export with formatting
   - Scheduled report generation via jobs

4. **Approval Notifications**
   - Email notifications for pending approvals
   - Slack/Teams integration
   - Escalation rules for overdue approvals

5. **Dashboard Widgets**
   - Appetite gauge charts
   - Risk heatmaps with appetite overlay
   - KRI breach timeline
   - Approval queue widget

---

**Last Updated**: February 24, 2026
**Version**: 1.0 Production Release
