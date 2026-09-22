# Formvex V1.0 / V1.1 consistency and readiness review

Status: findings documented; resolution discussion open. Every gap resolution requires the user's decision. No product decisions or application code changed by this review.

## Verdict

The agreed product scope and latest defaults are consistent, but the drafts are **not yet an implementation-ready contract**: eight findings below need resolution before the affected features are built. The user explicitly requires deciding how to resolve all gaps, including engineering choices; do not treat any recommendation as approval. Resolve these with the user before moving to V2.0. R4 is not the only decision reserved for the user.

These are document findings, not confirmed application defects: no implementation or performance tests exist in scope. Do not read this review as proof of reliability, security, or measured capacity. V2.0 discussion must not silently override the standalone decisions.

## Review coverage and checks

Reviewed the [V1.0 specification](formvex-v1-requirements-and-architecture.md), [V1.1 specification](formvex-v1.1-requirements-and-architecture.md), their [V1.0](formvex-design-discussions.md) and [V1.1](formvex-v1.1-design-discussions.md) decision records, and the historical [conceptualization](formvex-conceptualization.md) for version boundaries.

- Verified D01–D58 and V11-D01–V11-D15: 73 unique decision IDs with no gaps or duplicates.
- Checked all 13 local Markdown file links in the four specifications/decision records before adding this report: none broken.
- Compared current storage, scheduling, rotation, queue, recovery, spam, and version-boundary requirements with latest recorded answers.
- Walked through lost responses, worker interruption, SMTP acceptance ambiguity, paused delivery, full storage, backup rotation, restore, and upgrade rollback as specification scenarios.
- No external pricing/runtime claims were revalidated; this is a consistency/behavior review, not a dependency audit. No production services were contacted, email sent, backups created, or website files changed.

Source line numbers below refer to the reviewed drafts before any later correction.

## R1 — Upgrade backup can precede the final live writes

**Priority: high. Engineering gap.**

Evidence: V1.0 §12, paragraph beginning “Manual upgrades” (line 248), orders steps as back up, enter maintenance, install/migrate. V1.1 §10 preserves that path. The worker and public endpoint may still write between the backup and maintenance.

Failure scenario: take snapshot; accept a visitor's message and return a successful receipt; enter maintenance; migration fails; restore snapshot. The acknowledged submission is now missing. An SMTP send in the same interval can also make restored queue state stale.

Recommended correction: distinguish a preliminary online backup from the final rollback checkpoint. Before the final checkpoint, block new intake with an honest temporary-unavailable response, stop new job claims, drain or account for in-flight SMTP, and quiesce configuration/cleanup writes. Then take the consistent checkpoint, migrate and verify. Rolling back must restore matching code/data and remain under the restore hold described in R3.

Acceptance example: accept a message immediately before maintenance starts, simulate migration failure, restore the checkpoint, and prove the accepted record survives and no automatic duplicate send occurs.

## R2 — Duplicate suppression has no enforceable expiry contract

**Priority: high. Engineering gap.**

Evidence: V1.0 §6 accepts a client-generated UUID; §7 (line 183) proposes tombstones for at least 24 hours while requiring an expired attempt never silently become a fresh send. No trusted attempt issuance/expiry or client recovery behavior is specified. Known retry lookup is discussed before CAPTCHA but not clearly before stale configuration, disable, or capacity rejection.

Failure scenario: an old accepted attempt is replayed after its record/tombstone is removed. Its random UUID does not tell the server it was previously accepted. It can look like a new submission and be emailed again. Conversely, after a receipt is lost, changing form configuration can cause a retry of an already accepted message to report failure instead of its original receipt.

Recommended correction: define a finite retry-validity window enforced through server-issued or authenticated attempt metadata, bind form/payload, and retain duplicate-suppression evidence for that whole window. Reject expired unknown attempts rather than silently replacing their identifier; preserve input and explain that receipt could not be confirmed. A genuinely new attempt must be explicit. Under cheap request/flood checks, known accepted matching retries should recover their receipt before rules applicable only to new intake; no content disclosure or new delivery job.

