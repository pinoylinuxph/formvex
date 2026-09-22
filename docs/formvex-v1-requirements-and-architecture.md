# Formvex V1.0 — Requirements and technical architecture

> Product classification and release gate: V1.x is **Formvex Community**. Every release must pass the applicable cross-version security verification policy in [Formvex product editions and security](formvex-product-editions-and-security.md).

Status: consolidated review draft; no application implementation or performance testing has occurred.

Prepared: 2026-09-21.

## 1. Authority and scope

This specification consolidates the [original conceptualization](formvex-conceptualization.md) and [discussion/decision record](formvex-design-discussions.md). Explicit later user decisions take precedence over the original document. Decision IDs D01-D62 and the review-resolution history in that record provide traceability.

Release boundary: this document covers V1.0 only. V1.1 is a separate release being explored in the [V1.1 planning record](formvex-v1.1-design-discussions.md); its candidate improvements do not alter this baseline unless explicitly agreed.

Sections marked **Engineering recommendation** propose implementation choices; they are not additional user agreements. Resolve material contradictions before implementation. The planned questionnaire is complete; do not restart it to poll routine engineering details.

Formvex is a self-hosted form-processing and email-delivery application for existing HTML websites, intended for community reuse through PinoyLinux. The first integration target is the local production source for logoslab.xyz. A hosting administrator installs and maintains the backend; a beginner can perform normal configuration in the browser.

### Included

- PHP backend, Vanilla JavaScript client, SQLite storage, independent installation per website.
- Multiple selected forms within one website, including explicitly configured bare-domain/www aliases.
- Page-by-page form discovery, administrator-approved mapping and trusted validation.
- Local admin portal, one administrator, server-only bootstrap/recovery.
- One SMTP account per installation; exactly one configured recipient per form. External email forwarding can distribute to more recipients.
- Standard HTML and plain-text email composition, configurable subjects, mapped visitor Reply-To.
- Durable submission storage, background sending, retries, failed/uncertain delivery review, explicit resend.
- Spam-folder classification with tagged email delivery; mandatory validation, rate limits, optional per-form CAPTCHA, honeypot.
- Submission review, CSV export, retention, seven-day manual-deletion Trash, storage warnings.
- Ready-to-upload ZIP, manual upgrades, server-side backup/restore.

### Deferred

Attachments, custom widgets, third-party embedded forms, visitor confirmation emails, multiple SMTP accounts, multiple recipients within a form, answer-dependent routing, visual form/template builders, keyword databases/content-scanner integration, multiple admins/roles, automatic application updates, centralized Hub/Console. Docker is not a V1 prerequisite or promised initial deliverable.

MIT licensing is agreed. The author's legal copyright-holder text remains a release-time input. Dependencies retain their own license obligations.

## 2. End-to-end user flows

### Installation and initial configuration

1. Hosting administrator deploys the ZIP so only public assets/front controller are web-accessible; private code, database, credentials, backups, and logs stay outside public paths.
2. Run preflight checks, initialize SQLite/schema, provision the recurring PHP task, and create the administrator through a server-side bootstrap command.
3. Bootstrap displays a unique temporary password. First portal login requires replacement before configuration access.
4. Administrator configures website name, exact host aliases, SMTP transport, sender, and a separate operational-alert address.
5. Add the client reference to relevant website pages. Disable competing submission handlers and update any obsolete visitor instructions.
6. In the portal, enter a page URL, start authorized discovery, select forms, review field mappings/validation, and set each recipient/subject.
7. Complete an SMTP test before first activation. Submit a real end-to-end test and verify receipt and stored status.

The URL `/formvex` is the intended local portal location; exact route prefixes are configurable engineering details. A ZIP does not make PHP available on static-only hosting. Supported hosting needs PHP web/CLI execution, SQLite extensions, local writable private storage, a scheduler, HTTPS, and outbound SMTP/optional CAPTCHA connectivity.

