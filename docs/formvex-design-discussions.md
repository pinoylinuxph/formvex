# Formvex design discussions and decision record

Last updated: 2026-09-21

## Purpose and maintenance

Living record of the pre-build discussion, answers, rationale, agreements, revisions, and unresolved questions. This is a structured discussion record, not a verbatim transcript or an implementation specification.

Baseline: [formvex-conceptualization.md](formvex-conceptualization.md), previously named `formvex-conceptualization.txt`. Explicit later user decisions below supersede the baseline where they differ. Suggestions are not decisions until accepted. Preserve superseded decisions and their reasons rather than silently replacing history. Update this file as the discussion continues, including each new batch of questions and its answers.

The user requests questions ten at a time, one clear decision per question, explanations in ordinary language, and pushback on vague answers or technical problems. Do not ask again for facts already established. No application code should be written during this exercise.

## Product and people

- Formvex is a reusable, self-hosted form-processing and SMTP email-delivery application for existing HTML websites.
- The founder is a DevOps engineer whose services include hosting. The project is intended for the PinoyLinux community to share, fork, reuse, and improve.
- First deployment: logoslab.xyz, currently on a VPS. The local `logoslab/index.html` is identified by the user as the production website source.
- Standalone administrators may be nontechnical or beginners. A hosting administrator may perform server installation; normal configuration must be possible through a browser.
- Broad hosting compatibility is desired, including hosting panels and VPSs. A literal promise of support for every hosting service is not established: any backend needs compatible execution, storage, and SMTP connectivity.
- V1 is standalone, with multiple forms within one domain. The Hub and centralized Console remain future work.
- The Client/Core/Server separation remains an architectural principle regardless of backend language.

## Current decisions