This is an implementation choice, not a reason to require visitor accounts. Maintain the explicit limit that SMTP delivery itself is not exactly-once.

Acceptance examples: concurrent retries; replay after receipt loss and form disable/config change/full quota; replay immediately before/after expiry; replay after automatic record deletion; same attempt with altered payload.

## R3 — Restore needs a hard recovery hold and lifecycle reconciliation

**Priority: high. Engineering gap with a version-boundary risk.**

Evidence: V1.0 §12 (line 246) says restored sending is paused until queue review. V1.1 §4 distinguishes manual contact pause from other pauses and permits operational alerts through manual pause; §6 (line 131) reuses V1.0 restore behavior. Neither specifies a persisted restore gate covering all background activity, resume authority in V1.0, or reconciliation of restored sessions/expired records.

Failure scenario: an old backup contains valid-looking admin sessions, records whose current retention deadlines have passed, claimed jobs, or pending email already sent since backup. If restore means only V1.1's manual contact pause, alerts/other workers may operate before review. An old pending delivery can be sent again. A cleanup or dashboard routine can also act on the stale state before reconciliation.

Recommended correction: V1.0 must already have a persisted server-side recovery/maintenance hold independent of the V1.1 convenience pause. It should prevent outbound sends and mutable scheduled work and prevent visitor intake until recovery is validated. Provide a CLI release operation; do not accidentally depend on a V1.1 portal feature to restore V1.0.

Invalidate restored sessions and bootstrap/discovery grants. Reconcile expiry using original timestamps and the current clock without resetting retention. Clear/reconcile worker claims; keep possibly transmitted jobs in review. Present a restore summary before releasing the hold. Previously deleted records with no tombstone in the backup cannot be known to have been deleted afterward: document that recovery limit and do not promise perfect post-snapshot deletion reconstruction.

Acceptance example: restore an aged backup containing expired records, previously active sessions, queued alerts and stale jobs; verify no email or authenticated-session reuse is possible before recovery actions complete.

## R4 — Handling and Trash have unresolved lifecycle meanings

**Priority: medium. User-visible policy ambiguity plus engineering gap.**

Evidence: D16/D17 set a fresh retention period on Mark handled; V1.0 §§7–10 separate handling from delivery without specifying what happens to a still-pending/retrying job on that action. D58 promises seven-day Trash alongside automatic retention; §10 (line 222) recommends the earlier of Trash expiry and pre-existing automatic expiry. That precedence is a proposed interpretation, not a separately confirmed policy.

Examples:

- An administrator deals with a lead by telephone and marks the failed/retrying message handled. May Formvex still email it later? The draft does not define the action's delivery side effect.
- A sent record expires tomorrow but is manually deleted today. Seven-day Trash suggests seven days to undo, while the earlier-deadline proposal removes it tomorrow.

Recommended explicit contract: keep handling and delivery independent unless an action expressly cancels unsent jobs; label that distinction in the portal and do not silently cancel accepted deliveries. Define how expiry interacts with pending/in-flight jobs so an expiry timestamp is never reset just by retrying. This can be documented as an engineering proposal consistent with the separated model, but the visible meaning of “handled” should be reviewed.

The Trash choice needs an explicit user decision before implementation: either automatic expiry wins (current recommendation; show the actual earlier deletion date), or manual Trash guarantees seven days and therefore extends the ordinary expiry. Do not silently claim both guarantees. Neither choice requires reopening the entire retention discussion.

Acceptance examples: Mark handled on pending, retrying, accepted and uncertain records; delete one day before expiry; restore without changing original timestamps; cleanup competing with an in-flight attempt.

## R5 — Heavy backups may block the promised delivery schedule

**Priority: medium. Engineering gap.**

Evidence: V1.0 §§3/7 recommend a once-per-minute worker and avoiding overlapping workers; V1.1 §6 (line 117) reuses the recurring worker for full backups, while V1.0 retains approximately one-minute normal first-attempt latency.

