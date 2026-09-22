# Formvex V1.1 — Maintenance and troubleshooting

> Product classification and release gate: V1.x is **Formvex Community**. Every release must pass the applicable cross-version security verification policy in [Formvex product editions and security](formvex-product-editions-and-security.md).

Status: consolidated review draft. No implementation or verification of application behavior has occurred.

## 1. Authority and release boundary

This is an incremental specification over [V1.0](formvex-v1-requirements-and-architecture.md), grounded in the [V1.1 discussion record](formvex-v1.1-design-discussions.md), decisions V11-D01–D15. V1.0 requirements remain unless a change is explicitly stated here.

V1.1 remains standalone: PHP, SQLite, one website with explicit aliases, multiple forms, one SMTP account, one administrator, and local administration. The Hub remains V2.0 and centralized Console V2.1.

The release goal is easier maintenance and troubleshooting. **Engineering recommendations** below resolve implementation details without representing additional user agreements. V1.0 itself is still a pre-build review draft; no production feedback is assumed.

## 2. Scope

| Required feature | Change from V1.0 |
| --- | --- |
| Guided SMTP diagnostics | Explain failed stages and suggest remedies beyond basic test/results |
| Delivery pause/resume | Administrator can pause contact delivery for the installation while intake continues |
| Form-change observation | Compare form structure on page initialization and flag changes in the portal |
| Scheduled backups | Daily/weekly/monthly presets, monthly default; rotation 1–12 successful copies, default 4; protected same-server downloads and failure alerts |
| Release notifications | Optional daily update checks and clear instructions; installation stays manual |
| Diagnostic report | Redacted preview and download without automatic transmission |

**Nice-to-have:** portable form-configuration export/import; not a mandatory V1.1 release gate.

**Excluded:** portal Update now/self-installation, unattended upgrades, external spam-scanner integration, built-in cloud/SFTP backup destinations, browser-based restoration or password recovery, crawling websites, auto-approving changed forms, and new V2 multi-site management. Other V1.0 exclusions remain.

## 3. Guided diagnostics

### Agreed behavior

- Show the stage of an SMTP failure and an understandable suggested remedy.
- Send an actual test email only after an explicit administrator action. The existing V1.0 activation test remains required.
- Opening a diagnostic page or generating a report must not send messages.
- Test messages go to an existing configured recipient; no public arbitrary-destination facility.

### Engineering recommendation

Separate local checks (configuration validity, recent sanitized failures, worker heartbeat, queue age) from explicitly initiated external connection/authentication probes and Send test.

| Observed problem | Suggested explanation, qualified by evidence |
| --- | --- |
| Name resolution failure | Check SMTP hostname and server DNS access |
| Connection timeout/refusal | Check configured port, connectivity and hosting firewall; do not claim a specific cause without evidence |
| TLS negotiation/certificate failure | Check hostname, TLS mode, certificate trust and clock; never recommend disabling certificate checks |
| Authentication rejection | Check account credentials and provider authentication requirements |
| Recipient rejection | Check destination and rejection details; distinguish temporary from permanent responses |
| Temporary provider refusal | Explain that retry policy applies and sending may need pacing |
| Acceptance uncertain | Explain why review is required before resending |
| Scheduler stale | Show last heartbeat and point to server-side scheduler setup |

Never display raw authentication exchanges, credentials, tokens, or unsanitized provider messages that contain personal data. Timestamp each check and record the relevant configuration version. Label diagnosis as an observed stage, not a guaranteed root cause.

Tests need authentication, CSRF protection, a bounded timeout and rate limit, and audit records. A manual pause is not a diagnostic test ban, but the explicit test UI should say contact sending is paused. Tests and alerts still obey transport failures and provider limits.

## 4. Pause and resume

### Agreed behavior

- Pause is installation-wide for contact-message delivery.
- Valid incoming submissions continue to be accepted and saved within the existing storage allowance.
- Attempts already in progress finish; do not sever an SMTP session.
- Operational alerts remain enabled during an administrator-requested pause.
- Resume is explicit. Form disabling remains a different action: it stops new intake for that form without canceling accepted messages.

### Engineering recommendation

Store manual pause state, timestamp, and actor durably. Serialize pause changes with job claiming: a job claimed before the pause becomes effective may finish; no new contact job may be claimed afterward. Persist pause across worker/application restarts.