| ID | Decision | Status / rationale |
| --- | --- | --- |
| D01 | Preserve existing form design, fields, and submit button; add the Formvex JavaScript reference. | Agreed. Minimal integration remains a goal. |
| D02 | Disable existing competing submission handlers where necessary and adjust obsolete submission instructions. | Agreed after inspection of Logoslab. Replacing its button alone would not remove a handler attached to the form. |
| D03 | Support multiple forms under one domain; administrators choose one or several detected forms. | Agreed. Explicit bare/www aliases accepted in D47; arbitrary subdomains are not implicitly supported. |
| D04 | Each form has exactly one fixed recipient and its own subject. | Agreed. Multiple recipients within a form are excluded in V1; hosting-level email forwarding can distribute messages. Recipients remain server-controlled. |
| D05 | Automatically discover fields, then let the administrator assign built-in purposes or create custom parameters and map fields to them. | Agreed. Administrator confirms required fields and validation. |
| D06 | Custom parameters organize information in the generated email only. | Agreed for V1; no answer-dependent recipient routing. |
| D07 | Added or removed fields require administrator re-detection, review, and saving before activating changed mappings. | Agreed. Reject submissions containing unapproved fields with a generic form-unavailable message and flag the mismatch in the portal; do not silently discard those fields. Missing optional fields are not proof of a removed field; precise mismatch rules remain to specify. |
| D08 | V1 supports standard HTML controls from the conceptualization; no custom widgets or third-party embedded forms. | Agreed. Possible later support, not a V2 commitment. |
| D09 | Provide a small local admin portal, e.g. `/formvex`, for setup, configuration, and viewing stored submissions. | Agreed. Revises the original deferral of all web administration; centralized Console is still deferred. |
| D10 | One administrator account per installation in V1. | Agreed. Multiple accounts and roles deferred. |
| D11 | Bootstrap creates the admin with a unique temporary password; replacement is required at first login. | Agreed. Exact bootstrap mechanism still to specify. |
| D12 | Forgotten-password recovery uses a server-side bootstrap command to reset the existing admin account. | Agreed: server access required; no public browser recovery route. Reset invalidates sessions and issues a temporary password requiring replacement. The operator must have permission to manage that installation, not merely any server account. |
| D13 | Save accepted, validated submissions in SQLite before sending email. | Agreed. Revises the original exclusion of a submission database. |
| D14 | First delivery attempt plus five retries for temporary failures, waiting 1 minute, 5 minutes, 15 minutes, 1 hour, and 6 hours after preceding failures. | Agreed; six attempts total. Hold uncertain acceptance outcomes for administrator review instead of automatic resending. Permanent/configuration failures need explicit handling. |
| D15 | Acknowledge receipt after validation, abuse checks, and durable SQLite storage; send email in the background. | Agreed. SMTP failures and retry status are administrator-only. Do not claim receipt on storage failure or claim inbox delivery. |
| D16 | Retention defaults to 30 days and is configurable; marking a record handled starts a fresh retention period. | Review decision: delivery/retries continue; handling is not cancellation. Applies to other handled records as well. |
| D17 | Protect unreviewed failed submissions from normal 30-day deletion, but allow deletion at 365 days old; handling overrides that expiry with a fresh 30 days. | Explicit correction: a failure handled on day 364 remains until day 394 by default. The earlier proposed hard cap even after handling was rejected. Original submission time remains the proposed age anchor for unhandled failures. |
| D18 | Validate configuration in V1. | Agreed; moved forward from proposed V1.1 work. |
| D19 | Use the standard MIT license with copyright/license notices; no additional mandatory visible branding clause. | User agrees with MIT recommendation. Legal copyright-holder name still unspecified; no LICENSE file created yet. |
| D20 | Use PHP for the V1 backend. | Explicitly selected after comparing alternatives. Supersedes the original Node.js choice; Vanilla JavaScript client and Client/Core/Server separation remain. PHP framework and libraries are not yet selected. |
| D21 | Persist past, current, and future discussion in Markdown under `docs`. | Explicit user instruction; this document fulfills the initial record. |
| D22 | Discover forms page by page through the installed script: select forms, review fields, activate. | Agreed. Discovery session implementation still to specify. |
| D23 | Do not send automatic visitor confirmation emails in V1. | Agreed; acknowledgement is on the website only. |
| D24 | Optional CAPTCHA is configurable separately for each form. | Agreed. On verification-service outage, preserve input and offer retry; notify the separate admin address using D33 cadence. D53 accepts one provider; Turnstile is the specification's recommendation, not an explicit user vendor selection. |
| D25 | Show a temporary-limit message and preserve input; defaults are 5 attempts/10 minutes and 20/hour per IP/form, plus 30/hour per IP across forms. | Values now explicitly accepted, configurable. Same-submission retries do not create another submission; separate HTTP flood protection remains necessary. |
| D26 | Save suspected spam in a spam folder and send it to the configured recipient with a visible `[Suspected spam]` subject prefix. | Prefix and continued mandatory rate-limit, validation, and enabled-CAPTCHA enforcement explicitly accepted. A product-specific machine-readable header remains a recommended implementation detail. Spam classification never overrides mandatory rejection. |
| D27 | Repeated browser attempts after a lost response should reuse the original submission acknowledgement rather than create duplicate messages. | Agreed. Implement submission-attempt identifiers bound to form and payload; lifetime and replay controls remain to specify. |
| D28 | Validate dropdown/radio values against administrator-approved choices. | Agreed. |
| D29 | Accept absent optional fields; reject absent required fields. | Agreed. An unchecked checkbox is not a configuration mismatch. |
| D30 | Clear browser fields only after confirmed durable acceptance; preserve input on errors. | Agreed. |
| D31 | Temporarily stop accepting new submissions when storage allowance is full; preserve existing records. | Explicitly agreed. Show temporary-unavailable response and resume when capacity is available. D55 now defaults to 2 GB with configurable 80% normal/90% critical warnings. |
| D32 | Use a separately configured administrator email for operational alerts. | Agreed; distinct purpose from per-form recipient. |
| D33 | CAPTCHA outage alerts: one initial email, at most one reminder/hour, and one recovery notice. | Agreed. Portal warning remains necessary if SMTP also fails. |
| D34 | For successfully emailed submissions never marked handled, ordinary retention begins at SMTP acceptance. | Agreed. Later Mark handled starts a fresh configured retention period. |
| D35 | Require a successful SMTP test before first form activation. | Agreed; exact test semantics still to specify. Does not guarantee future delivery or inbox arrival. |
| D36 | Continue accepting valid submissions within storage limits when SMTP credentials fail; pause sending and warn administrator. | Agreed. |
| D37 | Pending submissions retain the recipient assigned at acceptance when a form recipient changes. | Agreed. New submissions use the updated recipient. |
| D38 | Mark not spam changes classification without automatically resending an already sent message. | Recorded as accepted by the user's concluding agreement to the preceding recommendations; explicit resend is separately accepted in D43. |
| D39 | Spam-tagged submissions follow delivery-based retention: sent uses ordinary retention, failed uses failed-message retention, handled starts a fresh configured period. | Recorded as accepted by the user's concluding agreement to the preceding recommendations. Spam is classification, separate from delivery state. |
| D40 | A filled honeypot classifies a submission as suspected spam, with tagged delivery if mandatory checks pass. | Agreed. |
| D41 | V1 uses one standard HTML/plain-text email layout using approved field labels and order. | Agreed; visual template editor deferred. |
| D42 | Administrators can export stored submissions as CSV. | Agreed. Spreadsheet formula injection protection is an implementation requirement. |
| D43 | Provide explicit manual resend for failed or uncertain deliveries. | Agreed, with duplicate-delivery warning for uncertain outcomes and attempt logging. |
| D44 | Disabling a form stops new intake but lets previously accepted pending deliveries continue. | Agreed. |
| D45 | Approximately one minute of normal delay before the first email attempt is acceptable. | Agreed to scheduled PHP processing; acknowledgement remains immediate after durable storage. Backlog and scheduler failures can delay attempts further and must be visible. |
| D46 | Defer bundled spam-keyword databases, administrator keyword rules, and full content-scanner integration beyond V1. | User accepted the anti-spam recommendation after explanation. Keep mandatory controls and honeypot tagging; recipient filtering may perform content analysis. |
| D47 | Support a website's bare domain and www alias in one installation through explicit configuration. | Agreed; arbitrary subdomains are not implicitly authorized. |
| D48 | Reuse stable unique form IDs; request a small form marker when no suitable identifier exists. | User acknowledged and understood the explained marker approach; recorded as the agreed integration approach. Logoslab already has a suitable ID. |
| D49 | Each form's configuration decides whether visitor email is required; the engine does not require it for every form. | Explicitly agreed. Website controls From; a valid mapped visitor email supplies Reply-To when present. |
| D50 | Distribute a ready-to-upload ZIP with PHP dependencies included. | Agreed. Hosting administrator still configures private paths, bootstrap, routing, and scheduled task. |
| D51 | V1 upgrades are manual hosting-administrator operations. | Agreed; document backup, migrations, and rollback. Automatic updates deferred. |
| D52 | Include server-side backup and restore commands for configuration and submissions; plain ZIP archives without encryption or password protection. | After successful restore, reopen intake and eligible queued sending without manual-release hold. User explicitly accepts possible duplicate sends from older snapshots, prioritizing delivery attempts and simplicity; no separate surviving delivery ledger is required to prevent that accepted risk. |
| D53 | Support one optional CAPTCHA provider initially. | Agreed; provider selected as a technical recommendation in the consolidated specification, not a user-mandated vendor. |
| D54 | Configurable defaults: 255 characters for ordinary text fields and 10,000 for message textareas. | Agreed; type-specific validation and request limits also apply. |
| D55 | Storage allowance defaults to 2 GB, configurable from 1 GB through 10 GB inclusive; warning defaults 80% normal and 90% critical are configurable. | Latest explicit bounds. Decimal interpretation: 2,000,000,000 bytes; warning levels 1.6 GB and 1.8 GB at defaults. Hard stop remains at allowance or earlier physical-disk safety threshold. |
| D56 | Admin session expires after 30 minutes of inactivity. | Agreed. |
| D57 | Five failed admin logins per IP within 15 minutes trigger a 15-minute IP cooldown; limits configurable in settings. | Explicitly agreed, including configurability. |
| D58 | Manual deletion guarantees seven full days in Trash even beyond an earlier automatic expiry. | Explicit review decision replaces the earlier proposed earliest-deadline rule. Trash counts toward storage; retention after restoring an already-expired trashed record remains open. |
| D59 | Operational logs and admin audit history default to 30-day retention, configurable independently from submission retention. | Accepted in review resolution round 1; byte limits/transient-data policies remain open. |
| D60 | During manual Formvex upgrades, enter maintenance and finish/account for in-flight work before taking the final rollback backup. | Accepted after clarifying that upgrades mean Formvex software versions. Temporarily stop new submissions and new delivery attempts, then snapshot, install/migrate, verify, and reopen. |
| D61 | Installed client automatically fetches minimal public page-to-approved-form mapping. | Accepted recommendation. Expose only form identifiers/selectors and necessary client metadata; never private recipients, credentials, or submitted data. Discovery authorization remains a separate detail. |
| D62 | Show a proper visitor-facing message when a connection problem prevents confirmation; server-side submission failures return an appropriate HTTP 5xx status with a readable error message. | Explicit user direction. No authentication required. A missing response does not prove the server failed to save the submission: say receipt could not be confirmed, rather than claiming it was never received. Once durably accepted, later SMTP failures remain administrator-only under D15. Exact wording/status mapping remains proposed; this does not approve the 24-hour retry window. |

