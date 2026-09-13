# ADR 0010 — BCMS AI runs on the product's own LLM service, not Laravel Prism

**Status:** Accepted · **Date:** 2026-09-08 · **Phase:** BCMS Phase 2 · **Author:** architect
**Consumers:** every phase with an AI capability — 2, 3, 9, 11, 12

## Context

The blueprint's stack line names **Laravel Prism (Claude)**, and Blueprint §12
lists eight AI capabilities across the module. Phase 2 ships the first of them.

Prism is not installed in this product and never has been. What is installed, and
what every existing AI feature already uses, is `App\Services\LlmService` — a thin
client for a locally hosted **Ollama-compatible** model, configured under
`services.llm`, with `LLM_ENABLED` defaulting to **false**. TPRM wraps it in
`App\Services\Tprm\Extraction\LlmClient`, which is the pattern this ADR adopts.

## Decision

**BCMS AI goes through `App\Services\Bcms\Ai\BcmsLlmClient`, which wraps
`LlmService`.** No Prism, no second HTTP client, no direct calls to a model from
anywhere else in the module.

This is not only a "reuse what exists" decision, and it is worth being explicit
about why, because it looks like a downgrade and is not:

**A locally hosted model is the right shape for this market.** Blueprint §1.2
sells the module on African infrastructure — "the network is the first thing to
fail" — and §14 requires an on-prem deployment package because *banks will ask*.
A BIA contains a bank's complete map of what it depends on and how long it can
survive without each piece. That document going to a third-party API over a link
that fails is a harder conversation with a Nigerian bank's data protection
officer than any feature is worth. An architecture that can run entirely inside
the customer's estate is the one this product can actually sell.

**The kill switch is checked in one place.** `BcmsLlmClient::enabled()` reads the
tenant's `bcms_settings.ai_enabled` **and** the per-capability flag under
`config('bcms.ai.capabilities')`. A caller cannot forget, because a caller cannot
reach a model any other way. When it is off the client returns a refusal rather
than throwing — a disabled optional service is not an error, and a BIA workspace
that 500s because AI is off has failed worse than one with no AI at all.

**Every AI output lands as a draft and cannot approve itself** (standing rule 4).
`ai_generated` is set, `ai_drafted_at` is stamped, `ai_reasoning` records what the
model said and why, and `BiaAssessmentService::approve()` requires a human actor
that is not the drafter. Phase 2's acceptance criterion 5 is exactly this.

## Consequences

- Swapping in a hosted model later is a change to `LlmService`'s driver, not to
  BCMS. The wrapper's contract is `available()`, `json()`, `complete()`.
- **The demo tenant ships with AI off.** `bcms_settings.ai_enabled` defaults
  false and `LLM_ENABLED` defaults false, so a fresh install has no AI at all and
  every workflow completes manually. That is a requirement, not a limitation:
  a module whose BIA cannot be filled in without a model is a module a bank
  cannot buy.
- Quality expectations differ from a frontier model's, and the design accounts
  for it: the drafter proposes and *shows its reasoning*, the human decides, and
  the validator (which is deterministic) checks the result either way. Nothing
  the model produces reaches an approved assessment without passing the same
  rules a typed answer does.
