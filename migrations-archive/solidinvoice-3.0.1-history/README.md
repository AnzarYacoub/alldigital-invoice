# SolidInvoice 3.0.1 historical migrations (archived)

This directory contains the original Doctrine migration files from upstream
SolidInvoice, as of the `3.0.1` release (fork point tagged `alldigital-baseline-3.0.1`).

They are kept here — not deleted — for attribution, audit, provenance, upstream
reference, and possible future upstream contribution.

## Why these are archived instead of active

AllDigital Invoice starts from a fresh, single Doctrine-metadata-generated baseline
migration instead of replaying this historical chain. This is because the historical
chain fails on modern MySQL (8.4) and MariaDB (13) with a fresh/empty database:
`Version20200` temporarily widens several tables' primary keys to a composite
`(id, company_id)` shape, then its `postUp()` step re-creates legacy foreign keys
that reference the bare `id` column — which is no longer unique on its own. Modern
MySQL/MariaDB reject this ("Missing unique key for constraint ..."); older versions
did not enforce it as strictly. The final schema these migrations eventually produce
(after later steps such as `Version30000_6` collapse the primary keys back down to a
single ULID column) matches current Doctrine entity metadata and is not itself
broken — only this one intermediate step in the chain is.

AllDigital Invoice is a brand-new SaaS product with no existing populated databases
to upgrade, so this fork does not need to preserve the ability to run a fresh install
through that historical chain. The new baseline migration (see `migrations/`)
represents the same final schema, generated directly from current Doctrine entity
metadata rather than replayed through 28 historical steps.

## What this means for upgrades

This fork does not support upgrading an old, already-populated SolidInvoice database
through this historical migration chain. A future contributor wanting that path
should consult these archived files or the upstream SolidInvoice project directly.

## License and attribution

These files are unmodified from upstream SolidInvoice and remain under the MIT
license, with their original copyright notices intact. See the repository's root
`LICENSE` file. No changes have been made to any file in this directory — they are
preserved exactly as they existed at the `alldigital-baseline-3.0.1` fork point.
