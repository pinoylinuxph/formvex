# Formvex V2.0 — Hub design discussions

## Authority and current status

The user explicitly requested moving to V2.0 after answering V1.1 review round 3. This begins conceptual design, not implementation, and does not declare every earlier engineering proposal approved or reliability verified. User decisions take precedence over recommendations. Continue questions ten at a time and record answers here.

Sources: [original conceptualization](formvex-conceptualization.md), [V1 decisions](formvex-design-discussions.md), [V1.1 decisions](formvex-v1.1-design-discussions.md), and [review history](formvex-v1-review.md).

## Established concept

### Latest user decisions — override the original release split and earlier questions

| ID | Decision | Consequence |
| --- | --- | --- |
| V20-D01 | Each spoke remains a standalone Formvex installation, centrally managed by a Hub API backend and management platform. | This replaces the proposed direct-browser-to-Hub processing model. The V2 architecture includes central management; the previous API-only V2.0 / Console V2.1 split is superseded for this discussion. Exact portal features remain to be decided. |
| V20-D02 | Spokes must accommodate restricted shared hosting: ordinary HTML/PHP/JavaScript/CSS websites, such as cPanel accounts under public_html. | Do not require installing Node.js, Go, Python, their runtimes/dependencies, a background daemon, or privileged system services on a spoke. Do not assume SSH/root access. Hub infrastructure is a separate deployment decision. |
| V20-D03 | Use the hosting account's cron scheduler for periodic spoke work. | User selected cron. Treat cron capable of invoking the required PHP tasks as a supported-hosting requirement, not a guarantee about every hosting provider. No alternative scheduler is required by the current scope; frequency and task coordination remain to be decided. |
| V20-D04 | Installation paths are configurable within the hosting account's permitted directories. | public_html was only an example, not a required folder. Separate the web-accessible entry points/assets from protected database, credentials and backup locations; do not assume every folder under the account root is web-accessible or writable. |
| V20-D05 | WordPress support and integration belong to V3, not V2.0 or V2.1. | Explicit user decision: dedicate development time, testing and QA to WordPress as its own workstream. Supersedes the assistant's proposed limited V2.1 plugin. Specific supported plugins, features and compatibility matrix remain to be defined for V3. |
| V20-D06 | V2 terminology has three distinct components: Spoke, Hub and Management Platform. | **Spoke:** standalone Formvex website installation. **Hub:** centralized API/backend. **Management Platform:** browser portal that communicates with the Hub. Do not use “Hub” to mean the portal or the complete three-part system. |
| V20-D07 | Management Platform supports platform administrators and customer/site-owner accounts. | Platform administrators operate the service; customers access only their own spokes. Accepted from current round Q1. |
| V20-D08 | Initial customer roles are Owner and Administrator. | Viewer is deferred; platform administrator is a separate role and must not silently impersonate customers. Accepted from Q2. |
| V20-D09 | Enroll a spoke using a short-lived, one-time connection code entered in its local portal. | Spoke exchanges it through outbound HTTPS for its own revocable credential; no inbound Hub connection to shared hosting. Accepted from Q3. |
| V20-D10 | Hub-managed configuration is read-only in the connected spoke's local portal. | Explicit disconnection returns the spoke to standalone local management without erasing its last valid configuration. Accepted from Q4. |
| V20-D11 | SMTP secrets remain stored and configured only on the spoke. | Hub may manage non-secret settings and receive health status; it does not store or relay SMTP passwords in V2.0. Accepted from Q6. |
| V20-D12 | A spoke continues local intake and email delivery during a Hub outage. | Use its last valid local configuration; central management becomes stale/unavailable, but forms continue. Accepted from Q7. |
| V20-D13 | Spokes synchronize with the Hub every minute using cron. | One active sync with short timeouts; delayed after 5 minutes and offline after 15 minutes. Accepted from Q8. |
| V20-D14 | V2.0 remote actions are limited to form configuration, non-secret settings, pause/resume and diagnostics. | Exclude remote code upgrades, backup restoration, account recovery and permanent submission deletion until separately designed. Accepted from Q9. |
| V20-D15 | Initial Hub design/test target is 100 connected spokes. | Submission volume and concurrency require separate targets; this is not a capacity guarantee before testing. Accepted from Q10. |
| V20-D16 | Every V2 spoke retains its local SQLite database and standalone processing state. | Store submissions, local configuration cache, queue/retry state, SMTP secrets and required audit/operational state locally. Prefer a protected directory outside the web-accessible document root; if hosting layout prevents that, installation must enforce web-server denial and verify that direct download fails. Hub database technology remains undecided. |
| V20-D17 | Visitor submission contents remain exclusively on each spoke in V2.0. | The Hub stores configuration, health, queue totals and sanitized errors, not visitor names, email addresses or message bodies. The Management Platform can display only the central metadata available through the Hub. Accepted after terminology clarification. |
| V20-D18 | Use PostgreSQL as the Hub's central database. | Accepted in round 2. SQLite remains on each spoke. Exact supported PostgreSQL version and schema/operations design remain implementation decisions. |
| V20-D19 | Initial deployment is one ordinary Linux VPS running the Hub API, Management Platform and PostgreSQL. | Accepted in round 2. Keep logical component boundaries; no initial Kubernetes or microservice requirement. Backup, monitoring and production sizing still require design. |
| V20-D20 | One customer organization may own multiple spokes; additional organization membership requires invitation. | Accepted in round 2. Every central record and authorization check must be scoped to organization and, where applicable, spoke. |
| V20-D21 | Two-factor authentication is available but optional for every Management Platform user. | User explicitly changed the recommendation that it be mandatory for some roles. The platform may recommend enrollment and issue recovery codes, but cannot require it under the current decision. |
| V20-D22 | Each spoke has its own revocable and rotatable credential. | Accepted in round 2. Credentials are never shared between spokes and secrets must not appear in logs. Exact storage/rotation protocol remains to be designed. |
| V20-D23 | Use PHP for the Hub API and Management Platform backend. | Accepted after efficiency/performance/flexibility/maintenance tradeoff explanation. Reuse contracts and relevant libraries from the PHP spoke; validate the 100-spoke target through load testing rather than claiming PHP guarantees it. |
| V20-D24 | Management Platform password recovery applies to all customer and platform users. | Accepted clarification: short-lived single-use verified-email reset links; optional-2FA recovery codes where enabled; audited server-side emergency recovery for platform administrators. Spoke-local recovery remains unchanged. |
| V20-D25 | Use a durable PostgreSQL-backed per-spoke command queue/outbox for V2.0. | Accepted after enterprise-pattern explanation: unique command/idempotency ID, target, type/version, bounded payload, expiry, state and audit trail; safe redelivery and acknowledgement. Redis or a message broker is not required by this decision. |
| V20-D26 | Offline status does not automatically disconnect a spoke. | Explicit disconnect/revocation starts the proposed 30-day central metadata-retention period. Delayed after 5 minutes and offline after 15 minutes are status indicators only; the spoke remains enrolled and operates locally. Accepted after clarification. |
| V20-D27 | V2.0 customer enrollment is invitation-only; V2.1 adds public registration and automatic customer-organization provisioning. | Explicitly approved. Billing and subscriptions are deferred to a later V2.x release. Public provisioning creates central accounts/organizations and enrollment workflow; it does not install a spoke into customer hosting automatically. Free-trial policy remains undecided and is not implied by public registration. |
| V20-D28 | Exclude Redis from initial V2.0. | Explicitly approved. Use PostgreSQL for the durable command outbox and current central coordination. Keep interfaces replaceable so Redis may be added later only when measurements justify it; Redis must not become the sole durable command store. |
| V20-D29 | Do not adopt HTMX for the Management Platform. | Explicit user decision. Retain the proposed server-rendered Twig and Bootstrap approach with minimal Stimulus/vanilla JavaScript where interactivity is needed. HTMX is not a project dependency. |
| V20-D30 | The Management Platform must support CRUD operations for centrally managed resources. | Explicit user requirement. At minimum this applies, subject to role/ownership rules, to customer organizations, users/invitations, spoke registrations, form definitions and Hub-managed non-secret configuration. All operations go through the Hub API and are authorized and audited. “Delete” may be deactivate, revoke or soft-delete where history, security or recovery requires it; it does not override exclusions on remote permanent submission deletion, restore, account recovery or code upgrades. Exact resource-by-resource deletion behavior requires specification. |
| V20-D31 | Support both soft delete and hard delete, depending on the resource and need. | Explicit user decision. Define permitted deletion modes, dependencies, recovery/retention behavior and authorization per resource. Hard delete is permanent and requires explicit confirmation, elevated permission and an audit event; it must not silently cascade across organizations/spokes or override the exclusion on remotely deleting visitor submissions stored on spokes. |
| V20-D32 | Build the Management Platform from reusable global UI modules rather than recreating common behavior per page. | Explicit user requirement. Initial shared modules include tables, navigation, configurable filters, sorting, pagination, forms, action menus, confirmation dialogs, alerts, loading/empty/error states and permission-aware controls. Implement with reusable Twig components/templates plus small shared Stimulus/vanilla-JavaScript controllers where needed. |
| V20-D33 | Every version has a mandatory final security application, test and verification gate covering all applicable Formvex components. | Explicit user requirement. V2 covers Spoke, Hub and Management Platform, including shared dependencies/deployment. Produce evidence and remediate findings; do not equate a checklist with a guarantee of zero vulnerabilities. Cross-version policy: [product editions and security](formvex-product-editions-and-security.md). |
| V20-D34 | V1.x is the Community product; V2.x, V3.x and later are Enterprise products. | Explicit user product classification. This does not itself choose enterprise licensing, pricing, source availability or hosted-service terms. V3 WordPress scope remains enterprise. |
| V20-D35 | Security goal is a reasonable, evidence-backed and maintained posture, not a promise of zero vulnerabilities. | Explicit user clarification: Formvex must not be broadly exposed or released without meaningful controls/testing. Apply secure design, defense in depth, secure defaults, least privilege, verification and ongoing updates; report residual risk honestly. |