### Visitor/admin feedback boundary — latest clarification

User directs that problems other than visitor connection problems or server-side submission failures remain silent to visitors and clear to administrators. Operational issues after durable acceptance (including SMTP failures, queue retries, and delivery uncertainty) must not change the visitor acknowledgement or ask the visitor to resubmit. Show administrators actionable status and available failure details through the portal and existing agreed alerts, without exposing secrets. This does not promise administrator visibility into requests that never reached the server.

Compatibility clarification: retain previously agreed validation, CAPTCHA, and rate-limit feedback when intake is rejected; do not display a false success or silently discard an unaccepted submission. These are submission-time feedback, not background operational disclosures. The 24-hour retry proposal remains unapproved.

### Review follow-up: anonymous browser retry policy — accepted

User approved the 24-hour retry-protection policy in review round 3 (V11-D35), superseding earlier pending statements. Use a hidden reference with server-verifiable expiry. Reusing a reference for an already accepted matching submission returns its receipt without creating another delivery job. Store only the reference/expiry in browser storage, not visitor field values. If the window expires without confirmation, do not silently resubmit: explain that receipt could not be confirmed and require an explicit new submission. Losing browser storage may lose correlation. This is separate from SMTP retries and the accepted post-restore duplicate risk.

## Evidence from Logoslab

Inspected `logoslab/index.html`, including the form near line 3155 and submission handler near line 3644.

| HTML field name | Label | Control | Required in markup |
| --- | --- | --- | --- |
| name | Name | Text | Yes |
| role | Role | Text | No |
| company | Company | Text | Yes |
| email | Work email | Email | Yes |
| sector | Sub-vertical | Select | No |
| team_size | Engineering team size | Select | No |
| message | What you'd like to discuss | Textarea | No |

The form has ID `contactForm`, associated labels, and `novalidate`. Its existing listener intercepts form submission and opens a `mailto:` URL. It checks that name, company, and email are nonempty. Existing success/help text says the visitor's email client will open. These details explain the necessary integration edits; no edits have been made to that website.

## Discussion history and revisions

1. Initial review recognized the three-component architecture and identified deployment, trusted validation, field mapping, delivery, and security gaps. Some early recommendations were overbroad: a public site identifier or domain binding is not authentication or a sufficient anti-abuse control.
2. The first questionnaire was rejected because it repeated the document and bundled multiple questions. Subsequent rounds must start from the established baseline.
3. User clarified the PinoyLinux community objective, beginner administration, script integration, custom field mapping, willingness to use SQLite, SMTP acceptance as initial success criterion, and attribution preference.
4. User accepted local administration, multiple forms per domain, choosing detected forms, trusted required-field confirmation, manual re-detection, and admin bootstrap. Asked for recommendations on storage and licensing.
5. Local HTML inspection exposed the existing mailto handler. User accepted disabling that handler and later explicitly accepted retaining the original button and styling.
6. User reopened the backend language choice, proposing PHP. Assistant recommended evaluating PHP for conventional hosting, without changing the Vanilla JavaScript client or reusable core architecture.
7. User accepted standard controls, fixed per-form destinations, submission viewing, durable SQLite storage, automatic retries, one admin, and 30-day retention. Latest answers override automatic deletion for unresolved delivery failures and exposing SMTP delays to visitors.
8. User requests retry frequency/limits, bootstrap-based recovery, backend alternatives beyond PHP, and this durable record. No application implementation has begun.
9. User selected PHP and requested a reset of the next ten questions. Questions must concern product development; contributor language comfort is not relevant to this exercise. The preceding unanswered batch is superseded, not accepted. It covered hosting scope, contributor comfort, discovery, retries, scheduler, uncertain delivery, acknowledgement, review actions, storage capacity, and bootstrap access. Its substantive unresolved topics remain in the record below.
10. User answered all ten replacement questions: approved page-by-page discovery and rejection of unapproved fields; limited each form to one recipient (forwarding handled by hosting); excluded visitor confirmation emails; approved durable acknowledgement/background delivery, the retry schedule, uncertain-outcome review, and retention starting at Mark handled; added a 365-day expiry exception for failed messages; enabled optional CAPTCHA scope; confirmed server-only bootstrap recovery.
11. Next answers: handling starts a fresh 30 days even beyond the proposed 365-day cap, including other handled records; CAPTCHA is per-form; CAPTCHA service failure should produce retry and email notification; rate-limit message accepted but thresholds/identity requested; spam folder required; duplicate suppression, approved-choice validation, optional-field absence, and clearing only after storage accepted. Storage-capacity question needs a plain-language explanation. Do not start a new ten-question batch before addressing these follow-ups.

