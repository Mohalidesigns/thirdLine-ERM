<?php

namespace Tests\Feature\Kri;

use App\Models\BusinessUnit;
use App\Models\Issue;
use App\Models\KeyRiskIndicator;
use App\Models\KriCycleRun;
use App\Models\KriReturnNotice;
use App\Models\KriReturnNoticeRecipient;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;

/**
 * ADR 0026 section 16 and A1.4 to A1.6: the Library grouped by department, the
 * dashboard card and the drill-down. Numbers are asserted against rows the test
 * wrote, and the query count is asserted to stay constant as departments grow.
 */
class KriLibraryDashboardTest extends KriCycleTestCase
{
    private User $viewer;

    protected function setUp(): void
    {
        parent::setUp();

        // KriTestCase's own unassigned, never-read KRI would otherwise sit in the Unassigned tile.
        $this->kri->delete();

        $this->viewer = $this->makeStaff('Library Viewer', ['kri.view', 'kri.all_departments', 'kri.send_reminder']);
    }

    /** A KRI with (or without) a reading, in a given engine status. */
    private function kriWith(?BusinessUnit $unit, string $status, bool $read, array $extra = []): KeyRiskIndicator
    {
        $kri = $this->deptKri($unit, array_merge(['current_status' => $status, 'business_unit_id' => $unit?->id], $extra));
        $kri->forceFill($read ? ['last_measurement_at' => '2026-10-31 10:00:00'] : ['last_measurement_at' => null, 'last_measurement_date' => null])->save();

        return $kri;
    }

