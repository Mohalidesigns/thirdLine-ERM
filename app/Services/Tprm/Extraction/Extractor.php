<?php

namespace App\Services\Tprm\Extraction;

use App\Enums\Tprm\DocumentExtractor;

/**
 * One document type's extraction contract.
 *
 * An extractor declares what it expects back and how to clean it. It does NOT
 * call a model, verify citations, or persist anything — the dispatcher does
 * all three for every extractor identically, which is what stops one
 * extractor quietly skipping the citation check.
 */
interface Extractor
{
    public function extractor(): DocumentExtractor;

    /**
     * The expected shape, as `field => spec`.
     *
     * A spec is `['type' => 'string|date|number|bool|list|object', 'in' => [...], 'required' => bool]`.
     * `required` means the KEY must be present, not that it must be non-null:
     * an absent key is a model that ignored the instruction, while a null is a
     * model correctly reporting that the document does not say.
     *
     * @return array<string, array<string, mixed>>
     */
    public function schema(): array;

    /**
     * Clean the validated payload into the shape the confirmation screen and
     * the cascade read.
     *
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    public function normalise(array $raw): array;
}
