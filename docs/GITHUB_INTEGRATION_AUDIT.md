# GITHUB INTEGRATION AUDIT — UC04, UC05, UC06

**Project:** MaintainGov  
**Sprint:** Sprint 3 – Week 14  
**PIC:** Muhammad Hanif (`@Haniippp`)  
**Backend Reviewer:** Demas Axel Marfiansyah  
**Frontend Support:** Valisha  
**E2E Support:** Gerard  
**Repository:** `AgilePemdigi-2026/MaintainGov`  
**Current Branch:** `maintenance/issue-7`  
**Date of Audit:** 15 November 2026  

---

## 1. Executive Summary

A comprehensive repository audit was conducted across backend (Laravel), frontend (Next.js), database migrations, integration adapters, webhooks, and test suites to verify the status of UC04 (Work on Maintenance Issue), UC05 (Create Pull Request), and UC06 (Pull Request Review & Synchronization).

All code components for UC04, UC05, and UC06 are fully implemented and verified with automated test suites:
- **Backend Tests:** 276 tests (275 passed, 1 skipped SQLite constraint, 0 failed, 1,381 assertions).
- **Frontend E2E Tests:** 36 Playwright tests passed (0 failed) covering UC03, UC04, UC05, and UC06.
- **Frontend TypeScript:** Type check passed with 0 errors (`tsc --noEmit`).

---

## 2. Component Audit Matrix

| Component | Existing State | Missing / Broken | Proposed Action |
|---|---|---|---|
| **GitHubService** | Full adapter (910 lines): metadata, branch, commit, ref creation, PR creation, PR retrieval, user identity, review listing, review submission. Strict timeout, connection error, and rate-limit handling. | Token-dependent: If server PAT lacks `Contents: Write`, ref creation fails with HTTP 403; if it lacks `Pull requests: Write`, PR and review creation fails with HTTP 403. | Retain adapter implementation. Document minimum required permissions in auth guide. Keep read/write separation clean. |
| **PullRequestService** | Handles UC05 PR creation (457 lines): pre-flight checks, target branch validation, commit SHA verification, idempotent creation via fingerprint, local persistence, reconciliation without duplication. | None in application logic. Production PR creation gated by live token permission. | Maintain existing logic. Verified with 82 unit/feature tests. |
| **Authentication & Authorization** | Role-based gating (`Tim Maintenance` and `Developer`). Session-based Sanctum authentication. `pengguna_sistem.github_login` mapping. UC06 self-review prevention and reviewer identity mismatch check (`GITHUB_REVIEWER_IDENTITY_MISMATCH`). | Fine-grained PAT attributes all GitHub reviews to the token owner, requiring local reviewer `github_login` to match token owner to prevent identity divergence. | Maintain identity mismatch guard. Document migration to GitHub App / user token exchange for multi-reviewer staging in Week 15. |
| **UC04 (Claim & Work Issue)** | Full workflow: atomic claim transaction, optimistic locking, assigns developer, sets status `Sedang Dikerjakan`, generates branch name `maintenance/issue-{id}`, best-effort GitHub branch creation, manual push verification, webhook push verification. | Automatic branch creation on GitHub returns 403 if PAT lacks `Contents: Write`. Claim still succeeds locally with fallback to "Siapkan branch GitHub" button or manual branch creation. | Keep claim atomic and non-blocking on GitHub branch creation. Ensure user is informed of token permission status. |
| **UC05 (Create Pull Request)** | Full workflow: requires `Ready for Pull Request`, validates source branch, commit SHA, target branch, creates PR on GitHub, records PR metadata in `pull_request` table. | External write requires `Pull requests: Write` on GitHub repository. | Maintain existing implementation. Fully covered by feature tests and idempotent replay handling. |
| **UC06 (Review & Sync Pull Request)** | Full workflow (`PullRequestReviewService` 871 lines): PR listing, detail view, GitHub snapshot refresh (`/sync`), review submission (`APPROVE`, `REQUEST_CHANGES` with mandatory notes), state machine update (`Ready for Merge`, `Sedang Dikerjakan`, `Selesai` if merged), concurrency locking, and idempotent replay. | MySQL migration `2026_11_13_170513` pending on local development MySQL server (verified passing on test SQLite). | Run migration on development database when MySQL daemon is active. Deploy migration in Week 15 staging. |
| **Database** | 16 migrations. Tables: `pengguna_sistem`, `maintenance_case`, `maintenance_backlog`, `github_repository`, `pull_request`, `github_webhook_delivery`, `code_review`. | Migration `2026_11_13_170513_add_uc06_review_and_merge_sync` adds columns to `pull_request`, creates `code_review`, and adds `'Ready for Merge'` to `maintenance_backlog.status`. | Apply migration in development and staging environments. Rollback path verified. |
| **Webhook** | Signed webhook handler (`GitHubWebhookService`): HMAC SHA-256 signature verification, delivery deduplication via `github_webhook_delivery` ledger, handles `push`, `pull_request` (`opened`, `synchronize`, `closed`), `pull_request_review` (`submitted`, `dismissed`), and `ping`. Authoritative refresh from GitHub on PR events. | Requires public ingress (reverse proxy / ngrok) in development; in staging, requires configured DNS and secret. | Document webhook configuration and payload contracts in `GITHUB_WEBHOOK_CONFIGURATION.md`. |
| **API Contract** | Endpoints for UC04, UC05, UC06 defined in `routes/github.php` and handled by `MaintenanceIssueController`, `PullRequestController`, `GitHubWebhookController`. | Needs formal unified contract document covering request, response, error codes, and role gating. | Produce `GITHUB_API_CONTRACT_UC04_UC05_UC06.md`. |
| **Testing** | 276 PHPUnit tests (275 pass, 1 skipped SQLite check constraint). 36 Playwright E2E tests (36 pass, 0 fail). | Live GitHub smoke test blocked from live PR creation due to safety policy against mutating production repo. | Document mocked and live verification boundaries in `GITHUB_LIVE_SMOKE_TEST.md`. |
| **Staging** | Architecture ready for multi-container / VM deployment (Laravel API, Next.js frontend, MySQL 8, Redis, Nginx). | Formal deployment runbook and environment specification needed for Week 15 team handoff. | Create `STAGING_DEPLOYMENT_RUNBOOK.md`. |

---

## 3. Git History and Branch State

- **Current Branch:** `maintenance/issue-7`
- **Base Commit:** `87752c0` (`fix(auth): perbaiki login`) ahead of `6d82d12` (`feat(uc04): create GitHub branch automatically on claim`) and previous PR merges #41–#46.
- **Uncommitted Changes:** UC06 implementation files (controller methods, service, models, requests, migration, and frontend PR review workspace). All uncommitted files are verified and protected.

---

## 4. Traceability to SRS Requirements

- **FR-UC04-01 to FR-UC04-11:** Implemented in `MaintenanceIssueController`, `MaintenanceIssueService`, and `PushVerificationService`.
- **FR-UC05-01 to FR-UC05-14:** Implemented in `PullRequestController` and `PullRequestService`.
- **FR-UC06-01 to FR-UC06-18:** Implemented in `PullRequestController`, `PullRequestReviewService`, `CodeReview` model, and Next.js `/pull-requests` workspace.
