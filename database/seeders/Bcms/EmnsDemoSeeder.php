<?php

namespace Database\Seeders\Bcms;

use App\Enums\Bcms\AlertSeverity;
use App\Models\Bcms\Alert;
use App\Models\Bcms\AlertRecipient;
use App\Models\Bcms\AlertTemplate;
use App\Models\Bcms\CallTree;
use App\Models\Bcms\Contact;
use App\Models\Bcms\SavedGroup;
use App\Models\Bcms\Site;
use App\Models\Organization;
use App\Services\Bcms\Emns\AlertDispatcher;
use App\Services\Bcms\Emns\AlertService;
use App\Services\Bcms\Emns\RollCallService;

/**
 * The EMNS demo estate: mixed channel availability, two saved audiences, and
 * one completed evacuation roll-call.
 *
 * THE CHANNEL SPREAD IS THE POINT. The phase's test data asks for 200 people
 * with no smartphone, 150 with no email and 80 with bad numbers, because a
 * console that only ever shows "1,000 recipients, all reachable" demonstrates
 * nothing. The interesting screen is the one that says 43 of them cannot be
 * reached at all, and that screen needs 43 people who cannot be reached.
 *
 * ONE ALERT IS RUN FOR REAL, AS A SIMULATION. It produces a genuine roll-call
 * with genuine unaccounted-for people, computed by the same code a live
 * dispatch uses. Nothing about the dashboard is fixtured, and because it is a
 * simulation nothing was dispatched — which is also the honest state of a
 * system whose gateways have no credentials yet.
 */
class EmnsDemoSeeder
{
    public function run(Organization $organization): void
    {
        if (Alert::query()->exists()) {
            return;
        }

        $this->spreadChannels();
        $this->seedSavedGroups($organization);
        $this->runEvacuationDrill($organization);
    }

    /**
     * Give the roster the shape a real bank's has.
     *
     * A DEMO WHERE EVERYBODY IS REACHABLE HIDES THE PRODUCT. These proportions
     * are roughly what a Nigerian commercial bank's contact roster actually
     * looks like: branch staff without corporate email, older staff without a
     * smartphone, and a tail of numbers that stopped working and nobody
     * noticed — which is the failure the whole module exists to surface.
     */
    private function spreadChannels(): void
    {
        $contacts = Contact::query()->where('is_active', true)->orderBy('id')->get();

        if ($contacts->count() < 20) {
            return;
        }

        foreach ($contacts as $index => $contact) {
            // Every fifth person has no smartphone: SMS, voice and USSD only.
            if ($index % 5 === 0) {
                $contact->update(['push_token' => null, 'whatsapp' => null, 'teams_id' => null]);

                continue;
            }

            // Every seventh has no corporate email — branch and facilities staff.
            if ($index % 7 === 0) {
                $contact->update(['email' => null]);

                continue;
            }

            // A tail whose numbers stopped working and nobody noticed.
            if ($index % 13 === 0) {
                $contact->update([
                    'mobile_primary' => null,
                    'mobile_secondary' => null,
                    'consecutive_failures' => 5,
                    'verification_status' => 'failed',
                    'last_verified_at' => null,
                ]);

                continue;
            }

            // AND A SMALLER TAIL WITH NOTHING AT ALL. These are the people the
            // roll-call reports as never contacted rather than as not having
            // answered, and without them the dashboard's most important
            // distinction has nothing to show. Every real roster has a few:
            // a contractor nobody re-verified, a leaver HR has not closed.
            if ($index % 29 === 0) {
                $contact->update([
                    'mobile_primary' => null,
                    'mobile_secondary' => null,
                    'email' => null,
                    'whatsapp' => null,
                    'teams_id' => null,
                    'push_token' => null,
                    'consecutive_failures' => 9,
                    'verification_status' => 'failed',
                    'last_verified_at' => null,
                ]);

                continue;
            }

            // The rest are reachable on everything the bank administers.
            $contact->update([
                'whatsapp' => $contact->mobile_primary,
                'teams_id' => $contact->email,
            ]);
        }
    }

    private function seedSavedGroups(Organization $organization): void
    {
        $lagosSiteIds = Site::query()
            ->where('city', 'Lagos')
            ->pluck('id')->map(fn ($id) => (int) $id)->all();

        if ($lagosSiteIds !== []) {
            SavedGroup::query()->updateOrCreate(
                ['name' => 'All Lagos sites'],
                [
                    'organization_id' => $organization->id,
                    'description' => 'Everybody at a Lagos address — the audience for a Lagos-only '
                        .'disruption such as a flood or a fuel shortage.',
                    'rule' => ['type' => 'site', 'ids' => $lagosSiteIds],
                    'is_dynamic' => true,
                ],
            );
        }

        $crisisTree = CallTree::query()->where('tree_type', 'crisis_team')->first();

        if ($crisisTree !== null) {
            SavedGroup::query()->updateOrCreate(
                ['name' => 'Crisis Management Team'],
                [
                    'organization_id' => $organization->id,
                    'description' => 'The crisis team, resolved through its call tree so the audience '
                        .'follows the tree rather than drifting from it.',
                    'rule' => ['type' => 'call_tree', 'id' => (int) $crisisTree->getKey()],
                    'is_dynamic' => true,
                ],
            );
        }
    }

