<?php

namespace Database\Seeders;

use App\Models\Organization;
use App\Models\RiskCategory;
use Illuminate\Database\Seeder;

class RiskCategorySeeder extends Seeder
{
    /**
     * Seed CBN-aligned risk taxonomy with materialized paths.
     */
    public function run(): void
    {
        /* ------------------------------------------------------------------ */
        /*  Default Organization */
        /* ------------------------------------------------------------------ */

        $org = Organization::firstOrCreate(
            ['cbn_institution_code' => 'NGN/COM/0001'],
            [
                'name' => 'Demo Financial Institution',
                'short_name' => 'DemoFI',
                'institution_type' => 'commercial_bank',
                'sector' => 'banking',
                'is_active' => true,
            ]
        );

        $orgId = $org->id;
        $sort = 0;

        /* ------------------------------------------------------------------ */
        /*  Category tree definition */
        /* ------------------------------------------------------------------ */

        $taxonomy = [
            // ── Credit Risk ──────────────────────────────────────────────
            [
                'code' => 'CR',
                'name' => 'Credit Risk',
                'description' => 'Risk of loss arising from a borrower or counterparty failing to meet obligations.',
                'icon' => 'account_balance',
                'color' => '#E53935',
                'cbn_mapping_code' => 'CBN-CR',
                'basel_category' => 'Credit Risk',
                'children' => [
                    [
                        'code' => 'CR-DR',
                        'name' => 'Default Risk',
                        'description' => 'Risk that a borrower will be unable to make required payments.',
                        'icon' => 'credit_card_off',
                        'color' => '#EF5350',
                        'cbn_mapping_code' => 'CBN-CR-DR',
                        'basel_category' => 'Credit Risk',
                        'children' => [
                            [
                                'code' => 'CR-DR-1',
                                'name' => 'Loan Default',
                                'description' => 'Failure of a borrower to repay a loan according to agreed terms.',
                                'icon' => 'money_off',
                                'color' => '#F44336',
                                'cbn_mapping_code' => 'CBN-CR-DR-01',
                                'basel_category' => 'Credit Risk',
                            ],
                            [
                                'code' => 'CR-DR-2',
                                'name' => 'Counterparty Failure',
                                'description' => 'Failure of a counterparty to honour a contractual obligation.',
                                'icon' => 'handshake',
                                'color' => '#F44336',
                                'cbn_mapping_code' => 'CBN-CR-DR-02',
                                'basel_category' => 'Credit Risk',
                            ],
                        ],
                    ],
                    [
                        'code' => 'CR-CN',
                        'name' => 'Concentration Risk',
                        'description' => 'Risk from excessive exposure to a single counterparty, sector, or geography.',
                        'icon' => 'filter_center_focus',
                        'color' => '#EF5350',
                        'cbn_mapping_code' => 'CBN-CR-CN',
                        'basel_category' => 'Credit Risk',
                        'children' => [
                            [
                                'code' => 'CR-CN-1',
                                'name' => 'Sector Concentration',
                                'description' => 'Excessive credit exposure to a particular economic sector.',
                                'icon' => 'domain',
                                'color' => '#F44336',
                                'cbn_mapping_code' => 'CBN-CR-CN-01',
                                'basel_category' => 'Credit Risk',
                            ],
                            [
                                'code' => 'CR-CN-2',
                                'name' => 'Single Obligor',
                                'description' => 'Excessive credit exposure to a single borrower or connected group.',
                                'icon' => 'person',
                                'color' => '#F44336',
                                'cbn_mapping_code' => 'CBN-CR-CN-02',
                                'basel_category' => 'Credit Risk',
                            ],
                        ],
                    ],
                    [
                        'code' => 'CR-CT',
                        'name' => 'Country Risk',
                        'description' => 'Risk of loss arising from events in a particular country.',
                        'icon' => 'public',
                        'color' => '#EF5350',
                        'cbn_mapping_code' => 'CBN-CR-CT',
                        'basel_category' => 'Credit Risk',
                        'children' => [
                            [
                                'code' => 'CR-CT-1',
                                'name' => 'Sovereign Default',
                                'description' => 'Risk that a sovereign government fails to honour its debt obligations.',
                                'icon' => 'flag',
                                'color' => '#F44336',
                                'cbn_mapping_code' => 'CBN-CR-CT-01',
                                'basel_category' => 'Credit Risk',
                            ],
                        ],
                    ],
                ],
            ],

            // ── Market Risk ──────────────────────────────────────────────
            [
                'code' => 'MR',
                'name' => 'Market Risk',
                'description' => 'Risk of losses in on- and off-balance-sheet positions arising from movements in market prices.',
                'icon' => 'trending_up',
                'color' => '#FF9800',
                'cbn_mapping_code' => 'CBN-MR',
                'basel_category' => 'Market Risk',
                'children' => [
                    [
                        'code' => 'MR-IR',
                        'name' => 'Interest Rate Risk',
                        'description' => 'Risk arising from changes in interest rates affecting earnings or economic value.',
                        'icon' => 'percent',
                        'color' => '#FFA726',
                        'cbn_mapping_code' => 'CBN-MR-IR',
                        'basel_category' => 'Market Risk',
                        'children' => [
                            [
                                'code' => 'MR-IR-1',
                                'name' => 'Repricing Risk',
                                'description' => 'Risk from timing differences in the maturity and repricing of assets and liabilities.',
                                'icon' => 'update',
                                'color' => '#FB8C00',
                                'cbn_mapping_code' => 'CBN-MR-IR-01',
                                'basel_category' => 'Market Risk',
                            ],
                            [
                                'code' => 'MR-IR-2',
                                'name' => 'Basis Risk',
                                'description' => 'Risk from imperfect correlation between rates on different instruments with similar maturities.',
                                'icon' => 'compare_arrows',
                                'color' => '#FB8C00',
                                'cbn_mapping_code' => 'CBN-MR-IR-02',
                                'basel_category' => 'Market Risk',
                            ],
                        ],
                    ],
                    [
                        'code' => 'MR-FX',
                        'name' => 'FX Risk',
                        'description' => 'Risk of loss from changes in foreign exchange rates.',
                        'icon' => 'currency_exchange',
                        'color' => '#FFA726',
                        'cbn_mapping_code' => 'CBN-MR-FX',
                        'basel_category' => 'Market Risk',
                        'children' => [
                            [
                                'code' => 'MR-FX-1',
                                'name' => 'Transaction Exposure',
                                'description' => 'Risk from the effect of exchange rate changes on outstanding obligations denominated in foreign currency.',
                                'icon' => 'swap_horiz',
                                'color' => '#FB8C00',
                                'cbn_mapping_code' => 'CBN-MR-FX-01',
                                'basel_category' => 'Market Risk',
                            ],
                        ],
                    ],
                    [
                        'code' => 'MR-EQ',
                        'name' => 'Equity Risk',
                        'description' => 'Risk of loss from changes in equity prices.',
                        'icon' => 'bar_chart',
                        'color' => '#FFA726',
                        'cbn_mapping_code' => 'CBN-MR-EQ',
                        'basel_category' => 'Market Risk',
                        'children' => [],
                    ],
                ],
            ],

            // ── Operational Risk ─────────────────────────────────────────
            [
                'code' => 'OR',
                'name' => 'Operational Risk',
                'description' => 'Risk of loss resulting from inadequate or failed internal processes, people, systems, or external events.',
                'icon' => 'settings',
                'color' => '#9C27B0',
                'cbn_mapping_code' => 'CBN-OR',
                'basel_category' => 'Operational Risk',
                'children' => [
                    [
                        'code' => 'OR-PR',
                        'name' => 'Process Risk',
                        'description' => 'Risk arising from failures in internal business processes.',
                        'icon' => 'account_tree',
                        'color' => '#AB47BC',
                        'cbn_mapping_code' => 'CBN-OR-PR',
                        'basel_category' => 'Operational Risk',
                        'children' => [
                            [
                                'code' => 'OR-PR-1',
                                'name' => 'Transaction Errors',
                                'description' => 'Errors in transaction processing, execution, or settlement.',
                                'icon' => 'error',
                                'color' => '#8E24AA',
                                'cbn_mapping_code' => 'CBN-OR-PR-01',
                                'basel_category' => 'Operational Risk',
                            ],
                            [
                                'code' => 'OR-PR-2',
                                'name' => 'Settlement Failures',
                                'description' => 'Failure to settle a transaction on the agreed date.',
                                'icon' => 'sync_problem',
                                'color' => '#8E24AA',
                                'cbn_mapping_code' => 'CBN-OR-PR-02',
                                'basel_category' => 'Operational Risk',
                            ],
                        ],
                    ],
                    [
                        'code' => 'OR-PE',
                        'name' => 'People Risk',
                        'description' => 'Risk arising from human factors including fraud, errors, and staffing issues.',
                        'icon' => 'groups',
                        'color' => '#AB47BC',
                        'cbn_mapping_code' => 'CBN-OR-PE',
                        'basel_category' => 'Operational Risk',
                        'children' => [
                            [
                                'code' => 'OR-PE-1',
                                'name' => 'Fraud',
                                'description' => 'Intentional acts of deception by employees or third parties for personal gain.',
                                'icon' => 'gavel',
                                'color' => '#8E24AA',
                                'cbn_mapping_code' => 'CBN-OR-PE-01',
                                'basel_category' => 'Operational Risk',
                            ],
                            [
                                'code' => 'OR-PE-2',
                                'name' => 'Key Person Dependency',
                                'description' => 'Risk from over-reliance on a small number of key individuals.',
                                'icon' => 'person_pin',
                                'color' => '#8E24AA',
                                'cbn_mapping_code' => 'CBN-OR-PE-02',
                                'basel_category' => 'Operational Risk',
                            ],
                        ],
                    ],
                    [
                        'code' => 'OR-SY',
                        'name' => 'Systems Risk',
                        'description' => 'Risk arising from IT system failures or inadequacies.',
                        'icon' => 'dns',
                        'color' => '#AB47BC',
                        'cbn_mapping_code' => 'CBN-OR-SY',
                        'basel_category' => 'Operational Risk',
                        'children' => [
                            [
                                'code' => 'OR-SY-1',
                                'name' => 'IT Failures',
                                'description' => 'Hardware or software failures leading to service disruption.',
                                'icon' => 'desktop_access_disabled',
                                'color' => '#8E24AA',
                                'cbn_mapping_code' => 'CBN-OR-SY-01',
                                'basel_category' => 'Operational Risk',
                            ],
                            [
                                'code' => 'OR-SY-2',
                                'name' => 'Cyber Attacks',
                                'description' => 'Malicious attacks on IT infrastructure and data assets.',
                                'icon' => 'shield',
                                'color' => '#8E24AA',
                                'cbn_mapping_code' => 'CBN-OR-SY-02',
                                'basel_category' => 'Operational Risk',
                            ],
                        ],
                    ],
                    [
                        'code' => 'OR-EX',
                        'name' => 'External Events',
                        'description' => 'Risk arising from external events beyond the institution\'s control.',
                        'icon' => 'thunderstorm',
                        'color' => '#AB47BC',
                        'cbn_mapping_code' => 'CBN-OR-EX',
                        'basel_category' => 'Operational Risk',
                        'children' => [
                            [
                                'code' => 'OR-EX-1',
                                'name' => 'Natural Disasters',
                                'description' => 'Losses from natural catastrophes such as floods, earthquakes, or pandemics.',
                                'icon' => 'flood',
                                'color' => '#8E24AA',
                                'cbn_mapping_code' => 'CBN-OR-EX-01',
                                'basel_category' => 'Operational Risk',
                            ],
                        ],
                    ],
                ],
            ],

            // ── Liquidity Risk ───────────────────────────────────────────
            [
                'code' => 'LR',
                'name' => 'Liquidity Risk',
                'description' => 'Risk that an institution cannot meet its financial obligations as they fall due.',
                'icon' => 'water_drop',
                'color' => '#1E88E5',
                'cbn_mapping_code' => 'CBN-LR',
                'basel_category' => 'Liquidity Risk',
                'children' => [
                    [
                        'code' => 'LR-FL',
                        'name' => 'Funding Liquidity',
                        'description' => 'Risk that the institution cannot raise funds at reasonable cost to meet obligations.',
                        'icon' => 'savings',
                        'color' => '#42A5F5',
                        'cbn_mapping_code' => 'CBN-LR-FL',
                        'basel_category' => 'Liquidity Risk',
                        'children' => [
                            [
                                'code' => 'LR-FL-1',
                                'name' => 'Deposit Withdrawal',
                                'description' => 'Risk of significant unexpected withdrawal of deposits.',
                                'icon' => 'output',
                                'color' => '#1976D2',
                                'cbn_mapping_code' => 'CBN-LR-FL-01',
                                'basel_category' => 'Liquidity Risk',
                            ],
                        ],
                    ],
                    [
                        'code' => 'LR-ML',
                        'name' => 'Market Liquidity',
                        'description' => 'Risk that the institution cannot easily offset or liquidate a position at market price.',
                        'icon' => 'speed',
                        'color' => '#42A5F5',
                        'cbn_mapping_code' => 'CBN-LR-ML',
                        'basel_category' => 'Liquidity Risk',
                        'children' => [],
                    ],
                ],
            ],

            // ── Compliance Risk ──────────────────────────────────────────
            [
                'code' => 'CMP',
                'name' => 'Compliance Risk',
                'description' => 'Risk of legal or regulatory sanctions, financial loss, or reputational damage from failure to comply.',
                'icon' => 'verified_user',
                'color' => '#43A047',
                'cbn_mapping_code' => 'CBN-CMP',
                'basel_category' => 'Operational Risk',
                'children' => [
                    [
                        'code' => 'CMP-RG',
                        'name' => 'Regulatory Risk',
                        'description' => 'Risk of non-compliance with applicable regulations and supervisory requirements.',
                        'icon' => 'policy',
                        'color' => '#66BB6A',
                        'cbn_mapping_code' => 'CBN-CMP-RG',
                        'basel_category' => 'Operational Risk',
                        'children' => [
                            [
                                'code' => 'CMP-RG-1',
                                'name' => 'CBN Sanctions',
                                'description' => 'Risk of punitive action by the Central Bank of Nigeria.',
                                'icon' => 'block',
                                'color' => '#388E3C',
                                'cbn_mapping_code' => 'CBN-CMP-RG-01',
                                'basel_category' => 'Operational Risk',
                            ],
                        ],
                    ],
                    [
                        'code' => 'CMP-LG',
                        'name' => 'Legal Risk',
                        'description' => 'Risk arising from unenforceable contracts, lawsuits, or adverse judgements.',
                        'icon' => 'balance',
                        'color' => '#66BB6A',
                        'cbn_mapping_code' => 'CBN-CMP-LG',
                        'basel_category' => 'Operational Risk',
                        'children' => [
                            [
                                'code' => 'CMP-LG-1',
                                'name' => 'Litigation',
                                'description' => 'Risk of financial loss from legal proceedings.',
                                'icon' => 'gavel',
                                'color' => '#388E3C',
                                'cbn_mapping_code' => 'CBN-CMP-LG-01',
                                'basel_category' => 'Operational Risk',
                            ],
                        ],
                    ],
                    [
                        'code' => 'CMP-FC',
                        'name' => 'Financial Crime',
                        'description' => 'Risk from money laundering, terrorist financing, or other financial crimes.',
                        'icon' => 'report',
                        'color' => '#66BB6A',
                        'cbn_mapping_code' => 'CBN-CMP-FC',
                        'basel_category' => 'Operational Risk',
                        'children' => [
                            [
                                'code' => 'CMP-FC-1',
                                'name' => 'Money Laundering',
                                'description' => 'Risk of the institution being used for money laundering activities.',
                                'icon' => 'local_laundry_service',
                                'color' => '#388E3C',
                                'cbn_mapping_code' => 'CBN-CMP-FC-01',
                                'basel_category' => 'Operational Risk',
                            ],
                        ],
                    ],
                ],
            ],

            // ── Strategic Risk ───────────────────────────────────────────
            [
                'code' => 'SR',
                'name' => 'Strategic Risk',
                'description' => 'Risk arising from adverse business decisions, improper implementation of decisions, or lack of responsiveness to changes.',
                'icon' => 'strategy',
                'color' => '#F4511E',
                'cbn_mapping_code' => 'CBN-SR',
                'basel_category' => null,
                'children' => [
                    [
                        'code' => 'SR-BM',
                        'name' => 'Business Model Risk',
                        'description' => 'Risk that the business model is not sustainable or is vulnerable to disruption.',
                        'icon' => 'business_center',
                        'color' => '#FF7043',
                        'cbn_mapping_code' => 'CBN-SR-BM',
                        'basel_category' => null,
                        'children' => [
                            [
                                'code' => 'SR-BM-1',
                                'name' => 'Product Obsolescence',
                                'description' => 'Risk that products or services become outdated or irrelevant.',
                                'icon' => 'inventory',
                                'color' => '#E64A19',
                                'cbn_mapping_code' => 'CBN-SR-BM-01',
                                'basel_category' => null,
                            ],
                        ],
                    ],
                    [
                        'code' => 'SR-RP',
                        'name' => 'Reputation Risk',
                        'description' => 'Risk of damage to the institution\'s reputation from negative public perception.',
                        'icon' => 'star_half',
                        'color' => '#FF7043',
                        'cbn_mapping_code' => 'CBN-SR-RP',
                        'basel_category' => null,
                        'children' => [],
                    ],
                    [
                        'code' => 'SR-CP',
                        'name' => 'Competition Risk',
                        'description' => 'Risk of losing market share due to competitive pressures or new entrants.',
                        'icon' => 'emoji_events',
                        'color' => '#FF7043',
                        'cbn_mapping_code' => 'CBN-SR-CP',
                        'basel_category' => null,
                        'children' => [],
                    ],
                ],
            ],

            // ── Technology Risk ──────────────────────────────────────────
            [
                'code' => 'TR',
                'name' => 'Technology Risk',
                'description' => 'Risk arising from failures in technology systems, processes, or third-party dependencies.',
                'icon' => 'memory',
                'color' => '#5E35B1',
                'cbn_mapping_code' => 'CBN-TR',
                'basel_category' => 'Operational Risk',
                'children' => [
                    [
                        'code' => 'TR-CY',
                        'name' => 'Cyber Risk',
                        'description' => 'Risk from cyber threats targeting data confidentiality, integrity, and availability.',
                        'icon' => 'security',
                        'color' => '#7E57C2',
                        'cbn_mapping_code' => 'CBN-TR-CY',
                        'basel_category' => 'Operational Risk',
                        'children' => [
                            [
                                'code' => 'TR-CY-1',
                                'name' => 'Data Breach',
                                'description' => 'Unauthorized access to or disclosure of sensitive data.',
                                'icon' => 'enhanced_encryption',
                                'color' => '#4527A0',
                                'cbn_mapping_code' => 'CBN-TR-CY-01',
                                'basel_category' => 'Operational Risk',
                            ],
                            [
                                'code' => 'TR-CY-2',
                                'name' => 'Ransomware',
                                'description' => 'Malware that encrypts data and demands ransom for decryption.',
                                'icon' => 'lock',
                                'color' => '#4527A0',
                                'cbn_mapping_code' => 'CBN-TR-CY-02',
                                'basel_category' => 'Operational Risk',
                            ],
                        ],
                    ],
                    [
                        'code' => 'TR-IN',
                        'name' => 'Infrastructure Risk',
                        'description' => 'Risk from physical or virtual infrastructure failures.',
                        'icon' => 'developer_board',
                        'color' => '#7E57C2',
                        'cbn_mapping_code' => 'CBN-TR-IN',
                        'basel_category' => 'Operational Risk',
                        'children' => [
                            [
                                'code' => 'TR-IN-1',
                                'name' => 'Power Failure',
                                'description' => 'Disruption to operations due to power supply failures.',
                                'icon' => 'power_off',
                                'color' => '#4527A0',
                                'cbn_mapping_code' => 'CBN-TR-IN-01',
                                'basel_category' => 'Operational Risk',
                            ],
                        ],
                    ],
                    [
                        'code' => 'TR-TP',
                        'name' => 'Third Party Tech Risk',
                        'description' => 'Risk arising from dependence on third-party technology vendors and service providers.',
                        'icon' => 'hub',
                        'color' => '#7E57C2',
                        'cbn_mapping_code' => 'CBN-TR-TP',
                        'basel_category' => 'Operational Risk',
                        'children' => [],
                    ],
                ],
            ],
        ];

        /* ------------------------------------------------------------------ */
        /*  Insert categories recursively */
        /* ------------------------------------------------------------------ */

        foreach ($taxonomy as $rootCategory) {
            $sort++;
            $this->createCategory($rootCategory, $orgId, null, '', 1, $sort);
        }
    }

    /**
     * Recursively create a category and its children.
     */
    private function createCategory(array $data, int $orgId, ?int $parentId, string $parentPath, int $level, int &$sort): void
    {
        $path = $parentPath === '' ? $data['code'] : $parentPath.'/'.$data['code'];

        $children = $data['children'] ?? [];
        unset($data['children']);

        $category = RiskCategory::create([
            'organization_id' => $orgId,
            'parent_id' => $parentId,
            'code' => $data['code'],
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'level' => $level,
            'path' => $path,
            'cbn_mapping_code' => $data['cbn_mapping_code'] ?? null,
            'basel_category' => $data['basel_category'] ?? null,
            'is_active' => true,
            'sort_order' => $sort,
            'icon' => $data['icon'] ?? null,
            'color' => $data['color'] ?? null,
        ]);

        foreach ($children as $child) {
            $sort++;
            $this->createCategory($child, $orgId, $category->id, $path, $level + 1, $sort);
        }
    }
}
