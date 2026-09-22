# Formvex V1.1 — Design discussions and scope planning

Status: two rounds completed; consolidated V1.1 review draft prepared. No application implementation performed.

## Purpose and authority

The user explicitly requested discussing V1.1 after completing the V1.0 interview. Continue with ten questions at a time, grounded in existing decisions, explaining tradeoffs and challenging vague requirements. Preserve answers, rationale, revisions, and open questions here. Do not write application code during this exercise.

Baseline: [V1.0 requirements and architecture](formvex-v1-requirements-and-architecture.md), [V1.0 decision record](formvex-design-discussions.md), and [original conceptualization](formvex-conceptualization.md). The conceptualization was renamed from `.txt`; existing references have been corrected without changing its contents.

V1.0 is still a review draft, not an implemented or tested release. Do not invent production problems or user feedback. Plan V1.1 provisionally and validate its priorities against V1.0 implementation results later.

## Established version boundaries

- V1.0: standalone, PHP, SQLite, existing HTML integration, local administration, approved discovery/validation, durable submission delivery, spam controls, retry/review, operational alerts, export, retention, bootstrap, packaged installation, manual upgrades, and server-side backup/restore.
- V1.1: maintenance and troubleshooting improvements defined below and in the consolidated review draft; import/export remains optional.
- V2.0: centralized Hub for multiple websites.
- V2.1: centralized Console. This does not exclude the already-agreed V1.0 local portal.

Original V1.1 themes were configuration validation, improved spam protection/logging, deployment/update tooling, SMTP diagnostics, and installation documentation. Several baseline capabilities have already moved into V1.0. Discuss specific improvements beyond them rather than asking whether those capabilities should exist at all.

## Baseline versus candidate improvement

| Area | Already in V1.0 | V1.1 direction after round 1 |
| --- | --- | --- |
| SMTP | Activation test, retries, failure states and warnings | Guided diagnostics accepted |
| Queue | Background jobs, manual resend, automatic credential-failure pause | Administrator pause/resume accepted |
| Discovery | Manual re-detection and approval, mismatch warnings | Page-load comparison accepted; change warnings administrator-only; no visitor technical notice |
| Backups | Server-side backup/restore command | Daily/weekly/monthly choices, monthly default; rotation 1–12 successful copies, default 4; protected same-server downloads and failure alerts |
| Updates | Manual upgrades with documented rollback | Optional notifications and clear instructions accepted; installation remains manual |
| Logging | Outcomes, audit events, redaction | Redacted downloadable report accepted |
| Spam | Honeypot tagging, rate limits, one optional CAPTCHA provider | External scanner integration rejected for V1.1 |
| Configuration | Per-form mapping, private settings, validation | Limited export/import acknowledged as nice-to-have, not a mandatory release gate |

Attachments, multiple admins, multiple SMTP accounts, and visual builders remain deferred; they are not implicitly V1.1 commitments. Basic security, usable installation instructions, and reliable backup procedures must not be postponed from V1.0 just to populate a V1.1 roadmap.

## Discussion history