    /**
     * A completed evacuation drill, run through the real engine.
     */
    private function runEvacuationDrill(Organization $organization): void
    {
        $template = AlertTemplate::query()
            ->where('code', 'EVACUATE')->where('locale', 'en')->first();

        $hq = Site::query()->where('code', 'HQ')->first();

        if ($hq === null) {
            return;
        }

        $alerts = app(AlertService::class);

        $alert = $alerts->compose([
            'organization_id' => $organization->id,
            'template_id' => $template?->getKey(),
            'title' => 'Head office evacuation drill',
            'message' => 'Leave the building by the nearest fire exit and assemble at the rear car park. '
                .'Reply SAFE when you are out, or HELP if you need assistance.',
            'severity' => AlertSeverity::LifeSafety->value,
            'channels' => ['sms', 'voice', 'email'],
            'audience_rule' => ['type' => 'site', 'ids' => [(int) $hq->getKey()]],
            'response_required' => true,
            'response_options' => [
                ['value' => 'safe', 'label' => "I'm safe"],
                ['value' => 'needs_help', 'label' => 'I need help'],
                ['value' => 'not_on_site', 'label' => "I'm not on site"],
            ],
            'ack_window_minutes' => 15,
        ]);

        // A SIMULATION. Every message carries the exercise prefix and nothing
        // is dispatched — which is both correct for a drill and the honest
        // state of a deployment whose gateways have no credentials yet.
        $alert->forceFill(['is_simulation' => true])->save();

        try {
            $alerts->release($alert, null);
        } catch (\InvalidArgumentException) {
            // Nobody at HQ on this estate: nothing to demonstrate, and a seeder
            // that threw would take the whole demo down with it.
            return;
        }

        $recipients = AlertRecipient::query()
            ->where('alert_id', $alert->getKey())->with('contact')->get();

        /*
         * THE DISPATCHER IS CALLED DIRECTLY RATHER THAN THROUGH THE JOB, and
         * that is not a shortcut. `DispatchAlertChunkJob` clears
         * `TenantContext` in its `finally` — correct for a queue worker, which
         * must not leak one tenant's context into the next job — but running it
         * inline inside a seeder leaves everything after it untenanted. It cost
         * a failed maturity assessment three lines further down before this was
         * changed. Any future seeder running a BCMS job inline has to re-set
         * the tenant afterwards; not running the job at all is simpler.
         */
        foreach ($recipients->chunk(200) as $chunk) {
            app(AlertDispatcher::class)->dispatchBatch($alert, $chunk);
        }

        // Most people answer, a few do not, and one needs help — the shape of
        // every real evacuation and the only shape the dashboard is worth
        // looking at in.
        $rollCall = app(RollCallService::class);

        foreach ($recipients as $index => $recipient) {
            // A PERSON NOTHING WAS SENT TO CANNOT ANSWER. Recording a response
            // for them would be the seeder telling a lie the dashboard then
            // repeats — and "never contacted" against "contacted and silent"
            // is the distinction the whole screen turns on.
            if ($recipient->refresh()->status === \App\Enums\Bcms\RecipientStatus::Failed) {
                continue;
            }

            $answer = match (true) {
                $index % 17 === 0 => null,                          // silent
                $index % 23 === 0 => RollCallService::NEEDS_HELP,
                $index % 11 === 0 => RollCallService::NOT_ON_SITE,
                default => RollCallService::SAFE,
            };

            if ($answer === null) {
                continue;
            }

            $rollCall->record(
                $recipient,
                $answer,
                $answer === RollCallService::NEEDS_HELP ? 'Stuck on the third floor, the lift is out.' : null,
                'sms',
            );

            // Spread over eleven minutes rather than all at once. A real
            // evacuation trickles in — and the headcount time, which is the
            // number an evacuation drill is actually scored on, is meaningless
            // if every response lands in the same second.
            $recipient->forceFill([
                'acknowledged_at' => $alert->dispatched_at?->copy()->addSeconds(30 + ($index * 97) % 630),
            ])->save();
        }

        $alert->forceFill(['status' => 'dispatched'])->save();
    }
}
