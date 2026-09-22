<?php

namespace App\Contracts\Bcms;

/**
 * An optional, additional surface a `DirectoryClient` implementation may offer
 * so the sync can record paging facts on the run row (`pages_fetched`,
 * criterion 1) without widening the frozen four-method `DirectoryClient`
 * contract itself. Not every consumer needs to check for this — a client that
 * does not implement it simply reports zero pages, which is honest for a
 * source that does not page.
 */
interface ReportsDirectoryFetchStats
{
    /** @return array{pages: int, objects: int} stats for the most recent users()/delta() call. */
    public function lastFetchStats(): array;
}
