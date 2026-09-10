<?php

namespace App\Services\Tprm\Reporting\Operational;

/**
 * One standard operational report — FR-RPT-07.
 *
 * The eight reports the requirement names are eight different queries with one
 * shape: a title, a permission, a set of headings and a set of rows. Declaring
 * that shape once means the hub, the exporter and the scheduler each handle
 * "a report" rather than handling eight things individually, and a ninth
 * report is a class rather than an edit in four places.
 *
 * `permission()` IS THE PART THAT MATTERS MOST. This module has fine-grained
 * permissions — screening decisions, access grants and contract terms are each
 * gated separately — and a reports hub that showed everything to anybody
 * holding `tprm.report.view` would be a permission bypass wearing a
 * spreadsheet. Each report names the permission its DATA needs, and the
 * registry filters on it before the report is ever built.
 */
interface OperationalReport
{
    /** Stable key used in URLs, schedules and exports. */
    public function key(): string;

    public function title(): string;

    /** What the report answers, in a sentence a reader can act on. */
    public function description(): string;

    /**
     * The permission this report's DATA requires, on top of
     * `tprm.report.view`.
     */
    public function permission(): string;

    /** @return list<string> */
    public function headers(): array;

    /** @return list<array<int, mixed>> */
    public function rows(): array;

    /**
     * Scope, cut-offs and anything the reader needs in order to read the rows
     * correctly. Rendered into the provenance block of every export.
     *
     * @return array<string, string>
     */
    public function notes(): array;
}
