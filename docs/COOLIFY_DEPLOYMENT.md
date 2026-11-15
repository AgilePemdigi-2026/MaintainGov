# MaintainGov deployment on Coolify (Compose)

This overlay is designed for the GitHub repo AgilePemdigi-2026/MaintainGov
and its current Laravel API, Next.js app, and Python analysis service.

## Public architecture: one frontend domain

Coolify public domain -> `web` (Next.js, port 3000).
Next.js rewrites `/api/v1/*` -> `api:80/api/v1/*` on Docker's internal network.
The browser uses `NEXT_PUBLIC_API_BASE_URL=/api/v1`, preserving a same-origin
cookie/CSRF flow. Do not expose the `db`, `analysis`, or `queue` services.
The Laravel GitHub webhook can be reached at
`https://<your-domain>/api/v1/webhooks/github`, but live merge synchronization
still depends on the UC06 backend implementation and completed acceptance.

## Preflight (local; Docker Desktop must be installed)

```powershell
cd "C:\Users\LENOVO\OneDrive\Attachments\Desktop\MaintainGov\MaintainGov-App"
# Copy overlay files into the repo root before executing these commands.
docker compose --env-file .env.coolify.local config --quiet
docker compose --env-file .env.coolify.local build
```

For local testing only, use .env.coolify.local (copy from .env.coolify.example,
replace every placeholder, and keep it untracked). The `APP_URL` for local
Compose smoke testing should be `http://127.0.0.1:3000`; secure cookies
are configured for HTTPS staging and login on local HTTP may therefore
require a temporary LOCAL-ONLY override. Do not disable secure cookies on
public deployments.

## Coolify configuration

1. Commit this overlay to a reviewed branch and merge after QA. No secrets
   or private ML artifacts in Git.
2. In existing Coolify resource: branch `main`, Base Directory `/`, Compose
   Location `/docker-compose.yml`, Build strategy `Compose`.
3. Save, then click **Load Compose**. Services expected: web, api, queue,
   analysis, db. A missing service means the Compose file has not been loaded.
4. Open **Environment Variables** and populate at least these:
   - `APP_KEY`: from `php artisan key:generate --show` (a secret)
   - `APP_URL`: `https://<front-end-domain-from-class-sheet>`
   - `DB_PASSWORD`: new random secret
   - `DB_ROOT_PASSWORD`: different random secret
   - optional `DB_DATABASE`, `DB_USERNAME`; optional `GITHUB_TOKEN` and
     `GITHUB_WEBHOOK_SECRET` when GitHub integration is ready.
   Do not paste any secret into chat or commit to Git.
5. Once Compose detects `web`, go to Domains -> Add Domain -> **Service web**,
   HTTPS, the class-assigned frontend domain, **internal port 3000**, path `/`.
   Only `web` needs a public domain. Leave forced HTTPS redirect enabled after
   the certificate is issued.
6. Do not deploy as 'completed' without private model artifacts; see below.
7. Deploy only after the acceptance gate and production environment review.
   Check deployment/runtime logs and component health.

## Critical external dependency: private UC01 model artifacts

The repository intentionally omits the private Colab-trained model. FastAPI
startup executes `load_resources()` and will exit if these are missing:
- `sentiment_pipeline.joblib`
- `slang_map.json`
- `domain_rules.json`

The Compose volume `analysis_artifacts` is mounted at `/app/artifacts` read-only.
A Coolify server administrator must populate the **actual generated/prefixed
Docker volume** with the three trusted files before starting FastAPI. Coolify
prefixes named volume names; find the exact volume in Persistent Storage or
from the server using `docker volume ls`. Never publish or commit the private
model, and never create fake 'production' data from CI synthetic artifacts.
Use trusted Colab outputs and the matching scikit-learn 1.6.1 runtime.

## Database initialization and checks

The MySQL data volume persists across deployments. After the containers start,
open Coolify -> Terminal for the `api` service and run:

```bash
php artisan migrate --force
php artisan migrate:status
```

Do not run `migrate:fresh`, `db:wipe`, or `db:seed --force` against public
staging automatically. The repository seeders may create demo users; audit them
and set strong credentials before intentionally seeding an isolated staging DB.
If the application is already running with data, take a MySQL backup first.

After DB and model are configured, verify:
- `https://<domain>/login`
- `https://<domain>/api/v1/auth/csrf`
- `https://<domain>/api/v1/auth/me` (401 without session is expected)
- from inside `api`: `php artisan github:check` (read-only check)
- from inside `analysis`: `GET /internal/health` (must report loaded model)
- from inside `queue`: queue worker stays running after test analysis batch.

## Known release blockers (as of preparation)

- GitHub UC06 real multi-user Approve / Request Changes + merged-state
  synchronization still require their live acceptance gate.
- Full integration and PO acceptance not yet verified.
- CI synthetic FastAPI model artifacts are tests only, not a production model.
- Coolify success on `web` does not imply all services and end-to-end features work.

## Rollback and backups

Record the exact Git commit SHA deployed. Keep a MySQL backup outside the
container; preserve the `mysql_data` and `api_storage` volumes. To roll back,
redeploy the last known-good commit in Coolify without deleting volumes.
DB migrations may be forward-only: review compatibility before code rollback.
