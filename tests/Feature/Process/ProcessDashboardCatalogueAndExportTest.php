<?php

namespace Tests\Feature\Process;

use App\Models\Risk;
use Inertia\Testing\AssertableInertia as Assert;

class ProcessDashboardCatalogueAndExportTest extends ProcessTestCase
{
    public function test_dashboard_on_empty_org_returns_null_risk_coverage_pct(): void
    {
        $this->actingAs($this->actor)
            ->get(route('risk.processes.dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Processes/Dashboard')
                ->where('kpis.total_processes', 0)
                ->where('kpis.total_sub_processes', 0)
                ->where('kpis.total_groups', 0)
                ->where('kpis.processes_with_risks', 0)
                ->where('kpis.unmapped_processes_count', 0)
                ->where('kpis.risk_coverage_pct', null)
            );
    }

    public function test_dashboard_and_catalogue_report_accurate_counts_and_coverage(): void
    {
        $group = $this->groupService->create([
            'code' => 'PG-CORE',
            'name' => 'Core Banking',
            'business_unit_id' => $this->unit->id,
        ], $this->actor);

        $mappedProcess = $this->processService->create([
            'code' => 'PRC-001',
            'name' => 'Core Ledger Posting',
            'process_group_id' => $group->id,
            'business_unit_id' => $this->unit->id,
            'owner_id' => $this->actor->id,
            'criticality' => 'critical',
        ], $this->actor);

        // 2 sub-processes under mappedProcess
        $this->processService->create([
            'level' => 'sub_process',
            'parent_id' => $mappedProcess->id,
            'code' => 'SUB-001',
            'name' => 'End-of-Day Batch',
            'criticality' => 'high',
        ], $this->actor);

        $this->processService->create([
            'level' => 'sub_process',
            'parent_id' => $mappedProcess->id,
            'code' => 'SUB-002',
            'name' => 'GL Reconciliation',
            'criticality' => 'medium',
        ], $this->actor);

        // 1 unmapped critical process without a group
        $unmappedProcess = $this->processService->create([
            'code' => 'PRC-002',
            'name' => 'Disaster Recovery Failover',
            'process_group_id' => null,
            'business_unit_id' => null,
            'owner_id' => $this->actor->id,
            'criticality' => 'critical',
        ], $this->actor);

        // Link 2 risks to mappedProcess
        Risk::create([
            'organization_id' => $this->organization->id,
            'risk_code' => 'RSK-P-001',
            'title' => 'Batch Posting Failure',
            'description' => 'EOD batch posting failure.',
            'category_id' => $this->category->id,
            'business_unit_id' => $this->unit->id,
            'entity_id' => $this->entityA->id,
            'process_id' => $mappedProcess->id,
            'risk_owner_id' => $this->actor->id,
            'status' => 'active',
        ]);

        Risk::create([
            'organization_id' => $this->organization->id,
            'risk_code' => 'RSK-P-002',
            'title' => 'Unreconciled Suspense Account',
            'description' => 'Suspense account breaks.',
            'category_id' => $this->category->id,
            'business_unit_id' => $this->unit->id,
            'entity_id' => $this->entityA->id,
            'process_id' => $mappedProcess->id,
            'risk_owner_id' => $this->actor->id,
            'status' => 'active',
        ]);

        // Dashboard assertions
        $this->actingAs($this->actor)
            ->get(route('risk.processes.dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Processes/Dashboard')
                ->where('kpis.total_processes', 2)
                ->where('kpis.total_sub_processes', 2)
                ->where('kpis.total_groups', 1)
                ->where('kpis.critical_processes', 2)
                ->where('kpis.processes_with_risks', 1)
                ->where('kpis.unmapped_processes_count', 1)
                ->where('kpis.risk_coverage_pct', 50)
                ->has('byGroup', 2) // Core Banking + Ungrouped
                ->where('byGroup.0.group_code', 'PG-CORE')
                ->where('byGroup.0.processes_count', 1)
                ->where('byGroup.0.sub_processes_count', 2)
                ->where('byGroup.0.linked_risks_count', 2)
                ->where('byGroup.0.unmapped_count', 0)
                ->where('byGroup.1.group_name', 'Ungrouped')
                ->where('byGroup.1.processes_count', 1)
                ->where('byGroup.1.unmapped_count', 1)
                ->has('unmappedCritical', 1)
                ->where('unmappedCritical.0.code', $unmappedProcess->code)
            );

        // Catalogue assertions (all 4 rows: 2 top-level + 2 sub-processes, sorted by code asc)
        $this->actingAs($this->actor)
            ->get(route('risk.processes.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Processes/Index')
                ->where('counts.processes', 2)
                ->where('counts.sub_processes', 2)
                ->where('counts.unmapped', 1)
                ->has('grid.rows.data', 4)
                ->where('grid.rows.data', function ($rows): bool {
                    $rows = is_array($rows) ? $rows : $rows->toArray();
                    $byCode = [];
                    foreach ($rows as $row) {
                        $byCode[$row['cells']['code']['text']] = $row['cells'];
                    }

                    $this->assertArrayHasKey('PRC-001', $byCode);
                    $this->assertSame('Core Banking', $byCode['PRC-001']['process_group.name']['text']);
                    $this->assertSame('Operations', $byCode['PRC-001']['business_unit.name']['text']);
                    $this->assertSame($this->actor->name, $byCode['PRC-001']['owner.name']['text']);
                    $this->assertSame('Critical', $byCode['PRC-001']['criticality']['text']);
                    $this->assertSame('2', $byCode['PRC-001']['sub_processes_count']['text']);
                    $this->assertSame('2', $byCode['PRC-001']['risks_count']['text']);

                    $this->assertArrayHasKey('PRC-002', $byCode);
                    $this->assertSame('Ungrouped', $byCode['PRC-002']['process_group.name']['text']);
                    $this->assertSame('Enterprise / Cross-Departmental', $byCode['PRC-002']['business_unit.name']['text']);
                    $this->assertSame('0', $byCode['PRC-002']['sub_processes_count']['text']);
                    $this->assertSame('0', $byCode['PRC-002']['risks_count']['text']);

                    $this->assertArrayHasKey('SUB-001', $byCode);
                    $this->assertSame('—', $byCode['SUB-001']['sub_processes_count']['text']);

                    $this->assertArrayNotHasKey($this->foreignProcess->code, $byCode);

                    return true;
                })
            );
    }

