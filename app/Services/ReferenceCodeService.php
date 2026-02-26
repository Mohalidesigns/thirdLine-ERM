<?php
namespace App\Services;

use Illuminate\Support\Facades\DB;

class ReferenceCodeService
{
    /**
     * Generate the next reference code for a given prefix and table
     * Format: PREFIX-YEAR-NNNN (e.g., RK-2026-0001)
     */
    public static function generate(string $table, string $column, string $prefix, int $digits = 4): string
    {
        $year = now()->year;
        $pattern = "{$prefix}-{$year}-%";
        
        $latest = DB::table($table)
            ->where($column, 'like', $pattern)
            ->orderByDesc($column)
            ->value($column);
        
        if ($latest) {
            $parts = explode('-', $latest);
            $number = (int) end($parts) + 1;
        } else {
            $number = 1;
        }
        
        return sprintf("%s-%d-%0{$digits}d", $prefix, $year, $number);
    }
}
