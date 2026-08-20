<?php

namespace App\Services;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * WP-11 TASK 1 — the single place an uploaded file is accepted, named, stored
 * and handed back out.
 *
 * WHY THIS CLASS CHANGED SHAPE
 * ----------------------------
 * Until now this service was dead code. It had zero callers anywhere in the
 * application, which meant the "safe" upload policy everyone assumed was in
 * force — an extension allowlist, a size cap and the PRIVATE `local` disk —
 * was in force nowhere. Each upload endpoint had instead grown its own inline
 * `$request->validate()` plus its own `$file->store(...)` call, and two of them
 * (control-test evidence and bulk data import) had grown them against the
 * `public` disk. `storage/app/public` is symlinked to `public/storage`, so the
 * web server served those bytes directly at `/storage/...` with no session, no
 * permission check and no tenant check. Control-test evidence is audit evidence
 * and a bulk import is the customer's entire risk register.
 *
 * Deleting the service was the alternative. It was rejected: deleting it leaves
 * the policy duplicated across N controllers, which is exactly the state that
 * let two endpoints drift onto the public disk without anyone noticing. This
 * class is now wired into the endpoints it was written for, and the answer to
 * "which endpoint got hardened?" is "the ones that call this".
 *
 * WHAT IT GUARANTEES
 * ------------------
 *  1. Storage is ALWAYS the `local` disk (`storage/app/private`), which is not
 *     web-reachable and has `'serve' => false`. There is no parameter to
 *     override this — a caller cannot opt back into a public bucket.
 *  2. Acceptance rules live in ONE place, {@see self::rules()}. Callers feed
 *     those same rules to `$request->validate()`, and {@see self::store()}
 *     re-runs them itself. That is shared validation rather than a comment
 *     asking two files to agree.
 *  3. `file_type` is derived from what the file actually IS, never from
 *     `$file->getClientMimeType()` (a header the client writes) and never from
 *     the raw client filename. See {@see self::detectExtension()}.
 *  4. {@see self::download()} cannot be walked out of the disk root, whatever
 *     the caller passes it. Previously it took a string straight to
 *     `Storage::disk('local')->path()`, so `../../../.env` would have resolved
 *     happily the moment anyone wired it to a request parameter.
 *
 * PROFILES
 * --------
 * Endpoints legitimately accept different things: control-test evidence
 * includes screenshots, extracts and memos; a data import is a spreadsheet.
 * Rather than narrow every endpoint to one hard-coded list — which would have
 * silently stopped banks uploading the .csv and .txt evidence they upload
 * today — the policy is expressed as named profiles. Adding an endpoint means
 * adding a profile here, not inventing a rule string in a controller.
 */
class FileUploadService
{
    /**
     * The ONLY disk this service will ever write to or read from.
     *
     * Deliberately a constant and not a constructor argument: the whole point
     * of routing uploads through here is that no call site can choose a
     * web-served bucket.
     */
    public const DISK = 'local';

    public const PROFILE_DEFAULT = 'default';

    public const PROFILE_CONTROL_TEST_EVIDENCE = 'control_test_evidence';

    public const PROFILE_DATA_IMPORT = 'data_import';

    public const PROFILE_LOSS_EVENT_ATTACHMENT = 'loss_event_attachment';

    /**
     * profile => [extensions, max_kilobytes]
     *
     * The extension lists mirror what each endpoint already accepted before
     * this refactor, so no tenant loses the ability to upload a file they could
     * upload yesterday. The size caps likewise: 10 MB, as the endpoints and the
     * original version of this service all had.
     *
     * @var array<string, array{extensions: list<string>, max_kilobytes: int}>
     */
    private const PROFILES = [
        self::PROFILE_DEFAULT => [
            'extensions' => ['pdf', 'docx', 'xlsx', 'jpg', 'jpeg', 'png'],
            'max_kilobytes' => 10240,
        ],
        self::PROFILE_CONTROL_TEST_EVIDENCE => [
            'extensions' => ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'csv', 'ppt', 'pptx', 'txt', 'png', 'jpg', 'jpeg', 'gif'],
            'max_kilobytes' => 10240,
        ],
        self::PROFILE_DATA_IMPORT => [
            'extensions' => ['csv', 'xlsx', 'xls'],
            'max_kilobytes' => 10240,
        ],

