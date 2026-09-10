<?php

namespace App\Services\Tprm\Reporting;

use App\Models\Tprm\BoardPack;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * Who owns a board pack's numbers — FR-RPT-05.
 *
 * `BoardPackBuilder` computes and returns; this class is the only thing that
 * writes. The split is the lesson from Phase 7's `ConcentrationAnalyzer`,
 * which used to persist a run inside `analyse()` and therefore left a row in
 * the history every time somebody opened a screen.
 *
 * A SIGNED-OFF PACK IS FROZEN, AND REFRESHING ONE IS REFUSED RATHER THAN
 * ALLOWED WITH A WARNING. The committee approved a set of numbers under a
 * period label; quietly recomputing them under the same label is how minutes
 * stop matching the pack they cite. A later position is a new pack with a new
 * label, which is also how the trend across periods stays honest.
 *
 * SIGN-OFF REQUIRES A NARRATIVE. A pack tabled with no assessment attached is
 * a spreadsheet, and the requirement is explicit that the Chief Risk Officer
 * edits the draft before signing. The guard is here rather than in the
 * controller because a scheduled job could otherwise sign one.
 */
class BoardPackService
{
    public function __construct(
        private readonly BoardPackBuilder $builder,
        private readonly BoardNarrativeWriter $narrative,
    ) {}

    /**
     * Create a pack for a period, or refresh the draft that already exists.
     */
    public function prepare(string $periodLabel, CarbonImmutable $asAt, int $userId): BoardPack
    {
        return DB::transaction(function () use ($periodLabel, $asAt, $userId) {
            $pack = BoardPack::query()
                ->where('period_label', $periodLabel)
                ->lockForUpdate()
                ->first();

            if ($pack !== null && ! $pack->isEditable()) {
                throw new RuntimeException(
                    "The {$periodLabel} pack has been signed off and its figures are frozen. Prepare a new "
                    .'period rather than recomputing an approved one — the committee\'s minutes cite these '
                    .'numbers.'
                );
            }

            $pack ??= BoardPack::create([
                'organization_id' => TenantContext::organizationId(),
                'period_label' => $periodLabel,
                'as_at' => $asAt->toDateString(),
                'created_by' => $userId,
            ]);

            $figures = $this->builder->figures($asAt);
            $draft = $this->narrative->draft($figures);

            $pack->forceFill([
                'as_at' => $asAt->toDateString(),
                'figures' => $figures,
                'engine_version' => $figures['engine_version'],
                'prepared_by' => $userId,
                'prepared_at' => now(),
                'status' => BoardPack::STATUS_DRAFT,
                'updated_by' => $userId,
            ]);

            // An edited narrative survives a refresh of the figures. Somebody
            // spent an hour on that paragraph; regenerating it because a
            // number moved would teach them not to write it until the end.
            if ($pack->narrative_source !== BoardPack::SOURCE_EDITED) {
                $pack->forceFill([
                    'narrative' => $draft['text'],
                    'narrative_source' => $draft['source'],
                    'narrative_generated_at' => now(),
                ]);
            }

            $pack->save();

            return $pack->refresh();
        });
    }

    /**
     * Replace the narrative with a person's own words.
     */
    public function editNarrative(BoardPack $pack, string $text, int $userId): BoardPack
    {
        $this->refuseIfSignedOff($pack, 'Its narrative cannot be rewritten.');

        $pack->forceFill([
            'narrative' => trim($text),
            'narrative_source' => BoardPack::SOURCE_EDITED,
            'narrative_edited_by' => $userId,
            'narrative_edited_at' => now(),
            'updated_by' => $userId,
        ])->save();

        return $pack->refresh();
    }

    /**
     * Move a prepared pack into review.
     */
    public function submitForReview(BoardPack $pack, int $userId): BoardPack
    {
        $this->refuseIfSignedOff($pack, 'It is already approved.');

        if ($pack->figures === null) {
            throw new RuntimeException('Prepare the pack before submitting it: it carries no figures.');
        }

        $pack->forceFill(['status' => BoardPack::STATUS_IN_REVIEW, 'updated_by' => $userId])->save();

        return $pack->refresh();
    }

    /**
     * Sign off, which freezes the figures.
     */
    public function signOff(BoardPack $pack, int $userId): BoardPack
    {
        $this->refuseIfSignedOff($pack, 'It has already been signed off.');

        if ($pack->figures === null) {
            throw new RuntimeException('A pack with no figures cannot be signed off.');
        }

        if (blank($pack->narrative)) {
            throw new RuntimeException(
                'Sign-off requires a narrative. A pack tabled with no assessment attached is a spreadsheet, '
                .'and FR-RPT-05 asks the Chief Risk Officer to edit the draft before approving it.'
            );
        }

        $pack->forceFill([
            'status' => BoardPack::STATUS_SIGNED_OFF,
            'signed_off_by' => $userId,
            'signed_off_at' => now(),
            'updated_by' => $userId,
        ])->save();

        return $pack->refresh();
    }

    /**
     * The trend a committee compares quarters on.
     *
     * ONLY SIGNED-OFF PACKS ARE PLOTTED. A trend line that moved because
     * somebody re-ran a draft this morning is a trend line nobody can discuss,
     * and a draft is by definition a position nobody has stood behind.
     *
     * @return list<array<string, mixed>>
     */
    public function trend(int $limit = 8): array
    {
        return BoardPack::query()
            ->signedOff()
            ->orderByDesc('as_at')
            ->limit($limit)
            ->get()
            ->reverse()
            ->map(fn (BoardPack $pack) => [
                'period_label' => $pack->period_label,
                'as_at' => $pack->as_at->toDateString(),
                'engagements' => $pack->figures['portfolio']['total'] ?? null,
                'mean_residual' => $pack->figures['portfolio']['mean_residual'] ?? null,
                'open_findings' => $pack->figures['findings']['open'] ?? null,
                'hhi' => $pack->figures['concentration']['hhi'] ?? null,
                'exit_plan_gaps' => $pack->figures['exit_readiness']['no_plan'] ?? null,
                'incidents' => $pack->figures['incidents']['count'] ?? null,
            ])
            ->values()
            ->all();
    }

    private function refuseIfSignedOff(BoardPack $pack, string $because): void
    {
        if ($pack->isSignedOff()) {
            throw new RuntimeException("The {$pack->period_label} pack is signed off. {$because}");
        }
    }
}
