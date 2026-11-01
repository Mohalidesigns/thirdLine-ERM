<?php

namespace App\Http\Requests\Kri;

/**
 * Save a draft of the champion's return (ADR 0026 section 6.4). NEVER a 422 for
 * a missing explanation or a missing value: a draft may be incomplete. The
 * explanation is stored only for lines the server finds red.
 */
class SaveKriReturnDraftRequest extends ReturnLinesRequest
{
    protected function ability(): string
    {
        return 'complete';
    }

    protected function enforcesSubmission(): bool
    {
        return false;
    }
}
