# Phase 6 — Review

Reviewers: Codex (three read-only passes over the committed diff), Claude Opus 5.5 (advisor).

## Findings and resolution

| # | Severity | Finding | Resolution |
|---|---|---|---|
| S1 | High | A stale scheduled re-verify could overwrite a webhook-verified attempt to FAILED/ABANDONED/MISMATCH | `verified_at IS NULL` guard on both downgrade updates (`2ad8abe`) |
| S2 | High | "No order" ignored manual orders sharing the Paystack reference; expiry and 90-day prune could touch them | `AttemptHasNoOrder` used in select and write (`2ad8abe`) |
| S3 | Medium | Scheduled marker consumed when the 10 s poll throttle rejected the claim, so Paystack was never called | Marker set in the same claim update (`2ad8abe`) |
| S4 | Medium | Session prune ignored `session.connection` | Fixed (`2ad8abe`) |
| I1 | P1 | `name|size` key collision let a bundle resolve the wrong variant (also in generated codes) | JSON-pair keys (`9355e28`) |
| I2 | P2 | Product-level conflicts on invalid rows were ignored | Consistency checked across all rows (`9355e28`) |
| I3 | P2 | Rejected products/bundles kept their codes reserved | Reserve on acceptance, release on failure (`9355e28`, `e8c9629`) |
| I4 | P2 | Duplicate hair-type aliases broke idempotency | Dedupe and sort ids (`9355e28`) |
| I5 | P2 | Audit row and revalidation job not atomic | Job dispatched inside the final audit transaction (`9355e28`) |
| I6 | P2 | Same-second snapshot filename collision | Microseconds in the name (`9355e28`) |
| I7 | P2 | Array spread renumbered numeric-looking codes (`"123"`, `"0"`) | `array_replace` (`e8c9629`) |

## Declined

- **S1 residual via `LinkAttemptToOrder`:** Codex noted a verified attempt is set to MISMATCH when a same-reference manual order has a different amount. For a verified payment that is intended Phase 4 behaviour (`ManualOrderTest` "flags an amount mismatch"): the flag keeps all evidence and surfaces the attempt for reconciliation. A fix (`658a1a6`) broke that test and was reverted (`2bf6549`). Codex then noted the flag can also be set on a never-verified attempt, because `paystackAmount()` falls back to `amount_minor` (`LinkAttemptToOrder.php:66`). This predates Phase 6 and Phase 6 jobs skip such attempts; it is deferred to the payment pass.

## Product Owner decisions (2026-10-13)

- Unknown categories and hair types are **created** by the import (dry-run lists them as "would create"; each new name gets an info line) (`1a81570`).
- Partial success stays; exit codes flag unattended runs: `0` clean, `2` completed with row errors, `1` fatal (`1a81570`).
- Per-image `catalogue.image_replaced` audit rows are kept alongside the run-level `catalog.import.applied` row.
- Payment bugs are deferred to a dedicated later pass (see Deferred).

## Deferred

- Pre-existing (Phase 4), payment pass: creating a manual order flags a never-verified same-reference attempt as MISMATCH when amounts differ (`LinkAttemptToOrder::paystackAmount()` falls back to `amount_minor`).

- Litestream freshness alerting: placeholder command until 7.4.
- Verified-but-orderless attempts are re-selected by the 15-minute job each run (harmless: they settle through `CheckCheckoutStatus`).
