<?php

namespace App\Http\Requests\Kri;

/**
 * Submit the champion's return (ADR 0026 section 6.4): every due KRI has a
 * value and every red KRI is explained, or the request is a 422 on
 * `lines.{i}.value` / `lines.{i}.red_band.*`.
 */
class SubmitKriReturnRequest extends ReturnLinesRequest
{
    protected function ability(): string
    {
        return 'submit';
    }

    protected function enforcesSubmission(): bool
    {
        return true;
    }
}