Failure scenario: a multi-gigabyte backup or verification step runs under the same long-held worker lock as delivery. SMTP work and operational alerts wait until it finishes, possibly for several scheduling ticks. The specifications do not establish task isolation or a runtime bound.

Recommended correction: use the same installed CLI package/scheduler but separate job categories, invocation budgets and locks. A backup job may serialize against another backup, not monopolize delivery/alert claiming. Avoid long database write transactions during snapshots, compression or downloads. If resource pressure can delay mail, expose it and qualify latency instead of promising timely delivery while a shared worker is blocked.

Acceptance example: run an intentionally slow backup alongside new submissions and alerts; measure first-attempt latency and queue progress and verify backup overlap remains prevented.

## R6 — Backup storage and recovery contracts are incomplete

**Priority: medium. Engineering gaps, not a change to the chosen monthly default.**

Evidence: V1.0 §10 (lines 224–226) mixes allocated database/WAL bytes, reusable pages, logs and temporary data in its live allowance without an exact metric. V1.1 §6 (lines 119–137) proposes separate backup budget, publish-before-prune, pinned downloads and encrypted recovery material, but gives no inventory/rotation treatment for manual backups, no temporary-overage contract, and no key-loss recovery procedure.

Concrete missing cases:

- With rotation 1, the old valid archive and new candidate coexist before verification. Four retained copies similarly require room for a fifth candidate and workspace; a downloading old copy can remain pinned longer.
- A manual pre-upgrade backup could unexpectedly rotate away a scheduled recovery point if the archive pools are not defined.
- Deleting records can free reusable database pages without shrinking the file. One quota metric may continue to reject valid storage reuse; another may fail to account for actual disk pressure.
- A downloaded encrypted backup is not independently useful without its recovery key and the application secret material needed to decrypt stored credentials.

Recommended correction: define separate live logical/allocated usage and actual free-disk safety checks; display the measure used for quota. Define whether manual and scheduled backups share rotation, and whether temporary candidates/pinned archives may exceed retained-copy count while still respecting a disk/budget ceiling. Preserve old valid copies when generation cannot complete. Require an explicit encryption-key provisioning/export/recovery drill before enabling scheduled backup; avoid presenting archive download alone as a proven recovery path.

No universal backup-budget number should be invented from the 2 GB live quota. The existing protection against deleting good copies merely to make room should remain.

Acceptance examples: full quota with reusable SQLite pages; rotation 1 and 12; pinned download during rotation; disk-full mid-archive; manual pre-upgrade backup; fresh-machine restore with archive and key, and clear failure when the key is absent.

## R7 — The script-only client has no defined route to its form configuration

**Priority: medium. Engineering contract gap affecting the core integration promise.**

Evidence: V1.0 §5 selects existing IDs/markers; §6 (lines 140–148) exposes configuration by `{public_id}`, but does not define how a page loading only the client script discovers the relevant public IDs. Discovery grants are proposed as admin-session-bound while cross-origin public calls are credential-free, without a distinct grant-redemption contract for a configured alias.

Failure scenario: configuration is saved for Logoslab's `contactForm`, but a client with just the script reference knows its DOM ID, not the server's public form ID. Implementers may invent extra markup or a broad configuration listing. Discovery on a www alias may accidentally require the canonical admin cookie there or leak more privilege than collecting draft metadata needs.

Recommended correction: specify a minimal active-form manifest/resolution contract keyed by approved page origin/path and selectors, containing public IDs and client metadata only, or explicitly define a generated script configuration mechanism. Preserve the existing minimal-integration promise. For discovery, the authenticated portal mints a limited server-tracked capability, the configured page redeems it for draft metadata collection, and approval remains in the authenticated portal. Alias support must not require sharing the administrator cookie with every website origin.

Acceptance examples: script-only Logoslab integration, two selected forms, two pages with the same DOM form ID, bare/www aliases, inactive forms, discovery grant replay, and no recipient/secret leakage in public metadata.

## R8 — Full operational logs and audit history have no bounded lifecycle

**Priority: medium. Engineering gap affecting retention and storage.**