        // Loss-event attachments are the evidence pack behind a reported
        // operational loss: the incident report, the bank statement, the
        // insurer's correspondence, the CBN submission receipt. So the list is
        // wider than the others and includes mail formats, because the
        // correspondence trail on a loss event genuinely arrives as .msg/.eml.
        //
        // DEFECT THIS REPLACES: this endpoint validated `required|file|max:20480`
        // and NOTHING ELSE. No `mimes:`, no `mimetypes:` — it accepted any file
        // type at all, while every other upload path in the codebase restricted
        // to a 13-type allowlist. It was the one endpoint the earlier hardening
        // pass missed.
        //
        // The 20 MB cap is retained deliberately rather than pulled down to the
        // 10 MB the other profiles use: a scanned multi-page incident file
        // legitimately runs larger than a control-test screenshot, and the
        // defect here was the absent type restriction, not the size.
        self::PROFILE_LOSS_EVENT_ATTACHMENT => [
            'extensions' => [
                'pdf', 'doc', 'docx', 'xls', 'xlsx', 'csv', 'ppt', 'pptx', 'txt',
                'png', 'jpg', 'jpeg', 'gif', 'msg', 'eml',
            ],
            'max_kilobytes' => 20480,
        ],
    ];

    /**
     * Detected MIME type => the extensions that MIME type may legitimately
     * carry, most canonical first.
     *
     * The client gets to pick among the entries for the type its bytes were
     * actually detected as, and nothing else. That is what makes a `.csv`
     * still record `csv` (both csv and txt are text/plain to libmagic) without
     * letting a client call a PNG a `.pdf`.
     *
     * @var array<string, list<string>>
     */
    private const MIME_EXTENSIONS = [
        'application/pdf' => ['pdf'],
        'application/msword' => ['doc'],
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => ['docx'],
        'application/vnd.ms-excel' => ['xls', 'csv'],
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => ['xlsx'],
        'application/vnd.ms-powerpoint' => ['ppt'],
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => ['pptx'],
        // Office Open XML files are ZIP containers. Some libmagic builds report
        // the container rather than the specific type, so the client's own
        // extension is allowed to disambiguate WITHIN this set only.
        'application/zip' => ['xlsx', 'docx', 'pptx', 'zip'],
        'text/csv' => ['csv'],
        'application/csv' => ['csv'],
        'text/plain' => ['txt', 'csv'],
        'image/png' => ['png'],
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/gif' => ['gif'],
        // Mail formats, for the loss-event correspondence trail. Outlook .msg
        // is an OLE2 compound file, which libmagic reports variously depending
        // on build; all three spellings are listed so a valid .msg is not
        // rejected on one server and accepted on another.
        'message/rfc822' => ['eml'],
        'application/vnd.ms-outlook' => ['msg'],
        'application/CDFV2' => ['msg'],
        'application/CDFV2-corrupt' => ['msg'],
        'application/x-ole-storage' => ['msg'],
    ];

    /* ------------------------------------------------------------------ */
    /*  Policy — one definition, used by both the request and the store */
    /* ------------------------------------------------------------------ */

    /**
     * The validation rules for a file on the given profile.
     *
     * Controllers pass this straight into `$request->validate()` so the user
     * gets a normal validation error, and {@see self::store()} re-runs the very
     * same array so a caller that forgets cannot slip past the policy. The
     * `mimes:` rule is content-based in Laravel — it compares against the
     * extension guessed from the detected MIME type, not the filename — which
     * is why it, and not an `in:` check on the client extension, is the gate.
     *
     * @return list<string>
     */
    public function rules(string $profile = self::PROFILE_DEFAULT, bool $required = true): array
    {
        $config = $this->profile($profile);

        return [
            $required ? 'required' : 'nullable',
            'file',
            'max:'.$config['max_kilobytes'],
            'mimes:'.implode(',', $config['extensions']),
        ];
    }

    /** @return list<string> */
    public function getAllowedTypes(string $profile = self::PROFILE_DEFAULT): array
    {
        return $this->profile($profile)['extensions'];
    }

    public function getMaxSizeMb(string $profile = self::PROFILE_DEFAULT): int
    {
        return (int) ($this->profile($profile)['max_kilobytes'] / 1024);
    }

    /* ------------------------------------------------------------------ */
    /*  Write */
    /* ------------------------------------------------------------------ */

    /**
     * Validate and store an uploaded file on the private disk.
     *
     * The returned array is deliberately shaped to be spread into the
     * attachment/evidence models: every value in it is server-derived. The
     * stored name is a UUID, so a client cannot choose where in the tree its
     * bytes land or overwrite an existing file by re-using a name, and the
     * original name is kept only as a display/download label.
     *
     * @param  string  $directory  Path RELATIVE to the private disk root.
     * @return array{file_name: string, file_size_bytes: int, file_type: string, storage_path: string, disk: string, uuid: string, uploaded_at: \Illuminate\Support\Carbon}
     *
     * @throws \Illuminate\Validation\ValidationException when the file fails the profile's policy
     * @throws \RuntimeException when the bytes could not be written
     */
    public function store(UploadedFile $file, string $directory, string $profile = self::PROFILE_DEFAULT): array
    {
        Validator::make(
            ['file' => $file],
            ['file' => $this->rules($profile)],
        )->validate();

        $directory = $this->safeRelativePath($directory);
        $extension = $this->detectExtension($file, $profile);
        $fileName = Str::uuid()->toString().'.'.$extension;

        $storagePath = $file->storeAs($directory, $fileName, self::DISK);

        if ($storagePath === false) {
            throw new RuntimeException('The uploaded file could not be written to storage.');
        }

        return [
            'file_name' => $this->sanitiseFileName($file->getClientOriginalName()),
            'file_size_bytes' => (int) $file->getSize(),
            'file_type' => $extension,
            'storage_path' => $storagePath,
            'disk' => self::DISK,
            'uuid' => Str::uuid()->toString(),
            'uploaded_at' => now(),
        ];
    }

    /**
     * The canonical extension for what this file ACTUALLY contains.
     *
     * DEFECT: the endpoints this replaces recorded either
     * `$file->getClientMimeType()` — a value taken verbatim from the
     * multipart Content-Type header, i.e. whatever the client typed — or
     * `$file->getClientOriginalExtension()`, which is just the tail of the
     * client-supplied filename. Both are attacker-chosen, and both were being
     * written into a column that later drives icon choice, filtering and, in
     * the document repository, what an auditor is told a file is.
     *
     * `$file->getMimeType()` is different: Symfony runs the platform MIME
     * guesser (libmagic/finfo) over the bytes on disk. That is the value used
     * here. The client filename is consulted only to choose between extensions
     * that the DETECTED type genuinely allows — `.csv` vs `.txt` for
     * text/plain — and only when that choice is inside the profile's allowlist.
     */
    public function detectExtension(UploadedFile $file, string $profile = self::PROFILE_DEFAULT): string
    {
        $allowed = $this->profile($profile)['extensions'];
        $detected = strtolower((string) $file->getMimeType());
        $candidates = self::MIME_EXTENSIONS[$detected] ?? [];

        if ($candidates !== []) {
            $claimed = $this->sanitiseExtension($file->getClientOriginalExtension());

            if ($claimed !== '' && in_array($claimed, $candidates, true) && in_array($claimed, $allowed, true)) {
                return $claimed;
            }

            foreach ($candidates as $candidate) {
                if (in_array($candidate, $allowed, true)) {
                    return $candidate;
                }
            }

            return $candidates[0];
        }

        // Unknown MIME type. Fall back to Symfony's own extension guess, which
        // is still derived from the detected type and never from the filename.
        // 'bin' rather than the client's extension: if we cannot tell what it
        // is, saying so is more honest than repeating the client's claim.
        $guessed = $this->sanitiseExtension((string) $file->guessExtension());

        return $guessed !== '' ? $guessed : 'bin';
    }

    /* ------------------------------------------------------------------ */
    /*  Read */
    /* ------------------------------------------------------------------ */

    /**
     * Stream a stored file back to the browser as a download.
     *
     * HARDENED. The previous signature took a raw string and handed it to
     * `Storage::disk('local')->path()`, which performs no containment check
     * whatsoever — `download('../../../.env')` would have returned the
     * absolute path of the environment file. Nothing called it, so nothing
     * exploited it, but "dead code with a traversal sink" is one wiring change
     * away from a live one.
     *
     * Callers are still responsible for AUTHORIZATION (does this user's tenant
     * own this row). This method's job is that whatever path a row holds, the
     * bytes returned come from inside the private disk or not at all.
     *
     * @throws \RuntimeException when the path escapes the disk or the file is absent
     */
    public function download(string $storagePath, ?string $downloadName = null): StreamedResponse
    {
        $path = $this->assertInsideDisk($storagePath);

        return $this->disk()->download(
            $path,
            $downloadName !== null ? $this->sanitiseFileName($downloadName) : null,
        );
    }

    public function exists(string $storagePath): bool
    {
        try {
            return $this->disk()->exists($this->assertInsideDisk($storagePath, requireExists: false));
        } catch (RuntimeException) {
            return false;
        }
    }

    /**
     * @return array{exists: bool, size: int, last_modified: int, mime_type: string}
     *
     * @throws \RuntimeException
     */
    public function getFileInfo(string $storagePath): array
    {
        $path = $this->assertInsideDisk($storagePath);

        return [
            'exists' => true,
            'size' => (int) $this->disk()->size($path),
            'last_modified' => (int) $this->disk()->lastModified($path),
            // Read off the stored bytes, not off anything a client sent.
            'mime_type' => (string) $this->disk()->mimeType($path),
        ];
    }

    public function delete(string $storagePath): bool
    {
        try {
            $path = $this->assertInsideDisk($storagePath, requireExists: false);
        } catch (RuntimeException) {
            return false;
        }

        return $this->disk()->delete($path);
    }

    /* ------------------------------------------------------------------ */
    /*  Internals */
    /* ------------------------------------------------------------------ */

    private function disk(): Filesystem
    {
        return Storage::disk(self::DISK);
    }

    /**
     * @return array{extensions: list<string>, max_kilobytes: int}
     */
    private function profile(string $profile): array
    {
        return self::PROFILES[$profile]
            ?? throw new \InvalidArgumentException("Unknown upload profile [{$profile}].");
    }

    /**
     * Reduce a caller-supplied path to a normalised, relative, traversal-free
     * form, or refuse.
     *
     * Rejecting rather than silently stripping: a path containing `..` is
     * either a bug or an attack, and quietly rewriting it to something that
     * "works" hides both.
     *
     * @throws \RuntimeException
     */
    private function safeRelativePath(string $path): string
    {
        if ($path === '' || str_contains($path, "\0")) {
            throw new RuntimeException('Refusing an empty or null-byte storage path.');
        }

        // Stream wrappers (phar://, http://, file://) must never reach a disk call.
        if (preg_match('#^[a-zA-Z][a-zA-Z0-9+.\-]*://#', $path) === 1) {
            throw new RuntimeException('Refusing a storage path carrying a stream wrapper.');
        }

        $normalised = str_replace('\\', '/', $path);

        // Absolute paths, POSIX or Windows-drive.
        if (str_starts_with($normalised, '/') || preg_match('#^[a-zA-Z]:/#', $normalised) === 1) {
            throw new RuntimeException('Refusing an absolute storage path.');
        }

        $segments = [];

        foreach (explode('/', $normalised) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                throw new RuntimeException('Refusing a storage path containing a parent-directory segment.');
            }

            $segments[] = $segment;
        }

        if ($segments === []) {
            throw new RuntimeException('Refusing an empty storage path.');
        }

        return implode('/', $segments);
    }

    /**
     * Belt and braces on top of {@see self::safeRelativePath()}: resolve the
     * path on the real filesystem and confirm it is still under the disk root.
     *
     * Segment filtering alone does not catch a symlink planted inside the disk
     * that points outside it. realpath() does.
     *
     * @throws \RuntimeException
     */
    private function assertInsideDisk(string $storagePath, bool $requireExists = true): string
    {
        $path = $this->safeRelativePath($storagePath);
        $disk = $this->disk();

        if ($requireExists && ! $disk->exists($path)) {
            throw new RuntimeException('File not found.');
        }

        $root = realpath($disk->path(''));
        $resolved = realpath($disk->path($path));

        if ($root !== false && $resolved !== false
            && ! str_starts_with($resolved, rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('Refusing a storage path that resolves outside the private disk.');
        }

        return $path;
    }

    /**
     * The original filename is client-controlled and is echoed back in the
     * Content-Disposition header, so strip anything that could be read as a
     * path or a header break before it goes anywhere near a response.
     */
    private function sanitiseFileName(string $name): string
    {
        $name = str_replace(['\\', '/'], '-', $name);
        $name = basename($name);
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '';
        $name = trim($name);

        if ($name === '' || $name === '.' || $name === '..') {
            return 'download';
        }

        return Str::limit($name, 200, '');
    }

    private function sanitiseExtension(string $extension): string
    {
        return (string) preg_replace('/[^a-z0-9]/', '', strtolower($extension));
    }
}
