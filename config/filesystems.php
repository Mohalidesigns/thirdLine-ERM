<?php

/*
| Modes for everything the application creates on a local-driver disk.
|
| Flysystem's own defaults are 0700 for a directory and 0600 for a file at
| "private" visibility, which is what every directory on the `local` disk gets
| (Laravel defaults its directory visibility to private). On the production host
| php-fpm and the queue worker run as www-data while `scripts/deploy.sh` runs as
| `deploy`, sharing the www-data group through setgid directories. A 0700
| directory shuts the deploy user out entirely: on 2026-10-05, the day after
| RCSA v2 was enabled, php-fpm created storage/app/private/rcsa as 0700, the
| deploy's permissions step could not list it, and the deploy aborted.
|
| So private is owner+group and nothing for "other" — 0770 / 0660 — and public
| is the same plus world read. Two things worth knowing:
|
|  - Directory modes pass through mkdir() and are therefore masked by the
|    process umask: under systemd's default UMask=0022 a private directory is
|    created 0750. Still group-listable (what the deploy needs) and still
|    closed to "other" (what the evidence needs).
|  - A FILE mode is only applied when a write carries a visibility. The `local`
|    disk sets none, so its files are created at 0666 & ~umask (0644). They are
|    not reachable by "other" all the same, because no directory above them is:
|    the disk root is 2770 (scripts/deploy.sh) and every directory the app
|    creates under it is 0770 & ~umask. A disk-level 'visibility' => 'private'
|    would make every file 0660 too, but it costs a chmod after every write and
|    that chmod FAILS when the file being overwritten belongs to another user —
|    which, with 'throw' => false, turns a successful write into a silent
|    `false`. Not worth it for a file no one else can reach.
|
| tests/Feature/StorageDiskPermissionsTest pins these values.
*/
$localPermissions = [
    'file' => ['public' => 0664, 'private' => 0660],
    'dir' => ['public' => 0775, 'private' => 0770],
];

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            // Serving is off. This disk holds loss-event attachments, issue
            // attachments and control-test evidence. With 'serve' => true the
            // framework registers GET and PUT /storage/{path} outside the web
            // middleware group — they demand a signed URL, but they carry no
            // authentication, no permission and no tenant check, and nothing in
            // this application ever mints such a URL. Every download already
            // goes through an authorized controller action, so the routes are
            // pure attack surface.
            'serve' => false,
            'permissions' => $localPermissions,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'permissions' => $localPermissions,
            'throw' => false,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
