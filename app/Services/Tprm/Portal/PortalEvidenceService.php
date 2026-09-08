<?php

namespace App\Services\Tprm\Portal;

use App\Models\Tprm\Document;
use App\Models\Tprm\DocumentType;
use App\Models\Tprm\PortalUser;
use App\Services\Tprm\Evidence\EvidenceService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use InvalidArgumentException;
use ThirdLine\Platform\Tenancy\OrganizationScope;

/**
 * Evidence the vendor maintains itself — FR-PRT-05.
 *
 * THE POINT IS WHOSE TASK CERTIFICATE FRESHNESS IS. Today it is the bank's:
 * somebody there notices an ISO certificate expired, emails the vendor, waits,
 * chases, and eventually gets a PDF. Moving the upload and the expiry date to
 * the vendor moves the work to the only party who knows when the new
 * certificate arrives — and the reminder goes to them, not to us.
 *
 * IT REUSES `EvidenceService` RATHER THAN WRITING ITS OWN UPLOAD PATH. That
 * service already detects the MIME from the bytes rather than trusting the
 * client's header, hashes the stored copy, and routes through the upload
 * profile's extension allowlist. A second path would be a second place for
 * that to be got wrong, on the surface where it matters most: this is the one
 * upload endpoint reachable from outside the bank's network.
 *
 * `uploaded_via` IS RECORDED AS `portal`, which is not bookkeeping. A reviewer
 * looking at a document needs to know whether it came from the vendor or from
 * a colleague who obtained it some other way, and the virus-scan posture
 * differs — see `EvidenceService`, which never marks a scan green it did not
 * run.
 */
class PortalEvidenceService
{
    public function __construct(private readonly EvidenceService $evidence) {}

    /**
     * Everything this vendor has uploaded or been given, newest first.
     *
     * @return Collection<int, Document>
     */
    public function documentsFor(PortalUser $user): Collection
    {
        return Document::query()
            ->where('owner_type', Document::OWNER_THIRD_PARTY)
            ->where('owner_id', $user->third_party_id)
            ->where('is_superseded', false)
            ->with('documentType')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * Upload, with an expiry date the vendor sets.
     *
     * THE EXPIRY DATE IS REQUIRED FOR A TYPE THAT EXPIRES and refused for one
     * that does not. A SOC 2 report has a period end; an insurance certificate
     * has an expiry; a policy document has neither and asking for one produces
     * a date somebody invented, which then drives a reminder nobody should
     * receive.
     *
     * @param  array<string, mixed>  $attributes
     *
     * @throws InvalidArgumentException
     */
    public function upload(
        PortalUser $user,
        UploadedFile $file,
        ?DocumentType $type,
        array $attributes,
    ): Document {
        if ($type !== null && $type->has_expiry && empty($attributes['valid_to'])) {
            throw new InvalidArgumentException(sprintf(
                'A %s needs the date it expires, so we can remind you before it does.',
                mb_strtolower($type->name),
            ));
        }

        return $this->evidence->store(
            $file,
            Document::OWNER_THIRD_PARTY,
            (int) $user->third_party_id,
            $type,
            $attributes,
            null,
            'portal',
        );
    }

    /**
     * Replace an expiring document with its successor.
     *
     * SUPERSEDES RATHER THAN OVERWRITES. The old certificate is the evidence
     * that the vendor WAS certified during the period a past assessment relied
     * on; deleting it to make room for the new one rewrites the answer to a
     * question somebody already asked.
     *
     * @param  array<string, mixed>  $attributes
     *
     * @throws InvalidArgumentException
     */
    public function replace(
        PortalUser $user,
        Document $document,
        UploadedFile $file,
        array $attributes,
    ): Document {
        $this->assertOwned($user, $document);

        return $this->evidence->replace(
            $document,
            $file,
            $attributes,
            null,
            'portal',
        );
    }

    /**
     * The document types a vendor may upload against itself.
     *
     * DROPS THE TENANCY SCOPE because the shipped catalogue carries
     * `organization_id = null` — the trap this module has hit on
     * `QuestionnaireTemplate`, `DocumentType` and `ClauseLibraryEntry` already.
     * Without it the vendor is offered an empty list and concludes the portal
     * is broken.
     *
     * @return Collection<int, DocumentType>
     */
    public function uploadableTypes(int $organizationId): Collection
    {
        return DocumentType::query()
            ->withoutGlobalScope(OrganizationScope::class)
            ->where(fn ($q) => $q->whereNull('organization_id')->orWhere('organization_id', $organizationId))
            ->where('is_active', true)
            ->orderBy('name')
            ->get();
    }

    /**
     * @throws InvalidArgumentException
     */
    private function assertOwned(PortalUser $user, Document $document): void
    {
        $isOwn = $document->owner_type === Document::OWNER_THIRD_PARTY
            && (int) $document->owner_id === (int) $user->third_party_id;

        if (! $isOwn) {
            throw new InvalidArgumentException('That document does not belong to your organisation.');
        }
    }
}
