<?php

namespace App\Services\Tprm\Evidence;

use App\Models\Tprm\AuditLog;
use App\Models\Tprm\Document;
use App\Models\Tprm\DocumentType;
use App\Services\FileUploadService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

/**
 * The evidence library — FR-EVD-01 through FR-EVD-03.
 *
 * BYTES GO THROUGH `FileUploadService` AND NOWHERE ELSE. That service is the
 * product's single hardened upload path: private disk with `serve => false`,
 * content-derived file type rather than the client's Content-Type header, a
 * UUID filename so a caller cannot choose where its bytes land, and a
 * traversal-proof download. Writing a second upload path here would recreate
 * precisely the drift that put audit evidence on a public bucket once already.
 * This class adds the EVIDENCE semantics on top: the hash, the version chain,
 * the validity window and the access log.
 *
 * DOWNLOADS ARE SIGNED AND SHORT-LIVED. A URL that grants access to a vendor's
 * SOC 2 for as long as it exists is a URL that ends up in a chat message, and
 * the report names control weaknesses in a live production system. Five
 * minutes is long enough to click and too short to forward usefully.
 *
 * EVERY ACCESS IS LOGGED, and to the append-only hash-chained audit table
 * rather than a plain log line, because "who has read this vendor's
 * penetration test" is a question an examiner asks and an answer that can be
 * edited afterwards is not an answer.
 */
class EvidenceService
{
    /** FR-EVD-03: downloads only through short-lived signed URLs. */
    public const SIGNED_URL_MINUTES = 5;

    public function __construct(private readonly FileUploadService $uploads) {}

    /**
     * Store an uploaded document against its owner.
     *
     * @param  array<string, mixed>  $attributes  title, issuer, scope_text, dates, confidentiality
     */
    public function store(
        UploadedFile $file,
        string $ownerType,
        int $ownerId,
        ?DocumentType $type,
        array $attributes,
        ?int $userId = null,
        string $via = 'internal',
    ): Document {
        // Read from the bytes BEFORE the upload service moves them: once the
        // temporary file has been relocated, Symfony can no longer run the
        // platform MIME guesser over it. Detected, never `getClientMimeType()`
        // — that is a header the client writes.
        $mime = $file->getMimeType();

        $stored = $this->uploads->store(
            $file,
            'tprm/evidence/'.$ownerType,
            FileUploadService::PROFILE_TPRM_EVIDENCE,
        );

        // Hashed from the bytes AS STORED, not from the temporary upload: the
        // hash has to answer "is the file behind this record still the file
        // that was uploaded", and that question is about the stored copy.
        $hash = hash_file('sha256', Storage::disk(FileUploadService::DISK)->path($stored['storage_path'])) ?: null;

        return DB::transaction(function () use ($stored, $hash, $mime, $ownerType, $ownerId, $type, $attributes, $userId, $via) {
            $document = Document::create(array_filter([
                'owner_type' => $ownerType,
                'owner_id' => $ownerId,
                'document_type_id' => $type?->getKey(),
                'title' => $attributes['title'] ?? $stored['file_name'],
                'file_path' => $stored['storage_path'],
                'mime' => $mime,
                'size' => $stored['file_size_bytes'],
                'hash' => $hash,
                'issuer' => $attributes['issuer'] ?? null,
                'scope_text' => $attributes['scope_text'] ?? null,
                'issue_date' => $attributes['issue_date'] ?? null,
                'valid_from' => $attributes['valid_from'] ?? null,
                'valid_to' => $attributes['valid_to'] ?? $this->defaultValidTo($type, $attributes),
                'confidentiality' => $attributes['confidentiality'] ?? null,
                'uploaded_by' => $userId,
                'uploaded_via' => $via,
            ], fn ($value) => $value !== null));

            // Not fillable — a form post must never declare its own upload
            // virus-free, so the scan verdict is written here where the code
            // owns it.
            $document->forceFill([
                'virus_scan_status' => $this->scan($document),
                'extraction_status' => $this->extractionStatusFor($type),
            ])->save();

            return $document->refresh();
        });
    }

    /**
     * Replace a document with a newer version of the same evidence.
     *
     * The old row survives, superseded. A citation written last year points at
     * a specific document id, and a citation that resolves to a document
     * nobody can retrieve is not a citation.
     */
    public function replace(
        Document $existing,
        UploadedFile $file,
        array $attributes,
        ?int $userId = null,
    ): Document {
        $replacement = $this->store(
            $file,
            $existing->owner_type,
            $existing->owner_id,
            $existing->documentType,
            $attributes + ['title' => $existing->title],
            $userId,
        );

        $existing->supersede($replacement);

        return $replacement->refresh();
    }