Evidence: V1.0 §7 groups abuse/alerts/audit under bounded storage and §10 includes logs in capacity; V1.1 §10 calls audit events immutable. Neither draft defines retention/rotation for audit events, error logs, counters, update metadata or report files, or what operational writes remain possible near the hard limit.

Failure scenario: form records expire correctly while repetitive failures/audit events continue growing. The installation stops accepting messages even though submission retention is working. If the hard quota also blocks audit/queue-result writes, already accepted delivery can lose its recorded outcome.

Recommended correction: define append-only/tamper-resistant-during-retention audit semantics rather than indefinite storage. Set bounded technical defaults per data class, reserve capacity for outcomes/cleanup/alerts, prune cheap transient counters/reports safely, and specify what is dropped or summarized during an event flood. Never log full failed payloads just to count rejects. These are engineering defaults, not an invitation to expand product scope.

Acceptance examples: repeated identical failures, long-running audit history, report generation near capacity, queue-result persistence near the limit, and cleanup with data retention still intact.

## Confirmed consistency and accepted tradeoffs

| Area | Review result |
| --- | --- |
| Version boundaries | V1.0 core standalone, V1.1 maintenance, V2.0 Hub and V2.1 Console are separated. Local portal already belongs to V1.0. |
| Storage | Current drafts use 2 GB default, inclusive 1–10 GB range, configurable 80% normal/90% critical warnings. Historical 5G references are marked superseded. |
| Backup schedule | Monthly default on day 1 at 00:00; daily/weekly presets; weekly Monday remains an explicitly disclosed interpretation. |
| Rotation | Four successful copies default, inclusive 1–12 range, independent of schedule; not four months of age-based deletion. |
| Failed retention | 365-day rule and later Mark handled +30-day reset are represented; a day-364 handling may extend to day 394. |
| Spam | Tag and send accepted suspected spam, retain Spam classification, mandatory controls still apply; Not spam does not automatically resend. |
| Recipients | One recipient per form, snapshot for pending messages; forwarding remains external. |
| Manual pause | Installation-wide, intake continues within space, active SMTP finishes, operational alerts may bypass manual pause but not a broken transport. |
| Recovery | Server-only admin recovery; backup download does not authorize portal restore. |
| Updates/reporting | Optional daily notices; manual installation; report preview/download with no automatic support transmission. |
| Optional scope | Form-configuration portability remains nice-to-have. External spam scanner and portal self-update remain excluded. |

Monthly backups intentionally have a longer potential recovery gap than daily backups; this is an accepted choice, not a review defect. Same-server archives require an independently kept copy to survive server loss. SMTP inbox delivery and exact-once sending remain outside guarantees. The 1,000/day capacity and runtime/framework/provider choices are still proposals, not approved performance claims.

## Documentation housekeeping

The V1.0 discussion record's rationale paragraphs still describe a few settled items as pending: initial-attempt latency, honeypot classification, sent/spam retention anchors and login-throttle policy. Their authoritative decisions D40/D45/D34/D39/D57 and specifications already resolve them. Historical questions should stay unchanged, but current rationale should be brought into line when applying the review.

The V1.0 spec introduction still calls V1.1 candidate scope “being explored” while V1.1 now has a consolidated draft. This is a wording issue, not scope ambiguity. Link it to the V1.1 draft during cleanup.

## V2.0 implications and recommended disposition

V2.0 can be discussed without changing these standalone decisions. Carry forward the following design constraints into that conversation, rather than implementing speculative multi-tenancy now:

- Define ownership/site scope for every future form, setting, recipient, job, rate counter, archive and admin operation.
- Keep manual pause, configuration pause and recovery hold distinct; a future site's pause must not accidentally pause or release another site's jobs.
- Make import/migration review explicit, especially SMTP credentials, key material, recipient snapshots and already-processed queues.
- Do not treat public form IDs, origin headers or a script embed as tenant authentication.
- Re-evaluate storage backend and worker scheduling against Hub workloads instead of assuming V1's SQLite/cron capacity scales unchanged.