1. User requested the next discussion cover V1.1. Assistant reviewed the original V1.1 themes and the expanded V1.0 scope, established a separate planning record, and prepared the first ten product questions. Recommendations below are proposals only.
2. User selected maintenance and troubleshooting; accepted guided SMTP diagnostics, queue pause/resume, scheduled backups, optional update notifications, and redacted diagnostic reports. Rejected external spam-scanner integration. Requests simplest form-change detection (Q4), lay explanation of portal updates (Q7), and export/import recommendation (Q10). Explain these before starting another question batch.
3. User asks whether change detection is invisible to visitors and warnings are administrator-only; assistant confirms that design, distinguishing it from V1.0's generic submission-unavailable response if an unapproved field is actually submitted. User explicitly accepts release notifications/instructions with manual installation. User describes configuration import/export as nice-to-have; preserve that priority rather than treating it as a mandatory V1.1 release requirement.
4. User acknowledged the visibility clarification and asked what comes next. Record the simple page-load check with administrator-only warnings as accepted, and proceed to ten questions about maintenance-operation behavior. No application code is authorized by this planning discussion.
5. User accepted all round-2 recommendations and specified that same-server backups must be downloadable. Record daily backups, configurable seven-successful-copy retention, warning/email on failure, daily optional update checks, report preview, explicit SMTP test action, installation-wide delivery pause, finish in-flight attempts, and operational alerts during manual pause. Consolidate the V1.1 specification with remaining engineering details clearly proposed rather than starting another routine questionnaire.
6. User asked why seven full backups and 5 GB each. Clarified: seven historical successful copies, not seven simultaneous copies; full-backup strategy was an engineering recommendation. Actual archive size tracks backed-up data, not the storage allowance; 100 MB per copy across seven copies is about 700 MB before overhead/compression.
7. User revised the storage default to 2 GB and backups to monthly with rotation every four months; all configurable. Stated interpretation: keep four successful monthly copies, then replace the oldest after each new successful backup. This is a count-based rotation normally spanning four monthly runs, not a promise of exact age deletion after missed runs. Prior daily/seven-copy defaults are superseded. Update checks remain daily and submission retention/Trash remain unchanged. Warn that monthly backups provide fewer recovery points; changes since the last successful backup may be lost on restore.
8. User specified exact settings: backup frequency daily at first hour, weekly at first day's first hour, or monthly at first day's first hour; monthly default. Rotation default 4, adjustable 1–12; live storage default 2 GB, adjustable 1–10 GB. Assistant interprets first hour as 00:00 and first weekday as Monday in the configured timezone, explicitly stated rather than presented as a user-specified weekday. These fixed schedule presets supersede earlier arbitrary day/time selection. Rotation is successful-copy count independent of frequency.

## Current decisions

Latest review overrides: plain ZIP backups (no encryption or password protection); automatic intake/eligible queue operation after successful restore (no mandatory manual-release hold), with possible duplicate sends from older snapshots explicitly accepted; separate backup scheduled task; automatic rotation excludes manual/pre-upgrade copies. Maintenance precedes the final upgrade backup. Minimal public page-to-form mapping is accepted. Anonymous retry-window duration remains pending. See the [review resolution answers](formvex-v1-review.md) for remaining gaps. These choices supersede older engineering recommendations preserved below.