### Management Platform CRUD requirement

CRUD means Create, Read, Update and Delete through the browser platform. The Management Platform is not a passive monitoring dashboard. It must allow authorized users to create and manage resources belonging to their organization, while platform administrators manage platform-level records through their separate role.

The Hub API remains the enforcement layer: the browser cannot bypass tenant isolation, field validation, command controls or audit logging. Because visitor submissions remain only on spokes, V2.0 central CRUD does not include reading or remotely deleting their contents.

Delete supports both modes under a resource-specific policy. **Soft delete** keeps a recoverable or auditable record for a defined period. **Hard delete** permanently removes the permitted central data and requires explicit confirmation, elevated authorization and an audit event. A later resource matrix must define which modes apply to organizations, users, invitations, spokes, forms, configuration versions, commands and audit-related records, including dependency/cascade behavior. Hard delete does not override the exclusions on remote submission deletion, backup restoration, account recovery or code upgrades.

### Reusable Management Platform modules

Common behavior must have one maintained implementation and a clear configuration contract. The shared table module should support configured columns, safe sorting, pagination, filters, row/bulk actions, empty/loading/error states and permission-aware visibility. The filter module should define field type, allowed operators/options, defaults, URL/query-state behavior and server-side validation. Navigation should derive visible entries and active state from routes and authorization, without treating hidden links as access control. Shared forms, confirmation dialogs, notices and status badges must use consistent validation, accessibility and audit-trigger behavior.

