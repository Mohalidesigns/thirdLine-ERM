# NexusRisk IRM — Client Demo Script

**Audience**: Nigerian commercial bank C-suite (CRO, CFO, Head of Compliance, Head of Internal Audit).
**Duration**: 25–30 minutes, plus Q&A.
**Premise**: You are demonstrating to a mid-sized Nigerian commercial bank that is currently running an Archer or MetricStream install they consider expensive and inflexible.

## Pre-demo setup (15 minutes before)

```bash
# 1. Make sure the local LLM is up
curl -s http://localhost:11434/api/tags | jq .

# 2. Warm the model and pre-generate AI responses for the top 5 risks
php artisan llm:warm
php artisan ai:warm-cache   # takes ~10-15 min on CPU - run BEFORE client arrives

# 3. Boot the server
php artisan serve --host=0.0.0.0 --port=8765 --no-reload

# 4. Open a clean incognito window, navigate to http://<demo-host>:8765
#    Log in as admin@risk.test / password
```

If granite is NOT pre-warmed, the first Risk Statement Builder click will take ~30 s. The room goes quiet. Pre-warm.

## Click path (suggested — adapt to the conversation)

### Act 1 — Enterprise posture (4 min)

1. **Command Centre** (`/risk/dashboard`)
   - **Say**: "This is the single pane of glass the CRO opens on Monday morning. Everything below is live — no refresh cron, no ETL lag."
   - **Point at**: 4 Critical residual risks, 3 KRI breaches (red threshold), ₦278 M YTD net loss, 8 open issues.
   - **Click on** one of the KPI cards to show it drills into the register.
   - **Key talking point**: "Archer takes a month of PSO time to change a KPI threshold. Our thresholds are configured by the bank — no vendor dependency."

2. **Heatmap** (`/risk/analysis/heatmap`)
   - Toggle Inherent / Residual.
   - **Say**: "A 5×5 matrix that most GRC tools ship. What we add is that every cell is clickable — the bubble shows the actual risk codes in that cell. One click to drill."

### Act 2 — Risk intake powered by local AI (5 min)

3. **Risk Register → New Risk** (`/risk/register/create`)
   - **Say**: "Registering a risk is the first pain point every bank hits. Let me show you what a risk analyst types in a real RCSA workshop."
   - Type in the AI banner: *"phishing attack on retail tellers stealing customer OTP tokens"*
   - Click **Draft with AI**.
   - While it thinks: "This is running on a local LLM on the bank's own hardware. Nothing is leaving the premises. CBN's data residency position is being increasingly assertive — everything you're seeing the model consider stays inside this box."
   - When the title + Cause/Event/Consequence populates: "The model picked up the scenario, wrote a board-ready statement, and flagged CBN and SEC as the regulators likely to care. The analyst now just reviews and saves."
   - **Competitive hook**: "Archer's ChatGPT integration streams to OpenAI. This does not."

4. **Back to the risk detail** — click any Critical-rated risk.
   - **Say**: "Once a risk is on the register, the AI has four jobs per risk."
   - **Call out**: residual rating badge, treatment strategy, control effectiveness.
   - **If the UI for Control Recommender / KRI Suggester is in**: click those buttons and narrate. If it isn't, hit the endpoint from the browser dev console and show the JSON — it's real.

### Act 3 — Operational loss lifecycle (5 min)

5. **Loss Events Dashboard** (`/risk/loss-events/dashboard`)
   - **Point at**: Total Events 5 YTD, Total Gross Loss ₦290 M, 2 Pending NFIU Filings (Requires filing), 4 Open Investigations, 8 Near Misses.
   - **Say**: "Banks in Nigeria are required to report qualifying loss events to CBN under the ORMS framework. When a loss is reportable, the platform flags it here with the countdown — 3 days from date of loss by default."

6. Click into a loss event, show the RCA workflow.
   - **Say**: "Root-cause is captured once, not three times — the same data feeds the CBN ORMS return, the Basel LDC template, and the board paper."

### Act 4 — Indicators, appetite, and the board (5 min)

7. **KRI Monitoring** (`/risk/kri/dashboard`)
   - **Point at**: 10 KRIs live, 3 Red, 9 Amber, Avg Health Score 10 %.
   - Click through to a red KRI (e.g. NPL Ratio) — show the 12-month trend with breach markers.
   - **Say**: "Every KRI is tied to a risk. The CRO doesn't hunt for which risk is breaching — the indicator tells them."

8. **Risk Appetite** (`/risk/appetite`)
   - Show the Board-approved appetite statement with tolerance bands and current position.
   - **Say**: "This is what the CBN examiner asks for. You no longer export it to Excel the week before — it's evergreen."

9. **Executive Report** (`/risk/reports/executive`)
   - Scroll through: Total Active 21, Critical 17, Treatment Completion, KRI Breaches, Risk Appetite Status.
   - **If Executive Narrative button is in**: click it. Wait ~30 s. Read the generated headline + summary aloud. "That's our granite model writing the board paper for this quarter, using the exact numbers on this page."

### Act 5 — Regulatory and close (4 min)

10. **Regulatory Dashboard** (`/risk/regulatory/dashboard`)
    - **Say**: "We track CBN, NFIU, SEC, NDPA, and NAICOM in one calendar. Quarterly Credit Risk Return, Semi-Annual ICAAP, NDPR reviews — the deadlines, the filings, the acknowledgement letters."
    - Point to 6 Active Circulars, 3 Overdue Deadlines.

11. **Close by opening two browser tabs side by side**:
    - Tab 1: Command Centre.
    - Tab 2: `/risk/ai/regulatory-pulse`.
    - **Say**: "Monday morning: one pane of glass, one local AI, one Nigerian regulatory context. That is the ask we've heard from every bank in your peer group. Thank you."

## Things NOT to do in the demo

- **Do not** click Risk Statement Builder without pre-warming. Thirty seconds of silence kills the room.
- **Do not** try to create a new treatment plan during the demo — the form flow has not been audited end-to-end.
- **Do not** scroll to the bottom of the Quantification dashboard — the ₦ values in the KPI cards truncate at the container edge. Cosmetic, but visible.
- **Do not** open `/risk/analysis/bowtie` without first selecting a risk — the empty state is bland.

## If the client asks "can we see it fail gracefully?"

Stop the Ollama container (`docker stop ollama` or kill the process). Click the AI Risk Statement Builder. The red banner appears: *"AI unavailable. You can fill the form manually below."* The rest of the page keeps working. Restart Ollama and click again — it recovers.

That fallback posture is the answer to every CIO asking "what if the AI is down on quarter-close?".
