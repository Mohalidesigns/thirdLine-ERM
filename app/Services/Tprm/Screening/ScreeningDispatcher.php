<?php

namespace App\Services\Tprm\Screening;

use App\Models\Tprm\Ownership;
use App\Models\Tprm\SanctionsList;
use App\Models\Tprm\ScreeningCheck;
use App\Models\Tprm\ScreeningMatch;
use App\Models\Tprm\ThirdParty;
use App\Services\Tprm\Screening\Drivers\LocalListDriver;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Running a screening across every configured provider — FR-DDL-05.
 *
 * THE ENTITY AND ITS PEOPLE ARE SCREENED SEPARATELY. CBN AML/CFT Reg. 29
 * requires directors and ultimate beneficial owners to be screened in their
 * own right, and that is not a formality: the common real case is a sanctioned
 * individual sitting behind a company that is not itself designated. Screening
 * only the company finds nothing and reports clear.
 *
 * A DRIVER THAT FAILS DOES NOT MAKE A CHECK CLEAR. Each provider's outcome is
 * recorded separately, and a run in which one provider timed out is recorded
 * as `failed` for that provider rather than folded into an overall clear
 * result. The distinction is the whole of the module's honesty here.
 *
 * NOTHING IS DECIDED HERE. Matches land `pending`; a person decides, with a
 * rationale, and only then does AC-08's escalation chain run.
 */
class ScreeningDispatcher
{
    /**
     * Every driver available to this installation.
     *
     * The two built-in list drivers always appear, because they need no
     * credentials — that is the point of shipping them. Commercial providers
     * register themselves here when their package is installed and their
     * credentials are configured.
     *
     * @return Collection<int, ScreeningDriver>
     */
    public function drivers(): Collection
    {
        /** @var Collection<int, ScreeningDriver> $drivers */
        $drivers = collect([
            new LocalListDriver(SanctionsList::UNSCR),
            new LocalListDriver(SanctionsList::NIGSAC),
        ]);

        foreach ((array) config('tprm.screening.drivers', []) as $class) {
            if (is_string($class) && class_exists($class)) {
                $driver = app($class);

                if ($driver instanceof ScreeningDriver) {
                    $drivers->push($driver);
                }
            }
        }

        return $drivers;
    }

    /**
     * Screen a third party and every director or UBO on its ownership record.
     *
     * @return array{checks: int, matches: int, failed: list<string>}
     */
    public function screenThirdParty(ThirdParty $thirdParty, ?int $userId = null): array
    {
        $subjects = collect([
            ['type' => ScreeningCheck::SUBJECT_THIRD_PARTY, 'id' => $thirdParty->getKey(), 'name' => $thirdParty->legal_name],
        ]);

        foreach ($this->screenableOwners($thirdParty) as $owner) {
            $subjects->push([
                'type' => ScreeningCheck::SUBJECT_OWNERSHIP,
                'id' => $owner->getKey(),
                'name' => $owner->holder_name,
            ]);
        }

        $checks = 0;
        $matches = 0;
        $failed = [];

        foreach ($subjects as $subject) {
            foreach ($this->drivers() as $driver) {
                $result = $this->run(
                    $driver,
                    $thirdParty->organization_id,
                    $subject['type'],
                    $subject['id'],
                    (string) $subject['name'],
                    $userId,
                );

                $checks++;
                $matches += count($result->matches);

                if (! $result->succeeded) {
                    $failed[] = $driver->name().': '.$result->error;
                }
            }
        }

        // The timestamp the screening-overdue signal and the data-confidence
        // calculation both read. Written once per run rather than per check,
        // and only when at least one provider actually answered — a run in
        // which every driver failed has not screened anybody, and recording it
        // as a screening date would silence the overdue signal for a year.
        if ($checks > count($failed)) {
            $thirdParty->forceFill(['last_screened_at' => now()])->save();
        }

        return ['checks' => $checks, 'matches' => $matches, 'failed' => array_values(array_unique($failed))];
    }

    /**
     * Run one provider against one subject and persist the check.
     */
    public function run(
        ScreeningDriver $driver,
        int $organizationId,
        string $subjectType,
        int $subjectId,
        string $name,
        ?int $userId = null,
    ): ScreeningResult {
        $result = $driver->search($name);

        DB::transaction(function () use ($driver, $organizationId, $subjectType, $subjectId, $userId, $result) {
            $check = ScreeningCheck::create([
                'organization_id' => $organizationId,
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'provider' => $driver->key(),
                'list_types' => $driver->listTypes(),
                'run_at' => now(),
                'status' => match (true) {
                    ! $result->succeeded => ScreeningCheck::STATUS_FAILED,
                    $result->hasMatches() => ScreeningCheck::STATUS_MATCHES,
                    default => ScreeningCheck::STATUS_CLEAR,
                },
                // Verbatim. Reg. 35 wants the provider's answer retrievable
                // for five years, not our reading of it.
                'raw_response' => $result->succeeded
                    ? $result->raw
                    : ['error' => $result->error, 'failed_at' => now()->toIso8601String()],
                'next_due_at' => now()->addMonths((int) config('tprm.defaults.screening_interval_months', 12)),
                'created_by' => $userId,
            ]);

            foreach ($result->matches as $match) {
                ScreeningMatch::create([
                    'organization_id' => $organizationId,
                    'check_id' => $check->getKey(),
                    'list_name' => $match['list_name'],
                    'matched_name' => $match['matched_name'],
                    'match_score' => $match['score'],
                    'entity_details' => $match['details'],
                    // `pending`. No driver decides.
                ]);
            }
        });

        return $result;
    }

    /**
     * The people Reg. 29 requires screening in their own right.
     *
     * Directors and ultimate beneficial owners. A shareholder below the
     * beneficial-ownership threshold is not screened, because screening every
     * minority holder of a listed company produces a queue nobody works —
     * and a match queue nobody works is the same as no screening.
     *
     * @return Collection<int, Ownership>
     */
    public function screenableOwners(ThirdParty $thirdParty): Collection
    {
        // `Ownership::SCREENABLE_RELATIONSHIPS` rather than a list spelled out
        // here. Phase 0 already declared which relationships Reg. 29 covers,
        // and a second list in this file would be the one that goes stale.
        return Ownership::query()
            ->where('third_party_id', $thirdParty->getKey())
            ->whereIn('relationship', Ownership::SCREENABLE_RELATIONSHIPS)
            ->get();
    }

    /**
     * The overall screening picture for a third party.
     *
     * @return array<string, mixed>
     */
    public function statusFor(ThirdParty $thirdParty): array
    {
        $checks = ScreeningCheck::query()->forThirdParty($thirdParty->getKey())->get();

        $pending = ScreeningMatch::query()
            ->whereIn('check_id', $checks->pluck('id'))
            ->pending()
            ->count();

        $trueMatches = ScreeningMatch::query()
            ->whereIn('check_id', $checks->pluck('id'))
            ->trueMatches()
            ->count();

        return [
            'last_screened_at' => $thirdParty->last_screened_at?->toDateString(),
            'checks' => $checks->count(),
            'providers' => $checks->pluck('provider')->unique()->values()->all(),
            'failed_providers' => $checks->where('status', ScreeningCheck::STATUS_FAILED)
                ->pluck('provider')->unique()->values()->all(),
            'pending_matches' => $pending,
            'true_matches' => $trueMatches,
            'owners_screened' => $checks->where('subject_type', ScreeningCheck::SUBJECT_OWNERSHIP)
                ->pluck('subject_id')->unique()->count(),
            'owners_to_screen' => $this->screenableOwners($thirdParty)->count(),
        ];
    }
}