Reuse must not become one oversized universal component full of page-specific conditions. Keep domain rules and authorization in Hub application services/API policies; UI components render and submit only the options their page configuration permits. Extend a common component where behavior is genuinely shared, and use a focused page-specific component where forcing reuse would make the shared contract unstable or unsafe.

Latest clarification: cron availability is now an explicit deployment requirement rather than an unresolved scheduler choice. Use scheduled PHP tasks for background queue work and the proposed Hub synchronization connector. The user permits installation under any suitable folder within the hosting account root; actual routing and filesystem permissions determine which paths can serve public endpoints and which can store private data. Earlier conditional scheduler wording below is superseded by V20-D03. Cron frequency and connector behavior have not yet been approved.

Packaging direction consistent with V1: ship PHP dependencies in the ready-to-upload release; do not require Composer or a build tool on client hosting. JavaScript runs in the visitor's browser. PHP compatibility, required extensions (including SQLite support), writable private storage, outbound HTTPS/SMTP access, and scheduled-task availability must be checked rather than assumed merely because hosting supports PHP. Protect databases, credentials and backups from public downloads; a public_html deployment must not imply publicly served private files. Server-side bootstrap/recovery must remain protected; a no-shell setup/recovery mechanism is not yet selected.

Architecture recommendation, not yet separately approved: keep local validation, storage, queue and SMTP processing in the existing V1 core; add a PHP connector for central management. Prefer short outbound HTTPS API calls so the Hub need not access a new inbound port on each spoke. A hosting-panel scheduled task could poll the Hub and process local work, if available. Do not promise timely background delivery/synchronization on hosts without a scheduler, or use visitor traffic as an equivalent guarantee. Spoke independence during Hub outages is recommended; command scope, polling interval and alternate scheduling are still open.

