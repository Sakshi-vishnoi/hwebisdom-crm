# AI Research + Outreach Drafting Agent for Webisdom B2B CRM

## Overview
The AI Research & Draft Agent on `/admin/email_send.php?lead_id=<id>` automates executive-level intelligence gathering and generates tailored outreach emails based **strictly on approved Webisdom credentials and verified research facts**.

The AI **never sends emails automatically**. A human sales consultant reviews, edits, and sends every email.

---

## Architecture & Workflow

### Stage 1: CRM Data Collection (No AI)
Gathers lead profile (`name`, `designation`, `company`, `email`, `contact`, `website`, `industry`, `city`, `pipeline stage`, `notes`, `source`, `owner`) along with chronological communication history (`email_messages`, `lead_followups`, `meetings`, `documents`, `activities`).
- If previous conversations or meetings exist, the draft automatically acknowledges the history (e.g. *"Following up on our conversation on..."*).

### Stage 2: Deep Agentic Research Loop
The agent conducts targeted intelligence gathering across:
1. **Company Profile & Segment**: What they do, products/services, segment (Bank / NBFC / Insurer / Fintech / Wealth / Enterprise), size, HQ, recent 12-month events.
2. **Website & Digital Footprint**: Safe server-side crawl (SSRF-protected) detecting mobile app presence, customer portals, KYC workflows, chatbot widgets, and tech stack.
3. **Key Decision Maker**: Public professional role and tenure.
4. **Industry & Regulatory Context**: RBI / IRDAI / SEBI compliance trends, digital lending guidelines, data governance.
5. **Competitor & Peer Benchmarks**: Digital maturity compared to sector leaders.
6. **Top 3 Verified Hooks**: Real, verifiable connection points linking their priorities to Webisdom's capabilities.
- **Rules**: Every fact must cite a verified source URL. If not found, it is recorded as `not found`. Never hallucinates or guesses.
- **Engine Options**:
  - **Gemini API** with native Google Search Grounding (`GEMINI_API_KEY` in `.env`).
  - **Built-in Autonomous Web Research Engine** (fallback if no API key is set or offline).

### Stage 3: Approved Template Filling & Tailoring
The agent fills and tailors `/config/email_templates/bfsi_intro.txt` (or `/config/email_templates/general_intro.txt` for non-BFSI leads) governed strictly by `/config/approved_claims.php`.
- **Allowed Claims**: Led by IIM alumni, 12+ years experience, BFSI vertical expertise.
- **Allowed Client References**: ONLY HDFC, KPMG, PolicyBazaar. No other client names are ever permitted.
- **Allowed Services**:
  1. AI Automation & Intelligent Workflows
  2. Data Engineering & Analytics
  3. CRM Development & Implementation
  4. Performance & Digital Marketing
  5. Web & App Development
- **Tailoring**:
  - Inserts 1-2 sentence neutral, respectful personalisation line based on `dossier.top_hooks[0]`.
  - Reorders the 5 services so those matching the lead's digital gaps come first.
  - Tailors the 5 challenges to industry norms ("what we commonly see in the industry", never "you have problems").
  - Output is strictly plain-text (`-` bullets, no markdown, no emojis, uppercase headers).

### Stage 4: Validation Engine
Before returning to the user, PHP validates:
1. No `[placeholders]` remain.
2. No unapproved client names or claims outside `approved_claims.php`.
3. Personalisation line maps directly to a verified dossier entry with source citation.
4. Body is non-empty and contains the authenticated sender's name.

---

## Security & Reliability Controls
- **SSRF Protection**: Server-side URL fetching blocks localhost, private subnets (10.0.0.0/8, 172.16.0.0/12, 192.168.0.0/16, 127.0.0.0/8), cloud metadata (169.254.169.254), and enforces a 1MB size limit and 6s timeout.
- **Asynchronous Execution**: Polling via `POST /admin/ajax/ai_email_draft_start.php` and `GET /admin/ajax/ai_email_draft_status.php?job_id=...` prevents web server timeouts.
- **Rate Limiting**: Limited to 10–15 drafts per user per hour.
- **7-Day Dossier Caching**: Saves AI cost by caching research dossiers per lead for 7 days (with a "Bypass Cache / Re-research" option).
- **Sent Tracking**: When an email is actually sent, `was_sent=1` is recorded in `ai_email_drafts`.

---

## Configuration

In `.env`:
```dotenv
AI_PROVIDER=gemini
GEMINI_API_KEY=your_gemini_api_key_here
GEMINI_MODEL=gemini-2.0-flash
```
*(If no `GEMINI_API_KEY` is provided, the system seamlessly operates using its built-in Autonomous Web Research & Verification engine).*
