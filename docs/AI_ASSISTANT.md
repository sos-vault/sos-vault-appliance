# AI Assistant (Mil)

**Mil** is the in-app chat assistant built into sos-vault. It answers questions about how to use the product, helps with general Linux troubleshooting, and — with a capable model configured — analyzes the sosreport you are currently viewing.

Mil can run against three kinds of backend:

| Backend | What it is | sosreport analysis? |
| --- | --- | --- |
| **Local model** (bundled) | A small model that runs on the appliance's own CPU. No internet, no third party. | **No** — general help only |
| **OpenAI** (cloud) | Your OpenAI account, via an API key you supply. | **Yes** |
| **Anthropic** (cloud) | Your Anthropic (Claude) account, via an API key you supply. | **Yes** |

> A fourth, self-hosted option exists for advanced operators: an on-prem **Ollama** server exposing an OpenAI-compatible endpoint, letting you run a large open model (Llama, Qwen, DeepSeek, …) entirely on your own hardware. If that model is big enough, it gets the full analysis budget too. This guide focuses on the local model and the two supported cloud providers.

## The bundled local model

Out of the box Mil uses a **small local model** that runs on the appliance CPU. The installer does **not** download it — it only prepares the model directory. You download the model file once from the admin UI after your first sign-in (the host needs outbound internet for that). Until it is downloaded, Mil has no local backend.

The local model is intentionally lightweight so it can run on the same box as everything else without a GPU. That comes with a hard limit on capability:

- **What it does well:** "how do I use sos-vault", where to find a feature, what a page does, and basic Linux questions.
- **What it cannot do:** analyze the sosreport you are looking at. Reasoning over a full sosreport means holding tens of thousands of tokens of host data in context and drawing correct conclusions from it — far beyond what a small CPU-sized model can do reliably. So for the local model, **live case analysis is disabled** and Mil will tell you it can only help in a general capacity.

If all you need is product help and you want to stay fully offline, the local model is enough — leave the provider set to **local** and you are done.

## Unlocking full sosreport analysis

To have Mil actually read and reason over the current case — summarize the host, spot problems, answer "why did this box OOM", correlate logs with memory pressure, and so on — point it at a capable model. On the appliance that means a **cloud provider**, and sos-vault supports two:

- **OpenAI**
- **Anthropic**

These are the only two supported cloud providers. When one of them is configured with a valid API key, Mil switches on case analysis and injects the relevant slices of the current sosreport into the conversation automatically.

### Configuring a provider

Everything is done from the admin UI — no editing files.

1. Sign in as an admin and open **Manage Settings → AI Assistant**.
2. Set **Provider** to `OpenAI` or `Anthropic`.
3. Paste your **API key** into the matching field (`OpenAI API key` / `Anthropic API key`).
4. (Optional) Override the **model** name. The defaults are sensible current models (`gpt-4o` for OpenAI, `claude-3-5-sonnet` for Anthropic); set a different model here if you prefer.
5. Save.

A few things worth knowing:

- **Keys are encrypted at rest.** The API key is stored encrypted in the database and is **never rendered back to the browser** after you save it — the field shows blank/masked on reload even though the key is set.
- **Env fallback.** If you leave the key field blank, sos-vault falls back to the `OPENAI_API_KEY` / `ANTHROPIC_API_KEY` environment variable, if present. The in-app setting is the recommended path.
- **Licensed feature.** On the appliance the AI Assistant settings section is part of the licensed tier — it is visible once a license is installed. See [`FEATURES.md`](FEATURES.md).

## How to obtain an API key

Provider dashboards change over time, but the shape of the flow is stable. In both cases you create an account, add a billing method (these are paid, usage-metered APIs — separate from any consumer chat subscription), then mint a key. **A key is shown only once**, so copy it immediately and paste it straight into Manage Settings.

### OpenAI

1. Go to `https://platform.openai.com` and sign in or create an account.
2. Add a payment method / credits under **Settings → Billing** (the API will not answer without a funded account).
3. Open **API keys** (`https://platform.openai.com/api-keys`) → **Create new secret key**.
4. Copy the key (it starts with `sk-…`) and paste it into the **OpenAI API key** field in sos-vault.

### Anthropic

1. Go to `https://console.anthropic.com` and sign in or create an account.
2. Add credits / a billing method under **Settings → Billing**.
3. Open **Settings → API Keys** (`https://console.anthropic.com/settings/keys`) → **Create Key**.
4. Copy the key (it starts with `sk-ant-…`) and paste it into the **Anthropic API key** field in sos-vault.

## Technical details

- **Where case data goes.** With the local model, nothing leaves the appliance. With a cloud provider configured, the relevant portions of the *current* sosreport are sent to that provider's API when you ask a case-analysis question — this only happens when `Inject case context` is enabled (it is, by default). If you would rather never send case data off-box, keep Mil on the local model or turn off case-context injection.
- **Outbound HTTPS.** Cloud calls are ordinary outbound HTTPS. On a proxied network they honour `HTTPS_PROXY`; keep internal hosts in `NO_PROXY`. See the "Outbound proxy" section of [`CONFIGURATION.md`](CONFIGURATION.md). If the proxy does TLS interception, upload its root CA via the Certificate Manager or the calls will fail validation.
- **Tunables.** `Max tokens`, `Temperature`, and a per-minute `Rate limit` are all in the same Manage Settings section and apply to whichever backend is active.
- **Air-gapped installs.** With no outbound internet the cloud providers are unavailable; Mil stays on the local model and provides general help only. A large on-prem Ollama model is the way to get analysis without reaching the public internet.

## Troubleshooting

- **Mil says it can only help in a general way / won't analyze the case.** You are on the local model. Configure OpenAI or Anthropic to enable case analysis.
- **"AI Assistant" section is missing in Manage Settings.** It is a licensed feature — install a license first (see [`LICENSE_REQUEST.md`](LICENSE_REQUEST.md)).
- **Cloud provider errors after saving a key.** Confirm the account is funded, the key was copied whole (they are shown once), and — on a proxied network — that `HTTPS_PROXY` and any interception CA are set. A malformed or unfunded key causes the request to fail; sos-vault falls back to the local model rather than erroring hard.