Core reuse clarification: no full rewrite is intended. Separate local management operations from portal code so both the local portal and connector can use the same validated functions. Some refactoring may be necessary; no implemented core was inspected or certified as reusable in this discussion.

The original concept and round below are retained as historical context. Questions about a CLI-only V2, direct browser-to-Hub submissions, and moving local queues to the Hub are superseded by V20-D01 and must not be asked as if still the selected architecture.

Original conceptualization sections 17–18 define V2.0 as Formvex Hub: a centralized API serving multiple registered websites, with independent site SMTP settings, recipients, validation, form configurations, and centralized logging/security. Reuse the existing client/core/server separation rather than rewriting the processing engine. V2.1 is the planned centralized browser-based Console.

Later V1 decisions supersede outdated original references to Node.js, no submission database, visitor-visible SMTP failures, and no local administration. PHP is the current backend choice, SQLite the standalone database, and the standalone portal already exists. A local portal does not by itself approve a centralized multi-site Console for V2.0. Hub database topology and operational requirements remain undecided.

Preserve accepted minimal HTML integration, existing form styling/buttons, durable acceptance before success, private backend operational errors, and mandatory validation/abuse controls unless the user explicitly changes them. Public browser identifiers must not be treated as secret authorization credentials. The backup disk-safety-margin feature is explicitly deferred beyond V2. Do not automatically apply per-installation limits globally across a Hub without discussing site isolation.

## Current V2.0 unresolved decisions

The architecture direction is settled, but the V2.0 specification is not complete. These are decision areas, not newly approved features or implementation failures:

1. Portal users, account model and permissions: operator-only versus customer access, site ownership/isolation, administrator recovery and security.
2. Enrollment and trust: how an existing spoke is connected, authorized, issued credentials, disconnected and revoked.
3. Management scope: which settings/actions the Hub may control; remote software installation/upgrades are not implicitly authorized by central management.
4. Configuration authority: how local and central edits interact, version checks and conflict resolution.
5. Synchronization: outbound polling proposal, cron interval, command acknowledgements, retries and stale/offline status.
6. Hub outage behavior: recommendation to continue local processing with the last valid configuration; this needs explicit acceptance and reconnection semantics.
7. Central data: whether the Hub stores only configuration/health summaries or also visitor submissions, logs, credentials and backup copies; retention and privacy follow that decision.
8. Lifecycle and recovery: disconnect/reconnect, re-enrollment after spoke restore, site-specific versus Hub-wide backup/restore and protected bootstrap on no-shell hosting.
9. Hub deployment and scale: hosting requirements, backend/database choices and expected spoke/activity counts; spoke PHP/cron requirements are already settled.
10. Release boundaries: exact portal features and whether customer signup, billing, subscriptions, non-WordPress widgets or other integrations are in scope. WordPress is already assigned to V3 and must not be reopened here.

The original direct-browser-to-Hub and CLI-only questions below are superseded, not unresolved blockers. V2 does not currently call for transferring local submission processing to the Hub. These areas should be discussed through refreshed ten-question rounds grounded in the selected architecture.

## Current round 1 — Hub accounts, enrollment and control (complete)

Answers received: Q1–4 and Q6–10 accepted their recommendations. After terminology clarification, Q5 was also accepted and recorded as V20-D17. The user also asked whether V2 spokes retain SQLite: yes, recorded as V20-D16. A connected spoke remains a standalone processor and local source of submission/queue data; central management does not remove its database.

Terminology for all following discussion:

- **Spoke:** the standalone Formvex installation attached to a website.
- **Hub:** the centralized API/backend used by spokes and the Management Platform.
- **Management Platform:** the browser-based SaaS-style portal; it reads/writes authorized data through the Hub.

