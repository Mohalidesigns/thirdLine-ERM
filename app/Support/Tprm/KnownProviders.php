<?php

namespace App\Support\Tprm;

/**
 * The providers a Nigerian bank's supply chain actually runs through — the
 * dictionary behind sub-processor discovery (TRD §12.4, FR-NTH-06).
 *
 * A DICTIONARY, NOT A MODEL. The discovery step this feeds could have been a
 * language model reading a DPA annex and inventing plausible vendor names,
 * and the result would have been unfalsifiable: nobody reviewing a proposed
 * edge can tell a real sub-processor from a fluent hallucination of one. A
 * name matched from a fixed list is either in the list or it is not, and a
 * reviewer can check.
 *
 * The list is deliberately SHORT AND REGIONAL. A global catalogue of ten
 * thousand SaaS vendors would match "Oracle" in the phrase "oracle problem"
 * and bury the reviewer. These are the entities whose failure would actually
 * appear in a CBN incident return, plus the hyperscalers everything else sits
 * on. `config('tprm.known_providers')` extends it per tenant, because every
 * bank has three or four names that matter only to it.
 *
 * `group` IS WHAT MAKES CONCENTRATION WORK. AWS, Amazon Web Services and
 * "Amazon Web Services EMEA SARL" are one dependency for the purpose of
 * asking how much of the bank stops if it stops, and clustering by the string
 * a vendor happened to type would have shown three small exposures instead of
 * one large one.
 */
class KnownProviders
{
    /**
     * @var array<string, array{group: string, category: string, aliases: list<string>}>
     */
    public const CATALOGUE = [
        'Amazon Web Services' => [
            'group' => 'Amazon',
            'category' => 'cloud_infrastructure',
            'aliases' => ['AWS', 'Amazon Web Services EMEA', 'AWS EMEA SARL'],
        ],
        'Microsoft Azure' => [
            'group' => 'Microsoft',
            'category' => 'cloud_infrastructure',
            'aliases' => ['Azure', 'Microsoft Ireland Operations'],
        ],
        'Microsoft 365' => [
            'group' => 'Microsoft',
            'category' => 'productivity',
            'aliases' => ['Office 365', 'O365', 'Microsoft Office 365'],
        ],
        'Google Cloud Platform' => [
            'group' => 'Google',
            'category' => 'cloud_infrastructure',
            'aliases' => ['GCP', 'Google Cloud', 'Google Ireland Limited'],
        ],
        'Salesforce' => [
            'group' => 'Salesforce',
            'category' => 'crm',
            'aliases' => ['Salesforce.com', 'Force.com'],
        ],
        'MainOne' => [
            'group' => 'MainOne',
            'category' => 'connectivity',
            'aliases' => ['Main One Cable Company', 'MainOne Cable'],
        ],
        'Rack Centre' => [
            'group' => 'Rack Centre',
            'category' => 'data_centre',
            'aliases' => ['RackCentre', 'Rack Centre Limited'],
        ],
        'Interswitch' => [
            'group' => 'Interswitch',
            'category' => 'payment_switch',
            'aliases' => ['Interswitch Limited', 'Interswitch Nigeria'],
        ],
        'NIBSS' => [
            'group' => 'NIBSS',
            'category' => 'payment_switch',
            'aliases' => ['Nigeria Inter-Bank Settlement System', 'NIBSS Plc'],
        ],
        'Flutterwave' => [
            'group' => 'Flutterwave',
            'category' => 'payment_processing',
            'aliases' => ['Flutterwave Inc'],
        ],
        'Temenos' => [
            'group' => 'Temenos',
            'category' => 'core_banking',
            'aliases' => ['Temenos T24', 'T24', 'Temenos Transact'],
        ],
        'Oracle Financial Services' => [
            'group' => 'Oracle',
            'category' => 'core_banking',
            'aliases' => ['Oracle FSS', 'Oracle FLEXCUBE', 'FLEXCUBE'],
        ],
        'Infosys Finacle' => [
            'group' => 'Infosys',
            'category' => 'core_banking',
            'aliases' => ['Finacle', 'Infosys Limited'],
        ],
    ];

    /**
     * The catalogue plus whatever the tenant added.
     *
     * @return array<string, array{group: string, category: string, aliases: list<string>}>
     */
    public static function all(): array
    {
        /** @var array<string, array{group: string, category: string, aliases: list<string>}> $extra */
        $extra = config('tprm.known_providers', []);

        return array_merge(self::CATALOGUE, $extra);
    }

    /**
     * Every searchable string mapped to its canonical name.
     *
     * @return array<string, string>
     */
    public static function index(): array
    {
        $index = [];

        foreach (self::all() as $canonical => $entry) {
            $index[self::normalise($canonical)] = $canonical;

            foreach ($entry['aliases'] as $alias) {
                $index[self::normalise($alias)] = $canonical;
            }
        }

        return $index;
    }

    public static function groupFor(string $canonical): ?string
    {
        return self::all()[$canonical]['group'] ?? null;
    }

    public static function categoryFor(string $canonical): ?string
    {
        return self::all()[$canonical]['category'] ?? null;
    }

    /**
     * Fold a name to its comparable form.
     *
     * Corporate suffixes go, because "Interswitch" and "Interswitch Limited"
     * are the same dependency and a reviewer asked to confirm both twice will
     * start confirming without reading.
     */
    public static function normalise(string $name): string
    {
        $name = strtolower(trim($name));
        $name = (string) preg_replace('/[^a-z0-9 ]+/', ' ', $name);
        $name = (string) preg_replace(
            '/\b(limited|ltd|plc|inc|incorporated|llc|gmbh|sarl|bv|nv|sa|pte|corp|corporation|company|co)\b/',
            ' ',
            $name,
        );

        return trim((string) preg_replace('/\s+/', ' ', $name));
    }
}
