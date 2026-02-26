<?php

namespace App\Events;

use App\Models\Issue;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class IssueOverdue
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Issue $issue,
        public int $daysOverdue
    ) {}
}
