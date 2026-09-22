# Formvex product editions and security release policy

## Authority and scope

This cross-version policy records explicit user direction. It applies to every Formvex release line and component. Version-specific requirements remain in their own specifications and decision records.

## Product editions

| Release line | Product classification | Current scope |
| --- | --- | --- |
| V1.x | **Formvex Community** | Standalone PHP/SQLite product and its maintenance improvements. |
| V2.x | **Formvex Enterprise** | Standalone spokes plus Hub API and Management Platform; later V2.x increments include approved enterprise/SaaS capabilities. |
| V3.x and later | **Formvex Enterprise** | Enterprise product evolution; V3 currently reserves focused WordPress support and integrations. |

This classification does not silently decide enterprise pricing, source availability, commercial license text, support contract or hosted-service terms. Existing licenses already granted for Community releases cannot be retroactively removed from copies distributed under those terms. Brand/trademark policy is separate from source-code licensing and must be documented before distribution claims are finalized.

## Mandatory security release gate

At the end of development for **every version**, before final release, apply, test and verify the security posture of all applicable components:

- **Spoke:** public submission endpoint/client, local portal, PHP processing core, SQLite/private files, cron entry points, SMTP/secrets, backup/restore and Hub connector where present.
- **Hub:** API authentication, spoke enrollment/credentials, command lifecycle, organization/spoke isolation, PostgreSQL access, background jobs and operational interfaces.
- **Management Platform:** authentication/recovery/optional 2FA, role and tenant authorization, CRUD and deletion controls, CSRF/session protections, rendering/input handling and administrator functions.
- **Shared supply chain and deployment:** dependencies, build/release artifacts, configuration, permissions, HTTPS/TLS, headers, secret handling, logs, backups and upgrade/migration paths.

The gate applies to the components present or changed in that version, plus regression coverage for shared security controls that a change could affect. V1 releases therefore test the Community spoke; V2 releases test spoke, Hub and Management Platform; V3 WordPress work adds its plugin/integration surfaces without dropping the earlier component checks.

## Required verification evidence

Each release must produce a versioned security report and test evidence, not merely a statement that the product is secure. At minimum:

1. Updated architecture/data-flow inventory and threat model for changed surfaces.
2. Peer security review of authentication, authorization, tenant boundaries, secrets, destructive actions and migrations.
3. Automated static analysis, dependency vulnerability review, secret scanning and release-artifact integrity checks.
4. Automated security regression tests, including role/ownership denial cases and cross-organization/cross-spoke access attempts.
5. Dynamic testing in a production-like environment for injection, XSS, CSRF, session/cookie issues, path/file exposure, request abuse/rate controls and unsafe error disclosure as applicable.
6. API-specific tests for credential scope/revocation, replay/idempotency, command target/version/expiry checks, malformed/oversized input and authorization on every object operation.
7. Spoke deployment tests on supported restricted-hosting layouts, including proof that SQLite, configuration, credentials, logs and backups cannot be downloaded from the web.
8. Backup/restore, upgrade/rollback and deletion tests showing that security state, tenant ownership, sessions and credentials are not incorrectly restored, leaked or reassigned.
9. Manual verification of findings and fixes; rerun affected tests after remediation.
10. A release decision recording residual known risks, accepted exceptions, responsible approver and follow-up deadline.

Use the current stable [OWASP Application Security Verification Standard](https://owasp.org/projects/asvs) as the web-application verification baseline and the [OWASP API Security Top 10](https://api-security.owasp.org/) as additional API coverage. Record the exact standard/version used in each release report so later standard changes do not alter historical claims.

## Security claims and unresolved release policy

The user does **not** require or expect a claim of zero vulnerabilities. The requirement is that Formvex must not be carelessly or broadly exposed: it needs a reasonable, evidence-backed and maintained security posture appropriate to the product and its risks. Security is built into design and implementation, verified before release, and revisited through updates—not added only as a final scan.

No test can prove that software has no vulnerabilities. Passing the gate means the defined controls were applied and tested, discovered issues were handled according to release policy, and residual risks were documented; it does not mean “unhackable.” Formvex must minimize attack surface, use defense in depth, secure defaults and least privilege, and provide a supported update path for later discoveries. Security limitations and supported configurations must be stated honestly. Detailed exploit information and secrets must not be placed in public reports.

The exact release-blocking severity policy still requires a user decision. Recommendation: no release with a known unresolved Critical or High vulnerability affecting a supported configuration; lower-severity exceptions require documented risk acceptance, owner and remediation target. Independent penetration-testing frequency and public/private report contents also remain to be decided.
