@php
    $currentRoute = request()->path();

    /**
     * Helper: Check if the current route starts with a given prefix.
     */
    $isSection = function ($prefix) use ($currentRoute) {
        return str_starts_with($currentRoute, $prefix);
    };

    /**
     * Helper: Check if the current route exactly matches.
     */
    $isActive = function ($path) use ($currentRoute) {
        return trim($currentRoute, '/') === trim($path, '/');
    };

    /**
     * Navigation sections configuration.
     * Each section has: id, label, icon, prefix (for active detection), and items.
     */
    /**
     * Ordered following the ISO 31000 / COSO ERM lifecycle:
     *   Context → Identify → Assess → Control → Treat →
     *   Monitor → Incidents → Issues → Analyze → Quantify →
     *   Report → Intelligence
     */
    $navSections = [

        // ── Phase 1: Context & Governance ──────────────────────────
        [
            'id' => 'scoping',
            'label' => 'Scoping',
            'icon' => 'account_tree',
            'prefix' => 'risk/scoping',
            'items' => [
                ['label' => 'Entity Dashboard', 'url' => '/risk/scoping/dashboard'],
                ['label' => 'Entity Register', 'url' => '/risk/scoping'],
                ['label' => 'Create Entity', 'url' => '/risk/scoping/create'],
            ],
        ],

        // ── Phase 2: Risk Identification & Assessment ──────────────
        [
            'id' => 'risk_register',
            'label' => 'Risk Register',
            'icon' => 'assessment',
            'prefix' => 'risk/register',
            'items' => [
                ['label' => 'Risk Register', 'url' => '/risk/register'],
                ['label' => 'Create New Risk', 'url' => '/risk/register/create'],
            ],
        ],
        [
            'id' => 'rcsa',
            'label' => 'RCSA',
            'icon' => 'fact_check',
            'prefix' => 'risk/rcsa',
            'items' => [
                ['label' => 'Dashboard', 'url' => '/risk/rcsa/dashboard'],
                ['label' => 'Worksheet', 'url' => '/risk/rcsa/worksheet'],
                ['label' => 'Risk Matrix', 'url' => '/risk/rcsa/matrix'],
            ],
        ],
        [
            'id' => 'assessments',
            'label' => 'Risk Assessments',
            'icon' => 'rate_review',
            'prefix' => 'risk/assessments',
            'items' => [
                ['label' => 'All Assessments', 'url' => '/risk/assessments'],
                ['label' => 'New Assessment', 'url' => '/risk/assessments/create'],
            ],
        ],

        // ── Phase 3: Controls & Treatment ──────────────────────────
        [
            'id' => 'controls',
            'label' => 'Control Library',
            'icon' => 'verified_user',
            'prefix' => 'risk/controls',
            'altPrefix' => 'risk/control-tests',
            'items' => [
                ['label' => 'All Controls', 'url' => '/risk/controls'],
                ['label' => 'New Control', 'url' => '/risk/controls/create'],
                ['label' => 'Testing Dashboard', 'url' => '/risk/control-tests/dashboard'],
                ['label' => 'All Tests', 'url' => '/risk/control-tests'],
                ['label' => 'Schedule Test', 'url' => '/risk/control-tests/create'],
            ],
        ],
        [
            'id' => 'treatment_plans',
            'label' => 'Treatment Plans',
            'icon' => 'healing',
            'prefix' => 'risk/treatments',
            'items' => [
                ['label' => 'Dashboard', 'url' => '/risk/treatments/dashboard'],
                ['label' => 'Active Plans', 'url' => '/risk/treatments'],
                ['label' => 'New Plan', 'url' => '/risk/treatments/create'],
                ['label' => 'Review', 'url' => '/risk/treatments/review'],
            ],
        ],
        [
            'id' => 'risk_appetite',
            'label' => 'Risk Appetite',
            'icon' => 'tune',
            'prefix' => 'risk/appetite',
            'items' => [
                ['label' => 'Appetite Statements', 'url' => '/risk/appetite'],
            ],
        ],
        [
            'id' => 'approvals',
            'label' => 'Approvals',
            'icon' => 'approval',
            'prefix' => 'risk/approvals',
            'items' => [
                ['label' => 'Pending Approvals', 'url' => '/risk/approvals'],
                ['label' => 'Approval History', 'url' => '/risk/approvals/history'],
            ],
        ],

        // ── Phase 4: Monitoring & Events ───────────────────────────
        [
            'id' => 'kri_monitoring',
            'label' => 'KRI Monitoring',
            'icon' => 'speed',
            'prefix' => 'risk/kri',
            'items' => [
                ['label' => 'Dashboard', 'url' => '/risk/kri/dashboard'],
                ['label' => 'KRI Library', 'url' => '/risk/kri'],
                ['label' => 'Thresholds', 'url' => '/risk/kri/thresholds'],
                ['label' => 'Breach Register', 'url' => '/risk/kri/breaches'],
            ],
        ],

        // ── WP-04: the measure engine's own governance screens ─────
        [
            'id' => 'reporting_periods',
            'label' => 'Reporting Periods',
            'icon' => 'calendar_month',
            'prefix' => 'risk/periods',
            'items' => [
                ['label' => 'Calendar & Close', 'url' => '/risk/periods'],
                ['label' => 'Threshold Re-baselining', 'url' => '/risk/thresholds/rebaseline'],
            ],
        ],
        [
            'id' => 'loss_events',
            'label' => 'Loss Events',
            'icon' => 'report_problem',
            'prefix' => 'risk/loss-events',
            'items' => [
                ['label' => 'Dashboard', 'url' => '/risk/loss-events/dashboard'],
                ['label' => 'Event Register', 'url' => '/risk/loss-events'],
                ['label' => 'New Event', 'url' => '/risk/loss-events/create'],
                ['label' => 'Near Misses', 'url' => '/risk/loss-events/near-misses'],
                ['label' => 'Approvals', 'url' => '/risk/loss-events/approvals'],
                ['label' => 'Root Cause', 'url' => '/risk/loss-events/rca'],
                ['label' => 'Reports', 'url' => '/risk/loss-events/reports'],
            ],
        ],
        [
            'id' => 'issues',
            'label' => 'Issues & Findings',
            'icon' => 'bug_report',
            'prefix' => 'risk/issues',
            'items' => [
                ['label' => 'Dashboard', 'url' => '/risk/issues/dashboard'],
                ['label' => 'Issues Register', 'url' => '/risk/issues'],
                ['label' => 'New Issue', 'url' => '/risk/issues/create'],
                ['label' => 'Ageing Report', 'url' => '/risk/issues/ageing'],
                ['label' => 'Closure', 'url' => '/risk/issues/closure'],
            ],
        ],

        // ── Assessment Campaigns & Questionnaires ───────────────────
        [
            'id' => 'campaigns',
            'label' => 'Campaigns',
            'icon' => 'campaign',
            'prefix' => 'risk/campaigns',
            'altPrefix' => 'risk/questionnaires',
            'items' => [
                ['label' => 'Dashboard', 'url' => '/risk/campaigns/dashboard'],
                ['label' => 'All Campaigns', 'url' => '/risk/campaigns'],
                ['label' => 'New Campaign', 'url' => '/risk/campaigns/create'],
                ['label' => 'Questionnaires', 'url' => '/risk/questionnaires'],
                ['label' => 'Question Library', 'url' => '/risk/question-library'],
            ],
        ],

        // ── Workflow Engine ──────────────────────────────────────────
        [
            'id' => 'workflows',
            'label' => 'Workflows',
            'icon' => 'device_hub',
            'prefix' => 'risk/workflows',
            'items' => [
                // WP-06. First, because it is the one entry here most people
                // use daily: everything a person owes, from every module.
                ['label' => 'My Tasks', 'url' => '/risk/my-tasks'],
                ['label' => 'Dashboard', 'url' => '/risk/workflows/dashboard'],
                ['label' => 'Definitions', 'url' => '/risk/workflows/definitions'],
                ['label' => 'Designer', 'url' => '/risk/workflows/definitions/create'],
            ],
        ],

        // ── Phase 5: Analysis & Quantification ─────────────────────
        [
            'id' => 'analysis',
            'label' => 'Risk Analysis',
            'icon' => 'analytics',
            'prefix' => 'risk/analysis',
            'items' => [
                ['label' => 'Heat Map', 'url' => '/risk/analysis/heatmap'],
                ['label' => 'Bow-Tie', 'url' => '/risk/analysis/bowtie'],
                ['label' => 'Trends', 'url' => '/risk/analysis/trends'],
                ['label' => 'Correlation', 'url' => '/risk/analysis/correlation'],
            ],
        ],
        [
            'id' => 'quantification',
            'label' => 'Risk Quantification',
            'icon' => 'calculate',
            'prefix' => 'risk/quantification',
            'items' => [
                ['label' => 'Dashboard', 'url' => '/risk/quantification/dashboard'],
                ['label' => 'Scenarios', 'url' => '/risk/quantification/scenarios'],
                ['label' => 'Simulate', 'url' => '/risk/quantification/simulate'],
                ['label' => 'Results', 'url' => '/risk/quantification/results'],
                ['label' => 'ICAAP', 'url' => '/risk/quantification/icaap'],
                ['label' => 'Library', 'url' => '/risk/quantification/library'],
                ['label' => 'Settings', 'url' => '/risk/quantification/settings'],
                ['label' => 'Reports', 'url' => '/risk/quantification/reports'],
            ],
        ],

        // ── Regulatory Compliance ────────────────────────────────────
        [
            'id' => 'regulatory',
            'label' => 'Regulatory',
            'icon' => 'gavel',
            'prefix' => 'risk/regulatory',
            'items' => [
                ['label' => 'Dashboard', 'url' => '/risk/regulatory/dashboard'],
                ['label' => 'Calendar', 'url' => '/risk/regulatory/calendar'],
                ['label' => 'Deadlines', 'url' => '/risk/regulatory/deadlines'],
                ['label' => 'Circulars', 'url' => '/risk/regulatory/circulars'],
                ['label' => 'Taxonomy', 'url' => '/risk/regulatory/taxonomy'],
            ],
        ],

        // ── Data Import ─────────────────────────────────────────────
        [
            'id' => 'imports',
            'label' => 'Data Import',
            'icon' => 'upload_file',
            'prefix' => 'risk/imports',
            'items' => [
                ['label' => 'Import History', 'url' => '/risk/imports'],
                ['label' => 'New Import', 'url' => '/risk/imports/create'],
            ],
        ],

        // ── Document Repository ────────────────────────────────────
        [
            'id' => 'documents',
            'label' => 'Documents',
            'icon' => 'folder_open',
            'prefix' => 'risk/documents',
            'items' => [
                ['label' => 'Repository', 'url' => '/risk/documents'],
            ],
        ],

        // ── Phase 6: Reporting & Intelligence ──────────────────────
        [
            'id' => 'reports',
            'label' => 'Reports',
            'icon' => 'summarize',
            'prefix' => 'risk/reports',
            'items' => [
                ['label' => 'Executive', 'url' => '/risk/reports/executive'],
                ['label' => 'Board', 'url' => '/risk/reports/board'],
                ['label' => 'Regulatory', 'url' => '/risk/reports/regulatory'],
                ['label' => 'Custom', 'url' => '/risk/reports/custom'],
                ['label' => 'Library', 'url' => '/risk/reports/library'],
            ],
        ],
        [
            'id' => 'emerging',
            'label' => 'Emerging Risk',
            'icon' => 'radar',
            'prefix' => 'risk/emerging-risks',
            'items' => [
                ['label' => 'Register', 'url' => '/risk/emerging-risks'],
            ],
        ],
    ];

    // The AI Intelligence section is gated by config('features.ai_intelligence').
    // The routes 404 when the flag is off, so linking to them unconditionally
    // would put dead entries in the nav.
    if (filter_var(config('features.ai_intelligence', false), FILTER_VALIDATE_BOOLEAN)) {
        $sections[] = [
            'id' => 'ai_intelligence',
            'label' => 'Risk Intelligence',
            'icon' => 'psychology',
            'prefix' => 'risk/ai',
            'items' => [
                ['label' => 'Forecast', 'url' => '/risk/ai/predictive'],
                ['label' => 'Emerging Risk Radar', 'url' => '/risk/ai/radar'],
                ['label' => 'Regulatory Pulse', 'url' => '/risk/ai/regulatory-pulse'],
            ],
        ];
    }
