<?php

namespace App\Http\Requests\Reports;

use App\Services\BoardPackAssembler;
use Illuminate\Foundation\Http\FormRequest;

/**
 * PUT risk/reports/board-pack/sections — which sections a board pack carries,
 * and in what order.
 *
 * The `in:` rule is derived from BoardPackAssembler::SECTIONS rather than
 * written out, so a section added to the assembler is accepted here without a
 * second edit, and an unknown key is refused rather than silently dropped from
 * the pack.
 */
class UpdateBoardPackSectionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('report.generate') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'sections' => ['required', 'array', 'min:1'],
            'sections.*' => ['string', 'in:'.implode(',', array_keys(BoardPackAssembler::SECTIONS))],
        ];
    }
}