    public function test_show_create_and_edit_pages_render_with_sub_processes_entities_risks_and_audit_trail(): void
    {
        $group = $this->groupService->create([
            'code' => 'PG-SHOW',
            'name' => 'Lending Operations',
            'business_unit_id' => $this->unit->id,
        ], $this->actor);

        $process = $this->processService->create([
            'code' => 'PRC-SHOW',
            'name' => 'Credit Underwriting',
            'process_group_id' => $group->id,
            'business_unit_id' => $this->unit->id,
            'owner_id' => $this->actor->id,
            'criticality' => 'high',
            'entity_id' => $this->entityA->id,
        ], $this->actor);

        $subProcess = $this->processService->create([
            'level' => 'sub_process',
            'parent_id' => $process->id,
            'code' => 'SUB-SHOW',
            'name' => 'Collateral Valuation',
            'criticality' => 'medium',
        ], $this->actor);

        $risk = Risk::create([
            'organization_id' => $this->organization->id,
            'risk_code' => 'RSK-SHOW-1',
            'title' => 'Overstated Collateral Value',
            'description' => 'Valuation bias or stale appraisal.',
            'category_id' => $this->category->id,
            'business_unit_id' => $this->unit->id,
            'entity_id' => $this->entityA->id,
            'process_id' => $process->id,
            'risk_owner_id' => $this->actor->id,
            'status' => 'active',
        ]);

        // Show
        $this->actingAs($this->actor)
            ->get(route('risk.processes.show', $process))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Processes/Show')
                ->where('process.code', 'PRC-SHOW')
                ->where('process.process_group.code', 'PG-SHOW')
                ->where('process.business_unit.name', 'Operations')
                ->where('process.owner.name', $this->actor->name)
                ->has('subProcesses', 1)
                ->where('subProcesses.0.code', $subProcess->code)
                ->has('entities', 1)
                ->where('entities.0.entity_code', $this->entityA->entity_code)
                ->where('entities.0.is_primary', true)
                ->has('risks', 1)
                ->where('risks.0.risk_code', $risk->risk_code)
                ->has('auditTrail')
            );

        // Create & Edit pages render cleanly
        $this->actingAs($this->actor)
            ->get(route('risk.processes.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Processes/Create')
                ->where('mode', 'create')
            );

        $this->actingAs($this->actor)
            ->get(route('risk.processes.edit', $process))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Processes/Edit')
                ->where('mode', 'edit')
                ->where('process.code', 'PRC-SHOW')
                ->where('process.sub_processes_count', 1)
            );
    }

    public function test_csv_and_pdf_exports_return_200_with_expected_content_types_and_tenant_scoping(): void
    {
        $this->processService->create([
            'code' => 'PRC-EXP-1',
            'name' => 'FX Settlement Export Test',
            'business_unit_id' => $this->unit->id,
            'owner_id' => $this->actor->id,
            'criticality' => 'high',
        ], $this->actor);

        // CSV export (dedicated route)
        $csvResponse = $this->actingAs($this->actor)
            ->get(route('risk.processes.export.csv'));

        $csvResponse->assertOk();
        $this->assertStringContainsString('text/csv', (string) $csvResponse->headers->get('Content-Type'));
        $csvContent = $csvResponse->streamedContent();
        $this->assertStringContainsString('Process Code', $csvContent);
        $this->assertStringContainsString('PRC-EXP-1', $csvContent);
        $this->assertStringNotContainsString($this->foreignProcess->code, $csvContent);

        // CSV via unified export endpoint
        $this->actingAs($this->actor)
            ->get(route('risk.processes.export', ['format' => 'csv']))
            ->assertOk();

        // PDF export (dedicated route)
        $pdfResponse = $this->actingAs($this->actor)
            ->get(route('risk.processes.export.pdf'));

        $pdfResponse->assertOk();
        $this->assertStringContainsString('application/pdf', (string) $pdfResponse->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF-', (string) $pdfResponse->getContent());

        // PDF via unified export endpoint
        $pdfUnified = $this->actingAs($this->actor)
            ->get(route('risk.processes.export', ['format' => 'pdf']));

        $pdfUnified->assertOk();
        $this->assertStringContainsString('application/pdf', (string) $pdfUnified->headers->get('Content-Type'));
    }
}