### Visitor submission

1. Client attaches once to approved forms, preserving styling and buttons.
2. Collect supported named controls, perform browser validation, disable submission while processing, and assign a stable identifier for this submission attempt.
3. Server checks request size, rate limits, form configuration/version, trusted field rules, and enabled CAPTCHA. Honeypot classification does not bypass these checks.
4. Atomically save accepted data and its queued delivery record. Only after commit return a receipt acknowledgement.
5. Display a configurable acknowledgement such as “Thank you. Your message has been received.” Clear fields only on confirmed acceptance; preserve input on errors.
6. Background processing delivers email; SMTP problems appear in the portal, not the visitor acknowledgement.

Repeated identical attempts after a lost response return the original acknowledgement without another email job. No receipt claim is made if storage fails.

**Visitor error feedback (D62):** when a network failure prevents confirmation, show a clear on-page connection/confirmation error without requiring login. Do not invent an HTTP response or assert that nothing was saved when the response is missing. Server-side submission failures must return an appropriate HTTP 5xx status, and the installed script must show a readable failure message rather than rely on the browser to display the HTTP status automatically. Do not expose stack traces or private server details. This concerns submission acceptance, not later SMTP delivery failures after durable acceptance, which remain administrator-only. Exact copy and status mapping are proposals; the anonymous retry-window duration remains unapproved.

**Latest feedback boundary:** keep other operational problems silent to visitors and clear to administrators. After durable acceptance, delivery failures, retries, and uncertain delivery outcomes must not replace the acknowledgement or require visitor action. Provide actionable portal status and available failure details using existing alert policies; never expose secrets. Retain required validation/CAPTCHA/rate-limit feedback for rejected submissions, without claiming acceptance. A request that never reaches Formvex cannot be reliably reported to its administrator.

### Administrator operations

Portal supports configuration, discovery, activation/disable, submission viewing by form/classification/delivery state, Mark handled, Not spam, explicit resend, CSV export, Trash/restore, and operational warnings. Show SMTP acceptance as “Sent to SMTP,” not confirmed inbox delivery. **Review decision:** Mark handled does not cancel or pause queued delivery/retries; it only changes review/retention state.

Disabling a form stops new intake but existing queued deliveries continue. Changing recipients affects new submissions only; accepted messages retain their recipient snapshot.

Recovery requires a server-side command with authority over the installation. Reset the existing admin, invalidate sessions, issue a temporary password, and force replacement. No public password-recovery page or SMTP-dependent recovery.

## 3. Proposed technology choices

**Engineering recommendation:** one PHP application with a clear internal domain boundary, not multiple services.

| Component | Recommendation | Reason |
| --- | --- | --- |
| Runtime | PHP 8.4 minimum; test 8.4 and 8.5 | Avoid targeting PHP 8.2 near its stated end of security support; preserves a supported baseline. |
| HTTP/admin shell | Symfony 7.4 LTS, server-rendered templates | Existing routing, security, validation, console tooling; avoid bespoke authentication infrastructure. |
| Core | Framework-independent PHP services/interfaces | Keep processing reusable for a future Hub. |
| Email | Symfony Mailer with explicit SMTP transport | Fits the proposed framework; do not implement SMTP manually. PHPMailer remains a viable alternative, not a second required transport. |
| Persistence | SQLite through PDO; explicit migrations/repositories | A local database and durable queue without a separate database service. |
| Client | Vanilla JavaScript, no required frontend framework | Preserve agreed minimal integration. |
| Worker | PHP CLI scheduled every minute | Accepted approximately one-minute normal delivery latency, no mandatory persistent worker. |
| CAPTCHA | Cloudflare Turnstile, optional per form | One provider for V1; see comparison below. |
| Distribution | Composer-built release ZIP with dependencies | No Composer/npm installation required from the end administrator. |

