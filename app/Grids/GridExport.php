<?php

namespace App\Grids;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;

/**
 * XLSX shape for any grid: the DataGrid component has already flattened the
 * current view (visible columns, filters, selection) into plain rows.
 */
class GridExport implements FromArray, WithHeadings
{
    public function __construct(
        protected array $headings,
        protected array $rows,
    ) {}

    public function headings(): array
    {
        return $this->headings;
    }

    public function array(): array
    {
        return $this->rows;
    }
}
