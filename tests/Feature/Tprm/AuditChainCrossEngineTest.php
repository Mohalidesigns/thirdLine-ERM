<?php

namespace Tests\Feature\Tprm;

use App\Models\Organization;
use App\Models\Tprm\AuditLog;
use App\Models\Tprm\ThirdParty;
use App\Support\CanonicalJson;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * ADR 0025: the TPRM audit chain verifies on MariaDB AND MySQL 8, and a real
 * edit made around the application is still detected on both.
 *
 * Both CI legs use the `mysql` driver, so the engine is told apart by
 * `SELECT VERSION()`. The round-trip test asserts what the raw column returned,
 * on each engine, so neither leg can pass by matching nothing: MariaDB must
 * hand back PHP's own text and MySQL must hand back something else, or the test
 * did not exercise the behaviour the ADR is about.
 */
class AuditChainCrossEngineTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private ThirdParty $vendor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::create([
            'name' => 'Kano Heritage Bank',
            'short_name' => 'KHB',
            'institution_type' => 'commercial_bank',
            'sector' => 'banking',
            'is_active' => true,
        ]);

        TenantContext::set($this->organization->id);

        $this->vendor = ThirdParty::create([
            'legal_name' => 'Chain Vendor',
            'slug' => 'chain-vendor',
            'entity_type' => 'company',
        ]);
    }

    protected function tearDown(): void
    {
        TenantContext::clear();

        parent::tearDown();
    }

    /** The ADR's rich fixture: reordered keys, a slash, an accent, a float, a list. */
    private function richPayload(): array
    {
        return [
            'trading_name' => 'Café / Ltd',
            'b' => 1,
            'a' => [3, 1, 2],
            'n' => null,
            't' => true,
            'f' => 75.9,
            'e' => [],
            'o' => ['z' => 1, 'y' => 2],
            'd' => '12.50',
        ];
    }

    private function isMariaDb(): bool
    {
        return str_contains((string) DB::selectOne('select version() as v')->v, 'MariaDB');
    }

    /** Write a row through the real path (the trait's method, so the hooks seal it). */
    private function writeRow(string $event, ?array $before, ?array $after): AuditLog
    {
        $this->vendor->writeAuditRow($event, $before, $after);

        return AuditLog::query()->where('event', $event)->orderByDesc('id')->firstOrFail();
    }

    #[Test]
    public function a_row_with_a_rich_payload_verifies_after_a_real_round_trip_and_the_engine_really_differs(): void
    {
        $this->writeRow('probe', null, $this->richPayload());

        $fresh = AuditLog::query()->where('event', 'probe')->firstOrFail();
        $raw = (string) DB::table('tp_audit_logs')->where('id', $fresh->id)->value('after');

        // What the `array` cast wrote: PHP's default encoding, insertion order,
        // of the payload AS STORED. The float 75.9 was quoted by `creating`
        // (ADR 0025 section 2a) before the cast encoded it.
        $phpText = json_encode(array_replace($this->richPayload(), ['f' => '75.9']));

        if ($this->isMariaDb()) {
            $this->assertSame($phpText, $raw, 'MariaDB should return the stored text verbatim.');
        } else {
            $this->assertNotSame($phpText, $raw, 'MySQL 8 should return its own re-serialisation, not PHP\'s text.');
        }

        // Either way the value is the same, and so is the verdict.
        $this->assertEquals(json_decode($phpText, true), json_decode($raw, true));
        $this->assertTrue($fresh->isIntact(), 'An honest row reads as tampered.');
        $this->assertSame($fresh->hash, $fresh->expectedHash());

        // Eloquent stores SQL NULL for a null payload, never the JSON literal.
        $this->assertNull(DB::table('tp_audit_logs')->where('id', $fresh->id)->value('before'));
    }

    #[Test]
    public function a_reformatted_but_equal_payload_still_verifies(): void
    {
        $row = $this->writeRow('probe', null, $this->richPayload());

        // Rewrite the column in a different key order, spacing and escaping:
        // exactly what the other engine does on its own. Built from the STORED,
        // decoded column: the fixture holds the float 75.9, the column holds
        // "75.9", and writing the fixture back would be a real type change.
        $storedValue = json_decode((string) DB::table('tp_audit_logs')->where('id', $row->id)->value('after'), true);
        $this->assertSame('75.9', $storedValue['f']);
        $reordered = array_reverse($storedValue, true);
        $text = json_encode($reordered, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        DB::table('tp_audit_logs')->where('id', $row->id)->update(['after' => $text]);

        $this->assertTrue(AuditLog::findOrFail($row->id)->isIntact());
    }

    #[Test]
    public function the_chain_is_intact_across_many_real_writes(): void
    {
        $this->vendor->update(['trading_name' => 'First / One']);
        $this->vendor->update(['trading_name' => 'Zweite Café']);
        $this->writeRow('probe', $this->richPayload(), $this->richPayload());

        $rows = AuditLog::query()->orderBy('id')->get();

        $this->assertGreaterThanOrEqual(4, $rows->count());
        foreach ($rows as $row) {
            $this->assertTrue($row->isIntact(), "Audit row {$row->id} ({$row->event}) does not verify.");
        }
        foreach ($rows->skip(1)->values() as $i => $row) {
            $this->assertSame($rows[$i]->hash, $row->previous_hash, "Row {$row->id} does not link to its predecessor.");
        }
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function tamperedAfters(): array
    {
        // The fixture is {"v":..., "keep":[3,1,2]}; the second item is the
        // replacement column text, written by raw SQL.
        return [
            'a value changed' => [['v' => 'x'], '{"v":"y","keep":[3,1,2]}'],
            'true became 1' => [['v' => true], '{"v":1,"keep":[3,1,2]}'],
            '1 became "1"' => [['v' => 1], '{"v":"1","keep":[3,1,2]}'],
            '1 became 1.0' => [['v' => 1], '{"v":1.0,"keep":[3,1,2]}'],
            '"72.5" became 72.5' => [['v' => 72.5], '{"v":72.5,"keep":[3,1,2]}'],
            'null became ""' => [['v' => null], '{"v":"","keep":[3,1,2]}'],
            'a list was re-ordered' => [['v' => 'x'], '{"v":"x","keep":[1,2,3]}'],
            'a key was added' => [['v' => 'x'], '{"v":"x","keep":[3,1,2],"extra":1}'],
            'a key was dropped' => [['v' => 'x'], '{"v":"x"}'],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    #[Test]
    #[DataProvider('tamperedAfters')]
    public function an_edit_inside_the_payload_made_around_the_application_is_detected(array $payload, string $replacement): void
    {
        $row = $this->writeRow('probe', null, $payload + ['keep' => [3, 1, 2]]);
        $this->assertTrue(AuditLog::findOrFail($row->id)->isIntact(), 'Control: the row must verify before the edit.');

        // MySQL 8 skips a write it judges to change nothing, and it judges the
        // integer 1 and the double 1.0 equal: a direct UPDATE from {"v":1} to
        // {"v":1.0} leaves the old value in place. Move through a different
        // value first so the replacement is a real change on both engines.
        DB::table('tp_audit_logs')->where('id', $row->id)->update(['after' => '{"__step":0}']);
        DB::table('tp_audit_logs')->where('id', $row->id)->update(['after' => $replacement]);

        // The tamper must have landed, or "detected" below would mean nothing.
        $stored = (string) DB::table('tp_audit_logs')->where('id', $row->id)->value('after');
        $this->assertSame(CanonicalJson::fromColumn($replacement), CanonicalJson::fromColumn($stored));

        $this->assertFalse(AuditLog::findOrFail($row->id)->isIntact());
    }

    /** @return array<string, mixed> */
    private function awkwardDoubles(): array
    {
        return [
            'ratio' => 478 / 53,
            'big' => 1.0e25,
            'whole' => 75.0,
            'n' => 7,
            'nested' => ['r' => [0.1 + 0.2]],
        ];
    }

    /** @return array<string, mixed> */
    private function awkwardDoublesAsStored(): array
    {
        return [
            'ratio' => '9.018867924528301',
            'big' => '1.0e+25',
            'whole' => 75,
            'n' => 7,
            'nested' => ['r' => ['0.30000000000000004']],
        ];
    }

    #[Test]
    public function a_payload_of_awkward_doubles_is_intact_on_both_engines_and_stores_no_float_in_before_or_after(): void
    {
        // Both columns carry the doubles: a loop that quoted only `after` would
        // leave `before` holding numbers MySQL 8 can read back differently.
        $row = $this->writeRow('probe', $this->awkwardDoubles(), $this->awkwardDoubles());

        $fresh = AuditLog::findOrFail($row->id);
        $this->assertTrue($fresh->isIntact(), 'An honest row holding a double reads as tampered.');

        foreach (['before', 'after'] as $column) {
            $raw = json_decode((string) DB::table('tp_audit_logs')->where('id', $row->id)->value($column), true);

            // MySQL 8 re-orders keys, so compare key-sorted; types stay strict.
            $this->assertSame(
                CanonicalJson::normalise($this->awkwardDoublesAsStored()),
                CanonicalJson::normalise($raw),
                "The `{$column}` column does not hold the quoted payload.",
            );
        }
    }

    #[Test]
    public function floats_are_quoted_on_the_set_raw_attributes_path_too(): void
    {
        // setRawAttributes bypasses the `array` cast, so the hook receives an
        // ARRAY, not text. Without the is_array branch it would not decode it.
        $row = new AuditLog;
        $row->setRawAttributes([
            'organization_id' => $this->organization->id,
            'auditable_type' => ThirdParty::class,
            'auditable_id' => $this->vendor->id,
            'event' => 'raw_probe',
            'actor_type' => 'system',
            'actor_id' => null,
            'actor_label' => 'raw',
            'before' => ['ratio' => 478 / 53],
            'after' => ['big' => 1.0e25, 'n' => 7],
        ]);
        $row->save();

        $fresh = AuditLog::findOrFail($row->id);
        $this->assertTrue($fresh->isIntact());
        $this->assertSame(
            ['ratio' => '9.018867924528301'],
            json_decode((string) DB::table('tp_audit_logs')->where('id', $row->id)->value('before'), true),
        );
        $this->assertSame(
            CanonicalJson::normalise(['big' => '1.0e+25', 'n' => 7]),
            CanonicalJson::normalise(json_decode((string) DB::table('tp_audit_logs')->where('id', $row->id)->value('after'), true)),
        );
    }

    #[Test]
    public function the_unquoted_control_row_drifts_on_mysql_and_not_on_mariadb(): void
    {
        // Bypasses the rule: the unquoted text {"big":1.0e25}, sealed by hand.
        $text = '{"big":1.0e25}';
        $attributes = [
            'organization_id' => $this->organization->id,
            'auditable_type' => ThirdParty::class,
            'auditable_id' => $this->vendor->id,
            'event' => 'control',
            'actor_type' => 'system',
            'actor_id' => null,
            'actor_label' => 'control',
            'before' => null,
            'after' => $text,
            'ip' => null,
            'user_agent' => null,
            'correlation_id' => null,
            'created_at' => '2026-10-03 09:30:00',
        ];
        $attributes['previous_hash'] = null;
        $attributes['hash'] = AuditLog::chainHash($attributes, null);

        $id = DB::table('tp_audit_logs')->insertGetId($attributes);
        $fresh = AuditLog::findOrFail($id);

        if ($this->isMariaDb()) {
            $this->assertTrue($fresh->isIntact(), 'MariaDB keeps the text, so the control must verify.');
        } else {
            $this->assertFalse(
                $fresh->isIntact(),
                'MySQL 8 should re-read 1.0e25 as 9.999999999999999e24. If this goes red, MySQL may have fixed its JSON double parser: re-evaluate ADR 0025 section 2a before deleting anything.',
            );
        }
    }

    #[Test]
    public function a_created_at_moved_by_one_second_is_detected(): void
    {
        $row = $this->writeRow('probe', null, $this->richPayload());
        $this->assertTrue(AuditLog::findOrFail($row->id)->isIntact());

        $stored = (string) DB::table('tp_audit_logs')->where('id', $row->id)->value('created_at');
        DB::table('tp_audit_logs')->where('id', $row->id)->update([
            'created_at' => CarbonImmutable::parse($stored)->addSecond()->format('Y-m-d H:i:s'),
        ]);

        $this->assertFalse(AuditLog::findOrFail($row->id)->isIntact());
    }

    #[Test]
    public function a_changed_event_is_detected(): void
    {
        $row = $this->writeRow('probe', null, $this->richPayload());

        DB::table('tp_audit_logs')->where('id', $row->id)->update(['event' => 'tampered']);

        $this->assertFalse(AuditLog::findOrFail($row->id)->isIntact());
    }

    #[Test]
    public function a_deleted_middle_row_breaks_the_link_of_the_next_one(): void
    {
        $first = $this->writeRow('probe_one', null, ['n' => 1]);
        $middle = $this->writeRow('probe_two', null, ['n' => 2]);
        $last = $this->writeRow('probe_three', null, ['n' => 3]);

        $this->assertSame($first->hash, $middle->previous_hash);
        $this->assertSame($middle->hash, $last->previous_hash);

        DB::table('tp_audit_logs')->where('id', $middle->id)->delete();

        $predecessor = AuditLog::query()
            ->withoutGlobalScopes()
            ->where('organization_id', $last->organization_id)
            ->where('id', '<', $last->id)
            ->orderByDesc('id')
            ->firstOrFail();

        $this->assertSame($first->id, $predecessor->id);
        $this->assertNotSame(
            $predecessor->hash,
            AuditLog::findOrFail($last->id)->previous_hash,
            'The row after a deleted row must no longer link to its new predecessor.',
        );
    }
}
