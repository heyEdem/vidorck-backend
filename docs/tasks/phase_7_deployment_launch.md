# Phase 7 — Deployment, Backups & Launch

**Goal:** Run the API on a hardened Contabo VPS behind Cloudflare, with continuous off-site backups, zero-downtime deploys, a staging environment, and a restore drill completed before launch.
**Estimate:** ~4-5 days (plus 2–4 weeks lead time for the Arkesel sender ID) · **Depends on:** Phases 0–6.
**Source:** [§8 Non-Functional](../product-spec.md#8-non-functional-requirements) · [§9 Environments](../product-spec.md#9-environments) · [§10 Success Criteria](../product-spec.md#10-success-criteria) · [§14 Open Items](../product-spec.md#14-open-items-before-build)

## Current Code Audit

- [ ] No server, DNS, deploy pipeline, backup configuration, or runbooks exist.

## Tasks

### 7.1 Provision the VPS
- Ubuntu 24.04 LTS; non-root deploy user with SSH key only; password and root login disabled; fail2ban.
- Install Nginx, PHP 8.4-FPM (`pdo_sqlite`, `gd`, `intl`, `mbstring`, `zip`, `bcmath`), SQLite 3, Composer, supervisor.
- UFW: allow SSH, and 80/443 only from Cloudflare IP ranges (script to refresh the list).
- Files: `deploy/provision.sh`, `deploy/nginx/api.conf`, `deploy/ufw-cloudflare.sh`
- Done when: direct requests to the server IP on 443 are refused.

### 7.2 Configure Cloudflare and Nginx
- DNS for `api` and `staging-api`; proxied; TLS `Full (strict)` with a Cloudflare origin certificate on Nginx.
- Nginx: PHP via FPM for `/api` and `/sanctum`; static `/media/*` with 1-year immutable caching; deny PHP under `/media`; deny dotfiles and `database/`.
- Cloudflare cache rule for `/media/*`; bypass cache for `/api/*`.
- Configure Nginx real-IP handling using the current Cloudflare source ranges (or narrowly trusted Laravel proxies); verify Laravel sees the original client IP so unrelated users do not share rate-limit buckets.
- Files: `deploy/nginx/api.conf`, `docs/runbooks/cloudflare.md`
- Done when: `curl -I https://api.vidorckhair.com/media/...` shows a Cloudflare cache HIT on the second request.

### 7.3 Configure queue worker and scheduler
- Supervisor program running `php artisan queue:work --tries=3 --max-time=3600` as the deploy user.
- Cron `* * * * * php artisan schedule:run`.
- Files: `deploy/supervisor/vhi-worker.conf`, `deploy/cron`
- Done when: killing the worker process restarts it automatically.

### 7.4 Configure backups
- Litestream replicating `database.sqlite` to Backblaze B2 (`litestream.yml`, systemd service; 30-day retention, daily snapshots).
- Nightly `rclone sync storage/app/public/media b2:…/media`.
- Wire the Phase 6 freshness check to Litestream's latest replica timestamp.
- Files: `deploy/litestream.yml`, `deploy/systemd/litestream.service`, `deploy/cron`
- Done when: a write appears in the B2 replica within 1 minute.

### 7.5 Implement deploy pipeline and staging
- GitHub Actions on `main`: run CI, then SSH deploy to a new release directory, `composer install --no-dev`, `migrate --force`, `config:cache`, `route:cache`, switch the `current` symlink, `queue:restart`, reload PHP-FPM.
- Shared `storage/`, `.env`, and `database/database.sqlite` symlinked into each release; keep last 5 releases.
- Staging: separate Linux user, `.env`, DB file, B2 prefix, Paystack test keys, `SMS_DRIVER=log` or test numbers; deployed from a `staging` branch or manual dispatch.
- Files: `.github/workflows/deploy.yml`, `deploy/deploy.sh`
- Done when: a deploy causes no failed requests in a 1-request-per-second probe.

### 7.6 Write runbooks and run the restore drill
- `docs/runbooks/restore.md`: restore DB with `litestream restore`, restore media with `rclone`, point DNS, verify.
- `docs/runbooks/deploy.md`, `docs/runbooks/rollback.md`, `docs/runbooks/rotate-secrets.md`.
- Perform the drill on a throwaway VPS and record time taken and data loss in the Notes of `TASKS_INDEX.md`.
- Files: `docs/runbooks/`
- Done when: restore completes in under 1 hour with no more than 1 minute of data lost.

### 7.7 Launch checklist
- Resend domain verified (SPF, DKIM, DMARC) for `noreply@<domain>`.
- Arkesel API key set and `VIDORCK` transactional sender ID approved; capture real Arkesel success/error responses as test fixtures (Phase 5 review).
- `MAIL_REPLY_TO_ADDRESS` set for customer email replies. Website support submissions are stored through `POST /api/v1/complaints`.
- Paystack live keys set; live webhook URL registered; one real low-value MoMo payment and refund tested.
- Confirm the real Paystack `refund.processed` / `refund.failed` payload fields (`transaction_reference`, `refund_reference`) against a live refund; decide handling of `refund.needs-attention` (Phase 4 review, item b).
- CORS origins, `SESSION_DOMAIN`, `SANCTUM_STATEFUL_DOMAINS`, `APP_DEBUG=false` verified.
- First Admin created with `staff:create-admin`; catalogue imported and checked by the Product Owner.
- UptimeRobot (or similar) monitoring `/up`; Product Owner sign-off on staging.
- Done when: every item is ticked and the frontend migration items in spec §11 are complete.

## Acceptance Tests

- [ ] **origin_not_directly_reachable:** Given the server IP, when requested on 443 without Cloudflare, then the connection is refused.
- [ ] **media_served_without_php:** Given an uploaded `.php` file placed under `/media`, when requested, then Nginx returns 403/404 and does not execute it.
- [ ] **backup_replicates_within_a_minute:** Given a new order written, when B2 is checked 60 seconds later, then the replica contains it.
- [ ] **restore_drill_meets_targets:** Given a fresh VPS, when the restore runbook is followed, then the API is serving restored data in under 1 hour.
- [ ] **zero_downtime_deploy:** Given a probe at 1 request per second, when a deploy runs, then no request fails.
- [ ] **live_payment_end_to_end:** Given live keys, when a real GH₵1–5 MoMo payment is made, then exactly one order, one confirmation email, and staff SMS alerts are produced.

## Verification

- `curl -sf https://api.vidorckhair.com/up`
- `litestream snapshots /var/www/vhi/shared/database/database.sqlite`
- `php artisan schedule:list && sudo supervisorctl status`
