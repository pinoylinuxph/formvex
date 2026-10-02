# Spoke release package

The production ZIP is built on a clean development machine. The customer host
receives the ZIP and runs the shipped PHP application; it does not need
Composer, Node.js, npm, Python, Go, Docker, or a persistent daemon.

The package contains `RELEASE-MANIFEST.json` and an external `.sha256` digest.
Verify both before placing the package under the private application release
directory. Never build a package from a customer private root.

The complete server-side upgrade, drain, migration-failure, rollback, health,
and scheduler procedures are in [upgrade.md](upgrade.md). The customer host
must retain the exact previous verified ZIP, its external digest, and the final
private pre-upgrade archive until the upgrade has passed post-upgrade checks.