Next action: ask focused questions about every finding and let the user decide. Do not incorporate any proposed resolution into the specifications until accepted. The prior suggestion that only R4 required a user choice was rejected by the user. Discuss ten questions at a time; record answers, tradeoffs, and still-open details. Complete gap decisions before proceeding to V2.0.

## Resolution discussion — round 2 (answers received)

### Round 3 — operational decisions (answered)

User deferred Q1 beyond V2 and accepted Q2–10. The backup disk-safety-margin feature is out of V1/V2 scope; do not introduce its proposed 256 MB setting. Ordinary disk-full errors still require safe handling. Accepted decisions are recorded as V11-D27–V11-D35; the 24-hour anonymous retry window is now approved. Recommendations below are retained as question history and are overridden by these answers.

The following were the recommendations presented for approval. Accepted numeric defaults are design choices, not measured reliability guarantees.

1. Backup disk safety: require enough space for the estimated full temporary backup workspace plus an additional configurable 256 MB free-disk margin. This is a physical-space safeguard, not a backup-size cap; no universal estimate guarantees safety against other server processes.
2. Abandoned backup downloads: release their rotation protection after 15 minutes with no download progress, only after establishing the stream is no longer active. A healthy slow download remains protected; releasing protection does not immediately delete the archive, which remains subject to ordinary rotation.
3. Interrupted backup artifacts: automatically remove confirmed abandoned incomplete backup files after 24 hours. Never remove a running job's files or a verified successful archive through this cleanup.
4. Outgoing delivery pace: use an installation-wide configurable default ceiling of 10 SMTP attempts per minute, including retries and operational alerts. Apply any lower configured provider limit as well. This is pacing, not a daily allowance or a provider-limit guarantee.
5. Backup failure emails: initial alert, at most one reminder per hour while unresolved, then one recovery notice. Portal retains status even if SMTP cannot send alerts.
6. First upgrade to V1.1: leave scheduled backups inactive until the administrator confirms the destination/schedule and setup validation succeeds. Monthly remains the schedule default; later upgrades preserve an already valid enabled schedule.
7. Preserve an existing manual sending pause across restarts and upgrades until the administrator explicitly resumes delivery. Do not silently clear other fault-related pauses either.
8. Diagnostic reports: include only an explicit safe-field list (versions, health, storage, queue counts, backup recency, sanitized error categories); omit addresses, hostnames, full server paths, raw logs and SMTP conversations by default, in addition to already excluded secrets and submission contents.
9. Diagnostic report downloads: expire and clean up the private generated snapshot after 15 minutes; regeneration provides a new preview/download pair. Do not delete the administrator's already-downloaded local copy or backups.
10. Anonymous browser retries: approve 24 hours of duplicate protection for retries using the same hidden submission reference. A matching accepted retry returns the same receipt without another job; enforce trusted expiry. No visitor login or wait is required. Past expiry, do not silently turn a retry into a fresh submission; use the already agreed visible submission-confirmation error and require an explicit new submission. SMTP retries remain independent.

### Round 2 answer history

Follow-up confirmation: user explicitly confirmed the recorded interpretation of Q1—backup size follows actual stored data, not the configured maximum; no separate backup-size limit. The explanation of Q5 (authenticated download without password re-entry) was also confirmed. These are settled decisions, not provisional interpretations.

User answers: Q1 “the backup is whatever is the total size of the storage”; Q2–4 yes; Q5 no; Q6–10 yes. Q1 is recorded as actual stored Formvex data determining backup size, without introducing a separate backup-size limit; this interpretation was disclosed, not an approval of an invented numeric budget. Q5 rejects password re-entry, not authenticated administrator access. All other recommendations in this round are accepted and recorded as V11-D17–V11-D25. The questions below are retained as discussion history, not pending approvals.

These ten questions addressed maintenance/recovery choices. The answers above determine their status. They do not reopen the chosen schedule, rotation count, archive format, or post-restore duplicate tolerance.

