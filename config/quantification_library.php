<?php

/*
|--------------------------------------------------------------------------
| Operational risk scenario library
|--------------------------------------------------------------------------
|
| Starting parameters a bank imports and then REPLACES with its own loss
| history. They are templates, not the bank's numbers, and the import writes
| that provenance into the scenario's own description so it travels with the
| record: "(Imported from library — source: …)".
|
| EVERY ENTRY CARRIES A `source`, and a test asserts it
| (QuantificationLibraryTest). That is the whole point of moving this out of a
| PHP literal inside a controller: a severity distribution feeding a Monte
| Carlo run that feeds an ICAAP capital add-on must be able to say where its
| parameters came from. An entry whose provenance cannot be named does not
| belong here.
|
| WHAT THESE ARE NOT. They are not the bank's own experience, and importing
| one is not an assessment. `mean` and `std_dev` are naira amounts per event;
| `frequency_per_year` is a Poisson lambda. QuantificationController::
| importLibrary() converts the two moments to lognormal mu/sigma through
| Distributions::lognormalFromMoments() and stores the scenario as a draft the
| bank is expected to re-parameterise.
|
| Amounts are in NAIRA here, because that is the unit the published sources
| quote and the unit a risk officer reading this file thinks in. They are
| converted to kobo on import, which is the unit everything downstream stores.
|
*/

return [

    'scenarios' => [

        [
            'id' => 'lib-1',
            'name' => 'Internal Fraud - Unauthorized Trading',
            'risk_category' => 'Operational Risk',
            'distribution_type' => 'lognormal',
            'description' => 'Losses from unauthorized transactions, mismarking, or rogue trading activities in Nigerian banking sector',
            'mean' => 850_000_000,
            'std_dev' => 425_000_000,
            'frequency_per_year' => 1.5,
            'source' => 'CBN ORMS Data',
        ],

        [
            'id' => 'lib-2',
            'name' => 'External Fraud - Cyber Attack',
            'risk_category' => 'Operational Risk',
            'distribution_type' => 'lognormal',
            'description' => 'Losses from cyber intrusion, phishing, BEC, or electronic fraud targeting bank systems',
            'mean' => 1_200_000_000,
            'std_dev' => 800_000_000,
            'frequency_per_year' => 3.2,
            'source' => 'CBN ORMS Data',
        ],

        [
            'id' => 'lib-3',
            'name' => 'IT System Failure',
            'risk_category' => 'Operational Risk',
            'distribution_type' => 'lognormal',
            'description' => 'Losses from core banking system outages, data center failures, or IT infrastructure disruptions',
            'mean' => 500_000_000,
            'std_dev' => 250_000_000,
            'frequency_per_year' => 2,
            'source' => 'Industry Benchmark',
        ],

        [
            'id' => 'lib-4',
            'name' => 'Regulatory Fine - CBN Penalty',
            'risk_category' => 'Operational Risk',
            'distribution_type' => 'lognormal',
            'description' => 'Monetary penalties from CBN for regulatory breaches, non-compliance, or AML/KYC failures',
            'mean' => 2_000_000_000,
            'std_dev' => 1_500_000_000,
            'frequency_per_year' => 0.8,
            'source' => 'CBN Published Sanctions',
        ],

        [
            'id' => 'lib-5',
            'name' => 'Credit Default - Corporate Portfolio',
            'risk_category' => 'Credit Risk',
            'distribution_type' => 'lognormal',
            'description' => 'Losses from corporate loan defaults, particularly in oil & gas, manufacturing, and real estate sectors',
            'mean' => 5_000_000_000,
            'std_dev' => 3_000_000_000,
            'frequency_per_year' => 4.5,
            'source' => 'CBN Credit Bureau',
        ],

        [
            'id' => 'lib-6',
            'name' => 'FX Volatility Shock',
            'risk_category' => 'Market Risk',
            'distribution_type' => 'lognormal',
            'description' => 'Losses from sudden Naira devaluation or FX market volatility affecting open positions',
            'mean' => 3_500_000_000,
            'std_dev' => 2_000_000_000,
            'frequency_per_year' => 1,
            'source' => 'CBN Market Data',
        ],

        [
            'id' => 'lib-7',
            'name' => 'Natural Disaster - Flooding',
            'risk_category' => 'Operational Risk',
            'distribution_type' => 'lognormal',
            'description' => 'Physical damage to branches and data centers from flooding events in Lagos, Port Harcourt, and other coastal cities',
            'mean' => 300_000_000,
            'std_dev' => 200_000_000,
            'frequency_per_year' => 0.5,
            'source' => 'NEMA Data',
        ],

        [
            'id' => 'lib-8',
            'name' => 'Key Person Risk',
            'risk_category' => 'Operational Risk',
            'distribution_type' => 'lognormal',
            'description' => 'Losses from departure or unavailability of critical staff including treasury, IT, and compliance personnel',
            'mean' => 200_000_000,
            'std_dev' => 100_000_000,
            'frequency_per_year' => 2.5,
            'source' => 'Internal HR Data',
        ],

        [
            'id' => 'lib-9',
            'name' => 'Third-Party Vendor Failure',
            'risk_category' => 'Operational Risk',
            'distribution_type' => 'lognormal',
            'description' => 'Losses from critical vendor failures including payment processors, cloud providers, and network providers',
            'mean' => 600_000_000,
            'std_dev' => 400_000_000,
            'frequency_per_year' => 1.8,
            'source' => 'Industry Benchmark',
        ],

        [
            'id' => 'lib-10',
            'name' => 'Liquidity Stress - Deposit Run',
            'risk_category' => 'Liquidity Risk',
            'distribution_type' => 'lognormal',
            'description' => 'Losses from a bank run scenario triggered by social media rumors or macroeconomic instability',
            'mean' => 10_000_000_000,
            'std_dev' => 7_000_000_000,
            'frequency_per_year' => 0.2,
            'source' => 'CBN Stress Test Framework',
        ],
    ],

];
