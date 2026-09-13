<?php

namespace App\Enums\Tprm;

use App\Enums\Tprm\Concerns\EnumHelpers;

/**
 * How a third party is technically joined to us — FR-ACC-01's seven types.
 *
 * The list is CLOSED rather than free text because the reconciliation control
 * this feeds ("close every connection before termination") is only testable if
 * an examiner and the bank agree on what a connection is. A free-text field
 * lets a team record "integration" and quietly omit the SFTP drop that still
 * has a live key on it.
 *
 * `isNetworkPath()` marks the types that survive a contract ending: a VPN
 * tunnel, a leased line or a firewall rule keeps carrying traffic until
 * somebody removes it, which is the whole reason AC-10 exists. Physical media
 * and remote support sessions end when the session does, so they close on a
 * different kind of evidence.
 */
enum ConnectionType: string
{
    use EnumHelpers;

    case Vpn = 'vpn';
    case Api = 'api';
    case Sftp = 'sftp';
    case LeasedLine = 'leased_line';
    case DirectDatabase = 'direct_db';
    case RemoteSupport = 'remote_support';
    case PhysicalMedia = 'physical_media';

    public function label(): string
    {
        return match ($this) {
            self::Vpn => 'VPN tunnel',
            self::Api => 'API',
            self::Sftp => 'SFTP',
            self::LeasedLine => 'Leased line',
            self::DirectDatabase => 'Direct database',
            self::RemoteSupport => 'Remote support tool',
            self::PhysicalMedia => 'Physical media',
        };
    }

    /** Whether the path persists on its own until somebody tears it down. */
    public function isNetworkPath(): bool
    {
        return in_array($this, [
            self::Vpn,
            self::Api,
            self::Sftp,
            self::LeasedLine,
            self::DirectDatabase,
        ], true);
    }

    /**
     * What closing this connection should be evidenced by.
     *
     * Displayed on the closure form so the person doing the work knows what
     * the auditor will ask for, rather than attaching a screenshot of a
     * ticket and discovering a year later that it proved nothing.
     */
    public function closureEvidenceHint(): string
    {
        return match ($this) {
            self::Vpn => 'Tunnel removed from the concentrator, with the configuration change reference.',
            self::Api => 'Credential or key revoked, with the gateway record showing the client disabled.',
            self::Sftp => 'Account disabled and keys removed from authorized_keys.',
            self::LeasedLine => 'Circuit disconnection confirmation from the carrier.',
            self::DirectDatabase => 'Database account dropped, with the change record.',
            self::RemoteSupport => 'Tool account removed and any standing session grant withdrawn.',
            self::PhysicalMedia => 'Media returned or destroyed, with the certificate.',
        };
    }
}
