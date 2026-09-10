<?php

namespace Tests\Feature\Console;

use App\Models\Tprm\SanctionsEntry;
use App\Models\Tprm\SanctionsList;
use Database\Seeders\Tprm\TprmReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `tprm:refresh-sanctions-lists`.
 *
 * `SanctionsListRefresher` itself has direct coverage via the screening tests
 * in MonitoringAndScreeningTest; this file covers the command wrapper: the
 * `--list` filter, the "leave the previous list standing" behaviour on a
 * failed fetch, and the no-op paths. `tp_sanctions_lists` is explicitly NOT
 * tenant-scoped (one list for every institution), so — unlike every other
 * command in this defect — there is no `TenantContext` for this wrapper to
 * set, and none of it should be tested as if there were.
 */
class RefreshTprmSanctionsListsCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('features.tprm', true);
        $this->seed(TprmReferenceSeeder::class);

        // Point both built-in lists at fake, stable URLs rather than the real
        // UN endpoint the seeder installs, so the test never makes a network
        // call and never depends on what the UN happens to be publishing.
        SanctionsList::query()->where('code', SanctionsList::UNSCR)
            ->update(['source_url' => 'https://sanctions.test/unscr.xml']);
        SanctionsList::query()->where('code', SanctionsList::NIGSAC)
            ->update(['source_url' => 'https://sanctions.test/nigsac.csv']);
    }

    #[Test]
    public function it_refreshes_every_active_list_and_records_the_entry_count(): void
    {
        Http::fake([
            'sanctions.test/unscr.xml' => Http::response($this->unscrXml([
                ['id' => 'UN-1', 'first' => 'Yasin', 'second' => 'Abdullah', 'third' => 'Al-Qadi'],
            ]), 200),
            'sanctions.test/nigsac.csv' => Http::response($this->nigsacCsv([
                ['id' => 'NG-1', 'name' => 'Example Sanctioned Entity Ltd'],
            ]), 200),
        ]);

        $this->artisan('tprm:refresh-sanctions-lists')->assertSuccessful();

        $unscr = SanctionsList::query()->where('code', SanctionsList::UNSCR)->firstOrFail();
        $nigsac = SanctionsList::query()->where('code', SanctionsList::NIGSAC)->firstOrFail();

        $this->assertSame(1, $unscr->entry_count);
        $this->assertSame(SanctionsList::STATUS_OK, $unscr->last_refresh_status);
        $this->assertNotNull($unscr->last_refreshed_at);
        $this->assertSame(1, SanctionsEntry::query()->where('list_id', $unscr->getKey())->count());

        $this->assertSame(1, $nigsac->entry_count);
        $this->assertSame(SanctionsList::STATUS_OK, $nigsac->last_refresh_status);
    }

    #[Test]
    public function a_failed_fetch_leaves_the_previous_list_standing(): void
    {
        // A "previous list" to protect, seeded directly rather than through a
        // first HTTP round trip — `Http::fake()` merges rather than replaces
        // its stubs, so a second `Http::fake([...])` call for the same URL
        // would not override the first within one test.
        $before = SanctionsList::query()->where('code', SanctionsList::UNSCR)->firstOrFail();
        SanctionsEntry::create([
            'list_id' => $before->getKey(), 'external_id' => 'UN-1', 'name' => 'Yasin Abdullah Al-Qadi',
            'normalised_name' => SanctionsEntry::normalise('Yasin Abdullah Al-Qadi'), 'entity_type' => 'individual',
        ]);
        $before->forceFill(['entry_count' => 1, 'last_refresh_status' => SanctionsList::STATUS_OK, 'last_refreshed_at' => now()->subDay()])->save();

        // The publisher now fails outright.
        Http::fake([
            'sanctions.test/unscr.xml' => Http::response('Service Unavailable', 503),
        ]);

        $this->artisan('tprm:refresh-sanctions-lists', ['--list' => 'unscr'])->assertSuccessful();

        $after = SanctionsList::query()->where('code', SanctionsList::UNSCR)->firstOrFail();
        $this->assertSame(1, $after->entry_count, 'A failed refresh must not empty the previous list.');
        $this->assertSame(SanctionsList::STATUS_FAILED, $after->last_refresh_status);
        $this->assertStringContainsString('503', (string) $after->last_refresh_error);
        $this->assertSame(1, SanctionsEntry::query()->where('list_id', $after->getKey())->count());
    }

    #[Test]
    public function a_parse_that_yields_nothing_is_treated_as_a_failure_not_an_empty_list(): void
    {
        // Seeded directly for the same reason as the test above: one
        // `Http::fake()` call per test, so there is no ordering ambiguity
        // between two stubs for the same URL.
        $list = SanctionsList::query()->where('code', SanctionsList::UNSCR)->firstOrFail();
        SanctionsEntry::create([
            'list_id' => $list->getKey(), 'external_id' => 'UN-1', 'name' => 'Yasin Abdullah Al-Qadi',
            'normalised_name' => SanctionsEntry::normalise('Yasin Abdullah Al-Qadi'), 'entity_type' => 'individual',
        ]);
        $list->forceFill(['entry_count' => 1, 'last_refresh_status' => SanctionsList::STATUS_OK, 'last_refreshed_at' => now()->subDay()])->save();

        Http::fake([
            // Well-formed but empty of individuals and entities.
            'sanctions.test/unscr.xml' => Http::response('<CONSOLIDATED_LIST><INDIVIDUALS/><ENTITIES/></CONSOLIDATED_LIST>', 200),
        ]);

        $this->artisan('tprm:refresh-sanctions-lists', ['--list' => 'unscr'])->assertSuccessful();

        $list = SanctionsList::query()->where('code', SanctionsList::UNSCR)->firstOrFail();
        $this->assertSame(1, $list->entry_count, 'An empty parse replaced a working list.');
        $this->assertSame(SanctionsList::STATUS_FAILED, $list->last_refresh_status);
    }

    #[Test]
    public function the_list_option_refreshes_only_the_named_list(): void
    {
        Http::fake([
            'sanctions.test/unscr.xml' => Http::response($this->unscrXml([
                ['id' => 'UN-1', 'first' => 'Yasin', 'second' => 'Abdullah', 'third' => 'Al-Qadi'],
            ]), 200),
            'sanctions.test/nigsac.csv' => Http::response($this->nigsacCsv([
                ['id' => 'NG-1', 'name' => 'Example Sanctioned Entity Ltd'],
            ]), 200),
        ]);

        $this->artisan('tprm:refresh-sanctions-lists', ['--list' => 'unscr'])->assertSuccessful();

        Http::assertSentCount(1);
        $this->assertSame(1, SanctionsList::query()->where('code', SanctionsList::UNSCR)->value('entry_count'));
        $this->assertNull(SanctionsList::query()->where('code', SanctionsList::NIGSAC)->value('last_refreshed_at'));
    }

    #[Test]
    public function it_no_ops_when_no_active_lists_are_installed(): void
    {
        SanctionsList::query()->update(['is_active' => false]);
        Http::fake();

        $this->artisan('tprm:refresh-sanctions-lists')
            ->expectsOutputToContain('No active sanctions lists are installed.')
            ->assertSuccessful();

        Http::assertNothingSent();
    }

    #[Test]
    public function it_no_ops_when_the_feature_is_switched_off(): void
    {
        config()->set('features.tprm', false);

        Http::fake();

        $this->artisan('tprm:refresh-sanctions-lists')
            ->expectsOutputToContain('disabled')
            ->assertSuccessful();

        Http::assertNothingSent();
    }

    #[Test]
    public function running_it_twice_with_an_unchanged_feed_does_not_duplicate_entries(): void
    {
        Http::fake([
            'sanctions.test/nigsac.csv' => Http::response($this->nigsacCsv([
                ['id' => 'NG-1', 'name' => 'Example Sanctioned Entity Ltd'],
            ]), 200),
        ]);

        $this->artisan('tprm:refresh-sanctions-lists', ['--list' => 'nigsac'])->assertSuccessful();
        $this->artisan('tprm:refresh-sanctions-lists', ['--list' => 'nigsac'])->assertSuccessful();

        $list = SanctionsList::query()->where('code', SanctionsList::NIGSAC)->firstOrFail();
        $this->assertSame(1, $list->entry_count);
        $this->assertSame(1, SanctionsEntry::query()->where('list_id', $list->getKey())->count());
    }

    /** @param list<array{id: string, first?: string, second?: string, third?: string}> $individuals */
    private function unscrXml(array $individuals): string
    {
        $nodes = '';

        foreach ($individuals as $person) {
            $nodes .= sprintf(
                '<INDIVIDUAL><DATAID>%s</DATAID><FIRST_NAME>%s</FIRST_NAME><SECOND_NAME>%s</SECOND_NAME><THIRD_NAME>%s</THIRD_NAME></INDIVIDUAL>',
                htmlspecialchars($person['id']),
                htmlspecialchars($person['first'] ?? ''),
                htmlspecialchars($person['second'] ?? ''),
                htmlspecialchars($person['third'] ?? ''),
            );
        }

        return "<CONSOLIDATED_LIST><INDIVIDUALS>{$nodes}</INDIVIDUALS><ENTITIES/></CONSOLIDATED_LIST>";
    }

    /** @param list<array{id: string, name: string}> $rows */
    private function nigsacCsv(array $rows): string
    {
        $lines = ['name,id,country'];

        foreach ($rows as $row) {
            $lines[] = sprintf('"%s","%s","NG"', $row['name'], $row['id']);
        }

        return implode("\n", $lines);
    }
}