@endphp

<aside id="sidebar" class="fixed left-0 top-0 h-screen w-[260px] bg-[#1A365D] text-white overflow-y-auto z-50 flex flex-col" style="scrollbar-width:thin;scrollbar-color:#2D4A7A #1A365D">

    {{-- Brand --}}
    <div class="px-5 pt-5 pb-3 border-b border-white/10">
        <div class="flex items-center gap-3">
            <div class="w-9 h-9 bg-white/15 rounded-lg flex items-center justify-center flex-shrink-0">
                <span class="material-symbols-outlined text-[#D4AF37] text-xl">shield</span>
            </div>
            <div>
                <div class="text-[13px] font-bold text-white leading-tight">Atheris ERM</div>
                <div class="text-[10px] text-white/50 font-medium">GRC Suite</div>
            </div>
        </div>
    </div>

    {{-- Navigation --}}
    <nav class="flex-1 px-3 py-3 space-y-0.5 overflow-y-auto">

        {{-- Command Centre --}}
        <a href="/risk/dashboard"
           class="flex items-center gap-2.5 px-3 py-2 rounded-lg transition-all text-[13px] mb-2
                  {{ $isActive('risk/dashboard') ? 'text-white bg-white/12' : 'text-white/70 hover:text-white hover:bg-white/8' }}">
            <span class="material-symbols-outlined text-[18px]">home</span>
            <span>Command Centre</span>
        </a>

        {{-- WP-08: the two navigation surfaces --}}
        @can('my.view')
        <a href="{{ route('my.index') }}"
           class="flex items-center gap-2.5 px-3 py-2 rounded-lg transition-all text-[13px] mb-0.5
                  {{ $isActive('my') ? 'text-white bg-white/12' : 'text-white/70 hover:text-white hover:bg-white/8' }}">
            <span class="material-symbols-outlined text-[18px]">checklist</span>
            <span>My Responsibilities</span>
        </a>
        @endcan
        @can('hq.view')
        <a href="{{ route('hq.index') }}"
           class="flex items-center gap-2.5 px-3 py-2 rounded-lg transition-all text-[13px] mb-0.5
                  {{ $isSection('hq') ? 'text-white bg-white/12' : 'text-white/70 hover:text-white hover:bg-white/8' }}">
            <span class="material-symbols-outlined text-[18px]">hub</span>
            <span>Business HQ</span>
        </a>
        @endcan
        @can('dashboard.manage')
        <a href="{{ route('risk.dashboards.index') }}"
           class="flex items-center gap-2.5 px-3 py-2 rounded-lg transition-all text-[13px] mb-2
                  {{ $isSection('risk/dashboards') ? 'text-white bg-white/12' : 'text-white/70 hover:text-white hover:bg-white/8' }}">
            <span class="material-symbols-outlined text-[18px]">dashboard_customize</span>
            <span>Dashboards</span>
        </a>
        @endcan

        <div class="h-px bg-white/10 mx-1 mb-2"></div>

        {{-- Permission-based visibility for main menu items --}}
        @can('view risks')

        @endcan

        {{-- Dynamic Nav Sections --}}
        @foreach ($navSections as $section)
            @php
                $sectionActive = $isSection($section['prefix']) || (isset($section['altPrefix']) && $isSection($section['altPrefix']));
                $sectionId = $section['id'];
            @endphp

            <div class="nav-group">
                {{-- Section Toggle Button --}}
                <button onclick="toggleNav('{{ $sectionId }}')"
                        class="w-full flex items-center justify-between px-3 py-2 rounded-lg text-[13px] font-medium transition-all group
                               {{ $sectionActive ? 'bg-white/12 text-white' : 'text-white/60 hover:bg-white/6 hover:text-white/80' }}">
                    <div class="flex items-center gap-2.5">
                        <span class="material-symbols-outlined text-[18px] {{ $sectionActive ? 'text-[#D4AF37]' : 'text-white/40 group-hover:text-white/60' }}">{{ $section['icon'] }}</span>
                        <span>{{ $section['label'] }}</span>
                    </div>
                    <span class="material-symbols-outlined text-[16px] transition-transform duration-200 text-white/40 {{ $sectionActive ? 'rotate-180' : '' }}"
                          id="chevron_{{ $sectionId }}">expand_more</span>
                </button>

                {{-- Section Links --}}
                <div id="nav_{{ $sectionId }}"
                     class="{{ $sectionActive ? 'block' : 'hidden' }} mt-0.5 ml-[30px] border-l border-white/10 pl-2 space-y-0.5">
                    @foreach ($section['items'] as $item)
                        @php
                            $itemPath = trim($item['url'], '/');
                            $itemActive = $isActive($itemPath);
                        @endphp
                        <a href="{{ $item['url'] }}"
                           class="block px-3 py-1.5 rounded-md text-[12px] transition-all
                                  {{ $itemActive ? 'font-semibold bg-[#D4AF37] text-[#1A365D]' : 'text-white/50 hover:text-white hover:bg-white/6' }}">
                            {{ $item['label'] }}
                        </a>
                    @endforeach
                </div>
            </div>
        @endforeach

        {{-- Administration Section --}}
        @role(['super-admin', 'chief-risk-officer'])
            <div class="h-px bg-white/10 mx-1 my-3"></div>

            <div class="nav-group">
                <button onclick="toggleNav('administration')"
                        class="w-full flex items-center justify-between px-3 py-2 rounded-lg text-[13px] font-medium transition-all group
                               {{ $isSection('admin') ? 'bg-white/12 text-white' : 'text-white/60 hover:bg-white/6 hover:text-white/80' }}">
                    <div class="flex items-center gap-2.5">
                        <span class="material-symbols-outlined text-[18px] {{ $isSection('admin') ? 'text-[#D4AF37]' : 'text-white/40 group-hover:text-white/60' }}">admin_panel_settings</span>
                        <span>Administration</span>
                    </div>
                    <span class="material-symbols-outlined text-[16px] transition-transform duration-200 text-white/40 {{ $isSection('admin') ? 'rotate-180' : '' }}"
                          id="chevron_administration">expand_more</span>
                </button>

                <div id="nav_administration"
                     class="{{ $isSection('admin') ? 'block' : 'hidden' }} mt-0.5 ml-[30px] border-l border-white/10 pl-2 space-y-0.5">
                    {{-- User Management --}}
                    @role('super-admin')
                        <a href="{{ route('admin.users.index') }}"
                           class="block px-3 py-1.5 rounded-md text-[12px] transition-all
                                  {{ $isSection('admin/users') ? 'font-semibold bg-[#D4AF37] text-[#1A365D]' : 'text-white/50 hover:text-white hover:bg-white/6' }}">
                            User Management
                        </a>
                    @endrole

                    {{-- Organization Settings --}}
                    @role('super-admin')
                        <a href="{{ route('admin.settings') }}"
                           class="block px-3 py-1.5 rounded-md text-[12px] transition-all
                                  {{ $isSection('admin/settings') ? 'font-semibold bg-[#D4AF37] text-[#1A365D]' : 'text-white/50 hover:text-white hover:bg-white/6' }}">
                            Settings
                        </a>
                    @endrole
                </div>
            </div>
        @endrole

    </nav>

    {{-- Footer --}}
    <div class="px-4 py-3 border-t border-white/10 mt-auto">
        <div class="flex items-center gap-2 text-[10px] text-white/30">
            <span class="material-symbols-outlined text-[14px]">verified</span>
            <span>CBN ORMS Compliant</span>
        </div>
    </div>
</aside>

{{-- Toggle Navigation Script --}}
<script>
    function toggleNav(id) {
        const nav = document.getElementById('nav_' + id);
        const chevron = document.getElementById('chevron_' + id);
        if (nav && chevron) {
            nav.classList.toggle('hidden');
            nav.classList.toggle('block');
            chevron.classList.toggle('rotate-180');
        }
    }
</script>