| ID | Decision | Remaining detail |
| --- | --- | --- |
| V11-D01 | Focus V1.1 on maintenance and troubleshooting. | Prioritize a bounded release around this outcome. |
| V11-D02 | Explain SMTP failure stages and suggested remedies. | Diagnostics depth, trigger, and reporting remain to specify. |
| V11-D03 | Installation-wide admin pause/resume of contact-email delivery; intake continues within capacity. | In-flight attempts finish; operational alerts remain enabled. Resume pacing is an engineering recommendation, not unlimited burst sending. |
| V11-D04 | Same-server downloadable plain-ZIP backups with daily, weekly, monthly presets; monthly default; separate scheduled task from delivery. | First-hour timing interpreted as 00:00; weekly Monday; monthly day 1; configured timezone. Archive encryption rejected during review. |
| V11-D05 | Optional portal notification of releases, checked once daily; installation remains manual. | Fetch public release metadata without website configuration, submissions, or analytics identifiers. Network metadata still exists. |
| V11-D06 | Preview a redacted diagnostic report before downloading it; do not automatically send it elsewhere. | Include health/version/queue/error context; exclude secrets and submission contents. Detailed redaction must be validated. |
| V11-D07 | No external spam-scanner integration in V1.1. | Existing V1.0 controls remain; do not keep proposing this as accepted scope. |
| V11-D08 | Keep V1.1 updates manually installed by the hosting administrator; provide notifications and clear upgrade instructions. | Explicitly agreed. Portal Update now/self-installation deferred. |
| V11-D09 | Limited form-configuration export/import is a nice-to-have candidate. | User understands and considers it useful; not a required release gate unless later promoted. Draft/review and secret-exclusion behavior remains the proposed design. |
| V11-D10 | Compare form structure at page initialization and report differences only to the administrator portal. | Accepted after clarification. No automatic config edits, visitor warning banner, website crawler, or claim of detection without page visits. V1.0 submission validation remains unchanged. |
| V11-D11 | Let in-progress SMTP attempts finish when an administrator pauses delivery. | Stop starting further contact-message attempts at the serialized pause/claim boundary; do not forcibly disconnect SMTP. |
| V11-D12 | Operational alerts remain enabled during a manual contact-delivery pause. | Does not bypass SMTP errors, provider throttles, or transport-safety constraints. |
| V11-D13 | Keep four successful scheduled backup copies by default; configurable integer count 1–12 inclusive. | Review decision: manual/pre-upgrade copies managed separately, not rotated by this policy. Replace excess scheduled copies only after success; failures cannot evict a good copy, even at count 1. Manual-copy cleanup remains open. |
| V11-D14 | Backup failure produces a portal warning and admin email. | Group repeated failures and show last successful backup; exact reminder schedule is an engineering default. |
| V11-D15 | SMTP diagnostic messages are sent only after explicit Send test action. | Opening troubleshooting/report pages does not send email. Existing activation-test requirement remains. |
| V11-D16 | Backup size follows actual stored data. | User explicitly confirmed the clarification: full backup of actual Formvex data, not allocation of the configured maximum; no separate backup-size limit introduced. ZIP size can differ due to compression/overhead. Physical free-space checks still apply. |
| V11-D17 | Manual/pre-upgrade backups remain until explicitly deleted by the administrator. | Scheduled rotation excludes them; show storage usage/warnings. |
| V11-D18 | Allow one replacement candidate beyond the configured retained-copy count during creation and verification. | Only with sufficient physical space; preserve good copies until replacement succeeds. |
| V11-D19 | Make one catch-up backup after missed scheduled dates. | Do not make multiple copies pretending to recover missed historical dates. |
| V11-D20 | No additional password confirmation to download a backup. | Existing authenticated administrator session is required; ZIP stays plain, without encryption/password protection. |
| V11-D21 | Defer rotation deletion of a backup while it is being downloaded. | Allow download to finish; bounded handling of abandoned downloads remains to specify. |
| V11-D22 | Full backup restoration invalidates all administrator sessions. | Fresh login required; no additional hold on automatic intake/eligible delivery after successful restore. |
| V11-D23 | Failed partial restoration leaves Formvex unavailable until server-side repair/recovery. | Generic server-unavailable response; do not operate on incomplete restored data. |
| V11-D24 | Restore from Trash after original expiry gives 30 new days of retention. | Record a separate recovery deadline and preserve original timestamps. |
| V11-D25 | Group repeated identical operational errors with count and first/last occurrence times. | Administrator actions remain individually audited during their retention period. Byte caps remain open. |
| V11-D26 | Defer the proposed backup free-disk safety-margin feature beyond V2. | No configurable 256 MB margin or related feature in V1/V2. Ordinary disk-full failure handling still preserves successful backups and reports failure. Supersedes earlier margin/headroom proposals. |
| V11-D27 | Release abandoned-download rotation protection after 15 minutes without progress, once confirmed inactive. | Healthy slow downloads stay protected; normal rotation, not immediate deletion, applies afterward. |
| V11-D28 | Remove confirmed abandoned incomplete backup files after 24 hours. | Never clean up active jobs or successful archives under this rule. |
| V11-D29 | Default configurable installation-wide ceiling: 10 SMTP attempts/minute. | Includes retries and alerts; any lower configured provider limit prevails. Applies to backlog recovery as well as ordinary sending. |
| V11-D30 | Backup failures: initial email, at most one reminder/hour, one recovery notification. | Portal remains authoritative when SMTP is unavailable. |
| V11-D31 | First V1.1 upgrade leaves scheduled backups inactive until admin confirmation and setup validation. | Monthly remains default; later upgrades preserve an already valid enabled schedule. |
| V11-D32 | Preserve manual sending pause across restarts/upgrades until explicit resume. | Do not clear separate fault-related pauses. |
| V11-D33 | Diagnostic reports use an explicit safe-field list. | Include versions, health, storage, queue counts, backup status and sanitized errors. Exclude addresses, hostnames, full paths, raw logs, SMTP conversations, secrets and submission contents by default. |
| V11-D34 | Generated diagnostic snapshot expires after 15 minutes. | Clean up private temporary copy; regeneration creates a new preview/download pair. Downloaded local copies and backups unaffected. |
| V11-D35 | Anonymous same-reference submission retries receive 24-hour duplicate protection. | Accepted matching retries return the original receipt without another job. Enforce trusted expiry; no silent fresh submission after expiry. No visitor account or waiting period; SMTP retry policy is separate. |

Round 3 answers supersede earlier pending statements about the anonymous retry window and earlier backup safety-margin proposals. Questions 2–10 were accepted; question 1 was deferred beyond V2, not merely assigned a different numeric value.

## Round 1: priority and standalone improvements

