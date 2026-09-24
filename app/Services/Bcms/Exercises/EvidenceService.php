<?php

namespace App\Services\Bcms\Exercises;

use App\Enums\Bcms\IsoClauseRef;
use App\Models\Bcms\Aar;
use App\Models\Bcms\Evidence;
use App\Models\Bcms\ExerciseOccurrence;
use App\Models\Bcms\ExerciseScore;
use App\Models\Bcms\ReadinessTask;
use App\Models\User;
use App\Services\FileUploadService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

/**
 * Evidence capture and its lock — ADR 0019.
 *
 * UPLOAD GOES THROUGH `FileUploadService` AND NOWHERE ELSE. One profile
 * (`PROFILE_BCMS_EVIDENCE`), one disk (`FileUploadService::DISK`), one
 * validation rule set. This service adds the `bcms_evidence` row on top; it
 * does not invent a second write path.
 *
 * THE HASH IS OF THE BYTES AS STORED, not the upload's own hash — computed
 * from the file once it is on disk, so a corrupted write is caught by the
 * same value that later verifies it.
 *
 * "IMMUTABLE ON FINALISE" IS ENFORCED HERE, NOT ON THE MODEL. `delete()`
 * refuses a locked row and logs the refusal (criterion 8: "refused and
 * logged"), because a service is where a write is refused and the model has
 * no channel of its own to write an audit row about a refusal. (There is no
 * `update()` on this service — evidence rows are uploaded, downloaded and
 * deleted; nothing about a stored artefact is ever edited in place.)
 *
 * `owner_id` IS VERIFIED AGAINST THIS OCCURRENCE (Gate 2 defect 3). Neither
 * `StoreEvidenceRequest` nor the route's own visibility scoping can see
 * whether a caller-supplied `owner_id` names a score, readiness task or AAR
 * belonging to some OTHER occurrence — the route only proves the occurrence
 * itself is visible. Without this check, a facilitator running occurrence A
 * could upload evidence with `owner_type=readiness_task`/`owner_id=<a
 * blocking task on occurrence B>`: the row is created against occurrence A
 * (this method's own tenant/visibility anchor), but `ReadinessService::
 * complete()`'s "does this task have evidence" query matches on
 * `owner_type`+`owner_id` alone, with no occurrence filter — so occurrence
 * B's blocking task reads as evidenced by a file nobody uploaded against it,
 * closing a task the facilitator never touched.
 */
class EvidenceService
{
    public function __construct(private FileUploadService $uploads) {}

    public function upload(
        ExerciseOccurrence $occurrence,
        UploadedFile $file,
        string $ownerType,
        int $ownerId,
        string $kind,
        User $uploadedBy,
        ?string $caption = null,
        ?string $isoClauseRef = null,
        ?\Illuminate\Support\Carbon $capturedAt = null,
    ): Evidence {
        if (! in_array($ownerType, Evidence::ownerKinds(), true)) {
            throw new InvalidArgumentException("'{$ownerType}' is not a recognised evidence owner kind.");
        }

        $this->assertOwnerBelongsToOccurrence($occurrence, $ownerType, $ownerId);

        // DoD: "every evidence-bearing artefact carries an iso_clause_ref."
        // A caller may name one (validated against the taxonomy at the Form
        // Request boundary — never a free string reaching here); absent that,
        // derive one from the owner kind per the clause map (§1.2): a photo
        // against the occurrence itself is 8.5's exercise record, one against
        // an observer's score is the evaluation view, one against a readiness
        // task is still evidence of the exercise as run, and one against the
        // AAR inherits the report's own clause.
        $isoClauseRef ??= match ($ownerType) {
            Evidence::KIND_OCCURRENCE, Evidence::KIND_READINESS_TASK => IsoClauseRef::Iso22301_8_5_exercise->value,
            Evidence::KIND_SCORE => IsoClauseRef::Iso22398_evaluation->value,
            Evidence::KIND_AAR => IsoClauseRef::Iso22301_8_5_report->value,
            default => null,
        };

        $stored = $this->uploads->store(
            $file,
            'bcms/evidence/'.$occurrence->organization_id.'/'.$occurrence->getKey(),
            FileUploadService::PROFILE_BCMS_EVIDENCE,
        );

        $hash = hash_file('sha256', Storage::disk(FileUploadService::DISK)->path($stored['storage_path']));

        if ($hash === false) {
            throw new InvalidArgumentException('The uploaded file could not be hashed after storage.');
        }

        // Advisory 8: `hash` is deliberately not in `Evidence::$fillable` (see
        // that model's docblock), so it cannot go through `create()`'s
        // mass-assignable array — and the column is `NOT NULL` with no
        // default, so it cannot be added in a second write after the row
        // exists either. Built unsaved, `forceFill()`'d, then saved ONCE: one
        // `INSERT`, carrying the one value the model otherwise refuses to
        // accept from anywhere but here.
        $evidence = new Evidence([
            'organization_id' => $occurrence->organization_id,
            'occurrence_id' => $occurrence->getKey(),
            'owner_type' => $ownerType,
            'owner_id' => $ownerId,
            'kind' => $kind,
            'caption' => $caption,
            'file_name' => $stored['file_name'],
            'file_path' => $stored['storage_path'],
            'mime' => Storage::disk(FileUploadService::DISK)->mimeType($stored['storage_path']) ?: $stored['file_type'],
            'size' => $stored['file_size_bytes'],
            'captured_at' => $capturedAt ?? now(),
            'iso_clause_ref' => $isoClauseRef,
            'uploaded_by' => $uploadedBy->getKey(),
        ]);
        $evidence->forceFill(['hash' => $hash]);
        $evidence->save();

        return $evidence;
    }

