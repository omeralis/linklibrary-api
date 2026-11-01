# AI processing and privacy

AI enrichment is **optional** (`AI_PROVIDER=null` disables it). Supported adapters: Anthropic Messages API
(`AI_PROVIDER=anthropic`) and any OpenAI-compatible Chat Completions endpoint (`AI_PROVIDER=openai`,
`OPENAI_ENDPOINT` may point at OpenAI or a self-hosted/compatible gateway).

## What is sent

Only **public, automatically extracted** information about the web page (`App\Services\Ai\AiInput`):
hostname, extracted title, extracted description, site name, a bounded text excerpt of the page
(≤ `AI_MAX_INPUT_CHARS`), declared language, detected source type, and the list of allowed category slugs.

## What is never sent

Private notes, custom titles/descriptions, tags or categories chosen by users, invitation details, e-mail
addresses, names, user identifiers, credentials, tokens, or any other account data. Analysis is per canonical
URL and shared between all users who saved it, so it cannot depend on any one user's private data.

## Safety of AI output

- Fetched content is treated as untrusted data and delimited; instructions inside it are ignored.
- Output must match a JSON schema and is independently validated: unknown categories, unsafe/overlong tags,
  HTML and invalid enums are dropped; malformed output is rejected and recorded as a failure.
- AI output is labelled (`ai.generated = true`) and never overrides user input
  (precedence: user override > extracted metadata > AI suggestion > fallback).
- AI can flag a page as *suspicious* (escalating its security status) but can never mark anything safe.
- Cost controls: daily request cap (`AI_DAILY_REQUEST_LIMIT`), input/output token limits, timeouts,
  retries with backoff, and skipping re-analysis when the input is unchanged.

## Suggested disclosure text (configure `BRAND_AI_DISCLOSURE_URL`)

> **EN:** To suggest summaries, categories and tags, we may send publicly available information from pages you
> save (such as the page title, description and an excerpt of its text) to a third-party AI provider. Your
> private notes and account details are never sent.
>
> **AR:** لاقتراح الملخصات والتصنيفات والوسوم، قد نرسل معلومات متاحة للعامة من الصفحات التي تحفظها (مثل عنوان
> الصفحة ووصفها ومقتطف من نصها) إلى مزوّد ذكاء اصطناعي خارجي. لا تُرسل ملاحظاتك الخاصة أو بيانات حسابك مطلقًا.
