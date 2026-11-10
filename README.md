# cloud-db-backup

Offsite backups of every database on your Laravel Cloud database clusters, to an
S3 bucket you own, encrypted before they leave Cloud.

A minimal Laravel app you deploy **once per Laravel Cloud account per region**.
Every night it connects to each configured cluster, dumps every database on it,
compresses and encrypts each dump with [age](https://age-encryption.org), and
uploads it to S3 Standard-IA. New databases on a cluster are picked up
automatically.

Storage buckets are backed up separately by
[cloud-storage-backup-action](https://github.com/ranium/cloud-storage-backup-action).

## Why one deployment per region

Laravel Cloud's private database endpoints are only reachable from the same
region. A deployment can back up any number of clusters in its own region over
the private network, plus any public cluster anywhere.

## What a run does

For each cluster in `BACKUP_CLUSTERS`, independently:

1. **Wait for it to wake.** Clusters suspend when idle and can take minutes to
   accept connections, so it retries for up to `BACKUP_WAKE_TIMEOUT` seconds.
   Bad credentials fail straight away rather than waiting.
2. **List its databases,** minus system databases and minus the cluster's
   `EXCLUDE` list (or only its `INCLUDE` list).
3. **Dump each one.** MySQL uses `mysqldump --single-transaction` (no table
   locks); PostgreSQL uses `pg_dump --format=custom`.
4. **Verify the dump is complete** before anything is kept. A MySQL dump must
   end with mysqldump's completion line; a PostgreSQL dump must pass
   `pg_restore --list`. A truncated dump is a failure, never a backup.
5. **Compress and encrypt** to your age public key(s), then **upload**.

One failing database never stops the rest of its cluster, and one failing
cluster never stops the others. Every run writes a JSON manifest and, if
configured, pings healthchecks.io with the outcome.

Passwords are passed to the database clients through private (0600) files
that are deleted after use, never on a command line.

### S3 layout

```
db/daily/<cluster>/<database>/YYYY/MM/DD/<database>-<timestamp>.sql.gz.age   MySQL
db/daily/<cluster>/<database>/YYYY/MM/DD/<database>-<timestamp>.dump.age     PostgreSQL
db/monthly/<cluster>/<database>/YYYY/MM/<database>-<timestamp>.…             on BACKUP_MONTHLY_DAY
db/manifests/<deployment>/YYYY/MM/DD/<timestamp>.json
```

Retention is enforced by the bucket's lifecycle rules, not by this app; the
app's credentials cannot delete anything.

## Setup

### 1. The bucket (once per AWS account)

```bash
aws cloudformation deploy \
  --region ap-south-1 \
  --stack-name offsite-backups \
  --template-file aws/backup-bucket.yml \
  --capabilities CAPABILITY_IAM \
  --parameter-overrides \
      BucketName=ranium-offsite-backups \
      GitHubRepository=ranium/cloud-backups
```

| Parameter | Default | |
|---|---|---|
| `BucketName` | — | Globally unique. |
| `DailyRetentionDays` | 35 | Days each daily dump is kept. |
| `MonthlyRetentionDays` | 365 | Days each monthly dump and manifest is kept. |
| `NoncurrentFileVersionDays` | 90 | Days a mirrored file's previous version is kept. |
| `GitHubRepository` | empty | `owner/repo` of the storage config repo. Empty skips the storage role. |
| `GitHubBranch` | `main` | Branch allowed to assume the storage role. |
| `CreateGitHubOidcProvider` | `true` | Set `false` if the account already has GitHub's OIDC provider. |

The bucket is versioned and encrypted at rest, blocks public access, and has
**no Object Lock**: an account administrator can delete anything in it.
Everything is stored in S3 Standard-IA, which bills each object for at least
30 days and 128 KB.

Then create the uploader's access key and keep the output for step 3:

```bash
aws iam create-access-key --user-name "$(aws cloudformation describe-stacks \
  --region ap-south-1 --stack-name offsite-backups \
  --query "Stacks[0].Outputs[?OutputKey=='DatabaseUploaderUserName'].OutputValue" --output text)"
```

### 2. An age key pair (once)

```bash
age-keygen -o offsite-backups.agekey
```

The file holds the **private** key: store it in your password manager and
nowhere else. Its `# public key: age1…` line is what goes in
`BACKUP_AGE_RECIPIENTS`. Listing several public keys (comma-separated) lets
any one of their private keys decrypt, which is how you give a second person
restore access or rotate keys.

### 3. Deploy to Laravel Cloud (once per account per region)

Create an application from this repository in the region you want to cover,
then on its environment:

- **Build command:**
  `composer install --no-dev --optimize-autoloader && bin/install-age`
- **Deploy command:** empty. There are no migrations, and the default
  `php artisan migrate --force` fails without a database.
- **Environment variables:** everything in `.env.example`, with passwords and
  the S3 secret stored as secrets.
- **Instance:** the smallest size, exactly one replica, **scheduler on**.
- **Hibernation off.** A hibernating environment does not run its scheduler,
  so no backups would happen.
- **No database attached.** The app connects to clusters through its own
  variables. Use a cluster's private hostname when it is in the same region.

`bin/install-age` downloads age for both Linux architectures and checks each
against a pinned SHA-256; the build fails on a mismatch.

### 4. Check it

From the environment's console:

```bash
php artisan backup:databases --dry-run      # connects and lists, uploads nothing
php artisan backup:databases                # a real run, right now
php artisan backup:databases --cluster=ranium-mysql-1
```

## Configuration

| Variable | Default | |
|---|---|---|
| `BACKUP_DEPLOYMENT` | `default` | Names this deployment in manifest keys. |
| `BACKUP_CLUSTERS` | — | Comma-separated cluster names. |
| `BACKUP_CLUSTER_<NAME>_DRIVER` | `mysql` | `mysql` or `pgsql`. |
| `BACKUP_CLUSTER_<NAME>_HOST` | — | |
| `BACKUP_CLUSTER_<NAME>_PORT` | 3306 / 5432 | |
| `BACKUP_CLUSTER_<NAME>_USERNAME` | — | Needs read access to every database it should back up. |
| `BACKUP_CLUSTER_<NAME>_PASSWORD` | — | |
| `BACKUP_CLUSTER_<NAME>_INCLUDE` | — | Only these databases. |
| `BACKUP_CLUSTER_<NAME>_EXCLUDE` | — | Skip these databases. |
| `BACKUP_CLUSTER_<NAME>_SSL` | — | MySQL: `true` adds `--ssl`. PostgreSQL: a libpq `sslmode`. |
| `BACKUP_CLUSTER_<NAME>_MAINTENANCE_DATABASE` | `postgres` | PostgreSQL: database used to list the others. |
| `BACKUP_S3_BUCKET` / `_REGION` / `_KEY` / `_SECRET` | — | |
| `BACKUP_S3_PREFIX` | `db` | |
| `BACKUP_AGE_RECIPIENTS` | — | age public keys. |
| `BACKUP_HEALTHCHECK_URL` | — | healthchecks.io ping URL. Unset means no pings. |
| `BACKUP_SCHEDULE` | `30 21 * * *` | Cron, UTC. The default is 03:00 IST. |
| `BACKUP_WAKE_TIMEOUT` | 300 | Seconds to wait for a suspended cluster. |
| `BACKUP_DUMP_TIMEOUT` | 3600 | Seconds allowed per dump. |
| `BACKUP_MONTHLY_DAY` | 1 | Day of month whose dump is also kept as the monthly copy. |

`<NAME>` is the cluster name upper-cased with each run of non-alphanumerics
replaced by `_`: `ranium-mysql-1` → `BACKUP_CLUSTER_RANIUM_MYSQL_1_HOST`.

## Restoring

See [RESTORE.md](RESTORE.md).

## Development

```bash
composer install
./vendor/bin/pest
```

## License

MIT. See [LICENSE](LICENSE).