    /**
     * A signed, five-minute download URL.
     *
     * The URL is signed rather than merely obscure. A UUID in a path is a
     * bearer token with no expiry and no revocation; a signature carries both.
     */
    public function downloadUrl(Document $document): string
    {
        return URL::temporarySignedRoute(
            'tprm.documents.download',
            now()->addMinutes(self::SIGNED_URL_MINUTES),
            ['document' => $document->uuid],
        );
    }

    /**
     * Record that somebody read a document — FR-EVD-03.
     *
     * Written to `tp_audit_logs`, which is append-only and hash-chained. The
     * document's own row is untouched: an access is not a change to the
     * evidence, and updating a timestamp on it would put a read into the
     * document's change history where a reader would take it for an edit.
     */
    public function logAccess(Document $document, ?int $userId, string $action = 'downloaded'): void
    {
        AuditLog::create([
            'organization_id' => $document->organization_id,
            'auditable_type' => Document::class,
            'auditable_id' => $document->getKey(),
            'event' => 'document_'.$action,
            'actor_type' => $userId !== null ? 'user' : 'system',
            'actor_id' => $userId,
            'before' => null,
            'after' => [
                'title' => $document->title,
                'owner_type' => $document->owner_type,
                'owner_id' => $document->owner_id,
                'hash' => $document->hash,
            ],
            'ip' => request()?->ip(),
            'user_agent' => substr((string) request()?->userAgent(), 0, 500) ?: null,
        ]);
    }

    /**
     * Whether the stored bytes still hash to what was recorded.
     *
     * A document that fails this is not merely corrupt: the audit chain says
     * a specific file evidenced a specific control, and the file behind it has
     * changed since.
     */
    public function verifyIntegrity(Document $document): bool
    {
        if ($document->hash === null) {
            return false;
        }

        $disk = Storage::disk(FileUploadService::DISK);

        if (! $disk->exists($document->file_path)) {
            return false;
        }

        return hash_equals($document->hash, (string) hash_file('sha256', $disk->path($document->file_path)));
    }

    /**
     * Other documents in this tenant with identical bytes.
     *
     * Two engagements evidenced by the same SOC 2 is normal and useful to
     * know; the same certificate uploaded against two DIFFERENT vendors is a
     * data-quality problem worth surfacing before somebody relies on it.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, Document>
     */
    public function duplicatesOf(Document $document)
    {
        return Document::query()
            ->where('hash', $document->hash)
            ->whereKeyNot($document->getKey())
            ->whereNotNull('hash')
            ->get();
    }

    /**
     * The expiry date a document of this type gets when none was stated.
     *
     * Derived from the type's validity period and the issue date, and null
     * whenever either is missing. A GUESSED expiry is worse than none: the
     * whole module treats `valid_to` as a fact, and an invented one would age
     * out real evidence or keep dead evidence alive.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function defaultValidTo(?DocumentType $type, array $attributes): ?string
    {
        if ($type === null || ! $type->has_expiry || $type->default_validity_months === null) {
            return null;
        }

        $from = $attributes['valid_from'] ?? $attributes['issue_date'] ?? null;

        if ($from === null) {
            return null;
        }

        return Carbon::parse($from)->addMonths((int) $type->default_validity_months)->toDateString();
    }

    /**
     * The virus scan verdict.
     *
     * There is no scanner wired in this phase, and this method says so rather
     * than returning `clean` — a column reading "clean" on every document in
     * a product that has never scanned one is a lie an auditor would catch and
     * a reviewer would rely on. `pending` is the honest state, the UI renders
     * it as unscanned, and wiring a scanner means changing this one method.
     */
    private function scan(Document $document): string
    {
        return 'pending';
    }

    /**
     * Whether this document is a candidate for extraction.
     *
     * `none` for a type with no extractor, so the documents grid can tell
     * "nothing will ever be extracted from this" apart from "extraction has
     * not run yet", which are different things to a user waiting for a panel
     * to populate.
     */
    private function extractionStatusFor(?DocumentType $type): string
    {
        if ($type === null || $type->extractor === null) {
            return 'none';
        }

        return $type->extractor->requiresAi() ? 'pending' : 'none';
    }
}