These questions use the selected architecture: standalone PHP spokes, outbound HTTPS API communication, hosting cron, and a centralized management portal. Recommendations are not decisions until the user answers.

1. Portal users: should V2.0 support both a Formvex platform administrator and customer/site-owner accounts? Recommendation: yes. Platform administrators operate the Hub; each customer can access only their own spokes. This makes it SaaS-style management rather than an operator-only internal dashboard.
2. Initial customer roles: should each customer organization have Owner, Administrator and Viewer roles? Recommendation: begin with Owner and Administrator only to reduce permission complexity; add read-only Viewer later if needed. Platform administrator remains a separate role and must not silently impersonate customers.
3. Spoke enrollment: should the portal generate a short-lived one-time connection code that the administrator enters in the spoke's local portal? Recommendation: yes. The spoke makes the outbound HTTPS request, exchanges the code for its own revocable credential, and the code then becomes unusable. No Hub-initiated inbound connection to shared hosting is required.
4. Configuration authority: after enrollment, should Hub-managed settings be editable only in the Hub, while the local portal shows them read-only? Recommendation: yes, to prevent conflicting edits. The local administrator can explicitly disconnect the spoke to return it to standalone local management; disconnection must not erase its last valid local configuration.
5. Central visitor data: should visitor submissions and message contents remain only on each spoke in V2.0? **Accepted.** Here, **Hub means the API/backend**. The spoke sends the Hub health, queue counts, configuration state and sanitized errors—not names, email addresses or message bodies. The Management Platform displays only what the Hub stores. Centralized submission viewing can be separately considered later because it substantially changes privacy, retention and breach impact.
6. SMTP secrets: should SMTP passwords remain stored only on the spoke and be configured through its protected local portal? Recommendation: yes for V2.0. The Hub may manage non-secret delivery settings and report SMTP health, but should not store or relay SMTP passwords initially.
7. Hub outage behavior: should a spoke keep accepting forms and delivering email from its last valid local configuration while the Hub is unreachable? Recommendation: yes. Show the spoke as offline/stale centrally after a defined period, but never stop local form service merely because central management is unavailable.
8. Synchronization interval: should cron contact the Hub every minute for commands/status, while local email processing remains independent? Recommendation: yes, with short timeouts and one active synchronization at a time. The Hub can show the spoke as delayed after 5 minutes and offline after 15 minutes; exact thresholds require approval.
9. Remote action limits: should V2.0 allow central form configuration, non-secret settings, pause/resume and diagnostics, but exclude remote code upgrades, backup restoration, bootstrap/password recovery and permanent submission deletion? Recommendation: yes. Those higher-risk actions remain local/server-side until separately designed and approved.
10. Initial scale target: how many connected spokes should one Hub be designed and tested for? A concrete target is required; recommend 100 connected spokes as the first reference target, with submission volume and simultaneous activity tested separately. This is a design/test target, not a guaranteed capacity claim before measurement.

Current status: all ten questions are answered. Q1–4 and Q6–10 are V20-D07–V20-D15; Q5 is V20-D17. V20-D16 separately confirms the spoke's SQLite database.

## Current round 2 — Hub and Management Platform foundation (pending)

Answers received so far: Q2, Q3, Q5 and Q8 accepted. Q6 was accepted with a change: two-factor authentication is optional for all users, recorded as V20-D21. Q1, Q4, Q7, Q9 and Q10 require the clarifications below and remain pending.

Latest follow-up: Hub PHP (Q1), all-user password recovery (Q7), the PostgreSQL command pattern (Q9), and explicit-disconnect behavior (Q10) are accepted as V20-D23–V20-D26. The user subsequently explicitly approved the public-registration roadmap (Q4), recorded as V20-D27. All original round 2 questions are now answered. The separate Redis recommendation in the technology-stack clarification remains pending.

### Proposed Management Platform technology stack — pending as a combined stack

Accepted foundations are PHP, PostgreSQL and one ordinary Linux VPS. Recommended initial stack:

