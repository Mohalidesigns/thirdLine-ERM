<?php

namespace App\Support\Tprm;

/**
 * What has to happen before a relationship is actually over — FR-EXT-03.
 *
 * NINE ITEMS, AND THE ORDER IS THE ORDER THEY BITE IN. Access and connections
 * first, because those are the ones that are still live while everybody
 * assumes the relationship ended months ago; the invoice reconciliation last,
 * because nobody has ever forgotten to stop paying an invoice for as long as
 * they have forgotten to close a VPN.
 *
 * `reconciles_with` NAMES THE ITEM THAT IS ANSWERED SOMEWHERE ELSE. Access
 * revocation and connection closure are already tracked by the Phase 7
 * register and enforced by its termination guard; duplicating them here as
 * free-text tick-boxes would give a bank two answers to one question and let
 * it close the checklist while the guard still refuses. They are generated
 * pre-satisfied where the register already says so, and blocked where it does
 * not.
 *
 * `is_mandatory` false ON TWO ITEMS ONLY. Customer communication does not
 * apply to a vendor no customer ever heard of, and knowledge transfer does
 * not apply where nothing was outsourced that anybody has to learn. Everything
 * else is mandatory and needs an approved exception to skip — see
 * `Waiver::TYPE_OFFBOARDING_ITEM`, which Phase 0 anticipated.
 */
class OffboardingTemplate
{
    /**
     * @var list<array{code: string, title: string, item_type: string, mandatory: bool, reconciles_with: string|null, guidance: string}>
     */
    public const ITEMS = [
        [
            'code' => 'OFF-ACCESS',
            'title' => 'Every access grant revoked, with evidence',
            'item_type' => 'access_revocation',
            'mandatory' => true,
            'reconciles_with' => 'access_grants',
            'guidance' => 'Reconciled against the access register (FR-ACC-03). This item cannot be completed '
                .'by hand while a live grant stands — revoke it there and this closes itself.',
        ],
        [
            'code' => 'OFF-CONN',
            'title' => 'Every connection closed, with evidence',
            'item_type' => 'connection_closure',
            'mandatory' => true,
            'reconciles_with' => 'connections',
            'guidance' => 'Reconciled against the connection register. A suspended tunnel is not a closed one.',
        ],
        [
            'code' => 'OFF-DATA-RETURN',
            'title' => 'Our data returned in the agreed format',
            'item_type' => 'data_return',
            'mandatory' => true,
            'reconciles_with' => null,
            'guidance' => 'The format is the one recorded on the exit plan. A return in a format nobody can '
                .'read is not a return.',
        ],
        [
            'code' => 'OFF-DATA-DESTROY',
            'title' => 'Data destruction certificate received',
            'item_type' => 'data_destruction',
            'mandatory' => true,
            'reconciles_with' => null,
            'guidance' => 'NDPA §24(1)(d) and GAID Art. 34(2)(g). A written certificate, not an assurance in '
                .'an email — this is the document a supervisor asks for.',
        ],
        [
            'code' => 'OFF-ASSETS',
            'title' => 'Licences, assets and equipment returned',
            'item_type' => 'asset_return',
            'mandatory' => true,
            'reconciles_with' => null,
            'guidance' => 'Including anything of ours on their premises and any of their equipment on ours.',
        ],
        [
            'code' => 'OFF-RECORDS',
            'title' => 'Records retention period set and applied',
            'item_type' => 'records_retention',
            'mandatory' => true,
            'reconciles_with' => null,
            'guidance' => 'What WE keep, and for how long. The engagement file, the assessments and the '
                .'audit log outlive the relationship — seven years by default.',
        ],
        [
            'code' => 'OFF-KNOWLEDGE',
            'title' => 'Knowledge transfer completed',
            'item_type' => 'knowledge_transfer',
            'mandatory' => false,
            'reconciles_with' => null,
            'guidance' => 'Where the service is being brought in house or moved to another provider.',
        ],
        [
            'code' => 'OFF-CUSTOMER',
            'title' => 'Customers communicated with',
            'item_type' => 'customer_communication',
            'mandatory' => false,
            'reconciles_with' => null,
            'guidance' => 'Where customers would notice — a channel, an agent network, a card programme. '
                .'CBN Consumer Protection Regulations §2.5.',
        ],
        [
            'code' => 'OFF-INVOICE',
            'title' => 'Final invoice reconciled and settled',
            'item_type' => 'invoice_reconciliation',
            'mandatory' => true,
            'reconciles_with' => null,
            'guidance' => 'Including anything prepaid that should come back.',
        ],
    ];

    /** @return list<string> */
    public static function codes(): array
    {
        return array_column(self::ITEMS, 'code');
    }

    /**
     * @return array{code: string, title: string, item_type: string, mandatory: bool, reconciles_with: string|null, guidance: string}|null
     */
    public static function byCode(string $code): ?array
    {
        foreach (self::ITEMS as $item) {
            if ($item['code'] === $code) {
                return $item;
            }
        }

        return null;
    }
}
