<?php

namespace App\Services\Tprm\Extraction;

use App\Models\Tprm\DocumentExtraction;

/**
 * What a dispatch attempt produced.
 *
 * `skipped` and `failed` are separated because they mean different things to
 * the person looking at the screen. Skipped is the module working as
 * configured — AI is off, the type has no extractor, the PDF is a scan — and
 * the correct response is to type the fields in. Failed means extraction ran
 * and did not work, which is worth reporting. Collapsing them into one "could
 * not extract" would train users to ignore both.
 */
class ExtractionOutcome
{
    private function __construct(
        public readonly ?DocumentExtraction $extraction,
        public readonly string $state,
        public readonly ?string $message = null,
    ) {}

    public static function extracted(DocumentExtraction $extraction): self
    {
        return new self($extraction, 'extracted');
    }

    public static function skipped(string $message): self
    {
        return new self(null, 'skipped', $message);
    }

    public static function failed(string $message): self
    {
        return new self(null, 'failed', $message);
    }

    public function succeeded(): bool
    {
        return $this->extraction !== null;
    }

    /**
     * Whether the fields need typing in.
     *
     * True for every non-success state, because that is the only question the
     * screen actually has to answer.
     */
    public function requiresManualEntry(): bool
    {
        return $this->extraction === null;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'state' => $this->state,
            'message' => $this->message,
            'extraction_id' => $this->extraction?->getKey(),
            'manual_entry_required' => $this->requiresManualEntry(),
        ];
    }
}
