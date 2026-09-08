<?php

namespace App\Services\Bcms\Notification\Channels;

use App\Enums\Bcms\ChannelKey;

/**
 * The Phase 0 mock for Email. Replaced by the real adapter in Phase 7, which
 * owns every channel adapter in the module (Orchestration §5) — Track B never
 * writes one.
 *
 * A named class rather than a configured instance of {@see MockChannel} so that
 * the container binds one class per channel, and Phase 7 can swap them one at
 * a time as each provider's paperwork clears rather than all at once.
 */
final class MockEmailChannel extends MockChannel
{
    public function __construct(int $failEvery = 0)
    {
        parent::__construct(ChannelKey::Email, $failEvery);
    }
}