12. User explicitly accepted the outstanding storage-capacity behavior: stop accepting new submissions while preserving stored messages. Rate-limit defaults and notification details remain proposals; do not infer acceptance from this narrow answer.
13. User accepted rate-limit values, separate admin alert address, outage alert cadence, sent-message retention anchor, activation SMTP test, continued intake during SMTP failure, and original-recipient preservation. User wants suspected spam emailed with a spam header and asks if this conflicts with earlier agreements; questions 5 and 6 refer back to that change rather than separately approving resend or expiry. Clarification: spam-folder storage was agreed, but withholding delivery was only an assistant proposal. New behavior is compatible with a folder that categorizes rather than blocks sending.
14. User asked how many questions remain. Plan: approximately two more batches of ten product questions, then consolidate a reviewable V1 specification; only actual contradictions/blockers should prompt further clarification. Remaining engineering details should receive reasoned proposals rather than become an endless questionnaire.
15. User explicitly accepted the spam-prefix recommendation and ongoing mandatory rate limits, validation, and enabled CAPTCHA, concluding with agreement. Recorded the preceding Not spam/no automatic resend and delivery-based retention recommendations as accepted, and stated that interpretation in the response. Begin the first of the final two planned ten-question rounds.
16. User accepted final-round-1 questions 1 and 6-10 (honeypot classification, standard layout, CSV export, manual resend, pending delivery after disable, and approximately one-minute sending delay). Questions 2-5 remain open: asks about free spam-keyword databases, the use case for domain aliases, a plain-language explanation of form markers, and the distinction between visitor email and SMTP sender. Explain these before advancing to the last round.
17. User agreed to the anti-spam recommendation, explicitly approved both domain variants, acknowledged the marker explanation, and explicitly chose per-form visitor-email requirements. Final planned round 1 is complete. Proceed to the last ten product questions, then consolidate the specification with engineering recommendations clearly distinguished from user decisions.
18. Final-round-2 answers 1-9 accepted, except storage increased to 5G with 80% normal and 90% critical warnings. Login throttling must be adjustable in configuration settings. User requests a recommendation for daily capacity. Recommend a provisional design/test target of 1,000 legitimate submissions/day/installation and a 60-new-submissions/minute burst from multiple sources; these are test targets, not measured capability, hard daily limits, or accepted user decisions. Questionnaire is complete; consolidate the specification without another routine question round.
19. Created [Formvex V1 requirements and architecture](formvex-v1-requirements-and-architecture.md), a review draft covering scope, flows, architecture, API/data contracts, discovery, queue/retry semantics, spam/security, storage/retention, deployment, backup, and acceptance milestones. Technical recommendations include PHP 8.4+, Symfony 7.4, Turnstile, capacity targets, and explicit interpretations of lifecycle edge cases. Only documentation was changed; no application or website implementation and no load testing occurred.

### Subsequent storage-default revision

The user replaced the 5 GB allowance with a 2 GB default, now explicitly configurable from 1 GB through 10 GB. V1.1 backup schedule choices are daily, weekly, or monthly (default); rotation defaults to four successful copies, configurable from 1 through 12. Timing interpretation stated to the user: daily at 00:00, weekly Monday at 00:00, monthly on the first at 00:00, in the configured timezone. The earlier 5G decision and daily/seven-copy schedule remain in discussion history as superseded choices. Warning defaults remain 80% normal and 90% critical, also configurable. This does not change submission retention, Trash, or update-check frequency.

## Version boundary and V1.1 planning

The consolidated requirements/architecture draft covers V1.0 only. Earlier shorthand "V1" refers to that release, not a combined V1.0/V1.1 deliverable. Configuration validation and several operational features originally suggested for V1.1 were brought into V1.0 during the interview.

The user has now explicitly requested V1.1 discussion. Continue in [V1.1 design discussions](formvex-v1.1-design-discussions.md), preserving V1.0 decisions. The completed V1.0 questionnaire does not prohibit the newly requested V1.1 exercise. No V1.1 scope is approved simply because it appears as a candidate question. V1.0 is still a pre-build review draft; no production feedback or measured shortcomings have been established.

### Cross-version review before V2.0

**Resolution round 1 answers:** user requests explanations of Formvex software upgrades and anonymous retry identification, and a recommendation on public client-to-form mapping. User rejects mandatory post-restore manual release and encrypted backup archives; chooses automatic intake/queued sending after successful restore, plain ZIP, continued retries after Mark handled, full seven-day manual Trash, separate scheduled backup task, exclusion of manual backups from scheduled rotation, and separately configurable 30-day operational/audit retention. Accepted choices are applied to specifications; remaining sub-decisions are not silently resolved. Duplicate emails after restoring an older snapshot require explicit discussion.

**Latest user direction:** all eight review findings must be discussed through questions and resolved by the user, including engineering choices. The assistant's earlier suggestion that only the Trash policy needed a decision is superseded. Recommendations remain unapproved; do not change the specifications or proceed to V2.0 on assumed acceptance. The review report now contains the first ten resolution questions.

At the user's request, reviewed both specifications and decision records before V2.0 discussion. See [V1.0/V1.1 review](formvex-v1-review.md). The review found eight implementation-contract gaps, including three high-priority issues around rollback checkpoint timing, enforceable duplicate-suppression expiry, and restore reconciliation. Current version boundaries, latest storage/schedule defaults and other principal product decisions are consistent. All 58 V1.0 and 15 V1.1 decision IDs were present and unique; all 13 then-existing local Markdown links in the four reviewed documents resolved.