    private function openRun(): KriCycleRun
    {
        return KriCycleRun::create([
            'organization_id' => $this->organization->id, 'period_id' => $this->reportingMonth()->id, 'run_on' => '2026-11-02',
            'trigger' => 'schedule', 'forced' => false, 'status' => 'completed', 'started_at' => now(),
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  The Library */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_landing_shows_department_tiles_with_counts_that_match_the_rows_and_an_unassigned_tile(): void
    {
        $this->kriWith($this->unit, 'red', true);
        $this->kriWith($this->unit, 'amber', true);
        $this->kriWith($this->unit, 'green', true);
        $this->kriWith($this->unit, 'yellow', true);                 // folds into green
        $this->kriWith($this->unit, 'unknown', true);                // a reading, but no recognised band
        $this->kriWith($this->unit, 'green', false);                 // NEVER READ: current_status says green, it is not
        $this->kriWith($this->unit, 'red', true, ['is_active' => false]);   // inactive: not counted
        $this->kriWith(null, 'green', true);
        $this->kriWith(null, 'green', false);
        $empty = $this->makeUnit('Empty Department', 'EMP');
        $this->makeUnit('Retired Department', 'RET', null, null, false);

        $this->actingAs($this->viewer)->get(route('risk.kri.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p
                ->component('Kri/Index')
                ->where('layout', 'departments')
                ->where('flatUrl', route('risk.kri.index', ['layout' => 'flat']))
                ->where('departments', function ($departments) use ($empty) {
                    $byName = collect($departments)->keyBy('name');

                    $t = $byName['Treasury'];
                    $ok = $t['kri_count'] === 6 && $t['red'] === 1 && $t['amber'] === 1 && $t['green'] === 2
                        && $t['unbanded'] === 1 && $t['not_read'] === 1
                        && $t['kri_count'] === $t['red'] + $t['amber'] + $t['green'] + $t['unbanded'] + $t['not_read'];

                    // A department with no KRIs still has a tile (decision 0.6); a retired one does not.
                    $e = $byName['Empty Department'];

                    return $ok && $e['kri_count'] === 0 && $e['not_read'] === 0 && $e['id'] === $empty->id
                        && ! $byName->has('Retired Department')
                        && $t['url'] === route('risk.kri.departments.show', $t['id']);
                })
                ->where('unassigned.kri_count', 2)
                ->where('unassigned.green', 1)
                ->where('unassigned.not_read', 1)
                ->where('unassigned.url', route('risk.kri.departments.unassigned')));
    }

    #[Test]
    public function a_never_read_kri_counts_in_not_read_and_never_in_green(): void
    {
        $this->kriWith($this->unit, 'green', false);

        $this->actingAs($this->viewer)->get(route('risk.kri.index'))
            ->assertInertia(fn (Assert $p) => $p->where('departments.0.green', 0)->where('departments.0.not_read', 1)->where('departments.0.kri_count', 1));
    }

    #[Test]
    public function a_kri_counts_as_read_when_any_of_its_three_signals_says_so(): void
    {
        $byDate = $this->kriWith($this->unit, 'green', false);
        $byDate->forceFill(['last_measurement_date' => '2026-10-31'])->save();
        $byTable = $this->kriWith($this->unit, 'green', false);
        (new \Tests\Support\TenantFixture)->make('kri_measurements', $this->organization->id, ['kri_id' => $byTable->id]);
        $this->kriWith($this->unit, 'green', true);
        $this->kriWith($this->unit, 'green', false);

        $this->actingAs($this->viewer)->get(route('risk.kri.index'))
            ->assertInertia(fn (Assert $p) => $p->where('departments.0.green', 3)->where('departments.0.not_read', 1));
    }

    #[Test]
    public function the_flat_layout_still_returns_the_grid_for_the_layout_flag_and_for_any_grid_state(): void
    {
        $this->kriWith($this->unit, 'green', true, ['name' => 'Attrition ratio']);

        foreach ([['layout' => 'flat'], ['search' => 'Attrition'], ['page' => 1], ['sort' => 'name'], ['per_page' => 25]] as $query) {
            $this->actingAs($this->viewer)->get(route('risk.kri.index', $query))
                ->assertOk()
                ->assertInertia(fn (Assert $p) => $p->component('Kri/Index')->where('layout', 'flat')->where('grid.name', 'kris')->has('grid.rows.data'));
        }
    }

    #[Test]
    public function the_department_page_summary_matches_the_landing_tile_and_unassigned_has_no_contacts(): void
    {
        $this->kriWith($this->unit, 'red', true);
        $this->kriWith($this->unit, 'green', false);
        $this->makeContact($this->unit, $this->makeStaff('Chidi Champion'));
        $this->kriWith(null, 'amber', true);

        $this->actingAs($this->viewer)->get(route('risk.kri.departments.show', $this->unit))
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p
                ->component('Kri/Department')
                ->where('department.name', 'Treasury')
                ->where('summary.kri_count', 2)->where('summary.red', 1)->where('summary.not_read', 1)
                ->where('contacts.risk_champion.0.name', 'Chidi Champion')
                ->where('contacts.group_head', [])
                ->has('grid.rows.data', 2));

        $this->actingAs($this->viewer)->get(route('risk.kri.departments.unassigned'))
            ->assertInertia(fn (Assert $p) => $p->where('department', null)->where('contacts', null)->where('summary.amber', 1)->has('grid.rows.data', 1));
    }

    #[Test]
    public function the_department_page_lists_the_departments_open_kri_issues_and_flags_the_overdue_ones(): void
    {
        foreach ([['OPEN', '2026-12-01'], ['OVERDUE', '2026-10-20'], ['CLOSED', '2026-10-20']] as $i => [$status, $due]) {
            $issue = Issue::create([
                'organization_id' => $this->organization->id, 'issue_reference' => "ISS-LIB-{$i}", 'title' => "Issue {$i}", 'description' => 'x',
                'issue_source' => 'kri_breach', 'issue_category' => 'KRI breach', 'priority' => 'high', 'issue_status' => $status,
                'responsible_owner_id' => $this->actor->id, 'remediation_due_date' => $due, 'created_by' => $this->actor->id,
            ]);
            $issue->forceFill(['business_unit_id' => $this->unit->id])->save();
        }

        $this->actingAs($this->viewer)->get(route('risk.kri.departments.show', $this->unit))
            ->assertInertia(fn (Assert $p) => $p
                ->where('openKriIssues', fn ($rows) => collect($rows)->pluck('reference')->sort()->values()->all() === ['ISS-LIB-0', 'ISS-LIB-1']
                    && collect($rows)->firstWhere('reference', 'ISS-LIB-1')['is_overdue'] === true
                    && collect($rows)->firstWhere('reference', 'ISS-LIB-0')['is_overdue'] === false));

        $this->actingAs($this->viewer)->get(route('risk.kri.index'))
            ->assertInertia(fn (Assert $p) => $p->where('departments.0.open_kri_issues', 2)->where('departments.0.overdue_kri_issues', 1));
    }

    /* ------------------------------------------------------------------ */
    /*  Dashboard card */
    /* ------------------------------------------------------------------ */

    /** Five departments with returns: submitted, submitted, draft, reopened, overdue draft. */
    private function fiveReturns(): array
    {
        $this->openRun();
        $returns = [];

        foreach ([
            ['A', 'submitted', '2026-11-09'], ['B', 'submitted', '2026-11-09'], ['C', 'draft', '2026-11-09'],
            ['D', 'reopened', '2026-11-09'], ['E', 'draft', '2026-10-30'],
        ] as [$letter, $status, $due]) {
            $unit = $letter === 'A' ? $this->unit : $this->makeUnit("Dept {$letter}", "D-{$letter}");
            $this->deptKri($unit);
            $returns[$letter] = $this->makeReturn($unit, ['status' => $status, 'due_on' => $due, 'submitted_at' => $status === 'submitted' ? now() : null]);
        }

        $this->makeContact($this->unit, $this->makeStaff('Chidi Champion'));

        return $returns;
    }

    #[Test]
    public function the_dashboard_card_counts_submitted_and_not_submitted_from_the_rows(): void
    {
        $this->fiveReturns();
        $this->makeUnit('Nothing Due', 'NDU');          // a department with no return is not in the denominator

        $this->actingAs($this->viewer)->get(route('risk.kri.dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p
                ->component('Kri/Dashboard')
                ->where('returnsCard.summary', [
                    'denominator' => 5, 'submitted' => 2, 'not_submitted' => 3, 'overdue' => 1, 'no_champion' => 4,
                ])
                ->where('returnsCard.cycleOpened', true)
                ->where('returnsCard.canRemind', true)
                ->where('returnsCard.period.id', $this->reportingMonth()->id)
                ->where('returnsCard.drillDownUrl', route('risk.kri.returns.index', ['period' => $this->reportingMonth()->id])));
    }

    #[Test]
    public function a_reopened_return_counts_as_not_submitted_and_a_draft_never_counts_as_submitted(): void
    {
        $returns = $this->fiveReturns();
        $returns['A']->forceFill(['status' => 'reopened'])->save();

        $this->actingAs($this->viewer)->get(route('risk.kri.dashboard'))
            ->assertInertia(fn (Assert $p) => $p->where('returnsCard.summary.submitted', 1)->where('returnsCard.summary.not_submitted', 4));
    }

    #[Test]
    public function the_card_is_empty_and_says_the_cycle_has_not_opened_before_any_run(): void
    {
        $this->actingAs($this->viewer)->get(route('risk.kri.dashboard'))
            ->assertInertia(fn (Assert $p) => $p
                ->where('returnsCard.period', null)
                ->where('returnsCard.cycleOpened', false)
                ->where('returnsCard.summary.denominator', 0));
    }

    #[Test]
    public function a_run_that_is_not_completed_does_not_say_the_cycle_opened(): void
    {
        $run = $this->openRun();
        $run->forceFill(['status' => 'failed'])->save();

        $this->actingAs($this->viewer)->get(route('risk.kri.dashboard'))
            ->assertInertia(fn (Assert $p) => $p->where('returnsCard.cycleOpened', false));
    }

    /* ------------------------------------------------------------------ */
    /*  Drill-down */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_drill_down_filters_orders_and_summarises(): void
    {
        $r = $this->fiveReturns();
        $url = fn (array $q = []) => route('risk.kri.returns.index', $q);

        $this->actingAs($this->viewer)->get($url())
            ->assertInertia(fn (Assert $p) => $p
                ->where('filter', 'not_submitted')
                ->where('rows', fn ($rows) => collect($rows)->pluck('return_id')->all() === [$r['E']->id, $r['D']->id, $r['C']->id]
                    && collect($rows)->pluck('display_status')->all() === ['overdue', 'reopened', 'not_started'])
                ->where('rows.0.days_overdue', 3)
                ->where('rows.0.due_on', '2026-10-30'));

        $this->actingAs($this->viewer)->get($url(['filter' => 'submitted']))
            ->assertInertia(fn (Assert $p) => $p->where('rows', fn ($rows) => collect($rows)->pluck('return_id')->sort()->values()->all() === collect([$r['A']->id, $r['B']->id])->sort()->values()->all()));

        $this->actingAs($this->viewer)->get($url(['filter' => 'all']))->assertInertia(fn (Assert $p) => $p->has('rows', 5));
        $this->actingAs($this->viewer)->get($url(['filter' => 'nonsense']))->assertInertia(fn (Assert $p) => $p->where('filter', 'not_submitted'));
    }

    #[Test]
    public function the_drill_down_row_carries_champions_progress_and_reminder_state(): void
    {
        $r = $this->fiveReturns();
        $champion = $this->makeStaff('Dormant Champion', [], false);
        $this->makeContact($r['C']->businessUnit, $this->makeStaff('Active Champion'));
        $this->makeContact($r['C']->businessUnit, $champion);
        \App\Models\KriReturnLine::query()->where('kri_return_id', $r['C']->id)->update(['value' => 4]);

        $this->actingAs($this->viewer)->get(route('risk.kri.returns.index'))
            ->assertInertia(fn (Assert $p) => $p->where('rows', function ($rows) use ($r) {
                $row = collect($rows)->firstWhere('return_id', $r['C']->id);

                return $row['has_champion'] === true && count($row['champions']) >= 1
                    && $row['contact_counts']['risk_champion'] === 1      // the dormant one could not receive a reminder
                    && $row['kris_due'] === 1 && $row['kris_completed'] === 1
                    && $row['display_status'] === 'in_progress'
                    && $row['reminder_count'] === 0 && $row['reminded_today'] === false && $row['next_tier'] === 1
                    && $row['last_reminder'] === null && $row['notices'] === [] && $row['notices_total'] === 0
                    && $row['view_url'] === route('risk.kri.returns.show', $r['C']->id);
            }));
    }

    #[Test]
    public function the_drill_down_carries_at_most_three_notices_and_the_full_history_comes_from_its_own_route(): void
    {
        $r = $this->fiveReturns();
        $return = $r['C'];

        foreach (range(1, 5) as $seq) {
            $notice = KriReturnNotice::create([
                'organization_id' => $this->organization->id, 'kri_return_id' => $return->id, 'business_unit_id' => $return->business_unit_id,
                'kind' => 'reminder', 'sequence' => $seq, 'tier' => min($seq, 4), 'idempotency_key' => "test:{$seq}",
                'sent_on' => '2026-11-0'.$seq, 'status' => 'sent', 'queued_at' => now(),
            ]);
            KriReturnNoticeRecipient::create([
                'organization_id' => $this->organization->id, 'kri_return_notice_id' => $notice->id, 'user_id' => $this->actor->id,
                'email' => 'a@example.test', 'name' => 'A', 'contact_role' => 'risk_champion', 'status' => 'sent',
            ]);
        }

        $this->actingAs($this->viewer)->get(route('risk.kri.returns.index'))
            ->assertInertia(fn (Assert $p) => $p->where('rows', function ($rows) use ($return) {
                $row = collect($rows)->firstWhere('return_id', $return->id);

                return count($row['notices']) === 3 && $row['notices_total'] === 5
                    && collect($row['notices'])->pluck('sequence')->all() === [5, 4, 3]     // newest first
                    && count($row['notices'][0]['recipients']) === 1
                    && $row['reminder_count'] === 5 && $row['next_tier'] === 4
                    && $row['last_reminder']['tier'] === 4;
            }));

        $this->actingAs($this->viewer)->getJson(route('risk.kri.returns.notices', $return))
            ->assertOk()
            ->assertJsonCount(5, 'notices')
            ->assertJsonPath('notices.0.sequence', 5)
            ->assertJsonPath('notices.4.sequence', 1)
            ->assertJsonCount(1, 'notices.4.recipients');
    }

    /* ------------------------------------------------------------------ */
    /*  No N+1 */
    /* ------------------------------------------------------------------ */

    private function queriesFor(string $url): int
    {
        // Warm first: creating users resets the permission cache, which is not what is measured.
        $this->actingAs($this->viewer)->get($url)->assertOk();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($this->viewer)->get($url)->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    private function addDepartments(int $n, int $offset): void
    {
        for ($i = 0; $i < $n; $i++) {
            $unit = $this->makeUnit("Growth {$offset}-{$i}", "G{$offset}{$i}");
            $this->kriWith($unit, 'red', true);
            $this->kriWith($unit, 'green', false);
            $return = $this->makeReturn($unit);
            $this->makeContact($unit, $this->makeStaff("Champion {$offset}-{$i}"));
            KriReturnNotice::create([
                'organization_id' => $this->organization->id, 'kri_return_id' => $return->id, 'business_unit_id' => $unit->id,
                'kind' => 'request', 'sequence' => 0, 'tier' => 1, 'idempotency_key' => "request:r{$return->id}",
                'sent_on' => '2026-11-02', 'status' => 'sent', 'queued_at' => now(),
            ]);
        }
    }

    #[Test]
    public function the_library_landing_runs_a_constant_number_of_queries_however_many_departments_there_are(): void
    {
        $this->openRun();
        $this->addDepartments(2, 1);
        $url = route('risk.kri.index');
        $this->queriesFor($url);                      // warm caches and permission lookups

        $few = $this->queriesFor($url);
        $this->addDepartments(10, 2);
        $many = $this->queriesFor($url);

        $this->assertSame($few, $many, "Library landing: {$few} queries for 2 departments, {$many} for 12 - a per-department query crept in");
    }

    #[Test]
    public function the_dashboard_card_and_drill_down_run_a_constant_number_of_queries(): void
    {
        $this->openRun();
        $this->addDepartments(2, 1);

        foreach ([route('risk.kri.dashboard'), route('risk.kri.returns.index', ['filter' => 'all'])] as $url) {
            $this->queriesFor($url);
        }

        $few = [$this->queriesFor(route('risk.kri.dashboard')), $this->queriesFor(route('risk.kri.returns.index', ['filter' => 'all']))];
        $this->addDepartments(10, 2);
        $many = [$this->queriesFor(route('risk.kri.dashboard')), $this->queriesFor(route('risk.kri.returns.index', ['filter' => 'all']))];

        $this->assertSame($few, $many, 'dashboard card / drill-down query counts grew with the number of departments');
    }
}
