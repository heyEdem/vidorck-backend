# Render Docker demo

The Laravel application is at the repository root. The original copied
`VHI_Backend` directory was an embedded Git repository; it has been flattened so
Render receives the application files when cloning this demo repository.

## Render service settings

- Runtime: Docker.
- Root Directory: leave blank.
- Dockerfile Path: `./Dockerfile`.
- Docker Build Context Directory: `.` (the repository root).
- Docker Command: leave blank to use the image's `/usr/local/bin/start-app`.
- Health Check Path: `/up`.
- Persistent disk: mount at `/var/data` on a paid service to keep demo data.

The image provides PHP 8.4, GD with JPEG/WebP support, EXIF, and the remaining
runtime extensions. Composer installs locked production dependencies during the
build. `.dockerignore` excludes local credentials, dependencies, SQLite files,
uploads, and cached configuration.

## Environment

The frontend in `../VHI_Website` runs on Vercel. Its canonical origin is
`https://www.vidorckhair.com`; `https://vidorckhair.com` redirects there. Its admin
is at `/admin`, and its cache refresh endpoint is `/api/revalidate`.

The filled environment templates are:

- [Render backend](../deploy/render.env.example)
- [Vercel frontend](../deploy/vercel.env.example)

They use `https://api.vidorckhair.com` as the backend custom domain. Attach that
hostname to the Render service and configure its DNS record before using these
API URLs. At the 2026-10-22 check, `api.vidorckhair.com/up` returned a Vercel
`DEPLOYMENT_NOT_FOUND` response; setting an environment variable does not route
the hostname to Render.

The domain and session values in Render are:

```dotenv
APP_URL=https://api.vidorckhair.com
STOREFRONT_URL=https://www.vidorckhair.com
ADMIN_URL=https://www.vidorckhair.com/admin
CORS_ALLOWED_ORIGINS=https://www.vidorckhair.com,https://vidorckhair.com
SANCTUM_STATEFUL_DOMAINS=www.vidorckhair.com,vidorckhair.com
SESSION_COOKIE=vhi_demo_session
SESSION_DOMAIN=.vidorckhair.com
SESSION_SECURE_COOKIE=true
SESSION_SAME_SITE=lax
LOG_CHANNEL=stderr
LOG_LEVEL=info
MAIL_MAILER=log
SMS_DRIVER=log
PAYSTACK_CALLBACK_URL=https://www.vidorckhair.com/checkout/return
STOREFRONT_REVALIDATE_URL=https://www.vidorckhair.com/api/revalidate
```

Fill `APP_KEY` with the existing Render key and keep it stable across deployments.
Only for a new installation, generate it locally with
`php artisan key:generate --show` and paste the complete `base64:` value into
Render. Never replace a deployed key just to copy this template.

The Vercel Production environment needs:

```dotenv
NEXT_PUBLIC_SITE_URL=https://www.vidorckhair.com
NEXT_PUBLIC_API_URL=https://api.vidorckhair.com
ADMIN_SESSION_COOKIE=vhi_demo_session
REVALIDATE_SECRET=<the same secret configured in Render>
```

Generate a shared cache refresh secret with `openssl rand -hex 32`, then set the
same `REVALIDATE_SECRET` in Render and Vercel. Redeploy Vercel after changing the
public API URL, because Next.js includes `NEXT_PUBLIC_*` values at build time.
The frontend adds `/api/v1` to requests itself, so `NEXT_PUBLIC_API_URL` must be
the API origin without that suffix.

`SESSION_COOKIE` and Vercel's `ADMIN_SESSION_COOKIE` must match: the frontend
proxy checks that cookie before serving protected admin pages. Laravel issues
both the session and CSRF cookies for `.vidorckhair.com`; the frontend reads the
CSRF cookie before making admin mutations. Calling the API directly on an
`onrender.com` hostname cannot share those cookies with `www.vidorckhair.com`.

Copy templates to `deploy/render.env` and `deploy/vercel.env` if you want local
files for entering the hosted variables. Those copies are ignored by Git and
excluded from Docker builds. The templates contain no credentials; their empty
key and secret fields must be filled before use. Paystack keys are only needed
when enabling checkout, and should be test keys for this demo.

The image supplies these defaults; Render environment variables can override them:

```dotenv
APP_ENV=production
APP_DEBUG=false
DB_CONNECTION=sqlite
DB_DATABASE=/var/data/database.sqlite
LARAVEL_STORAGE_PATH=/var/data/storage
PORT=10000
```

SQLite, uploads, and Laravel storage all live beneath the single `/var/data`
mount. Without a persistent disk, this is a disposable demo: database writes and
uploaded media are lost on redeploy or restart. Creating the directory alone
does not provision a disk.

The startup script initializes directories, clears old configuration, runs
additive migrations, recreates media links, and runs `bin/start prod`. That runner
starts the HTTP server, database queue worker, and scheduler together. Keep these
processes in one Render service because another service cannot access its SQLite
disk. Migrations run at startup because a Render pre-deploy command cannot access
the service's persistent disk.

Use test payment credentials for this demo. Each start also runs the idempotent
catalogue seeders (categories, hair types, delivery areas; no products) and,
when `BOOTSTRAP_ADMIN_EMAIL` is set, `php artisan staff:create-admin --from-env`.
That creates the first administrator from `BOOTSTRAP_ADMIN_*` only when no Admin
exists, so no Render shell is needed. Keep those variables set: without a disk,
every restart starts from an empty database, and in-app password changes and
products are lost. Invalid credentials are logged and the API still starts.

## Local Docker check

From the repository root, run:

```sh
docker compose up --build
```

Compose builds the image and starts the API at `http://localhost:10000`, with the
queue worker and scheduler in the same container. The local frontend defaults to
`http://localhost:3000`. HTTP cookies are enabled for local use.

The first start generates a separate local `APP_KEY` in the `demo-data` named
volume. The key, SQLite database, and uploaded media survive container
recreation. Compose does not forward your local `.env` credentials or database
paths into the container. Render continues to use its explicitly configured
`APP_KEY` and environment variables.

To change the local port, use `DEMO_PORT=10001 docker compose up --build`.
Frontend overrides are `DEMO_STOREFRONT_URL`, `DEMO_ADMIN_URL`,
`DEMO_CORS_ALLOWED_ORIGINS`, and `DEMO_STATEFUL_DOMAINS`.

Create a demo administrator in another terminal:

```sh
docker compose exec app php artisan staff:create-admin
```

Stop the local service with `docker compose down`; the named volume is retained.

Alternatively, use Docker directly with an explicitly supplied application key:

```sh
docker build -t vidorck-backend-demo .
docker run --rm -p 10000:10000 \
  --mount type=volume,source=vidorck-demo-data,target=/var/data \
  -e APP_KEY='<your generated key>' \
  -e APP_URL=http://localhost:10000 \
  vidorck-backend-demo
```

Check `http://localhost:10000/up`. The volume preserves the database and uploads
between runs. Do not pass your local development `.env` wholesale: it can
override the image's environment and database paths.

This demo reuses the existing `artisan serve` runner. A live customer deployment
should use a production HTTP server such as FrankenPHP or Nginx/PHP-FPM.

References: [Render Docker](https://render.com/docs/docker),
[persistent disks](https://render.com/docs/disks), and
[PHP's built-in server](https://www.php.net/manual/en/features.commandline.webserver.php).