Manual pause is independent from credential-failure pause, provider backoff, and storage faults. Clearing manual pause must not clear another blocking condition. Operational alerts use their existing separate destination and logical job type, not a second SMTP account. Their ability to bypass manual pause does not override broken SMTP.

Paused jobs do not consume retry attempts. Due times remain recorded; on resume, process due jobs in a stable order with the existing configured provider pacing, not an unbounded catch-up burst. Show backlog count, oldest age, pause reason, and storage warnings in the portal. Never extend retention silently just because delivery is paused.

## 5. Form-change observation

### Agreed behavior

On page initialization, the existing client compares current form structure with its approved discovery snapshot. The comparison and reporting are background operations. Only the administrator sees the change warning and review instructions. No automatic schema change, form disabling, website crawl, or visitor-facing technical notice.

V1.0 validation remains: a submission containing unapproved fields may receive a generic unavailable response and retain entered data. This is distinct from a proactive change-detection warning.

### Engineering recommendation

Compare normalized named controls, types, source required attributes, and choice values against a stored source snapshot. Keep source HTML separate from intentional administrator validation overrides. Exclude Formvex's injected CAPTCHA/honeypot/protocol controls. Collect definitions only, not values entered by visitors.

Treat browser reports as untrusted observations, not proof of a website edit. Use a size-bounded report endpoint with cheap request limits, per-form/version deduplication, and bounded stored samples. Show one unresolved warning for repeated identical differences. No new email-alert channel for these reports is included by default.

Administrator re-detects the actual page, reviews changes, publishes a new version, and resolves the warning. Old clients/configuration versions must not reopen a resolved warning without showing that the report references an obsolete version.

Limits: no page visit means no check; missing client scripts cannot report their own absence; initialization-only checks do not promise detection of controls later inserted by unrelated scripts. This feature is not uptime monitoring.

## 6. Scheduled backups and downloads

### Agreed behavior

- Schedule choices: daily, weekly, or monthly; **monthly is the default**. Fixed first-hour/day presets are specified below.
- Destination: same server, in a private location managed by the hosting administrator.
- Rotation retains **4 successful backups by default**, configurable as an integer from **1 through 12 inclusive**, independent of frequency. After a new successful backup, remove the oldest excess copies.
- Failed/incomplete backups do not replace successful copies.
- Administrator can download backups.
- On failure, show a portal warning and email the existing operational-alert address; group repeated failures.

| Schedule setting | Run time in configured timezone |
| --- | --- |
| Daily | Every day at 00:00 |
| Weekly | Monday at 00:00 |
| Monthly (default) | First day of each month at 00:00 |

Timing interpretations explicitly stated to the user: "first hour" means 00:00 (midnight); "first day" of a week means Monday. These are implementation interpretations, not an explicit user weekday selection. There is no arbitrary time/day picker in these presets. The scheduler starts at or shortly after the due boundary when healthy, not a guaranteed exact-second execution.

Rotation is a count, not an age-based deletion deadline: 4 daily, 4 weekly, or 4 monthly successful recovery points depending on frequency. Missed runs can leave older copies, so show actual dates and approximate coverage. This supersedes daily/seven-copy defaults and earlier four-month age wording. A same-server archive does not survive loss of that server. Download allows the owner to keep an independent copy; automatic off-server transfer is not included.

Monthly backups trade fewer archives for less frequent recovery points. Restoring from them can lose changes since the last successful backup, normally up to roughly a month and potentially longer after failures. Manual backups remain available, especially before upgrades.

### Engineering recommendations: generation and scheduling

Reuse the V1.0 server-side backup capability, but **run backups as a separate scheduled task from email delivery**, as explicitly decided during review. Record configured frequency enum, timezone, retained-copy count, next due run, actual start/end, archive ID/size, manifest version, integrity result, and last success/failure. Enforce the frequency enum and integer 1–12 rotation bounds in server-side settings validation. Use a backup-specific lock to prevent overlapping backups without monopolizing delivery's task lock; shared-resource contention still needs testing. If the scheduler missed several runs, make one catch-up backup rather than many identical copies; display the missed schedule. Proposed timezone behavior: execute once per scheduled calendar boundary, at the next valid instant if local midnight is skipped; do not duplicate a run if the clock repeats. Recalculate the next due run when settings change without mass-generating historical backups. Lowering rotation takes effect after the next successful backup rather than immediately deleting recoverable copies.