1. Should backup storage have its own configurable limit, separate from the live-data allowance? Recommend yes, with separate usage displays. The numeric default needs agreement if accepted; do not invent it from the 2 GB live allowance.
2. Should manual/pre-upgrade backups remain until the administrator explicitly deletes them? Recommend yes, subject to the backup storage limit, with clear usage warnings; scheduled rotation must not remove them.
3. May backup creation temporarily exceed the retained-copy count by one while verifying the replacement? Recommend yes, but never bypass storage/free-space safety checks; at rotation 4 this means four good copies plus one candidate before pruning.
4. If the server misses several backup dates, should it create just one catch-up backup when scheduling resumes? Recommend yes; backups cannot reconstruct missed historical recovery points.
5. Should an administrator re-enter their password before downloading a full backup? Recommend yes because plain ZIPs may contain submissions and restorable credentials. This does not password-protect the archive.
6. If rotation targets an archive currently downloading, should deletion wait until that download finishes? Recommend yes; any temporary extra retained archive must still respect space safety, and abandoned downloads need bounded cleanup.
7. Should restoring a backup sign out all administrator sessions? Recommend yes; require fresh login without adding a manual hold on intake or queued delivery after successful restoration.
8. If restoration fails partway through, should Formvex remain unavailable until the server administrator repairs or completes recovery? Recommend yes; show a generic server-unavailable response, and do not serve partially restored data. Automatic reopening applies only after successful restoration.
9. If an administrator restores a Trash item whose original retention deadline has passed, should it receive 30 new days of retention? Recommend yes, so the recovery action does not immediately lose the record again; preserve original timestamps and record a separate recovery deadline. This is a proposal, not a change to agreed seven-day Trash recovery.
10. Should repeated identical operational errors be grouped with a count and first/last occurrence times instead of storing an unlimited line per repeat? Recommend yes; preserve individual administrator-action audit records during their retention window. Byte caps remain to be specified.

Other still-open items include the anonymous retry window, exact storage accounting/reserves and backup budget default, discovery authorization, and detailed failure/diagnostic limits. Do not claim this is the final round before those are reviewed.

## Resolution discussion — round 1 (answers and clarifications)

Latest answers override the original review recommendations where they differ. Accepted choices have been incorporated into the specifications; remaining suggestions are not accepted merely because they appear here.

| Question | User answer / status |
| --- | --- |
| 1: upgrade checkpoint | Accepted after clarification: Formvex upgrades enter maintenance, finish/account for in-flight work, then take the final rollback backup before installing/migrating. |
| 2: anonymous retries | User requires a proper visible message for connection failures and appropriate HTTP 5xx responses for server-side submission failures. Record as D62; no visitor login required. Missing confirmation is not proof of non-receipt. Later SMTP failures remain administrator-only. Proposed 24-hour retry window is still not approved. |
| 3: post-restore operation | Reopen submissions and eligible queued delivery after successful restore. User explicitly accepts possible duplicate sends from an older snapshot to keep recovery simple and prioritize delivery attempts. |
| 4: handled | Continue delivery/retries. Handled changes review/retention, not queue eligibility. |
| 5: Trash | Guarantee a full seven days of recovery after manual deletion, overriding an earlier normal retention deadline. Restoring after original expiry needs a separate policy; not silently decided. |
| 6: scheduling | Separate scheduled backup task from delivery task. |
| 7: backup format | Plain ZIP archive, explicitly no encryption or password protection. Keep private storage and protected download. This does not reject password hashing or decide application-secret-at-rest storage. |
| 8: rotation pools | Scheduled rotation excludes manual/pre-upgrade backups; manual-copy deletion policy remains open. |
| 9: client mapping | Accepted: minimal public page-to-approved-form mapping fetched automatically by the installed script; no private recipients or credentials. |
| 10: logs | Operational logs and admin audit default to 30 days, separately configurable from submissions. |

Clarifications: Formvex cannot infer post-snapshot delivery facts from an older backup. A job shown pending in that backup might have been delivered later. The user now explicitly accepts resending in this situation; a separate surviving delivery ledger is not required to prevent this accepted risk. During restoration, partial data must not be exposed; automatic reopening is after successful completion. Original findings and questions below/above remain historical review evidence, not overrides of these answers.

