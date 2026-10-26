# Sprint 1 BDD

Install services/analysis/requirements-dev.txt into the analysis virtual environment.
Run Laravel, its database worker, FastAPI with the trusted private model, and a dedicated seeded demo database.

From the repository root:

    python -m behave tests/bdd/api_sprint1.feature --tags="not @service_down" --format json --outfile tmp/bdd-http.json

The eight normal scenarios exercise real HTTP, sessions, database persistence, queue/model integration, RBAC, idempotency and pagination. This command does not execute a browser or establish human sentiment quality.

On a disposable test environment only, stop FastAPI while keeping Laravel and its worker running, then run:

    python -m behave tests/bdd/api_sprint1.feature --tags=@service_down --format json --outfile tmp/bdd-service-down.json

Restart FastAPI afterwards and test retry. Do not stop a shared service for testing.

Configuration: MAINTAINGOV_API_URL, MAINTAINGOV_ANALYSIS_URL, MAINTAINGOV_DEMO_PASSWORD. Defaults match SETUP_UC01.md. The original authentication.feature and analyze_feedback.feature retain the UI acceptance scenarios; their browser steps remain a separate review checklist and are not claimed as executed by this HTTP runner.


Browser coverage is provided separately by apps/web/tests/e2e/sprint1.spec.ts using Playwright. Start the full seeded stack, run npm ci and npx playwright install chromium from apps/web, then npm run test:e2e. These five tests cover selected UI acceptance paths; they do not mean that every step in the two original feature files or every browser/NFR has been executed. Public CI uses a synthetic model; local private-model evidence is recorded separately.