Use a consistent SQLite snapshot, not an unsynchronized copy of only the live database file. Include configuration and necessary restore metadata, exclude prior backup archives and transient exports/log payloads unless explicitly required. Write a temporary archive, verify integrity, atomically publish, then prune expired successful generations. A checksum establishes archive integrity, not a complete restore guarantee; release tests must restore it.

**Review decision:** automatic rotation applies only to scheduled backups. Manual/pre-upgrade backups remain in a separate inventory until explicitly deleted by the administrator. Allow one temporary replacement candidate beyond the configured retained-copy count while creating/verifying a scheduled backup, subject to physical free-space checks. Missed backup dates produce one catch-up backup. Active downloads defer deletion of the affected archive.

If there is insufficient room for the next valid archive and temporary workspace, fail safely, alert, and preserve existing successful backups. Clean up known incomplete artifacts within a bounded policy. Never delete the last good copy simply to attempt an unverified replacement.

### Archive format decision and private download recommendations

Backups contain private submissions and configuration and are not the redacted diagnostic report. Store them outside the web root. Serve only through an authenticated administrator action, using an opaque archive ID resolved against a server-side inventory. Never accept arbitrary paths or expose a public static backup URL.

**Review decision:** full-backup download requires an authenticated administrator session but no additional password confirmation. Use CSRF-protected authorization, no-store cache headers, safe attachment names, audit records, and bounded concurrent streaming. **Accepted:** defer rotation deletion while an archive is downloading. Do not read a multi-gigabyte archive entirely into PHP memory.

**Review decision:** archives are plain ZIP files; the user rejected the proposed archive encryption and separately managed archive recovery key. The ZIP must contain the data/configuration and recovery material needed by the selected application-secret storage design to restore a functioning installation. No backup-specific encryption-key setup is required. Anyone obtaining an archive may access private submissions and credential material; keep private storage and authenticated downloads. This choice does not change password hashing or itself decide application-secret-at-rest encryption.

Restore remains server-side; no portal restore feature is introduced. **Review decision:** after successful restoration, reopen intake and process eligible queued email automatically, without a mandatory post-restore administrator-release hold. Do not operate against partially restored data. The user explicitly accepts possible duplicate sends when an old backup shows pending messages that were emailed afterward. A separate surviving delivery ledger is not required to prevent this accepted risk. Existing uncertain-job review and transport failure controls are not silently bypassed.

Archive clarification: plain ZIP means no encryption and no password protection; private storage and authenticated downloads remain required. Upgrade clarification: enter maintenance and finish/account for in-flight work before taking the final rollback backup, then install/migrate and verify before reopening.

### Storage interaction

User confirmation: the backup-size interpretation below is explicitly approved—actual stored data determines backup size, without a separate backup-size limit; physical free-space checks remain required.

The latest live Formvex data allowance defaults to **2 GB**, adjustable from **1 GB to 10 GB inclusive**, with configurable **80% normal** and **90% critical** warning defaults. At decimal units, default warnings occur at 1.6 GB and 1.8 GB. The 1–10 GB range applies to live data, not the combined size of rotated archives. Validate it server-side; lowering it below current usage must preserve records and stop new intake. **Latest answer, recorded interpretation:** backup size follows actual stored Formvex data; do not introduce a separate backup-size limit or allocate the live allowance per archive. Physical free-space checks remain necessary. Report live-data use and backup use separately.

Archive size follows actual backed-up data, not the 2 GB allowance. At approximately 100 MB per full backup, four monthly copies total approximately 400 MB before archive overhead/compression; live data and temporary workspace are additional. If each copy instead contains about 2 GB, four copies total approximately 8 GB before overhead/compression. These are illustrations, not reserved capacity. Full backups remain the engineering recommendation. Space for retained archives and the temporary next backup must be checked separately from the live-data allowance.

For recurring backup failures, reuse the established operational alert pattern as a proposed default: initial alert, at most one reminder/hour, and recovery notice. Portal status is essential because email or the scheduler may also be unavailable. If the scheduler stops completely, it cannot send its own alerts; show stale status when the portal next checks and document that external monitoring is separate.

## 7. Update notifications and manual installation

### Agreed behavior

