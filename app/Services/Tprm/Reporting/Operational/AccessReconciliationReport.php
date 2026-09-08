<?php

namespace App\Services\Tprm\Reporting\Operational;

use App\Services\Tprm\Access\AccessService;

/**
 * Access that should not still exist — FR-RPT-07, FR-ACC-03.
 *
 * IT READS `AccessService::reconciliation()` RATHER THAN QUERYING GRANTS
 * ITSELF. The four populations that report resolves — access against a
 * terminated engagement, access against a lapsed contract, access past its own
 * end date, and access with no end date at all — are the module's own
 * definition of the problem, and a second implementation here would be a
 * second definition. When one moved, the screen and the export would disagree
 * about who still has a credential.
 *
 * A PRIVILEGED GRANT SORTS FIRST. `sortByExposure()` already encodes that, and
 * the reader of a reconciliation is looking for administrative access at a
 * vendor that left, not for the alphabetical list.
 */
class AccessReconciliationReport implements OperationalReport
{
    public function __construct(private readonly AccessService $access) {}

    public function key(): string
    {
        return 'access-reconciliation';
    }

    public function title(): string
    {
        return 'Access grant reconciliation';
    }

    public function description(): string
    {
        return 'Credentials that should no longer work: access against terminated engagements, against lapsed '
            .'contracts, past its own end date, or granted with no end date at all.';
    }

    public function permission(): string
    {
        return 'tprm.access.view';
    }

    public function headers(): array
    {
        return [
            'Population', 'Grantee', 'Email', 'System', 'Access level', 'Privileged', 'Status',
            'Valid to', 'Engagement', 'Provider', 'Engagement status',
        ];
    }

    public function rows(): array
    {
        $result = $this->access->reconciliation();

        $populations = [
            'discontinued' => 'Engagement terminated or archived',
            'expired_contract' => 'Contract lapsed',
            'overdue' => 'Past its end date, not revoked',
            'open_ended' => 'No end date recorded',
        ];

        $rows = [];

        foreach ($populations as $key => $label) {
            foreach ($this->access->sortByExposure($result[$key] ?? []) as $grant) {
                $rows[] = [
                    $label,
                    $grant['grantee_name'] ?? 'Not recorded',
                    $grant['grantee_email'] ?? '',
                    $grant['system_name'] ?? 'Not recorded',
                    $grant['access_level_label'] ?? 'Not recorded',
                    ($grant['is_privileged'] ?? false) ? 'Yes' : 'No',
                    ucfirst((string) ($grant['status'] ?? '')),
                    $grant['valid_to'] ?? 'None set',
                    $grant['engagement_name'] ?? 'Not linked',
                    $grant['third_party'] ?? 'Not recorded',
                    $grant['engagement_status'] ?? '',
                ];
            }
        }

        return $rows;
    }

    public function notes(): array
    {
        return [
            'Source' => 'AccessService::reconciliation(), the same query the access screen renders',
            'Ordering' => 'Privileged access first within each population',
            'Expired is live' => 'A grant past its end date is still counted as live until somebody records a '
                .'revocation with evidence — expiry is a date passing, revocation is an act',
        ];
    }
}