No specification behavior or application code was changed by the review. Recommendations in the report remain proposals. One visible policy clarification is required: if an automatically expiring record is manually moved to Trash shortly before that expiry, does automatic expiry still win or does the full seven-day manual recovery period extend it? The report also recommends making handling-versus-delivery side effects explicit, along with other engineering details, before implementation. V2.0 may be discussed without claiming the standalone drafts are implementation-ready or performance-tested.

## Design rationale and remaining implementation proposals

Decision-table statuses take precedence. The sections below distinguish accepted behavior from remaining proposals.

### Backend alternatives considered (PHP now selected)

- PHP: preferred if conventional PHP hosting is a core release target. Use existing web-server/PHP execution and a scheduled command for reliable retry processing. SQLite extension, writable private storage, scheduler, and outbound SMTP support must be verified.
- Go: strong alternative for a VPS-focused downloadable service; compiled executable distribution, with OS/architecture-specific builds and a managed service. SQLite driver/packaging choices require validation.
- Node.js: retains JavaScript across client/backend and matches the original proposal; requires a supported runtime and managed application process.
- Python: viable, e.g. Django for an admin-heavy application; production application-server/runtime setup is still required.
- C# / ASP.NET Core: viable web backend with supported hosted/service deployment; choose if maintainers and hosting targets favor its ecosystem.

Outcome: user selected PHP for V1. Alternatives are retained here as discussion history. Exact supported hosting prerequisites and PHP framework/library choices remain open. Do not reopen language selection or ask about contributor comfort without a new user reason.

### Discovery

Accepted flow: administrator-led page-by-page discovery through the installed script, followed by selection, review, and activation. Proposed mechanism: register a page URL and open a short-lived authenticated discovery session to inspect rendered standard forms. Do not assume Logoslab's CSS or layout; do not collect entered field values. Avoid a whole-site crawler in V1.

Pending engineering details: authorize and bind discovery to the installation and expected origin; separate it from normal visitors; handle pages that cannot be framed; identify forms without stable IDs; support multiple URLs explicitly. Browser-readable discovery metadata is untrusted until administrator approval. No promise that arbitrary remote pages can be inspected directly by the portal.

### Delivery and visitor responses

Accepted retry policy: first background attempt followed by retries 1 minute, 5 minutes, 15 minutes, 1 hour, and 6 hours after the preceding failed attempt (six attempts total, approximately 7 hours 21 minutes from the first to final attempt, plus processing/scheduler delay). First-attempt scheduling latency is still to specify.

Only retry clearly temporary failures automatically. Proposed: permanent recipient failures require review; invalid SMTP credentials/configuration pause affected sending rather than hammer the server. Accepted: an uncertain outcome after message transmission is flagged for review because automatic resend may duplicate email. Ordinary SMTP cannot guarantee exactly-once delivery.

Recommend a scheduler checking due submissions once a minute. Safe job claiming, attempt logs, and duplicate-request handling are necessary design details. Background sending independent of the visitor response is accepted.

Visitor wording proposed: "Thank you. Your message has been received." Show this only after validation/abuse checks and successful durable storage. A storage failure must return an honest generic failure. SMTP acceptance remains an internal delivery milestone; it is not proof of inbox delivery.

### Abuse and retention

Keep baseline rate limits, honeypot, request limits, server-controlled destinations, and trusted validation. Apply request size and traffic limits before storing full submissions; public identifiers and Origin/Referer checks do not authenticate callers. Latest user direction: suspected spam that passes mandatory checks is saved in the spam folder and emailed with a spam marker. Protect admin login separately from public form submission. Optional per-form CAPTCHA, alert recipient purpose, alert cadence, and per-IP rate defaults are accepted; provider, technical retry details, aggregate limits, and rejected-request logging remain undecided.

Accepted spam interpretation: folder is classification, delivery status is independent (pending/sent/failed). Add visible `[Suspected spam]` subject prefix. A product-specific header such as `X-Formvex-Spam: Yes` is a recommended implementation detail; recipient filtering must be configured to use it. Do not claim an external spam engine scored the message. Recipient filtering does not protect Formvex's outbound SMTP quota/storage, so intake controls remain mandatory, explicitly confirmed by the user. The exact classification rules, including honeypot treatment, need specification.

Accepted consequences following the latest agreement: Mark not spam reclassifies without automatic resend to avoid duplicates; explicit resend is separate. Apply normal delivery-based retention to spam-tagged records: sent follows D34, failed follows D17, handled follows D16. The earlier proposed spam-specific 30-days-from-receipt expiry is superseded.

Retain unreviewed delivery failures beyond ordinary retention; failed messages may expire at 365 days old. User explicitly corrected the cap: Mark handled starts a fresh 30 days (or configured duration), including when this extends past 365 days. Applies to other handled records too. The original-submission age anchor for unhandled failures is proposed. Need to define whether repeated handled actions reset time and automatic retention anchors for sent/spam records.

Accepted storage-capacity behavior: stop accepting new submissions at the storage allowance while preserving existing records; show visitors a temporary-unavailable message. Latest default is 2 GB, adjustable from 1 GB to 10 GB inclusive, with configurable 80% normal and 90% critical warnings. This supersedes the earlier 5G selection. SQLite journal/WAL, logs, and free-disk headroom must also be considered; storage is not just a message count. Email-only fallback would violate accepted durable-first behavior and would need an explicit scope change.

### Accepted rate limits and proposed identification details

Accepted configurable starting defaults for a low-volume contact-form installation, not industry standards:

- Per form and client IP: up to 5 new submission attempts in a rolling 10 minutes, and 20 in a rolling hour; enforce both.
- Across all forms for the same client IP: up to 30 new attempts in a rolling hour.
- Count new attempts including invalid/spam attempts. Recognized identical retries do not create another submission or consume another new-submission allowance, but all HTTP requests still need a separate cheap flood limit.
- Administrator-adjustable limits. Aggregate installation protection/storage limits must also be specified because distributed bots can rotate IPs. Admin login needs a separate policy.