Latest visitor/admin boundary: the user requires other operational issues to remain silent to visitors and clear to administrators. Accepted submissions retain their acknowledgement despite subsequent email failures or retries; details belong in the portal and existing alerts. Previously agreed validation, CAPTCHA, and rate-limit rejection feedback remains necessary to avoid false success. This clarification does not approve a retry duration.

Question 2 follow-up recommendation (pending approval): use a 24-hour anonymous retry-protection window with a hidden reference and server-verifiable expiry. Store the reference/expiry, not visitor field values, in browser storage. A matching already-accepted retry returns the original receipt without another email job. After expiry with no confirmed receipt, do not automatically create a new submission; explain uncertainty and require the visitor to submit again explicitly. This is not the outgoing SMTP retry schedule.

Anonymous retry proposal: the browser keeps an opaque attempt identifier and the server tracks its result. No visitor account, email-based identity or login is needed. A retry within the supported window can show the original receipt. Outside it, preserve input and ask before a new attempt if receipt cannot be confirmed. Closing the browser/clearing its storage may lose local correlation; the retention window and storage mechanism remain proposals.

The original ten questions follow for traceability.

The following questions cover the eight findings; R4 and R6 have multiple distinct choices. This is not a claim that one answer resolves every technical detail of a finding. Existing requirements stay in effect until explicitly revised.

1. R1: Should upgrades temporarily stop new submissions and new email attempts before taking the final rollback backup? Let in-flight attempts finish/account for them, then snapshot and upgrade. This creates a short maintenance window but closes the acknowledged-message loss gap.
2. R2: Should an uncertain browser submission be automatically retryable with the same attempt identity for 24 hours, after which a new submission requires explicit visitor confirmation? This proposes a finite guaranteed recovery window and rejects expired unknown retries; trusted expiry and matching receipt retrieval still need specification.
3. R3: After restoring a backup, should Formvex stay closed to new submissions and all outgoing email until the hosting administrator reviews the restored state and releases the recovery hold? This is distinct from ordinary V1.1 contact-delivery pause; reconcile expired data/jobs and invalidate old sessions before release.
4. R4: When an administrator marks a message handled, should pending retries stop, or should delivery continue? Stopping treats handled as resolution without further email; continuing treats it only as a review/retention annotation. An already-transmitting attempt cannot be safely recalled.
5. R4: If a message expires tomorrow but is moved to Trash today, should it disappear tomorrow or remain recoverable for a full seven days? The latter explicitly extends normal expiry; neither choice is assumed.
6. R5: Should backup processing run separately from the delivery task, so a slow backup does not hold the delivery worker's lock? Same installed PHP application/scheduler, separate task invocation/locks; requires resource contention tests, not a promise that shared hardware is unlimited.
7. R6: Should backup archives be encrypted with a recovery key kept separately by the hosting administrator? Protects downloaded archives, but losing the key prevents restoration; provisioning/export/test-restore flow must be decided if accepted.
8. R6: Should automatic rotation manage only scheduled backups, with manual/pre-upgrade backups managed separately? Protects a manual recovery checkpoint from ordinary rotation, but those copies need their own capacity/deletion rules.
9. R7: Should the installed script automatically request a minimal public page-to-form mapping from Formvex to locate its approved form settings? Preserves script-only integration; expose identifiers/selectors and client metadata only, never private recipients or credentials. Discovery-grant mechanics remain a separate sub-detail.
10. R8: Should operational logs and admin audit records each default to 30-day retention, configurable separately from submission retention? Limits growth and keeps history policy explicit; technical limits for transient counters/reports and space reserved for job outcomes remain to decide.

Further details to cover only as needed after these answers: exact backup/live-disk metrics and headroom, manual-backup retention, key setup/rotation, expiry-token protocol, restore reconciliation steps, discovery capability boundaries, and log/counter size ceilings. Do not silently select them as approved defaults.