- Linux VPS with Nginx terminating HTTPS and serving static assets.
- PHP-FPM modular monolith using Symfony for both the versioned Hub JSON API and Management Platform backend. Keep API/application/domain boundaries explicit even in one deployable application.
- Server-rendered Twig pages with Bootstrap and small Stimulus/vanilla-JavaScript enhancements. Do not require a separate React/Vue single-page application for the initial management workflows.
- PostgreSQL for organizations, users, spoke registry, configuration versions, command outbox, health summaries, sessions and audit metadata. It never receives visitor submission contents under V20-D17.
- PHP CLI background workers managed on the controlled Hub server (for account email, command maintenance and cleanup); exact service manager and retry rules remain to specify.
- Transactional SMTP for invitations, verification, password reset and Hub operational alerts.
- Versioned HTTPS JSON API with documented schemas; spoke communication remains outbound from restricted hosting.
- Centralized structured logs, health endpoints, backups and monitoring; detailed retention and operational tooling remain to decide.

**Redis decision:** do not require Redis in the initial V2.0 deployment. At 100 spokes and one Hub instance, PostgreSQL provides the durable command outbox and central state while reducing installation, backup, monitoring and failure-recovery complexity. Design queue/cache/session interfaces so Redis can be introduced later if measured contention, multiple Hub instances, high-rate ephemeral counters or real-time fan-out justify it. Do not use Redis as the only durable copy of commands. Explicitly accepted as V20-D28.

### HTMX clarification — rejected

HTMX is a small browser-side JavaScript library that adds attributes such as `hx-get`, `hx-post` and `hx-target` to HTML. An interaction sends an HTTP request; the server normally returns rendered HTML fragments, and HTMX replaces the selected part of the page. In the proposed Symfony platform, Twig can render those fragments. The Hub's spoke-facing API remains versioned JSON; HTMX would apply only to Management Platform browser interactions.

