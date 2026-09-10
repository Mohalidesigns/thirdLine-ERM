<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * WP-05 TASK 4 — config:diff
 *
 * A thin alias over `config:import` without --apply, because the work package
 * names both and an operator should not have to know that a diff is a dry-run
 * import. Sharing the implementation is the point: a separate diff command is
 * a second thing that can disagree with what the import will actually do.
 */
class ConfigDiff extends Command
{
    protected $signature = 'config:diff
        {source : A bundle id, or a path to a JSON file produced by config:export}
        {--organization= : Target organization id. Required unless exactly one exists.}';

    protected $description = 'Show what importing a configuration bundle would change. Writes nothing';

    public function handle(): int
    {
        return $this->call('config:import', array_filter([
            'source' => $this->argument('source'),
            '--organization' => $this->option('organization'),
        ], fn ($value) => $value !== null));
    }
}
