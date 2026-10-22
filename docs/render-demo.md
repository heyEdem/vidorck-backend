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

Set these in Render before the first deploy:

```dotenv
APP_KEY=<generate once with php artisan key:generate --show>
APP_URL=https://your-demo-api.onrender.com
STOREFRONT_URL=https://your-demo-storefront.example.com
ADMIN_URL=https://your-demo-storefront.example.com/admin
CORS_ALLOWED_ORIGINS=https://your-demo-storefront.example.com
SANCTUM_STATEFUL_DOMAINS=your-demo-storefront.example.com
SESSION_SECURE_COOKIE=true
LOG_CHANNEL=stderr
LOG_LEVEL=info
MAIL_MAILER=log
SMS_DRIVER=log
```

Keep `APP_KEY` stable across deployments. For cookie-based admin authentication,
host the frontend and API on subdomains of the same domain and set
`SESSION_DOMAIN` to that shared domain, for example `.demo.example.com`.
An `onrender.com` API and an unrelated frontend domain can encounter browser
restrictions on third-party cookies.

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

Use test payment credentials for this demo. No database seeding runs on deploy.
Create the first administrator in the Render service shell when needed:

```sh
php artisan staff:create-admin
```

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