Optional release checks run once daily. Show the available version and clear upgrade instructions; the hosting administrator performs installation manually. Do not send site configuration, submissions, or analytics identifiers in the check. A network request inherently exposes ordinary network metadata such as the server IP.

### Engineering recommendation

Retrieve a small public release manifest from a fixed project-controlled HTTPS endpoint, with timeouts, response-size limits, caching and schema validation. Do not follow arbitrary remote URLs or execute returned content. Compare structured versions and show compatibility requirements and trusted release-note links. Check failure is a nonblocking update-check status, not a form-processing failure. Disabled checks must produce no release-check requests.

Do not turn a V2 release notice into an instruction to migrate standalone installations automatically. Distinguish current-series compatible updates from major releases. Verify release artifacts through the documented server-side upgrade procedure before installation. No portal code replacement or writable application-directory requirement is added.

## 8. Redacted diagnostic reports

### Agreed behavior

Provide a redacted preview followed by download. Never automatically transmit the report to developers or support. Include application version, health context, queue counts and sanitized errors; exclude credentials, tokens and submission contents.

### Engineering recommendation

Generate structured allowlisted fields rather than dumping logs and hoping regexes remove secrets. Include PHP/application/schema versions, extension availability, coarse storage status, worker heartbeat, counts by delivery state, backup recency, relevant nonsecret limit settings, and categorized recent errors. Mask addresses, hostnames and filesystem paths when not required for diagnosis. Exclude raw SQL, headers, session IDs, client IP histories, configuration files, stack arguments, and SMTP transcripts by default.

Bind preview/download to the same generated snapshot so downloading cannot silently include newer sensitive content. Use admin authentication and protected no-store downloads; prefer a bounded streamed report with short-lived private temporary storage. Escape preview text and audit generation/download without logging report content. Clearly label a report as diagnostic information, not a restorable backup.

## 9. Optional configuration portability

Nice-to-have only; do not delay core release acceptance for it.

If implemented, use versioned settings export for labels, validation rules, mappings, display order and subject text. Exclude credentials, provider secrets, admin identities, visitor data and destination addresses. Require destination discovery, field compatibility review, recipient selection, and existing activation checks. Import produces an inactive draft and never overwrites a live form silently. This is not a whole-installation backup or migration to the Hub.

## 10. Architecture and migration

Extend existing PHP application services and SQLite repositories; no new daemon, database engine, SPA framework, or mandatory external scanning service.

Proposed additional state:

- Manual delivery pause, actor/timestamp and audit event retained under the agreed configurable audit-retention policy (30-day default).
- Discovery source signatures and bounded unresolved difference reports.
- Backup schedule/policy, run history, archive inventory and scheduled-versus-manual classification; plain ZIP format, no archive-encryption key reference.
- Update-check preference, last check/result and cached release metadata.
- Short-lived report/download authorization metadata where needed.

Treat settings migrations as additive and versioned. Existing forms, recipient snapshots, retry counters, retention timestamps, rate limits, and bootstrap recovery must remain unchanged. Back up before upgrade, preserve secrets, and verify the worker afterward. Where V1.0 has no stored source-discovery snapshot, request re-detection before claiming proactive monitoring coverage.

Do not enable external release checks without an explicit setting or informed setup choice. Proposed defaults for upgrades: manual sending pause off, backup schedule not active until path/schedule and permissions are validated, form monitoring active only for compatible clients with a valid baseline. Preserve the V1.0 manual backup capability throughout. No archive-encryption key setup is required under the user's plain-ZIP decision.

## 11. Acceptance criteria

