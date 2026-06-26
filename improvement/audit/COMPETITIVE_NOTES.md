# NexusRisk IRM — Competitive Positioning for Nigerian Banking

One-line answers to the questions a CRO will actually ask, per AI capability. Use these as the "why this beats Archer" line when the client frowns.

## AI capability comparison

| Capability | Archer | MetricStream | LogicGate | ServiceNow GRC | **NexusRisk** |
|---|---|---|---|---|---|
| **Risk Statement Builder** | Add-on chatbot requiring OpenAI API key; every risk description leaves the bank | "Gen AI" module requires a cloud tenant; roadmap item for most SKUs | No native capability — JSON templates | "Now Assist" — cloud inference, per-seat uplift | **Local LLM on-prem**, no egress, sub-minute drafts, CBN data-residency clean |
| **Control Recommender** | Control catalogue with manual tagging | Library-based, no generative proposal | Library-based | Library + Now Assist summaries (cloud) | **Generative + clause-mapped** to ISO 27001, CBN RBCF, Basel OR, NDPA — with the model *instructed not to fabricate* clause numbers |
| **KRI Suggestion Engine** | Template library; no AI | Template library; no AI | Template library; no AI | Manual | **Proposes 3 leading + 2 lagging KRIs per risk, constrained to data the bank already collects** (core banking, treasury, channels, HR, call centre) |
| **Executive Narrative Generator** | Board Book export (static) | MetricStream Navigator AI — cloud-hosted, English-only | Custom reports | Flow Designer + Now Assist | **Generates from live DB counts every time, no data egress, writes in English appropriate for CBN-regulated Board audiences** |
| **Regulatory Mapping Assistant** | Policy Exchange content; US/EU bias | Regulatory Change Management module (per-seat) | No native | Cloud-based | **Ships with CBN, NDPC, NFIU, SEC, NAICOM baseline; on-prem; no subscription to a regulatory content vendor** |
| **Natural-Language Risk Query** | ArcherX experimental; cloud | Roadmap | No | Now Assist — cloud | **Planned next iteration; same on-prem LLM; no incremental licence** |
| **Regulatory Pulse (circulars)** | Content library updated by Archer (quarterly) | Thomson Reuters content (licence required) | No | Requires a content partner | **Operator-owned; updated in-house — first-mover advantage on CBN circulars before the international vendors catch up** |

## The one-sentence version

> **Archer** ships the best risk data model on the market with the worst UX and the slowest AI story. **MetricStream** ships a lot of modules and a cloud AI that is not comfortable sitting inside a CBN-regulated network. **LogicGate** and **ServiceNow GRC** are platforms, not GRC tools — you will build most of what you need. **NexusRisk** ships the Nigerian regulatory baseline, a navy-and-gold brand the board recognises, and a local AI that never talks to the cloud — all in a platform one bank-side analyst can administer.

## Objections and crisp answers

**"We already pay for Archer. What's the switching cost?"**
Data is exportable from Archer. Our register, control library, and KRI definitions import cleanly — we do it in the first week of the onboarding. The switching cost is three to six weeks of parallel run, not a re-platform.

**"Local LLM sounds slow."**
It is slower than GPT-4 on a per-request basis. It is also inside your network. The four features we showed today are cached for the top residual risks before you start your morning, so the *user experience* is near-instant.

**"How do we trust the AI output?"**
Every AI panel cites the numbers it was given (see `grounded_on` block on the Executive Narrative). We never ask the model to invent clause references it is not confident about — the system prompt explicitly allows it to return `null` rather than fabricate. The CRO reviews and signs off; the AI drafts, it does not sign.

**"What about CBN data residency?"**
No inference happens outside the perimeter. The LLM endpoint is `http://localhost:11434`. Your InfoSec team can verify with a packet capture during the pilot.

**"We need NDPA 2023 compliance."**
Controls in the library are tagged to NDPA clauses where the model is confident of the mapping. Subject-access, lawful basis, and breach-notification workflows are in the Issues module — the SOP is in the platform.

**"Archer gives us Basel II/III alignment out of the box."**
So do we — and we add CBN ORMS event taxonomy, which Archer does not ship natively for Nigerian banks.

**"What if the model hallucinates?"**
You will see us constrain it in three ways: (1) temperature 0.2, not 0.7 — the model is boring, which is the goal; (2) system prompt instructions that explicitly forbid fabrication; (3) mandatory fields validation on the server so incomplete responses return a fallback rather than a broken form. If you find a hallucination in a pilot, we fix the prompt. No retrain, no vendor ticket.
