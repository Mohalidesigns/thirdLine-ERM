<?php

namespace Tests\Feature;

use App\Services\FileUploadService;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Directories the application creates must be listable by the deploy user's
 * group and closed to everyone else.
 *
 * Without an explicit `permissions` map Flysystem creates every directory on
 * the `local` disk 0700. On 2026-10-05 that was storage/app/private/rcsa,
 * created by php-fpm as www-data the day after RCSA v2 was enabled; `deploy`
 * could not list it and the production deploy aborted. See
 * DeployScriptRuntimePermissionsTest for the other half of the fix.
 *
 * The equal and opposite regression is a map that "fixes" this with 0775 for
 * private directories, which would expose every module's evidence to every
 * account on the host. Both directions are pinned here.
 */
class StorageDiskPermissionsTest extends TestCase
{
    private const EXPECTED = [
        'file' => ['public' => 0664, 'private' => 0660],
        'dir' => ['public' => 0775, 'private' => 0770],
    ];

    /**
     * Every disk the application writes evidence or workbooks to: the upload
     * service (loss events, control tests, TPRM and BCMS evidence), the RCSA
     * and TPRM import controllers and issue attachments (all `'local'`
     * literally), report output (the default disk) and the BCMS offline
     * bundle disk.
     *
     * @return array<int, string>
     */
    private function disksInUse(): array
    {
        return array_values(array_unique([
            FileUploadService::DISK,
            'local',
            'public',
            (string) config('filesystems.default'),
            (string) config('bcms.offline_disk'),
        ]));
    }

    #[Test]
    public function every_local_driver_disk_in_use_carries_the_group_permission_map(): void
    {
        foreach ($this->disksInUse() as $disk) {
            $config = config("filesystems.disks.{$disk}");
            $this->assertIsArray($config, "Disk [{$disk}] is used by the application but not configured.");

            if (($config['driver'] ?? null) !== 'local') {
                continue;
            }

            $this->assertSame(
                self::EXPECTED,
                $config['permissions'] ?? null,
                "Disk [{$disk}] has no explicit permissions map, or a different one. Flysystem's default makes ".
                'every app-created directory 0700, which the deploy user cannot list.'
            );
        }
    }

    #[Test]
    public function private_directories_are_0770_and_private_files_0660(): void
    {
        $this->assertSame(0770, config('filesystems.disks.local.permissions.dir.private'));
        $this->assertSame(0660, config('filesystems.disks.local.permissions.file.private'));

        foreach (['dir', 'file'] as $kind) {
            $this->assertSame(
                0,
                config("filesystems.disks.local.permissions.{$kind}.private") & 0007,
                "The local disk's private {$kind} mode grants 'other' access. That disk holds every module's evidence."
            );
        }
    }

    #[Test]
    public function a_directory_the_app_creates_on_the_local_disk_is_group_listable_and_closed_to_others(): void
    {
        $root = sys_get_temp_dir().'/disk-perms-'.bin2hex(random_bytes(6));

        try {
            // The real disk configuration, pointed at a scratch root — the same
            // write path RcsaImportController's `store('rcsa/imports', 'local')`
            // takes.
            $disk = Storage::build(array_merge(config('filesystems.disks.local'), ['root' => $root]));
            $this->assertTrue($disk->put('rcsa/imports/workbook.xlsx', 'x'));

            clearstatcache();
            $mode = fileperms("{$root}/rcsa") & 0777;

            $this->assertSame(0, $mode & 0007, sprintf('An app-created directory is %o: open to "other".', $mode));

            // mkdir() is masked by the umask; only assert the group bits the
            // umask of this process leaves standing.
            $expectedGroup = 0070 & ~umask();
            $this->assertSame(
                $expectedGroup,
                $mode & 0070,
                sprintf('An app-created directory is %o; the deploy user\'s group cannot list it (the 2026-10-05 abort).', $mode)
            );
        } finally {
            File::deleteDirectory($root);
        }
    }
}