Use the connection IP, or a forwarded client IP only through explicitly trusted reverse proxies. Never trust arbitrary visitor-provided IP headers. An IP is an approximate network identifier, not a person: shared offices/mobile networks can share addresses, and attackers can rotate them. Cookies or browser tokens can supplement correlation but are removable. Submitted email is unverified and must not be the primary limiter identity. IPv6 address normalization/prefix policy remains an engineering detail to decide.

Return a temporary-limit response with retry timing, preserving fields; IP limits do not replace CAPTCHA, honeypots, size limits, or infrastructure flood protection. The proposal may inconvenience legitimate users sharing an IP, so defaults must be tested and adjustable.

### CAPTCHA outage notifications

Notify the separately configured administrator about verification-service outages, not visitors or every incorrect CAPTCHA. Preserve fields and offer retry without bypassing enabled verification. Accepted cadence: one alert when an outage is first observed, no more than one reminder per hour while it persists, and a recovery notice. Keep a portal warning too: SMTP may also be unavailable. Technical outage detection/retry mechanics remain to specify.

### Bootstrap recovery

Accepted: a command run by an authorized hosting administrator with server access, not a browser reset route. It resets the existing admin, generates a unique temporary password, invalidates existing sessions, and requires replacement on next login. This avoids depending on SMTP for recovery. Exact PHP command and permission checks remain to specify.

### Licensing

Standard MIT permits reuse, modification, sale, and redistribution while retaining copyright and license notices. It does not require visible Formvex branding. Later releases may use different terms subject to existing rights and compatibility; a later change does not retract permissions on previously released MIT copies. Contributions and dependency licenses must be respected. No custom attribution clause proposed.

## Answered batch: discovery, delivery, and recovery

All ten answered; see discussion history item 10 and decisions D04, D07, D12, D14-D17, D22-D24. Original question wording is preserved below.

1. Should form discovery begin with the administrator entering a page URL and clicking Detect forms? Recommendation: page-by-page discovery using the installed client, followed by selection and review.
2. If a visitor submits a newly added field before administrator re-detection, should Formvex reject the submission or accept only already-approved fields? Recommendation: reject with a generic form-unavailable message and flag the configuration mismatch in the portal rather than silently omit visitor information.
3. Should V1 support several recipient addresses for a single form? Different recipients across forms are already agreed; recipient count within one form is unresolved.
4. Should visitors receive an automatic confirmation email? Recommendation: defer in V1 to avoid allowing public requests to cause mail to arbitrary visitor-supplied addresses.
5. Should Formvex acknowledge receipt immediately after successful validation, abuse checks, and SQLite storage, leaving email sending to background processing? SMTP details remain administrator-only; storage failure must still produce a generic failure response.
6. Should temporary SMTP failures receive five retries after successive delays of 1 minute, 5 minutes, 15 minutes, 1 hour, and 6 hours? This means six total attempts including the first, followed by administrator review rather than deletion if unsuccessful.
7. If SMTP disconnects after message transmission and acceptance is unknown, should Formvex hold the submission for review rather than automatically resend? Recommendation: review to reduce duplicate emails.
8. What should marking a failed submission as handled mean for retention? Recommendation: make it eligible for deletion 30 days after that action, using the configured duration, while unreviewed failures remain protected.
9. Should V1 offer an optional CAPTCHA integration in addition to the already-agreed honeypot and rate limits? External service integration versus a self-contained initial release remains a product scope decision.
10. Should password recovery require a server-side bootstrap command that resets the existing admin, invalidates active sessions, and issues a temporary password? Bootstrap recovery is agreed; this specifies its access boundary and behavior.

## Answered batch: retention, spam, validation, and capacity

Answers are captured in D16-D17 and D24-D31 and history items 11-12. Original questions below are retained for context; their recommendations are not authoritative when rejected. Question 10 is now accepted. Rate-limit values and alert details carry forward into the next batch.

1. Should the 365-day expiry be measured from original submission, even if the failed message is marked handled shortly before expiry? Recommendation: yes, use the earlier of that cap and the handled-retention deadline.
2. Should CAPTCHA be enabled or disabled separately for each form? Recommendation: per-form setting.
3. If enabled CAPTCHA cannot be verified because its service is unavailable, should submission be blocked? Recommendation: block with a retry message; never silently bypass an enabled control.
4. Should exceeding a submission rate limit tell the visitor to wait and try again? Recommendation: show a temporary-limit message and preserve entered data.
5. Should suspected spam be discarded before full submission storage, or retained in a separate quarantine? Recommendation: discard with minimal abuse-event metadata in V1 to limit storage growth; exact detection rules remain to specify.
6. Should a retry of the same browser submission after a network timeout return the original receipt rather than create another message? Recommendation: yes, assign an attempt identifier and reuse it on retries; exact deduplication lifetime remains to specify.
7. Should a dropdown or radio answer be rejected when it is not among the administrator-approved choices? Recommendation: yes, record choices during discovery and apply trusted validation.
8. Should an optional field missing from a submission be accepted? Recommendation: yes; absence is normal for unchecked checkboxes and is not sufficient evidence of a website change. Missing required fields still fail validation.
9. Should visitors' fields clear only after confirmed durable acceptance? Recommendation: yes; preserve data on validation, network, or temporary service errors.
10. When storage reaches its configured capacity, should Formvex reject new submissions with a temporary-unavailable message while preserving existing records? Recommendation: yes; never acknowledge unstored data or prematurely delete protected failures.

## Answered batch: delivery and administration; spam consequences pending

Answers are recorded in D25-D26, D32-D37 and history item 13. Questions 4-6 prompted send-with-spam-marker behavior rather than accepting the original withholding, resend, or expiry proposals. Resolve their consequences in the next planned product round. The original questions follow for historical context.