These choices revise the initial Fastify/Nodemailer/Ajv/Pino proposal. Exact dependency patch versions must be locked and security-reviewed during implementation. Composer requirements and preflight checks must verify all actual extensions; proposed baseline includes PDO SQLite, mbstring, OpenSSL, cURL, and sodium.

PHP's published schedule lists security support through 2028 for 8.4; Symfony 7.4 is an LTS branch supporting PHP 8.2 or newer. Those facts inform, but do not establish, the project recommendation. [PHP support](https://www.php.net/supported-versions.php), [Symfony 7.4](https://symfony.com/releases/7.4)

**CAPTCHA comparison:** Turnstile's published free plan permits unlimited challenges, up to 20 widgets, and 10 hostnames per widget; it can be used without other Cloudflare services. Google's reCAPTCHA FAQ describes a 10,000-assessment monthly free tier. Prefer Turnstile for the proposed volume without assuming plan limits never change. Administrator supplies their provider keys; CAPTCHA being off must remove that service dependency for submissions. [Turnstile plans](https://developers.cloudflare.com/turnstile/plans/), [reCAPTCHA FAQ](https://developers.google.com/recaptcha/docs/faq)

## 4. Component boundaries

```text
Existing website -> Formvex Client -> HTTP submission adapter
                                           |
                                  Core acceptance service
                                           |
                                   SQLite + queued work
                                           |
                              Scheduled worker -> SMTP adapter

Admin portal -> configuration/discovery/review services -> SQLite
Server CLI   -> bootstrap/backup/migration/worker services
```

Core owns field validation, classification, recipient snapshots, composition inputs, processing results, and delivery policy. It does not inspect website HTML, read HTTP globals, render the portal, or load installation secrets directly. Adapters supply repositories, clock, transport, and trusted configuration. HTTP layer owns request parsing, origin policy, sessions/CSRF, and response formatting; client owns DOM interaction.

Avoid speculative multi-tenant infrastructure in V1. Stable form IDs, configuration versions, transport interfaces, and installation-bound data enable future reuse without promising a rewrite-free Hub.

## 5. Discovery, field mapping, and validation

**Agreed:** discovery is page-by-page through the installed client, followed by administrator selection/review/activation. Reuse unique stable IDs; request a `data-formvex` marker if necessary. Never guess silently between ambiguous forms.

**Engineering recommendation:** launch discovery in a top-level page, avoiding an iframe requirement. Use a short-lived, single-use discovery grant bound to the admin session, expected origin, exact page URL, and operation. Prefer a fragment-carried grant, remove it before collection, never put the admin session/password in the page URL. Separate this operation from ordinary visitors. Discovery collects metadata only, never entered field values. Treat metadata as untrusted until reviewed. No server-side arbitrary-URL crawler.

For bare-domain/www aliases, configure exact HTTPS origins. Prefer relative same-origin public submission routes on each alias; keep the admin session on a canonical admin origin. If the API is cross-origin, use explicit origin allowlists and credential-free submission requests. Origin checks and public form identifiers are not authentication or spam prevention.

| Field behavior | Requirement / recommended implementation |
| --- | --- |
| Controls | Text, email, telephone, textarea, select, radio, checkbox. Unsupported/file controls are flagged during discovery. |
| Identification | Stable HTML names for data; approved field ID/mapping for semantics. Empty/ambiguous names require correction. |
| Labels | Proposed priority: admin override, associated HTML label, aria-label, field name. Do not depend on placeholders for meaning. |
| Custom parameters | Display/organization only; no destination selection or executable expressions. |
| Required | Server-approved rule, not a browser-provided assertion. Whitespace-only required text fails. |
| Optional | Empty/absent allowed; absent checkbox is normal. |
| Choice controls | Accept only approved values; multi-select/checkbox groups represented as arrays with explicit cardinality limits. |
| Length | Agreed defaults: 255 ordinary-text characters, 10,000 textarea characters; configurable. Validate semantic types separately. |
| Unknown fields | Reject unknown data fields, flag mismatch; exclude only explicitly defined protocol/honeypot/CAPTCHA fields from business mapping. |
| HTML content | Treat user input as text; escape in HTML email/portal; do not execute markup. |
| Visitor email | Per-form requiredness; valid mapped address becomes Reply-To when present; never visitor-controlled From/To. |

**Engineering defaults:** at most 50 configured business fields and 256 KiB request body, with consistent proxy/PHP/application limits. UTF-8 character limits and byte-level body limits are separate. Reject oversized payloads before expensive work. No silent truncation.

Configuration changes publish a new version. Browser submits its version and field-shape metadata; reject stale versions and identifiable shape mismatches with a generic unavailable response plus deduplicated portal warning. An absent optional value cannot establish that the underlying HTML field was removed. Re-detection is the administrator's responsibility. Preserve the configuration snapshot for accepted messages.

## 6. Public API and configuration contracts

**Engineering recommendation:** versioned JSON API; these paths are proposed, not existing commands/endpoints.

| Operation | Proposed path / behavior |
| --- | --- |
| Client asset | `GET /formvex/assets/formvex.js` |
| Public form metadata | `GET /formvex/api/v1/forms/{public_id}`; active form's validation/display metadata only |
| Submission | `POST /formvex/api/v1/forms/{public_id}/submissions` |
| Admin UI/API | Under `/formvex/admin`; session and CSRF protected |
| Health | Minimal public availability response; detailed health authenticated/CLI only |

Submission envelope: `attempt_id` (random UUID), `config_version`, `fields` (approved names to strings/string arrays), `field_shape`, `honeypot`, and optional `captcha_token`. Never accept recipient, From, SMTP configuration, HTML template, or validation rules in this envelope.

| Result | Proposed HTTP response |
| --- | --- |
| Newly accepted | 202 with opaque receipt ID and acknowledgement; no fields echoed |
| Identical known retry | Same receipt acknowledgement; do not disclose message contents |
| Invalid fields | 422 with safe field error codes |
| Version/field mismatch | 409, generic form-unavailable message |
| Attempt ID reused with different payload | 409 conflict |
| Rate limit | 429 and Retry-After |
| Too large | 413 |
| CAPTCHA verification failure | 422, retry challenge |
| CAPTCHA service/storage unavailable | 503, preserve browser data |
| Disabled/unknown form | Safe generic unavailable response; no private metadata |

Configuration is divided into private installation settings (origins, paths, SMTP secrets, key location, storage limits), server form settings (recipient, subject, schema, mapping), and minimal client metadata. Public metadata may expose validation rules for usability; the server's independent stored copy remains authoritative. Never expose private recipient/SMTP/admin data in the public config endpoint.

**Review decision (D61):** the installed script automatically fetches minimal public page-to-approved-form mapping to locate its forms and settings. Publish only necessary identifiers/selectors and client metadata, never private recipients, credentials, or submissions. Endpoint format and discovery authorization remain to be resolved.

## 7. Storage model, queue, and duplicate prevention

**Engineering recommendation:** SQLite on local disk; WAL mode, short write transactions, bounded busy timeout, foreign keys, and indexed due-job lookup. SQLite WAL allows readers alongside a writer but still requires coordination of writers and associated files. Avoid network-mounted database storage. [SQLite WAL](https://www.sqlite.org/wal.html)

| Entity | Minimum purpose |
| --- | --- |
| Installation settings | Nonsecret settings, encrypted secrets, alert destination, storage/rate defaults |
| Administrator/session | Password hash, must-change flag, session invalidation generation |
| Forms/config versions | Approved origins/page identity, field schema/mapping, recipient/subject snapshots |
| Submissions | Opaque ID, form/version, validated values, classification, accepted time, lifecycle timestamps |
| Delivery jobs | Recipient/content snapshot, due time, state, attempt count, lease/claim metadata |
| Delivery attempts | Timing, sanitized error category, SMTP-accepted/uncertain result; no credentials |
| Idempotency | Unique form/attempt key, canonical payload hash, original receipt |
| Abuse/alerts/audit | Bounded counters, cooldowns, alert state, admin actions without sensitive payload copies |
| Schema migrations | Applied versions and compatibility metadata |

Spam classification, delivery status, handling, and Trash are separate dimensions. A message can be spam-tagged and successfully sent, or spam-tagged and failed. Avoid a single status field that cannot represent both.

Accepted submission and initial job are committed in one transaction. Unique `(form_id, attempt_id)` prevents concurrent duplicate acceptance. Compare canonical business payload/version without volatile CAPTCHA tokens. Check known accepted retries before consuming another CAPTCHA token; replay never returns message contents. Apply a cheap HTTP flood guard even to retries. **Accepted in review round 3:** use a 24-hour retry-protection window with server-verifiable expiry and retain duplicate-suppression evidence throughout that window. Never let an expired client attempt silently become a fresh send without an explicit new attempt. This supersedes earlier references to the duration as pending.

Worker atomically claims due work, releases database transaction before network I/O, and records outcomes afterward. Prevent overlapping scheduled workers. Track whether SMTP transmission may have occurred: after a crash in that phase, hold for review instead of assuming safe resend. Exactly-once SMTP delivery is not guaranteed.

## 8. Delivery and composition

Use configured SMTP authentication with STARTTLS or implicit TLS as appropriate; verify certificates and never silently downgrade mandatory TLS. Support host/port/credentials, sender name/address, fixed subject, per-form fixed recipient, and optional validated visitor Reply-To. Standard escaped HTML plus plain text uses approved labels/order. Proposed custom header `X-Formvex-Spam: Yes` accompanies the agreed visible `[Suspected spam]` prefix; downstream filters may require explicit rules.

Queue states: `pending`, `sending`, `retry_wait`, `smtp_accepted`, `failed`, `uncertain`, `paused_configuration`. Manual handling/Trash are separate lifecycle flags. Temporary failure retries wait 1 minute, 5 minutes, 15 minutes, 1 hour, then 6 hours after preceding failures: six total attempts over approximately 7h21m plus processing delay. SMTP acceptance is the success milestone, not inbox delivery.

Permanent recipient rejection goes to review. Rejected credentials pause affected sending but intake continues within capacity. Show portal warning; operational email may fail through the same broken transport. Uncertain outcomes require review. Manual resend of failed/uncertain records is explicit and logged; warn of duplicates. **Proposed:** explicit resend starts a new bounded attempt cycle retaining prior history and the original recipient snapshot.

An SMTP test before activation should submit a clearly labeled test message through the actual configured transport to the form's recipient, not merely establish TCP connectivity. Record configuration fingerprint, acceptance time, and result. Recipient inbox confirmation remains a manual end-to-end check. Changes to relevant SMTP/recipient settings invalidate test status for future activations; existing live forms need visible warnings rather than silently losing saved data.

## 9. Abuse controls and authentication

- Agreed configurable new-attempt limits: 5 per rolling 10 minutes and 20/hour for each IP/form; 30/hour for that IP across forms.
- Agreed login defaults: five failures/IP/15 minutes, then 15-minute cooldown; editable in settings. Avoid permanent account lock from attacker-controlled failures.
- Trust forwarded IP information only from explicitly configured proxies. Shared IPs can cause false positives; address rotation can evade per-IP rules. Submitted email is not identity.
- Filled honeypot classifies as suspected spam; accepted spam is saved under Spam and sent tagged. It never bypasses mandatory validation, request limits, or enabled CAPTCHA.
- No bundled word database or full content scoring in V1. Mark not spam only reclassifies, not automatically resends.
- CAPTCHA is per-form optional. Server validation is mandatory when enabled. Validate provider hostname/action binding as well as success; keep secret keys server-side. Turnstile tokens expire after five minutes and are single-use, so handle lost-response idempotency before attempting token reuse. [Turnstile validation](https://developers.cloudflare.com/turnstile/get-started/server-side-validation/)
- CAPTCHA outage: preserve fields, offer retry, do not bypass. Separate admin alert email: initial alert, at most one reminder/hour, recovery notice. Keep portal warnings; do not send alerts for every ordinary incorrect challenge.

**Engineering defaults:** 60 HTTP requests/IP/minute for the submission endpoint before heavier processing, plus a configurable installation ceiling of 120 new attempts/minute; tune against tests and legitimate shared networks. These are additional recommendations, not user-agreed limits or DDoS guarantees. Counter/log storage must also be bounded.

Admin uses modern password hashing, secure HttpOnly SameSite cookies, CSRF tokens, session rotation, and 30-minute idle expiry. Proposed absolute lifetime: 12 hours. Password changes/resets invalidate sessions. Bootstrap/recovery runs under filesystem privileges for that installation. No default shared password, unauthenticated setup reopening, or emailed reset flow. Audit config changes, resend, deletion/restore, exports, and recovery events without logging passwords/message bodies. **Review decision:** operational logs and administrator audit history each default to 30-day retention, independently configurable from submission retention; byte caps and transient-counter/report policies remain open.

## 10. Retention, Trash, and storage limits

| Record condition | Agreed retention / interpretation |
| --- | --- |
| SMTP accepted, never handled | 30 days by default from SMTP acceptance; configurable |
| Unhandled failed delivery | Eligible for deletion at 365 days old; propose original acceptance time as age anchor |
| Mark handled | Fresh configured period (30 days default) from handling, even beyond day 365 |
| Spam-tagged | Same delivery/handling rules; classification does not change retention |
| Manually deleted | Full seven-day recovery period from manual deletion, even beyond an earlier ordinary expiry; counts toward storage |
| Automatic retention expiry | Permanent deletion according to policy, not another Trash period; cannot shorten an active manual seven-day Trash window |

**Review decisions:** Mark handled allows queued delivery/retries to continue. Manual Trash guarantees seven days, superseding the earlier proposal to choose the earlier automatic expiry. Restoring a trashed record after its original expiry grants 30 new days of retention; preserve original timestamps and record a separate recovery deadline. **Remaining engineering recommendations:** repeat Mark handled on an already-handled record does not silently extend expiry; explicit reopen/re-handle is audited. Trash pauses unsent jobs; restoring from Trash requires explicit delivery resume to prevent surprise sending (distinct from full-backup restoration). Do not delete a job during active SMTP transmission; coordinate worker leases, deletion, and maintenance. Treat aged unhandled uncertain/paused records like failed delivery for the 365-day safety cleanup, clearly shown in the portal.

Latest user-selected storage default: **2 GB**, configurable from **1 GB through 10 GB inclusive**, with configurable **80% normal warning** and **90% critical warning** defaults. **Unit interpretation:** decimal 2 GB = 2,000,000,000 bytes; default warnings at 1.6 GB and 1.8 GB. Validate the allowed range server-side as well as in settings. This replaces the earlier 5G choice. At allowance, reject new intake, preserve existing records, and resume once safely below it. If an administrator lowers the allowance below current usage, stop new intake without deleting existing data. This is an allowance, not reserved disk or a promised number of messages. Actual disk shortage may stop intake sooner.

Budget must account for database and journal/WAL files, managed logs, and managed temporary/export data; reserve operating headroom for processing existing records. Backups need separate private storage and free-space checks. SQLite deletion does not necessarily shrink the physical database immediately: account for reusable pages and perform controlled checkpoint/compaction when safe rather than leaving the installation falsely full forever. Provide capacity diagnostics. Cleanup cannot erase protected leads merely to admit new traffic.

## 11. Capacity and performance targets

Latest review scope override: the proposed backup free-disk safety-margin feature is deferred beyond V2; no configurable reserve is required for V1/V2. This supersedes prior backup-headroom feature proposals, but not ordinary safe handling of failed writes or disk-full conditions. The outgoing delivery ceiling is now accepted as a configurable 10 SMTP attempts/minute across the installation, including retries and alerts, subject to a lower configured provider limit.

**Engineering recommendation requested by the user, not yet an approved or measured capacity:** design and test one installation for **1,000 legitimate submissions/day**, with a **60 new submissions/minute burst for five minutes from multiple visitors**. These are workload targets, not a daily quota. Single-source abuse limits still apply; test ordinary traffic with multiple client identities behind trusted test infrastructure.

Proposed reproducible reference: Linux VPS, 2 vCPU, 2 GB RAM, local SSD, PHP 8.4/8.5, SQLite, one scheduled worker, production-like web proxy. This is a test reference, not a proven minimum hosting requirement.

Test 24-hour normal workload and separate burst/recovery scenarios. Measure accepted-request p95 below 1 second with CAPTCHA disabled; report enabled-CAPTCHA latency separately because it depends on the provider. Under no backlog, first SMTP attempt should normally start within the accepted approximately one-minute schedule. Test burst drain against a local SMTP sink and a paced slow sink; publish results before claiming capacity. Never load-test a real recipient or production SMTP without separate authorization.

At 1,000/day, ordinary 30-day retention means roughly 30,000 records; at an illustrative 10 KB of stored data per record that is around 300 MB before indexes, logs, WAL, Trash, and retained failures. At the same rate, a year of failures is 365,000 records, roughly 3.65 GB before overhead, exceeding the new 2 GB default. Actual size depends on payloads and storage format. **The 365-day retention policy does not guarantee space for a year of failed traffic**; capacity controls may stop new intake earlier unless the allowance and available disk are increased.

SMTP sending quotas, latency, hosting CPU/disk, CAPTCHA availability, and actual payload sizes can dominate capacity. Verify the owner's SMTP allowance and use configurable worker pacing. Once measured, revise the supported workload honestly; SQLite selection alone proves no throughput figure.

## 12. Deployment, backup, and updates

ZIP contains code, public client/assets, dependencies, migration definitions, example config, license notices, and installation documentation. Never include live keys, developer secrets, tests requiring internet credentials, or existing data. Proposed commands (names are illustrative): `formvex install`, `admin:reset`, `worker:run`, `backup:create`, `backup:restore`, `migrate`, `health:check`.

Preflight checks PHP/extensions, private-path web isolation, database permissions, HTTPS, exact origins, SMTP reachability, clock, writable local disk, and scheduler heartbeat. Require host routing to expose only public assets/front controller; an all-files-public upload arrangement is unsupported. Alert if recurring processing/cleanup is stale. No claim of compatibility with every panel or static host.

Backup must use a consistent SQLite snapshot or a documented quiesced procedure, not copy only the live database while ignoring WAL. SQLite provides backup mechanisms for consistent snapshots. Include schema/application version, configuration, and the material needed to recover stored application secrets. **Review decision:** use a plain ZIP archive, with no archive encryption or separate archive decryption key. Store it privately and expose downloads only to an authorized administrator; possession of the ZIP can expose submissions and restorable credentials. Application secret-storage choices remain distinct from archive format. [SQLite backup guidance](https://www.sqlite.org/backup.html)

**Review decision:** after successful server-side restore, automatically reopen submission intake and process eligible queued email, without an additional administrator-release hold. Partial restore must not serve requests; coordination and failure behavior still need definition. Restored jobs already marked uncertain retain the existing review requirement. The user explicitly accepts that pending jobs in an older snapshot may already have been sent afterward and may be sent again. Do not require a separate surviving delivery ledger to prevent this accepted risk or promise guaranteed delivery.

Manual upgrades (D60): verify artifact, enter maintenance to stop new submissions and delivery attempts, finish/account for in-flight work, take the final rollback backup, install versioned code, migrate, run health checks, exit maintenance, verify scheduler. Document rollback compatibility; use matched code/data snapshots when schema rollback is unsafe. Restoring a backup does not undo emails already delivered. Backups may retain data beyond live retention; the operator needs a separate backup-expiry policy.

Archive clarification: plain ZIP means no archive encryption and no password protection. Private storage and administrator-only downloads remain required.

Review round 2: full restoration invalidates all administrator sessions. Failed partial restoration leaves the service unavailable with a generic server-unavailable response until server-side recovery; successful restoration still resumes intake and eligible delivery automatically. Group repeated identical operational errors with count and first/last timestamps while retaining individual administrator-action audit records. Full-backup downloads require an authenticated administrator session, without additional password confirmation.

## 13. Acceptance criteria and milestones

| Milestone | Acceptance evidence |
| --- | --- |
| M1: package, private storage, bootstrap | Clean ZIP install on reference VPS; only public routes exposed; first-login change and server-only reset/session invalidation work. |
| M2: discovery/configuration/client | Logoslab plus generic multi-form fixture; IDs/markers and bare/www aliases; approved mapping/version checks; no field values collected during discovery. |
| M3: durable intake/validation | Required/optional/choice/length checks; unknown fields rejected; atomic save/job; parallel same-attempt submissions create one record; failures preserve browser data. |
| M4: SMTP/worker | Configured sender/Reply-To, one recipient snapshot, escaped HTML/text, activation test, retry timing, credential pause, uncertain outcomes, crash recovery, manual resend. |
| M5: admin lifecycle/protection | Spam tagged and delivered, Not spam no duplicate, CSV safe in spreadsheets, CAPTCHA enabled/disabled/outage, agreed throttle settings, idle expiry, audit redaction. |
| M6: operations/release | 2 GB default, inclusive 1–10 GB range and 80%/90% warnings verified; reduced internal test budgets exercise full-storage rejection; lowering allowance preserves existing data; full seven-day manual Trash guarantee, plain-ZIP consistent backup without encryption/password protection, automatic reopening after successful restore with accepted possible stale-snapshot resends, maintenance before final upgrade backup, rollback, scheduler failure visibility, load results. |

Mandatory regression cases include day-364 Mark handled extending to day 394 while retries continue; fields absent because unchecked versus truly unknown fields; rate-limit shared-IP behavior; stale CAPTCHA versus known accepted retries; two concurrent workers; SMTP accepted then worker crash; recipient changes with pending jobs; disabling a form with pending work; logs/exports not leaking secrets; full seven-day Trash recovery despite earlier expiry; no email against partially restored data; automatic queue operation after successful restore with possible duplicate sends explicitly accepted; no diagnostic email without explicit test action.

For Logoslab specifically: keep its existing design and button; disable the mailto submit handler and replace mailto-specific instructions; detect its seven named fields and preserve name/company/email requiredness; map email to Reply-To; verify queued delivery and error preservation. No production source edits have been made during specification work.

## 14. Review gates and remaining engineering work

The interview has established the product scope. Remaining recommendations for review are the PHP/framework/provider versions, capacity target, exact storage-unit interpretation, discovery protocol, API/error names, aggregate limits, operational defaults, and retention/Trash intersections identified above. These do not require another broad questionnaire.

Before implementation claims or release: validate discovery on configured aliases without leaking grants, verify SQLite/Symfony integration and transport error classification, pin/audit dependencies and verify packaging, test supported hosting prerequisites and capacity, and supply copyright-holder text. No architecture document can prove reliability without these tests. SMTP inbox arrival and exact-once external delivery remain outside the guarantees.

Use the discussion record to capture corrections to this draft. Do not silently promote proposed settings into user-agreed decisions.
