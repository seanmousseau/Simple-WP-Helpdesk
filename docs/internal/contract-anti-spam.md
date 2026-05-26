# Contract: Anti-Spam

Spam-defense layer applied to **every** untrusted form action: submission, lookup, portal reply, portal close, portal reopen. Four methods, all server-side-verified.

> Touch this contract before adding a new method, changing where verification runs, or relaxing where it applies.

---

## Methods

Operator selects one in **Settings → Anti-Spam → Method**:

| Method (`swh_spam_method`) | Provider | Verification |
|---|---|---|
| `none` | — | No spam check |
| `honeypot` | — | Hidden field check |
| `recaptcha` (v2 checkbox) | Google reCAPTCHA v2 | POST to `recaptcha.google.com/recaptcha/api/siteverify` |
| `recaptcha` + `swh_recaptcha_type = enterprise` | Google reCAPTCHA Enterprise | POST to `recaptchaenterprise.googleapis.com`; score-based |
| `turnstile` | Cloudflare Turnstile | POST to `challenges.cloudflare.com/turnstile/v0/siteverify` |

---

## Where it applies

Anti-spam runs on **every** form action that an untrusted visitor can take:

| Action | Form | Why |
|---|---|---|
| Submit ticket | `[submit_ticket]` form | Bot-driven ticket spam |
| Look up tickets | `[helpdesk_portal]` lookup form | Email enumeration |
| Reply (portal) | Portal reply form | Even with a token, reply spam is possible if a token leaks |
| Close (portal) | Portal close action | Form-action abuse |
| Reopen (portal) | Portal reopen action | Form-action abuse |

Skipping anti-spam on any of these is a regression. The portal actions inherit it specifically because token leakage isn't a zero-probability event.

---

## Configuration storage

| Option | Method that uses it |
|---|---|
| `swh_spam_method` | All — selector |
| `swh_recaptcha_site_key` | reCAPTCHA |
| `swh_recaptcha_secret_key` | reCAPTCHA v2 |
| `swh_recaptcha_type` | reCAPTCHA (`v2` or `enterprise`) |
| `swh_recaptcha_project_id` | reCAPTCHA Enterprise |
| `swh_recaptcha_api_key` | reCAPTCHA Enterprise |
| `swh_recaptcha_threshold` | reCAPTCHA Enterprise (0.0–1.0; below = spam) |
| `swh_turnstile_site_key` | Turnstile |
| `swh_turnstile_secret_key` | Turnstile |

Defaults all blank — `none` is the safe fallback if keys aren't configured.

---

## Honeypot

Hidden field named like a real field but invisible to humans. Bots that auto-fill every field get caught. Zero user friction; no third-party network call.

- Field: `swh_hp_email` (intentionally plausible-looking).
- Rendered with inline CSS `position:absolute;left:-9999px;height:0;width:0;` plus `tabindex="-1"`.
- Submission with the field populated → reject with the configured "Spam Check Failed" message.

Honeypot is the recommended default for low-spam sites.

---

## reCAPTCHA v2 (checkbox)

Standard Google v2. Renders the "I'm not a robot" checkbox; submission body must include `g-recaptcha-response`. Server verifies via siteverify.

- We add the user's IP (`swh_get_client_ip()`) to the verification payload.
- A non-200 response from Google is treated as a verification failure (fail-closed).
- Network timeout: 5 seconds.

---

## reCAPTCHA Enterprise

Score-based. Browser produces a token; server creates an assessment via the Enterprise API; result includes a risk score 0.0–1.0.

- Score below `swh_recaptcha_threshold` → reject as spam.
- Default threshold: 0.5.
- Operator-tunable per their tolerance.
- Same fail-closed behavior on network errors.

---

## Cloudflare Turnstile

Privacy-friendly CAPTCHA — Cloudflare claims no third-party cookies, minimal data collection. Same flow as reCAPTCHA v2: token in `cf-turnstile-response`, server-verified via siteverify.

- We send the user's IP to Turnstile.
- Network timeout: 5 seconds.
- Fail-closed on errors.

---

## Invariants

1. **Server-side verification only.** Client-side claims are advisory — every form handler re-verifies on the server before any DB write.
2. **Fail-closed on provider errors.** If the verification HTTPS request fails or times out, the submission is rejected. Never silently succeed.
3. **No bypass for logged-in users.** Anti-spam runs even when a WP user is logged in. The portal actions for token-authenticated clients also run anti-spam.
4. **All five actions covered.** Submission, lookup, reply, close, reopen.
5. **Keys are read at request time**, not cached. Operator can rotate keys mid-flight.
6. **No fallback to a different method on failure.** If reCAPTCHA's API is down, the form is broken — we don't quietly degrade to honeypot. Operators are responsible for picking a method that works for their site.
7. **Anti-spam UI never blocks accessibility flows.** Both reCAPTCHA and Turnstile widgets are operated by their vendors; our wrapper does not interfere with their keyboard / screen-reader support.

---

## CSP / domain allowlist

For operators with Content Security Policy headers, the following domains must be allowed:

- reCAPTCHA: `https://www.google.com`, `https://www.gstatic.com`, `https://recaptchaenterprise.googleapis.com`
- Turnstile: `https://challenges.cloudflare.com`

Documented in end-user `docs/troubleshooting.md`.

---

## Failure modes

| Scenario | Behavior |
|---|---|
| Method = `none`, submission has data | Accepted (operator chose no protection) |
| Honeypot triggered | Rejected; "Spam Check Failed" message |
| reCAPTCHA token missing | Rejected |
| reCAPTCHA verification times out (>5s) | Rejected (fail-closed) |
| reCAPTCHA Enterprise score < threshold | Rejected |
| Turnstile token missing | Rejected |
| Operator configured a method but did not save keys | Form posts → rejected with a generic error; logged with a clearer message under WP_DEBUG |

---

## What integrators may rely on

- The five form actions above always run anti-spam.
- Verification is server-side and fail-closed.
- Honeypot stays available as a zero-network-dependency option.

## What integrators may NOT rely on

- The exact form-field name of the honeypot (it can change in any release).
- The wire format of provider verification responses — we own that surface.

---

## Routing

| Touching … | Read also |
|---|---|
| Adding a method | This doc + `config-reference.md` + `security-model.md` |
| Changing what forms anti-spam applies to | This doc + `contract-portal.md` + `contract-ticket-lifecycle.md` |
| Touching verification HTTP calls | This doc + `security-model.md` |
| Changing keys or threshold storage | This doc + `data-dictionary.md` |
| UI work on the spam selector | `design-guide.md` + this doc |