1. Accept the proposed configurable rate-limit defaults: 5 attempts per 10 minutes and 20 per hour per IP/form, plus 30 per hour per IP across forms? These are new-attempt limits; identical retries do not create new submissions. Shared-IP false positives remain possible.
2. Should operational alert emails go to a separately configured administrator address rather than the form recipient? Recommendation: yes, keep contact inquiries and service alerts separate.
3. Accept CAPTCHA outage notification frequency of one initial alert, at most one reminder per hour, and one recovery notice? Recommendation: yes, avoid notifications for every failed request.
4. Should spam-folder submissions be withheld from SMTP delivery until an administrator marks them Not spam? Recommendation: yes.
5. Should Mark not spam automatically queue the submission for delivery to that form's configured recipient? Recommendation: yes, revalidate against trusted rules before queuing; do not bypass unsafe-data or configuration checks.
6. Should unreviewed spam automatically expire 30 days after receipt? Recommendation: yes, with configurable duration; manually handled records use the already-agreed fresh retention period. Unreviewed failed legitimate delivery remains a separate state with its longer retention.
7. For successfully emailed submissions never marked handled, should ordinary retention start at SMTP acceptance? Recommendation: yes. Later Mark handled starts a fresh configured period as agreed.
8. Should a form require a successful SMTP test before its first activation? Recommendation: yes, but clarify that a successful test does not guarantee future delivery or inbox arrival.
9. If sending stops because SMTP credentials are rejected, should Formvex continue accepting and storing valid submissions while alerting the administrator? Recommendation: yes, within storage capacity, with affected sending paused until configuration is repaired.
10. If the administrator changes a form's recipient while messages are pending, should existing messages retain the recipient assigned at acceptance? Recommendation: yes; new submissions use the changed address, avoiding silently redirecting existing messages.

## Answered batch: final planned round 1 of 2

Round complete: questions 1 and 6-10 accepted in D40-D45; clarified questions 2-5 resolved in D46-D49. Original questions and clarification wording below remain historical. The following final round completes the planned questionnaire; engineering defaults will be proposed in the specification rather than endlessly polled.

1. Should a filled honeypot classify a submission as suspected spam rather than reject it, provided all mandatory controls pass? Recommendation: yes, to align with tagged delivery. Honeypot is a hidden field people normally leave blank, but browser autofill can cause false positives.
2. Should V1 let administrators define words/phrases that flag submissions as suspected spam? Recommendation: defer keyword rules and content scoring; start with the honeypot classification to keep spam behavior predictable.
3. Should one installation support both the bare domain and its www alias? Recommendation: explicitly registered aliases only; other subdomains require separate decisions, and origin checks remain non-authenticating.
4. If a detected form lacks a stable unique identifier, may setup request adding a short data-formvex identifier to its form tag? Recommendation: yes, preserving layout while avoiding fragile position-based selection.
5. Should forms without any visitor email field be supported? Recommendation: yes, omit visitor Reply-To rather than require an email field; configured sender remains unchanged.
6. Is one standard plain-text/HTML email layout sufficient for V1? Recommendation: yes, use approved field labels/order; defer a visual template editor.
7. Should V1 allow administrators to export stored submissions as CSV? Recommendation: yes for reviewing leads and taking copies before deletion; exported text must be safe to open in spreadsheets.
8. Should the portal offer an explicit manual resend action for failed or uncertain deliveries? Recommendation: yes, confirm duplicates are possible for uncertain outcomes and record the attempt.
9. When an administrator disables a form, should already accepted pending deliveries continue? Recommendation: yes, stop new intake only; retain existing delivery commitments. A queue pause would be a distinct operation.
10. Is a normal delay of up to about one minute before the first email attempt acceptable? Recommendation: yes for contact forms; this supports a scheduled PHP worker without requiring a permanently running worker process. Requires a functioning scheduler; outage/backlog delays can be longer and must be visible.

### Clarifications for questions 2-5 (not new questions)

Historical explanation below preceded acceptance; later decisions D46-D49 supersede its pending/proposal status.

- Q2: Free maintained spam-rule resources exist, notably Apache SpamAssassin and its downloadable/updateable rules. These are rules for an engine (text patterns, scoring, header/network checks), not a universal PHP keyword list. A keyword such as "free" can occur in a legitimate sales inquiry; keywords alone do not establish spam. SpamAssassin integration adds an engine/service dependency, and mail-origin checks applied to a Formvex-generated message assess Formvex's infrastructure rather than necessarily the visitor. Recommendation remains no bundled keyword database/content scanner in V1; use established mandatory controls and honeypot tagging, allowing the recipient's existing filtering to inspect content. Optional scanner integration can be evaluated later. This is a proposal, not a decision.
- Q3: `example.com` and `www.example.com` can serve the same website but are different browser hostnames. Explicit aliases let one installation accept configured forms on both without admitting arbitrary subdomains. If one redirects to the other before forms load, only the final hostname needs form support. No claim about live logoslab.xyz redirect configuration has been made. Recommendation: primary hostname plus optional explicit aliases; cross-origin request/session details still require specification.
- Q4: A form marker is a name tag that reliably identifies a selected form when multiple forms exist or the page is rearranged. Example: `<form>` becomes `<form data-formvex="contact">`. It changes no styling or visible fields. Logoslab already has `id="contactForm"`, so an additional marker is unnecessary for that form. Recommendation: reuse an existing unique stable ID and request an added marker only where necessary.
- Q5: Outgoing From is always the website's configured sender (e.g. `forms@example.com`); To is its configured recipient. The visitor's address (e.g. `ana@example.net`) is an optional validated Reply-To so pressing Reply reaches the visitor. A form without an email field can still send anonymous feedback, a phone callback request, or other data, but no visitor email reply is possible. Logoslab's work-email field stays required. Recommendation: each form's confirmed rules decide whether visitor email is mandatory; Formvex itself need not require every form to have one. Pending user decision.

## Completed batch: final planned round 2 of 2