| Area | Required evidence before release |
| --- | --- |
| Diagnostics | Known SMTP failures receive accurate stage-specific guidance; no secrets exposed; page/report viewing sends no email; explicit test is authenticated and audited |
| Pause | No new contact job claims after pause takes effect; active job finishes; restart preserves pause; intake continues within capacity; alerts work when transport is healthy; resume respects provider pacing |
| Form observation | Added/removed/type/choice changes produce one admin-only warning; no visitor values reported; intentional overrides do not trigger false changes; reports cannot mutate config or flood storage |
| Scheduling | Monthly default; daily 00:00, weekly Monday 00:00, monthly day 1 at 00:00 in configured timezone; invalid enum rejected; timezone transitions, settings changes, no overlapping runs, bounded missed-run handling, last-success visibility |
| Backup retention | Default 4; accept integer counts 1 and 12 and reject 0, 13 and fractions; scheduled inventory only, excludes manual/pre-upgrade copies; rotate excess only after success; interrupted creation never evicts last good copy even at count 1; disk-full preserves records and produces warning |
| Storage settings | Default 2 GB, inclusive 1–10 GB validation; reject out-of-range values; lowering below usage does not delete data; warnings use configured percentages |
| Downloads | Unauthorized, stale and traversal attempts fail; no web-root exposure; large archives stream within memory limits; rotation and download do not race |
| Restore | Recover configuration and data from a plain ZIP without encryption or password protection using server CLI; partial restore does not serve traffic; successful restore reopens intake and eligible queued sending; verify and document accepted possible resends of stale-snapshot pending jobs |
| Update checks | Once-daily optional check; no prohibited telemetry; bad/stale/oversized manifests and unavailable source do not affect forms; no portal installation |
| Diagnostic report | Seed secrets and personal data into test errors and prove exclusion from both preview and download; preview matches downloaded snapshot |
| Upgrade | V1.0 fixtures migrate with submissions, secrets, recipients, retry state and retention semantics intact; documented rollback path verified |

Suggested implementation order: pause/diagnostics; scheduled backups/protected downloads; form monitoring; reports/update notices; migration/regression testing. Configuration portability is a separate optional increment.

## 12. Review status

### Round 3 accepted decisions and deferral

These decisions supersede earlier proposals or pending labels in this draft:

- The backup disk-safety-margin feature is deferred beyond V2. Do not implement the proposed configurable 256 MB reserve in V1/V2. Ordinary disk-full failures must still fail safely, preserve existing successful backups, and appear to the administrator; no capacity guarantee is implied.
- Release abandoned-download protection after 15 minutes without progress only once the stream is confirmed inactive. Healthy slow downloads remain protected; ordinary rotation determines subsequent deletion.
- Clean up confirmed abandoned incomplete backup artifacts after 24 hours, never active jobs or successful archives.
- Default sending ceiling is a configurable 10 SMTP attempts/minute installation-wide, including retries and alerts; honor a lower configured provider limit. Apply this on resume as well as normal sending.
- Backup-failure email pattern: initial notification, at most one reminder/hour, one recovery notice. Portal status persists even if SMTP is unavailable.
- On first upgrade to V1.1, scheduled backups stay inactive until administrator confirmation and setup validation. Monthly remains the default; later upgrades preserve valid enabled schedules.
- Preserve manual pause through restarts/upgrades until explicit resume; do not clear independent fault-related pauses.
- Diagnostic reports use an explicit safe-field list: versions, health, storage, queue counts, backup recency and sanitized error categories. Omit addresses, hostnames, full paths, raw logs, SMTP conversations, secrets and submission contents by default.
- Generated diagnostic snapshots expire after 15 minutes and their private temporary copies are cleaned up. Regeneration creates a new matched preview/download pair; local downloaded copies and backups are unaffected.
- Shared submission foundation: anonymous same-reference retries have 24-hour duplicate protection with trusted expiry; accepted matching retries return the same receipt without a new job. After expiry, never silently create a fresh submission. No visitor account or waiting period is involved.

Round 2 decisions supersede earlier proposals: manual/pre-upgrade copies remain until explicit administrator deletion; one temporary replacement candidate beyond rotation count is allowed with sufficient space; missed schedules produce one catch-up backup; downloads require no extra password confirmation and rotation waits for active downloads. Full restoration invalidates all administrator sessions. Failed partial restoration stays unavailable until server-side repair; successful restoration still resumes intake/eligible delivery automatically. Recovering an expired item from Trash grants 30 new days while preserving original timestamps. Group repeated identical operational errors with count and first/last times; keep individual administrator-action audit entries. Backup-size interpretation is documented above; no separate budget default has been selected.

The two scope rounds and subsequent review decisions establish current V1.1 requirements. Round 3 resolves abandoned-download handling, resume pacing, backup-failure notifications, first-upgrade backup activation, pause persistence, report redaction/expiry and anonymous retry duration. Backup safety-margin functionality is deferred beyond V2, not an outstanding V1.1 decision. No separate backup budget is introduced. Detailed protocols and remaining proposals must not be silently represented as user-approved. Production reliability remains unproven until acceptance checks are implemented and run.
