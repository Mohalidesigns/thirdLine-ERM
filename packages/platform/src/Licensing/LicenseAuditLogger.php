<?php

namespace ThirdLine\Platform\Licensing;

use Illuminate\Database\Eloquent\Collection;
use ThirdLine\Platform\Licensing\Models\LicenseAuditLog;

class LicenseAuditLogger
{
    public function log(string $action, array $metadata = []): void
    {
        try {
            LicenseAuditLog::create([
                'action' => $action,
                'metadata' => array_merge($metadata, [
                    'hostname' => gethostname(),
                    'ip' => request()->ip() ?? 'cli',
                    'timestamp' => now()->toIso8601String(),
                ]),
                'synced' => false,
            ]);
        } catch (\Exception $e) {
            // Silently fail — logging should never break the app
            logger()->error('License audit log failed: '.$e->getMessage());
        }
    }

    public function getRecent(int $limit = 50): Collection
    {
        return LicenseAuditLog::latest()->limit($limit)->get();
    }

    public function getUnsynced(int $limit = 100): Collection
    {
        return LicenseAuditLog::where('synced', false)->oldest()->limit($limit)->get();
    }
}
