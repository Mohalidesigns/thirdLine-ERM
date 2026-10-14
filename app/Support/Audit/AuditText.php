<?php

namespace App\Support\Audit;

use Illuminate\Http\Request;

/**
 * The one place a caller-influenced string is made safe for an audit row's
 * varchar column.
 *
 * WHY THIS EXISTS. `substr($userAgent, 0, 500)` counts BYTES, so on a header
 * full of multi-byte characters it cuts one in half. The result is not valid
 * UTF-8, and a strict-mode `utf8mb4` insert (MariaDB 10.4 and MySQL 8 alike)
 * refuses it. The TPRM and BCMS auditable traits, the clause analyser and the
 * BCMS pack exports catch that failure and only log it ("auditing never fails
 * the write"), so the business change commits and the audit row is silently
 * gone. On the vendor portal the User-Agent is written by the person being
 * audited: anyone could suppress the record of their own actions by sending a
 * long enough header of the right characters. An over-long actor name or
 * X-Correlation-Id did the same.
 *
 * EVERY WRITER OF THESE AUDIT COLUMNS GOES THROUGH HERE:
 *  - `tp_audit_logs` (`user_agent` 500, `actor_label` 200, `correlation_id`
 *    64): `TprmAuditable`, the four portal services (`PortalAuthService`,
 *    `PortalIncidentService`, `TrustProfileService`, `VendorIdentityService`),
 *    `Tprm\Evidence\EvidenceService` and `ClauseAnalyzer`;
 *  - `bcms_audit_logs` (`actor_label` 150): `BcmsAuditable`, `DrService`, and
 *    the board, evidence and CSAT pack services;
 *  - `rcsa_export_jobs` (`user_agent` 512): `RcsaExportService`.
 * A new writer of any of these columns calls `clip()` with the column's width.
 *
 * TWO THINGS, IN THIS ORDER.
 *
 *   1. SCRUB. A raw header can carry invalid bytes with no truncation involved
 *      at all (`"\xC3\x28"`). `mb_scrub()` replaces each with `?`, so the row is
 *      written and the evidence that something odd was sent survives, rather
 *      than the row being dropped.
 *   2. CLIP BY CHARACTER. The columns are `utf8mb4` varchars, whose declared
 *      length is a CHARACTER count, so `mb_substr()` is exactly the column's own
 *      rule and cannot split a character. (`mb_strcut()` is the answer for a
 *      byte-limited column; none of these is one.)
 *
 * For valid ASCII input the output is byte-identical to the old `substr()`, so
 * a digest sealed over such a row is unchanged. Only a value that was previously
 * invalid, or that exceeded the limit in bytes but not in characters, differs,
 * and only for rows written from now on. No stored row is ever touched.
 *
 * Scrubbing happens BEFORE the value reaches the model so that what the hash
 * chain seals is what the database stores; `AuditLog::chainHash()` json-encodes
 * with JSON_THROW_ON_ERROR and would itself throw on an invalid byte.
 */
final class AuditText
{
    /** `tp_audit_logs.user_agent` is `string(500)`. */
    public const USER_AGENT_MAX = 500;

    /**
     * Valid UTF-8, at most `$max` characters, or null for null / empty.
     */
    public static function clip(mixed $value, int $max): ?string
    {
        if ($value === null) {
            return null;
        }

        $clean = mb_substr(mb_scrub((string) $value, 'UTF-8'), 0, $max, 'UTF-8');

        return $clean === '' ? null : $clean;
    }

    /**
     * The request's User-Agent, made safe for `tp_audit_logs.user_agent`.
     */
    public static function userAgent(?Request $request = null, int $max = self::USER_AGENT_MAX): ?string
    {
        $request ??= request();

        return self::clip($request?->userAgent(), $max);
    }
}
