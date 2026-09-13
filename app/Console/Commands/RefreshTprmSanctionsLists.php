<?php

namespace App\Console\Commands;

use App\Models\Tprm\SanctionsList;
use App\Services\Tprm\Screening\SanctionsListRefresher;
use Illuminate\Console\Command;

/**
 * Refresh the locally held sanctions lists.
 *
 * A FAILED REFRESH LEAVES THE PREVIOUS LIST STANDING. A publisher changing
 * their file format must not empty a bank's sanctions list, and an empty list
 * is not a list with nothing on it — `LocalListDriver` refuses to report a
 * clear result against one, so the failure surfaces at the point of screening
 * rather than as a silently clean search.
 */
class RefreshTprmSanctionsLists extends Command
{
    protected $signature = 'tprm:refresh-sanctions-lists {--list= : Refresh only this list code}';

    protected $description = 'Refresh the built-in sanctions lists from their published sources';

    public function handle(SanctionsListRefresher $refresher): int
    {
        if (! config('features.tprm')) {
            $this->comment('TPRM is disabled; nothing to do.');

            return self::SUCCESS;
        }

        $lists = SanctionsList::query()
            ->active()
            ->when($this->option('list'), fn ($query) => $query->where('code', $this->option('list')))
            ->get();

        if ($lists->isEmpty()) {
            $this->warn('No active sanctions lists are installed.');

            return self::SUCCESS;
        }

        $failures = 0;

        foreach ($lists as $list) {
            $result = $refresher->refresh($list);

            if ($result['refreshed']) {
                $this->info(sprintf('%s: %d entries.', $list->name, $result['entries']));

                continue;
            }

            $failures++;

            // A warning rather than an error exit: one publisher being
            // unreachable must not fail a scheduled run that refreshed the
            // others successfully.
            $this->warn(sprintf('%s: %s', $list->name, $result['reason']));
        }

        if ($failures > 0) {
            $this->warn(sprintf(
                '%d list(s) could not be refreshed. Screening against them will report a failure rather than a '
                .'clear result, which is the intended behaviour — a clear result against a stale or empty list '
                .'is not a clear result.',
                $failures,
            ));
        }

        return self::SUCCESS;
    }
}