Potential fit: server-rendered account/spoke tables, filters, forms, modals, status refresh and pagination without building a separate React/Vue application. This aligns with a single PHP codebase and lower frontend maintenance. It does not remove authentication, CSRF protection, authorization, validation or accessibility requirements. Complex highly interactive client-side state may still require ordinary JavaScript or a dedicated frontend approach. Official reference: [HTMX documentation](https://htmx.org/docs/).

Decision: user explicitly rejected adopting HTMX. Keep Symfony + Twig + Bootstrap with minimal Stimulus/vanilla JavaScript for Management Platform interactions. The spoke-to-Hub API remains unaffected and uses versioned JSON over HTTPS.

### Clarifications requested in round 2

- **Q1 language tradeoff:** PHP is the recommended overall choice for the current 100-spoke target because it maximizes V1 core/contract reuse, keeps one backend language, and reduces maintenance. It is not the universal winner in raw throughput; Go would generally be a stronger candidate for a highly concurrent API on that criterion alone, but would duplicate or require porting shared business rules. The Hub is mainly HTTPS/PostgreSQL I/O and its measured workload does not yet justify a second backend stack. Proposed implementation direction: a modular PHP application behind PHP-FPM with production OPcache and PostgreSQL, then load-test before making capacity claims. Official references: [PHP-FPM](https://www.php.net/manual/en/install.fpm.php), [Symfony production performance](https://symfony.com/doc/current/performance.html), and [Go concurrency guidance](https://go.dev/doc/effective_go#concurrency). User approval of PHP remains pending.
- **Q4 roadmap recommendation:** place public account registration and automatic customer-organization provisioning in V2.1. A free trial can join V2.1 only after trial limits, abuse handling and expiry behavior are decided. “Automatic provisioning” means creating the central account/organization and enrollment workflow; it cannot install the spoke into a customer's restricted hosting account automatically. Billing/subscriptions can be a later V2.x increment rather than blocking V2.1. User decision remains pending.
- **Q7 user scope:** verified-email password reset applies to all Management Platform users: customer Owners, customer Administrators and platform administrators. Recommend short-lived single-use reset links for all, recovery codes for optional two-factor authentication, and an audited server-side emergency reset available only to the Hub operator for platform-administrator recovery. It does not change spoke-local recovery. User decision remains pending.
- **Q9 enterprise command pattern:** recommend a durable per-spoke command table/outbox in PostgreSQL. Each command has a unique ID/idempotency key, target spoke, version/type, bounded payload, creation/expiry time and status. An authenticated spoke polls over HTTPS, receives ordered eligible commands, executes each supported action at most once locally, and acknowledges success or a sanitized failure. Lost responses cause safe redelivery of the same ID, not a repeated action. Hub records audit/status transitions and rejects expired, unsupported or wrong-spoke commands. For the 100-spoke target, a database-backed queue is simpler than requiring Kafka/RabbitMQ; an internal broker can be added later if measurements justify it. User decision remains pending.
- **Q10 disconnect meaning:** disconnect is an explicit unlink/revoke action by an authorized customer or platform administrator, or an explicit security revocation—not a heartbeat timeout. No automatic disconnect duration is recommended. Missing sync marks a spoke delayed after 5 minutes and offline after 15 minutes, but it stays enrolled indefinitely and continues locally. The proposed 30-day central metadata-retention clock starts only after explicit disconnection. User approval remains pending.

Recommendations are not decisions until the user answers.

1. Hub backend language: should the Hub API also use PHP so it can share validation contracts and libraries with the spoke? Recommendation: yes. It does not need to run on restricted shared hosting; use a supported modern PHP runtime on controlled Hub infrastructure.
2. Hub database: should the centralized Hub use PostgreSQL instead of SQLite? Recommendation: yes. SQLite remains appropriate for isolated spokes; PostgreSQL is better suited to concurrent portal/API activity and tenant-scoped central records.
3. Initial Hub deployment: should V2.0 target one ordinary Linux VPS with the Hub API, Management Platform and PostgreSQL, rather than Kubernetes or microservices? Recommendation: yes. Keep components logically separated but deploy simply; scaling out can follow measured need.
4. Customer enrollment: should V2.0 be invitation-only, with the platform administrator creating or inviting the first Owner for each customer organization? Recommendation: yes. Public self-registration, trials and automatic provisioning add abuse and business-policy work and can be considered later.
5. Organization model: should one customer organization own multiple spokes, with a user able to belong to more than one organization only through separate invitations? Recommendation: yes. Every API record must carry organization/site ownership so one customer cannot access another's data.
6. Management authentication: should email/password plus two-factor authentication be mandatory for platform administrators and customer Owners, and optional for customer Administrators? Recommendation: yes. Recovery codes must be generated when two-factor authentication is enabled.
7. Password recovery: should Management Platform users recover accounts through verified email, unlike the server-only recovery of a standalone spoke? Recommendation: yes, using short-lived single-use reset links. Platform administrators also need separately stored recovery codes; spoke-local recovery remains unchanged.
8. Spoke credential lifecycle: should each enrolled spoke receive a unique revocable credential that is never shared with another spoke and can be rotated from either the Management Platform or local spoke portal? Recommendation: yes. Store only a protected verifier or encrypted credential as appropriate; never log the secret.
9. Hub command delivery: should spokes poll for versioned commands, acknowledge success/failure, and ignore a command already completed? Recommendation: yes. This prevents repeated actions after a lost API response; commands need expiry and an audit trail.
10. Disconnect behavior: when a customer disconnects a spoke, should the Hub revoke its credential immediately but retain its central configuration/audit metadata for 30 days before deletion? Recommendation: yes. The spoke keeps operating locally with its last valid configuration; reconnection during the 30 days requires fresh enrollment and explicit confirmation rather than silently trusting an old credential.

No answers to round 2 have yet been received.

## Earlier round and clarifications — historical context

### WordPress roadmap — V3 accepted

User chose V3 for WordPress support and integrations so development, testing and QA can focus on this work. WordPress-specific packaging, integrations and compatibility commitments are excluded from V2.0 and V2.1. The earlier recommendation to ship a limited plugin in V2.1 is superseded. This establishes a release workstream, not a promise to support every WordPress form plugin or page builder.

Distinguish ordinary custom HTML forms on a WordPress page (potentially compatible with existing JS/PHP integration, but not a V2 WordPress support commitment) from a supported native WordPress plugin experience and specific third-party form-plugin adapters. A PHP-core-based local spoke plugin with WordPress-admin configuration/Hub connection remains an architectural option for V3, not an approved feature specification. Exact packaging, local storage and WordPress permission mapping need design; core reuse does not mean embedding an unmodified standalone application blindly. Do not redirect all WordPress mail or assume third-party form handlers can be intercepted safely; adapters must avoid duplicate submission/email processing.

Keep hosting cron for reliable background work. WordPress documents that its default WP-Cron is triggered by page loads and can be connected to the system scheduler. WordPress also provides an HTTP API suitable for outbound Hub requests. Sources: [HTTP API](https://developer.wordpress.org/plugins/http-api/), [WP-Cron](https://developer.wordpress.org/plugins/cron/), [system scheduler integration](https://developer.wordpress.org/plugins/cron/hooking-wp-cron-into-the-system-task-scheduler/).

Decision settled: WordPress support and integrations target V3. Define their detailed scope and focused QA plan during V3 design; no new V2.1 scope is inferred from this deferral. Continue current design on standalone PHP spokes, Hub API and centralized management.

### Earlier clarification — portal and communication (superseded in part)

Historical discussion below preceded V20-D01. The standalone-spoke model and inclusion of central management are now selected; only their detailed implementation and feature scope remain open.

User paused the question round to clarify their understanding of V2 as a SaaS-style management portal. The original document splits centralized API into V2.0 and Console into V2.1; assistant recommended combining Hub and centralized management portal in V2.0. That release-boundary change awaits explicit confirmation. Public signup, billing and subscriptions are separate undecided features, not implied by a portal.

User then asked whether spoke-to-Hub communication uses HTTP and whether an API would be better. Clarification: these are complementary, not competing choices. Recommend a versioned HTTP API over HTTPS, using JSON request/response bodies for form submissions and configuration exchanges where applicable. API defines operations and data contracts; HTTPS transports requests securely. No specific endpoint path or protocol implementation is approved yet.

Two spoke models remain open: (1) static website JavaScript calls the Hub API directly, without local PHP; (2) a locally installed Formvex backend calls the Hub API. Assistant recommends the direct-browser model for simpler static-site integration, but the user has not selected it. A central portal does not require a backend agent on each website. Browser site/form identifiers are public, not secret credentials; server-side agents could use private scoped credentials, with lifecycle and permissions still to design. Durable acceptance, independent server-side validation and abuse controls remain required in either model.

The original ten questions below remain pending and should be revisited after these conceptual clarifications, not treated as answered by them.

1. Who administers a Hub: only its hosting operator, or individual customer/site owners too? Recommend operator-only in V2.0, deferring customer accounts and delegated access. Community members can still run independent Hub installations.
2. Should V2.0 retain the original boundary of no centralized browser Console until V2.1? Recommend yes; do not silently expand the existing standalone portal into multi-site management.
3. If the Console remains deferred, should V2.0 provide server-side commands as the primary administrative interface? Recommend commands for registering sites, configuring forms/SMTP and inspecting queues, with actionable output. This concerns Hub operators, not nontechnical visitors or standalone installers.
4. Should each website's browser submit directly to the Hub with no local Formvex PHP backend? Recommend yes, preserving the script/button integration and explicitly approved website origins; direct cross-origin submission needs independent abuse controls, not secret browser keys.
5. Should site registration require proof of domain control before activation? Recommend an operator-assisted verification file on the website, checked by the Hub, rather than accepting an arbitrary claimed domain. Domain verification does not authenticate individual visitor requests.
6. Should suspension of one website stop only its new submissions while already accepted messages continue sending? Recommend yes, with delivery pause a separate action consistent with V1. Unrelated websites stay operational.
7. Should the configurable 2 GB default live-data allowance apply independently to each registered website? Recommend yes; backup archives remain separate and actual server capacity still limits the installation. Do not treat this as reserved disk or introduce the deferred backup margin feature.
8. For initial standalone-to-Hub migration, should scope be configuration transfer only, leaving history and pending jobs in the old installation? Recommend yes; drain old queued work there and send new submissions to the Hub after cutover. Detailed safe cutover and credential transfer still need design.
9. Should V2.0 keep standard HTML forms only, with custom widgets and third-party embedded forms still excluded? Recommend yes; their possible V2 inclusion was discussed but never approved.
10. What number of websites should one initial Hub be designed and tested to support? Request a user target, not a fabricated benchmark. Submission volume and burst targets will be separate follow-up questions.

This original question round was interrupted by architectural clarification. Do not treat its recommendations as accepted or its superseded assumptions as current pending choices. Use the current unresolved-decision inventory above for the next round.
