<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Feature Flags
    |--------------------------------------------------------------------------
    |
    | Flags gate surfaces that are not yet fit to be seen by a customer. A flag
    | defaults to FALSE so that a fresh environment — including production —
    | never exposes the surface by accident. Turning one on is a deliberate act
    | recorded in that environment's .env file.
    |
    | Routes are gated with the `feature:<key>` middleware, which aborts 404
    | (not 403) when the flag is off: a disabled surface should be
    | indistinguishable from one that does not exist.
    |
    */

    /*
     * AI Intelligence (Predictive / Radar / Regulatory Pulse).
     *
     * These screens previously generated user-facing figures with mt_rand() and
     * hardcoded model-performance metrics. They have since been rebuilt on the
     * organisation's own data, but the flag stays default-off so that each
     * environment opts in only once someone has looked at the output and
     * confirmed the underlying tables are populated enough for it to be
     * meaningful. Every number these screens now show is traceable to a query —
     * see docs/ai-number-provenance.md.
     */
    'ai_intelligence' => env('FEATURE_AI_INTELLIGENCE', false),

];
