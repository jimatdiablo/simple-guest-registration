# SGR App Update and Container Maintenance Cheat Sheet

Use this when you need to patch SGR and update a deployed container without destroying customer data.

## The Big Rule

Normal app updates replace containers. They should not delete volumes.

Data is preserved in:

- MySQL database volume: `SGR_DB_VOLUME`
- App storage/log volume: `SGR_APP_STORAGE_VOLUME`
- Site-specific `.env`

Do not delete these unless you intentionally want to destroy that deployment's data.

## Normal Patch Workflow

1. Make the code change in your local repo.
2. Test it in the lab.
3. Commit the change.
4. Push to GitHub.
5. GitHub Actions builds and publishes a new container image.
6. Update the deployed instance to use the new image.
7. Pull and restart the deployed containers.
8. Verify the app and data.

## Local Code Change Commands

Check what changed:

```powershell
git status
```

Review changes:

```powershell
git diff
```

Commit changes:

```powershell
git add .
git commit -m "Describe the app fix"
```

Push changes:

```powershell
git push
```

## Production Container Update

Use the production compose file:

```powershell
docker compose -f docker-compose.prod.yml pull
docker compose -f docker-compose.prod.yml up -d
```

This pulls the new image and recreates the app containers while keeping volumes.

## If Using A Versioned Image Tag

Edit the site `.env`:

```env
SGR_APP_IMAGE=ghcr.io/jimatdiablo/simple-guest-registration:v1.0.4
SGR_DNS_IMAGE=ghcr.io/jimatdiablo/simple-guest-registration-dns:v1.0.4
```

Then run:

```powershell
docker compose -f docker-compose.prod.yml pull
docker compose -f docker-compose.prod.yml up -d
```

## Quick Verification After Update

Confirm containers are running:

```powershell
docker compose -f docker-compose.prod.yml ps
```

Check recent logs:

```powershell
docker compose -f docker-compose.prod.yml logs --tail=100 app
docker compose -f docker-compose.prod.yml logs --tail=100 scheduler
docker compose -f docker-compose.prod.yml logs --tail=100 dns
```

Open the app:

```text
http://assigned-sgr-ip/
```

Verify:

- Login works.
- Active Guests still exist.
- Admin Settings still exist.
- Logs/storage are still present.
- DNS/captive portal behavior still works.
- Scheduler is running.

## Commands That Preserve Data

These are normally safe:

```powershell
docker compose -f docker-compose.prod.yml pull
docker compose -f docker-compose.prod.yml up -d
docker compose -f docker-compose.prod.yml restart app scheduler
docker compose -f docker-compose.prod.yml logs --tail=100 app
docker compose -f docker-compose.prod.yml ps
```

These update/restart containers without deleting named volumes.

## Dangerous Commands

Do not run this during a normal update:

```powershell
docker compose -f docker-compose.prod.yml down -v
```

The `-v` flag deletes volumes. That can delete the customer database and storage logs.

Be very careful with:

```powershell
docker volume rm ...
docker system prune --volumes
```

Those can also delete deployment data.

## Before A Major Update

Before a bigger change, make or confirm a backup of:

- MySQL database / `SGR_DB_VOLUME`
- App storage / `SGR_APP_STORAGE_VOLUME`
- Site `.env`

At minimum, record the current image tag:

```env
SGR_APP_IMAGE=ghcr.io/jimatdiablo/simple-guest-registration:v1.0.3
```

That gives you a known rollback target.

## Simple Rollback

If the new image has a problem and the old image is still available:

1. Edit the site `.env` back to the previous image tag.

```env
SGR_APP_IMAGE=ghcr.io/jimatdiablo/simple-guest-registration:v1.0.3
```

2. Pull and restart:

```powershell
docker compose -f docker-compose.prod.yml pull
docker compose -f docker-compose.prod.yml up -d
```

3. Verify the app.

Rollback preserves the same database and storage volumes.

## Per-Customer Values To Never Accidentally Reuse

Each deployment needs its own values:

```env
APP_URL=http://assigned-sgr-ip
POISON_DNS_BIND_IP=assigned-sgr-ip
POISON_DNS_TARGET_IP=assigned-sgr-ip
SGR_HTTP_BIND=assigned-sgr-ip:80

SGR_APP_CONTAINER_NAME=sgr_customer_app
SGR_SCHEDULER_CONTAINER_NAME=sgr_customer_scheduler
SGR_DNS_CONTAINER_NAME=sgr_customer_dns
SGR_DB_CONTAINER_NAME=sgr_customer_db

SGR_DB_VOLUME=sgr_customer_db_data
SGR_APP_STORAGE_VOLUME=sgr_customer_app_storage
```

Reusing the same IP causes network/port collisions.

Reusing the same volume names can cause deployments to share or overwrite data.

## Full Factory Reset Reminder

Full Factory Reset is not an app update.

It clears customer data, settings, logs, queues, modem cache, and non-master-admin users. Use it only for controlled resets.

It is protected behind `master_admin`, but it is still destructive.

## Best Mental Model

Think of the system in three layers:

```text
GitHub repo = source code
GHCR image = packaged app version
Customer deployment = .env + persistent volumes + running containers
```

Updating the app should change the image.

It should not delete the customer's `.env` or volumes.
