<?php

namespace App\Exceptions\Bcms;

use RuntimeException;

/**
 * The only shape a directory-read failure takes, from
 * `App\Services\Bcms\Identity\EntraGraphClient` up to
 * `App\Services\Bcms\Identity\DirectorySyncService`.
 *
 * NO PROVIDER MESSAGE, EVER (ADR 0018 §2.3, §3.1; work order §12 item 3).
 * `error_class` and `error_code` are a closed, bounded vocabulary — never
 * `$e->getMessage()`, which for a Guzzle `ConnectException` carries the full
 * request URI and for a Graph error body could carry `error.message` free
 * text. The constructor's own message is a static, content-free string
 * precisely so that even a future call site that forgets this rule and logs
 * `getMessage()` still logs nothing sensitive.
 */
final class DirectorySyncException extends RuntimeException
{
    public function __construct(
        public readonly string $errorClass,
        public readonly ?string $errorCode,
        public readonly int $objectsReadBeforeFailure = 0,
    ) {
        parent::__construct('Directory provider request failed.');
    }
}