Questions 1-3, 5-9 have decisions above; question 10 is nice-to-have. Question 4's proposed implementation is clarified below, with administrator-only warnings. Original questions are retained below as history; rejected recommendations are not requirements.

1. What should be V1.1's main outcome: easier maintenance, easier troubleshooting, or stronger spam classification? Recommendation: maintenance and troubleshooting first, building on the existing V1.0 flows. User may choose a different concrete outcome.
2. Should SMTP diagnostics explain the failed stage and a suggested remedy? Example: distinguish connection failure, rejected credentials, and recipient rejection, beyond V1.0's test outcome/error record. Recommendation: yes.
3. Should administrators be able to pause and resume outgoing email while valid incoming submissions continue to be saved within storage limits? Recommendation: yes, as a separate queue control from disabling form intake.
4. Should the client detect changed form structure during ordinary page visits and notify the administrator before someone submits? Recommendation: useful candidate; never auto-approve changes. Requires abuse-resistant, deduplicated metadata reporting, not visitor-value collection; no traffic means no observation.
5. Should V1.1 schedule recurring backups automatically? Recommendation: build on the existing CLI command, with explicit destination, retention and capacity policy rather than claiming local backups alone survive server loss.
6. Should the portal notify administrators when a newer Formvex release is available? Recommendation: optional update checks; no automatic installation. Network request/metadata behavior needs specification if accepted.
7. Should upgrades be installable through an administrator-initiated portal action? This changes V1.0's server-admin manual-upgrade model. Recommendation: defer unless it is a release priority; it needs artifact verification, permissions, backup/migration and recovery design.
8. Should the portal generate a downloadable redacted diagnostic report for troubleshooting? Recommendation: include version, health, queue counts, and sanitized errors; exclude credentials, secrets, and submission contents by default.
9. Should V1.1 optionally integrate an external spam-scanning service already operated by the hosting administrator? This revisits the deferred content-scanner integration explicitly, not as an automatic scope inclusion. Recommendation: optional only, keeping basic standalone operation independent of a scanner.
10. Should administrators export and import reusable form configurations? This differs from submission CSV export and full installation backup. Recommendation: copy labels/validation/mapping while requiring review of page identity and recipient; exclude credentials and do not auto-activate imported forms.

## Clarifications for questions 4, 7, and 10

Original recommendations are retained below; later statuses in the decision table take precedence. Manual installation is now accepted; import/export is nice-to-have; change-warning visibility is clarified.

### Q4: simplest proactive form-change check

Recommend one comparison when the installed client initializes on a visited page: compare actual named controls/types/required attributes/choice options to the last administrator-approved discovery snapshot. Compare structure, never entered values. Do not compare HTML required flags directly to intentionally different server overrides; keep the source-discovery snapshot distinct from effective validation rules. If different, post a small bounded change report and show a portal warning directing the administrator to re-detect and review. Do not scan the whole website, add continuous DOM watching, auto-update configuration, or treat a browser report as trusted authorization to disable a form.

Example: a new Phone field appears. On the next visit, Formvex can warn that the saved field definition is outdated. Without a page visit there is no observation; an initialization-only check does not promise to detect controls inserted later by unrelated scripts. Form submission validation continues to follow V1.0 rules.

Limit/deduplicate reports per form/version and cap stored differences so public callers cannot flood notifications/storage. Proposed default: portal warning only, once per unresolved change pattern; email notifications would be a separate choice. A missing script or inaccessible page cannot report its own absence; do not describe this as complete website monitoring.

Visibility clarification: the comparison runs in the background. Only the authenticated administrator sees change details and the re-detect instruction in the portal. The visitor sees no drift banner, technical warning, or administrator configuration. This is not a guarantee that changed forms always submit successfully: under agreed V1.0 validation, an attempted submission containing unapproved fields still receives the generic form-unavailable message and preserves entered data. Detection reports alone never auto-disable a form.

### Q7: portal updates in ordinary language

An update notification says a newer release exists; the hosting administrator still installs it manually. A portal installation button means pressing Update now causes Formvex itself to replace program files and migrate its database. This is user-initiated, distinct from unattended automatic updating. A failed partial upgrade can break intake/admin access, and application write permissions become more extensive.

Accepted decision: retain manual installation in V1.1, add release notification and a clear upgrade checklist. A portal installer remains deferred; a later proposal would need package verification, maintenance mode, permissions, backup/migration, rollback, and recovery design.

### Q10: portable form-settings export/import

