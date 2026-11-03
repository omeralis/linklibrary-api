# AI integration plan (free-tier, no local LLM)

Updated 2026-11-03. Replaces any earlier plan that mentioned a self-hosted model.

## 1. Constraints

| Item | Value |
|---|---|
| Production VPS | 2 GB RAM (~1.77 GB already used), 2 vCPU, 50 GB disk |
| Local LLM / Ollama | **Not installed, not supported.** No code path starts or calls a local model. |
| Cost | **Free only.** No paid billing, no paid fallback, no automatic upgrade. |
| Architecture | Existing Laravel backend + `ai` queue. The Next.js app never sees a key. |
| Out of scope (unchanged) | Security scanning stays "Coming Soon"; the approved temporary sharing policy is untouched. |

## 2. Verified provider facts (checked 2026-11-03)

### Google Gemini API, free tier — recommended, with conditions

| Topic | Finding | Source |
|---|---|---|
| Free models | Free of charge for input and output: Gemini 3.5 Flash-Lite, 3.1 Flash-Lite, 3.6 Flash, 3.8 Flash (and audio/live models). Pro and image models are paid only. | [Pricing](https://ai.google.dev/gemini-api/docs/pricing) |
| Quota numbers | Google **no longer publishes** free-tier numbers in its docs; they are shown in AI Studio. Third-party measurements (2026-09 and 2026-10) report **15 RPM / 500 RPD** for the two Flash-Lite models. **Treat as unverified**; the app's own caps are set well below them. | [Rate limits](https://ai.google.dev/gemini-api/docs/rate-limits), [measurement](https://aiplug.work/en/blog/gemini-free-tier-limits), [PR #176](https://github.com/mnfst/awesome-free-llm-apis/pull/176) |
| Scope and reset | Limits apply **per project**, not per key. Daily quota resets at **midnight Pacific time**. Specified limits are "not guaranteed". | [Rate limits](https://ai.google.dev/gemini-api/docs/rate-limits) |
| Commercial use | Allowed for "professional or business purposes, not consumer use", for unpaid and paid services. | [Additional Terms](https://ai.google.dev/gemini-api/terms) |
| Data handling (free) | Content is used to **improve Google products**; **human reviewers may read** input/output (disconnected from account/key/project). "Do not submit sensitive, confidential, or personal information." Paid services do not use content for product improvement. | [Additional Terms](https://ai.google.dev/gemini-api/terms) |
| Regions | In the **EEA, UK and Switzerland**, API clients may be made available to users **only with Paid Services**. | [Additional Terms](https://ai.google.dev/gemini-api/terms) |
| Age | The API client must not be directed at, or likely to be accessed by, people under 18. | [Additional Terms](https://ai.google.dev/gemini-api/terms) |

**Verdict: suitable for Arabic and English summaries, categories and tags, on three conditions the owner must accept before enabling it** (this is not legal advice):

1. The audience is outside the EEA, UK and Switzerland (or the owner accepts the risk). The app cannot reliably detect user region.
2. Only public page data may be sent. This is already the design (`AiInput`: host, extracted title/description/site name, a bounded excerpt). No notes, emails, names or user ids. Update the public AI disclosure text (`BRAND_AI_DISCLOSURE_URL`) to say the provider may use it to improve its products.
3. The product is not aimed at under-18 users.

The provider stays **disabled** until `AI_GEMINI_FREE_TIER_ACKNOWLEDGED=true` is set deliberately.

### Groq free plan — evaluated as the alternative, not enabled

| Topic | Finding | Source |
|---|---|---|
| Limits (free/developer) | `openai/gpt-oss-20b`, `gpt-oss-120b`, `qwen/qwen3.8-27b`: 30 RPM, 1K RPD, **8K TPM, 200K TPD**. 429 with `retry-after`; limits are per organisation. | [Rate limits](https://console.groq.com/docs/rate-limits) |
| Data | No retention of inference data by default; inputs/outputs may be logged up to 30 days for reliability/abuse; Zero Data Retention can be switched on. The page does **not** state whether Groq trains on customer data. | [Your data](https://console.groq.com/docs/your-data) |
| Commercial terms of the free plan | **NOT VERIFIED** (the legal pages must be read by the owner). | [Legal index](https://console.groq.com/docs/legal) |

The 8K tokens-per-minute cap means roughly 2 analyses a minute and about 60 a day (200K TPD). Use it only if Gemini is rejected (for example because of the EEA condition or the data terms). It works today without new code through the OpenAI-compatible adapter:
`AI_PROVIDER=openai`, `OPENAI_ENDPOINT=https://api.groq.com/openai/v1/chat/completions`, `OPENAI_API_KEY=<groq key>`, `OPENAI_MODEL=openai/gpt-oss-20b`, `AI_REQUESTS_PER_MINUTE=2`, `AI_DAILY_REQUEST_LIMIT=60`, `AI_QUOTA_TIMEZONE=UTC`.
Switching provider is a manual configuration change; the code never falls back by itself.

## 3. What was implemented

* `GeminiProvider` (`AI_PROVIDER=gemini`): `generateContent` with JSON output; key in the `x-goog-api-key` header (never in a URL, never logged); 20-30 s timeout; classifies 429 / 5xx / 401-403 / blocked / truncated output.
* `AiQuotaGuard`, applied to **every** provider before a request is sent:
  * `AI_REQUESTS_PER_MINUTE` (default **8**), `AI_DAILY_REQUEST_LIMIT` (default **300**, counted per quota day in `AI_QUOTA_TIMEZONE`, default `America/Los_Angeles`),
  * **one request in flight** (cache lock), timeout `AI_TIMEOUT` (default 30 s), input capped by `AI_MAX_INPUT_CHARS`, output by `AI_MAX_OUTPUT_TOKENS`,
  * a shared "blocked until" marker when the provider itself returns 429.
* **Quota exhausted → wait, never skip, never pay.** The link stays `PENDING`; the job is released until the reset (daily: next midnight Pacific + 2 min; per-minute: provider `retryDelay`, minimum 60 s). Waiting does not consume retry attempts; real failures still stop after `AI_MAX_ATTEMPTS`. No other provider is tried.
* API: `processing.ai_wait = { code: "AI_QUOTA_EXHAUSTED", retry_at }` while waiting, and the link shows as ready. UI (ar/en): "AI suggestions are paused because today's free quota has been used… resume automatically around {time}".
* Safety unchanged: AI output validated, never marks anything safe, never overrides user input.
* `linklibrary:doctor` prints the AI limits and, in production, fails when mail is `log`/`array` or the sender domain is a placeholder.

## 4. VPS resource plan (2 GB RAM)

No new service is added. Gemini calls are outbound HTTPS only. Run **one** queue worker for all queues with a memory cap (`php artisan queue:work --queue=security,extraction,ai,default --memory=128 --max-time=3600 --sleep=2`) under the existing supervisor/systemd unit, plus the existing scheduler cron. With a 1.77 GB baseline, keep `QUEUE_CONNECTION=database` (or the existing Redis) and do not add a second worker; the AI lock already limits concurrency to 1.

## 5. Owner setup (exact steps; no credential is stored in the repo)

1. Sign in to [Google AI Studio](https://aistudio.google.com/) and create a key in a project that has **no billing account attached**. Confirm the key shows the free tier. Never link billing to this project (that would end free-tier handling).
2. On the VPS edit `.env` (permissions `600`, never committed):
   ```
   AI_PROVIDER=gemini
   GEMINI_API_KEY=<paste the key here>
   GEMINI_MODEL=gemini-3.5-flash-lite
   AI_GEMINI_FREE_TIER_ACKNOWLEDGED=true   # only after accepting section 2 conditions
   AI_REQUESTS_PER_MINUTE=8
   AI_DAILY_REQUEST_LIMIT=300
   ```
3. `php artisan config:cache && php artisan queue:restart`, then `php artisan linklibrary:doctor` (it must show the provider and limits).
4. Save one link and watch the `ai` job; if the daily cap or Google's quota is hit you will see the "paused" notice and the job resumes after the reset.
5. Rotate the key in AI Studio if it is ever exposed.

## 6. Status

| Item | Status |
|---|---|
| Backend tests (new: provider, guard, quota wait, daily cap) | 244 passed on PostgreSQL 16 (`linklibrary_test`) |
| Live Gemini and Groq calls | **NOT VERIFIED** (no key; tests use HTTP fakes) |
| Real quota numbers of the owner's project | **NOT VERIFIED** (visible only in AI Studio) |
| SMTP delivery | **NOT VERIFIED** (no credentials; log mailer only) |
| Queue and scheduler | Verified in the sandbox: backlog drained, heartbeat green, no failed jobs |
| Production VPS | Not touched |