Answers 1-9 are recorded in D50-D58; question 10 requests a capacity recommendation, supplied in history item 18 and the consolidated specification. Original questions are retained below as history. No routine question rounds remain. See [V1 requirements and architecture](formvex-v1-requirements-and-architecture.md) for the consolidated review draft; its engineering recommendations are not additional user agreements.

1. Should the installation download be a ready-to-upload ZIP containing its PHP dependencies? Recommendation: yes; the hosting administrator still configures private storage, bootstrap, web routing, and the scheduler. End users need not run dependency-installation tools.
2. Should V1 upgrades be performed manually by the hosting administrator rather than through a self-updating portal? Recommendation: yes; document backup, migration, and rollback steps.
3. Should V1 include a server-side backup/restore command for configuration and stored submissions? Recommendation: yes; a CSV export is not a full application backup. Keep backup archives and secret material off public web paths.
4. Is support for one CAPTCHA provider sufficient initially? Recommendation: yes, retaining per-form enable/disable; select the provider through a documented technical comparison in the specification rather than making the user evaluate libraries now.
5. Accept configurable default maximum lengths of 255 characters for ordinary text fields and 10,000 for message textareas? These are product defaults, not standards. Semantic fields such as email get appropriate specific validation; overall request/field-count limits will also be specified.
6. Accept an initial configurable 500 MB Formvex data allowance with an administrator warning at 80%? Example becomes a proposed default here, not previously agreed. Account for database supporting files and operating headroom; actual disk shortage may stop acceptance earlier.
7. Should the admin portal sign out after 30 minutes of inactivity? Recommendation: yes; protect sensitive submissions on unattended devices. Absolute session duration and CSRF controls remain technical defaults to specify.
8. Should admin login impose a temporary 15-minute cooldown after five failed attempts from the same IP within 15 minutes? Recommendation: yes as a configurable starting limit, plus separate aggregate protection; do not permanently lock the sole account based on public failures.
9. Should manually deleted submissions go to Trash for seven days before permanent removal? Recommendation: yes for accidental-deletion recovery. This concerns manual deletion only; automated retention expiry remains permanent according to agreed rules. Trashed records still consume storage.
10. What maximum legitimate daily submission volume should one V1 installation be designed and tested to handle? Count all forms together. This is a capacity requirement, separate from anti-abuse limits; if unknown, propose a provisional target in the specification rather than claiming measured capacity.

## Engineering work transferred to the consolidated specification

The following list records the categories needing implementation-level detail at the close of the interview. The consolidated specification resolves them with explicit recommendations or flags release gates; it does not reopen the questionnaire.

- Backend/framework/runtime versions and package distribution; release/update/backup procedures.
- Exact deployment prerequisites; scheduler availability on hosting panels.
- API and configuration schemas, response codes, field limits and normalization, unknown field policy, label priority, multi-value controls, honeypot definition.
- Stable form identity, URL/domain/subdomain matching, stale configurations, browser discovery protocol, and trust boundaries.
- SQLite schema/migrations, concurrency, queue claiming, storage limits, retention timing, manual resolution behavior, export/backup policy.
- SMTP transport settings and secret storage, reply-to mapping, HTML escaping, email templates, configuration testing.
- Authentication/session/CSRF controls, login throttling, bootstrap lifecycle, audit logs and redaction.
- Public abuse thresholds, CAPTCHA options, proxy-aware IP handling, duplicate prevention, aggregate SMTP/storage limits.
- Acceptance tests and nonfunctional requirements; anticipated traffic and supported installation sizes.
- Author/copyright-holder naming and project contribution policy.

## Sources consulted

These support factual comparisons; recommendations above remain project judgments.

- [Apache SpamAssassin overview](https://spamassassin.apache.org/): free open-source engine with multiple kinds of scoring tests, not just keywords.
- [SpamAssassin downloads](https://spamassassin.apache.org/downloads.cgi): core tools/modules and downloadable rules with update support.

- [OWASP denial-of-service guidance](https://cheatsheetseries.owasp.org/cheatsheets/Denial_of_Service_Cheat_Sheet.html): layered controls and early inexpensive checks; does not prescribe the numerical limits proposed here.
- [MDN X-Forwarded-For](https://developer.mozilla.org/en-US/docs/Web/HTTP/Reference/Headers/X-Forwarded-For): trusted proxy handling for security uses such as rate limiting.
- [SpamAssassin tagging configuration](https://spamassassin.apache.org/full/4.0.x/doc/Mail_SpamAssassin_Conf.html): subject tagging and machine-readable headers are configurable mechanisms; this does not imply every recipient recognizes a Formvex-specific header.

- [Formspree HTML integration](https://formspree.io/html/): configure form action and named controls.
- [Formspree JavaScript integration](https://help.formspree.io/articles/building-your-form/submit-forms-with-javascript-ajax): initialize against a selected form.
- [Netlify Forms setup](https://docs.netlify.com/manage/forms/setup/): marked forms and additional JavaScript-form setup.
- [Browser form submission events](https://developer.mozilla.org/en-US/docs/Web/API/HTMLFormElement/submit_event): submission belongs to the form, including button/keyboard submission.
- [PHP capabilities](https://www.php.net/whatisphp): web-server and command-line/scheduled execution.
- [PHP SQLite support](https://www.php.net/manual/en/ref.pdo-sqlite.php).
- [PHPMailer](https://github.com/PHPMailer/PHPMailer): SMTP-capable PHP library; dependency license obligations remain separate from Formvex's license.
- [Go compilation](https://go.dev/doc/tutorial/compile-install): executable distribution.
- [Django deployment](https://docs.djangoproject.com/en/5.2/howto/deployment/): WSGI/ASGI production deployment.
- [ASP.NET Core deployment](https://learn.microsoft.com/en-us/aspnet/core/host-and-deploy/?view=aspnetcore-10.0).
- [MIT license](https://opensource.org/license/mit).
- [GitHub Open Source Guides: licensing](https://opensource.guide/legal/): licensing changes, contributors, and dependency obligations.