    /**
     * Soft-delete an evidence row. Refused, and logged, once locked.
     */
    public function delete(Evidence $evidence, User $by): void
    {
        if ($evidence->isLocked()) {
            $evidence->recordAudit('evidence_delete_refused_locked', [
                'by' => $by->name,
                'file' => $evidence->file_name,
            ]);

            throw new InvalidArgumentException(
                'This evidence was locked when its after-action report was finalised. It cannot be removed.'
            );
        }

        $evidence->delete();
    }

    /**
     * Verify every stored row for this occurrence still matches its hash,
     * then lock all of them, in one transaction with the caller's own AAR
     * write — condition 12, ADR 0019 §2.
     *
     * @return list<string> the file names of any row that failed verification (empty means all clear)
     */
    public function verifyAndLock(ExerciseOccurrence $occurrence, User $by): array
    {
        $rows = Evidence::query()->where('occurrence_id', $occurrence->getKey())->whereNull('locked_at')->get();

        $failures = [];

        foreach ($rows as $row) {
            if (! $this->verifyHash($row)) {
                $failures[] = $row->file_name;
            }
        }

        if ($failures !== []) {
            return $failures;
        }

        DB::transaction(function () use ($rows, $by): void {
            foreach ($rows as $row) {
                $row->forceFill(['locked_at' => now(), 'locked_by' => $by->getKey()])->save();
            }
        });

        return [];
    }

    /** Whether the stored bytes still match the row's recorded hash. */
    public function verifyHash(Evidence $evidence): bool
    {
        if (! $this->uploads->exists($evidence->file_path)) {
            return false;
        }

        $actual = hash_file('sha256', Storage::disk(FileUploadService::DISK)->path($evidence->file_path));

        return $actual !== false && hash_equals($evidence->hash, $actual);
    }

    /**
     * Refuse an `owner_id` that names a real row belonging to a DIFFERENT
     * occurrence than the one this evidence is being uploaded against — see
     * the class docblock. `KIND_OCCURRENCE` is checked by identity, the other
     * three by an `occurrence_id` match; an owner id that names no row at all
     * is refused too, rather than silently accepted as evidence for nothing.
     */
    private function assertOwnerBelongsToOccurrence(ExerciseOccurrence $occurrence, string $ownerType, int $ownerId): void
    {
        $belongsToThisOccurrence = match ($ownerType) {
            Evidence::KIND_OCCURRENCE => $ownerId === (int) $occurrence->getKey(),
            Evidence::KIND_SCORE => ExerciseScore::query()->whereKey($ownerId)
                ->where('occurrence_id', $occurrence->getKey())->exists(),
            Evidence::KIND_READINESS_TASK => ReadinessTask::query()->whereKey($ownerId)
                ->where('occurrence_id', $occurrence->getKey())->exists(),
            Evidence::KIND_AAR => Aar::query()->whereKey($ownerId)
                ->where('occurrence_id', $occurrence->getKey())->exists(),
            default => false,
        };

        if (! $belongsToThisOccurrence) {
            throw new InvalidArgumentException(
                "This evidence's owner does not belong to this occurrence and cannot be attached here."
            );
        }
    }
}
