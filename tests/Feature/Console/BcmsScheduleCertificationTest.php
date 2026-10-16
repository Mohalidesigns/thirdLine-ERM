<?php

namespace Tests\Feature\Console;

use App\Models\Bcms\ExerciseOccurrence;
use App\Models\Bcms\ExerciseParticipant;
use App\Models\Bcms\TrainingRecord;
use App\Models\Organization;
use App\Models\User;
use Database\Seeders\Bcms\BcmsReferenceSeeder;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * GATE 1 (BCMS Phase 7 certification). Two things no other file does:
 *
 *  1. Asserts the SCHEDULE ENTRIES themselves. Every `bcms:*` command had a
 *     test that invokes it; nothing asserted that routes/console.php actually
 *     schedules it, at the cadence its docblock argues for, with the overlap
 *     guard a minute-resolution or multi-tenant sweep needs. A command that is
 *     tested but never scheduled is code that never runs in production.
 *  2. Invokes `bcms:training-link-exercises`, the one scheduled BCMS command
 *     that no test anywhere ran.
 */
class BcmsScheduleCertificationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * command (as scheduled) => [cron expression, withoutOverlapping, onOneServer]
     *
     * @return array<string, array{0: string, 1: bool, 2: bool}>
     */
    public static function schedule(): array
    {
        return [
            'bcms:dispatch-reminders' => ['0 * * * *', true, false],
            'bcms:watchdog' => ['30 * * * *', true, false],
            'bcms:sweep-actions' => ['45 6 * * *', false, false],
            'bcms:chase-bia' => ['0 7 * * *', false, false],
            'bcms:check-plan-drift' => ['30 5 * * *', true, false],
            'bcms:cascade-tick' => ['* * * * *', true, false],
            'bcms:call-tree-hygiene' => ['15 5 * * *', true, false],
            'bcms:training-link-exercises' => ['0 6 * * *', true, true],
            'bcms:vendor-attestation-recompute' => ['15 6 * * *', true, true],
            'bcms:sync-directory' => ['30 2 * * *', true, true],
            'bcms:sync-directory --delta' => ['7,22,37,52 * * * *', true, true],
        ];
    }

    #[Test]
    public function every_scheduled_bcms_command_is_registered_with_its_documented_cadence_and_guards(): void
    {
        // Booting the console kernel is what loads routes/console.php.
        Artisan::call('list');

        $events = collect(app(Schedule::class)->events())
            ->filter(fn (Event $e) => str_contains((string) $e->command, 'bcms:'))
            ->mapWithKeys(function (Event $e) {
                // "'/usr/bin/php' 'artisan' bcms:cascade-tick" -> "bcms:cascade-tick"
                $command = trim((string) preg_replace('/^.*?artisan[\'"]?\s+/', '', (string) $e->command));

                return [$command => $e];
            });

        $this->assertEqualsCanonicalizing(
            array_keys(self::schedule()),
            $events->keys()->all(),
            'The set of scheduled bcms:* commands changed; update this table and the certification record together.',
        );

        foreach (self::schedule() as $command => [$cron, $overlap, $oneServer]) {
            /** @var Event $event */
            $event = $events[$command];

            $this->assertSame($cron, $event->expression, "[{$command}] cadence");
            $this->assertSame($overlap, $event->withoutOverlapping, "[{$command}] withoutOverlapping");
            $this->assertSame($oneServer, $event->onOneServer, "[{$command}] onOneServer");
        }
    }

    #[Test]
    public function the_training_link_command_links_present_participants_of_recent_completed_occurrences_and_is_idempotent(): void
    {
        config()->set('features.bcms', true);

        $organization = Organization::create([
            'name' => 'Kano Heritage Bank', 'short_name' => 'KHB',
            'institution_type' => 'commercial_bank', 'sector' => 'banking', 'is_active' => true,
        ]);
        TenantContext::set($organization->id);
        $this->seed(BcmsReferenceSeeder::class);
        TenantContext::set($organization->id);

        $warden = User::create([
            'name' => 'Warden', 'email' => 'warden@khb.test',
            'password' => Hash::make(Str::random(32)), 'email_verified_at' => now(),
            'organization_id' => $organization->id, 'is_active' => true,
        ]);
        $warden->assignRole(Role::findOrCreate('floor-warden', 'web'));

        $recent = ExerciseOccurrence::factory()->create([
            'status' => 'completed', 'scheduled_date' => now()->subDay()->toDateString(), 'actual_end' => now()->subDay(),
        ]);
        ExerciseParticipant::factory()->create([
            'occurrence_id' => $recent->getKey(), 'user_id' => $warden->getKey(), 'attendance_status' => 'present',
        ]);

        // A completed occurrence last touched a month ago is outside --days=7.
        $stale = ExerciseOccurrence::factory()->create([
            'status' => 'completed', 'scheduled_date' => now()->subMonth()->toDateString(), 'actual_end' => now()->subMonth(),
        ]);
        ExerciseParticipant::factory()->create([
            'occurrence_id' => $stale->getKey(), 'user_id' => $warden->getKey(), 'attendance_status' => 'present',
        ]);
        ExerciseOccurrence::query()->whereKey($stale->getKey())->toBase()->update(['updated_at' => now()->subMonth()]);

        TenantContext::clear();

        $this->artisan('bcms:training-link-exercises')
            ->expectsOutputToContain('1 training record(s) linked')
            ->assertSuccessful();

        TenantContext::set($organization->id);
        $this->assertSame(1, TrainingRecord::query()->where('user_id', $warden->getKey())->count());
        $record = TrainingRecord::query()->where('user_id', $warden->getKey())->firstOrFail();
        $this->assertSame((int) $recent->getKey(), (int) $record->occurrence_id);
        $this->assertFalse((bool) $record->competency_assessed, 'A machine never asserts competence.');
        TenantContext::clear();

        // Second run: nothing new.
        $this->artisan('bcms:training-link-exercises')
            ->expectsOutputToContain('0 training record(s) linked')
            ->assertSuccessful();

        TenantContext::set($organization->id);
        $this->assertSame(1, TrainingRecord::query()->where('user_id', $warden->getKey())->count());

        // --days widens the look-back to take in the stale occurrence.
        TenantContext::clear();
        $this->artisan('bcms:training-link-exercises', ['--days' => 60])
            ->expectsOutputToContain('1 training record(s) linked')
            ->assertSuccessful();

        TenantContext::set($organization->id);
        $this->assertSame(2, TrainingRecord::query()->where('user_id', $warden->getKey())->count());
        TenantContext::clear();
    }

    #[Test]
    public function the_training_link_command_does_nothing_while_the_module_is_off(): void
    {
        config()->set('features.bcms', false);

        $this->artisan('bcms:training-link-exercises')
            ->expectsOutputToContain('switched off')
            ->assertSuccessful();

        $this->assertSame(0, TrainingRecord::query()->withoutGlobalScopes()->count());
    }
}