Recommend a limited form-configuration export/import, not a whole-site clone or replacement for backup. Example: reuse approved Name, Email, Company, Message field labels/validation on a second installation instead of recreating them individually.

Export a versioned settings file containing field definitions, labels, validation defaults, display order, custom mappings, and subject text. Exclude credentials, CAPTCHA secrets, admin accounts/sessions, submitted data, and operational secrets. Recommend requiring recipient selection afresh rather than silently carrying an old destination into another website.

On import, inspect the destination form first, show a mapping/compatibility preview, flag missing/incompatible fields and choices, and create an inactive draft. Administrator confirms mapping and recipient and completes the existing activation checks. Never overwrite an active form or automatically activate an imported configuration. V1.0 assumptions about one domain/installation and one SMTP account remain unchanged.

## Round 2: operational behavior (answered)

All ten originally answered and accepted; Q6 additionally requires downloadable same-server backups. Later user revision supersedes Q4-Q5 with monthly backups and four-copy rotation. The original questions are retained below as history. Decisions above take precedence.

1. Should Pause sending apply to the whole installation or be selectable per form? Recommendation: installation-wide only for V1.1, consistent with its single SMTP account; new accepted intake continues within storage capacity.
2. When paused, should an email attempt already in progress finish? Recommendation: yes, stop claiming new jobs rather than interrupt SMTP mid-transmission and create uncertainty.
3. Should administrative alert emails remain enabled during a user-requested delivery pause? Recommendation: yes, distinguish contact-message delivery from operational alerts. Neither may bypass an actual SMTP failure, provider limit, or transport-safety pause.
4. Should scheduled backups default to once daily at an administrator-selected time? Recommendation: yes, using a configured timezone and scheduler; exact scheduling and missed-run handling remain engineering details.
5. Should backup retention default to the seven most recent successful backups? Recommendation: yes, configurable, never replace a known-good copy with a failed/incomplete backup. This is generation retention, not a guarantee of seven calendar days if jobs are missed.
6. Should V1.1 write backups to a hosting-administrator-configured private server directory, leaving off-server transfer to existing hosting tools? Recommendation: yes, defer built-in cloud/SFTP integrations. A same-server backup cannot protect against total server loss.
7. Should backup failure generate a portal warning and an email to the existing operational-alert address? Recommendation: yes, deduplicate recurring failures to avoid alert floods and retain the last successful-backup timestamp.
8. Should optional update checks run once daily? Recommendation: yes, retrieve public release metadata without sending website/form/submission details or adding analytics identifiers; a routine HTTP request still exposes network metadata such as the server IP.
9. Should the diagnostic-report workflow show a redacted preview before download? Recommendation: yes, so the administrator can inspect the report before sharing; do not automatically transmit it to developers or a support service.
10. Should SMTP troubleshooting send a real test email only after an explicit Send test action? Recommendation: yes; passive checks can analyze recorded failures and safe configuration checks, while the UI must label any probe that connects/authenticates externally. Test delivery goes to an existing configured recipient, not a visitor-supplied address.

## Consolidated specification and next step

See [V1.1 requirements and architecture](formvex-v1.1-requirements-and-architecture.md) for the review draft. It separates agreed scope from proposed engineering details: pause/claim coordination, paced resume, backup encryption/key recovery and protected downloads, scheduler behavior, capacity headroom, untrusted discovery reports, redaction, and update-source validation. Keep configuration import/export optional and portal installation deferred. No further routine question round is needed before reviewing that draft; ask only about material blockers or requested changes.

### Completed cross-version review

**Latest user direction:** reopen resolution discussion for all eight findings, not just Trash policy. Ask ten questions at a time, record the user's choices, and do not silently apply engineering recommendations. See the review report's resolution round 1. The earlier suggestion below that one immediate decision sufficed is historical and superseded.

User requested review before V2.0. The [review report](formvex-v1-review.md) records eight gaps and proposed resolutions. In particular, V1.0 needs a restore/recovery hold distinct from V1.1 manual contact-delivery pause; heavy backups must not monopolize delivery scheduling; backup rotation, keys, temporary space and manual-backup interactions need exact contracts. Backup schedule/rotation/storage defaults are consistent with the latest answers. Recommendations have not been silently adopted or applied to the specifications. The only immediate product clarification raised is Trash recovery versus a nearer automatic-expiry deadline. No code or runtime tests were performed.
