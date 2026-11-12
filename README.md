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
that are deleted after use, never on a command line. If the process is told to
stop mid-run (Laravel Cloud sends SIGTERM before replacing an instance), it
removes its plain dump and credential files first; anything a hard kill leaves
behind is swept by the next run once it is a day old.

Results go to the app's log (Laravel Cloud: **Monitoring → Logs**, application
logs) and to the manifest. **Set `BACKUP_HEALTHCHECK_URL`**: it is optional, but
it is the only thing that tells you when a nightly run fails, or never ran.

### S3 layout

```
db/daily/<cluster>/<database>/YYYY/MM/DD/<database>-<timestamp>.sql.gz.age   MySQL
db/daily/<cluster>/<database>/YYYY/MM/DD/<database>-<timestamp>.dump.age     PostgreSQL
db/monthly/<cluster>/<database>/YYYY/MM/<database>-<timestamp>.…             on BACKUP_MONTHLY_DAY
db/manifests/<deployment>/YYYY/MM/DD/<timestamp>.json
```

Retention is enforced by the bucket's lifecycle rules, not by this app. On AWS
the app's credentials cannot read, list or delete anything. S3 does let a
write replace an existing object, so the bucket keeps a replaced or expired
dump as a previous version for `dump_version_recovery_days` (7 by default):
a leaked key could overwrite backups, but not beyond recovery within that window.

## Where backups can go

Any S3-compatible bucket. **AWS S3** gets the full setup from a CloudFormation
template: an upload-only IAM user (it can write dumps but not read or delete them)
and a GitHub OIDC role for the storage sync, so no AWS key is stored in GitHub.
**DigitalOcean Spaces** gets the same bucket, versioning and retention rules
through the S3 API, and its access keys are made with `doctl` and stored
straight into GitHub and Laravel Cloud, so there are no console steps and no key
is ever displayed. **Other providers** (Backblaze B2, MinIO, ...) get the same
bucket rules; their access keys are created in the provider's console. Outside
AWS a key that can write can usually also delete.

## Setup

### Tools

On the machine you run the setup from (macOS or Linux):

| Tool | Needed for | Install (macOS) |
|---|---|---|
| `bash`, `curl` | everything | built in |
| `jq` | `aws/setup-bucket` | `brew install jq` |
| AWS CLI (`aws`) | `aws/setup-bucket`, every provider (it speaks the S3 API, also to Spaces) | `brew install awscli` |
| `doctl` (1.x or 2.x) | provider `digitalocean` | `brew install doctl` |
| GitHub CLI (`gh`) | provider `digitalocean` (stores the storage sync's key); `aws` (detects the OIDC subject format) | `brew install gh` |
| Laravel Cloud CLI (`cloud`) | provider `digitalocean` with `--db-key`; reading cluster details | see [the Cloud docs](https://cloud.laravel.com/docs) |
| `age` | making the encryption key pair, and restoring | `brew install age` |

Sign each one in to the **organization being set up**: `aws` with a profile for
its AWS account; `doctl auth init --context <name>` with an API token from its
DigitalOcean team (it needs to create, read and delete Spaces keys); `gh auth
login` as someone who can set secrets on its config repository; `cloud auth`
in its Laravel Cloud organization.

### 1. The bucket (once per account)

Describe it in a `stack.json` (keep this file in your private config
repository, next to the storage sync's `buckets.yml`):

```json
{
  "provider": "aws",
  "region": "ap-south-1",
  "bucket": "ranium-offsite-backups",
  "stack_name": "offsite-backups",
  "github_repository": "ranium/cloud-backups",
  "retention": { "daily_days": 35, "monthly_days": 365, "noncurrent_file_version_days": 90 }
}
```

```json
{
  "provider": "digitalocean",
  "region": "blr1",
  "bucket": "client-offsite-backups",
  "doctl_context": "client",
  "github_repository": "client/cloud-backups"
}
```

```json
{
  "provider": "s3-compatible",
  "endpoint": "https://s3.eu-central-003.backblazeb2.com",
  "region": "eu-central-003",
  "bucket": "client-offsite-backups"
}
```

Then run [`aws/setup-bucket`](aws/setup-bucket). `--plan` shows what it would do
and changes nothing:

```bash
aws/setup-bucket stack.json --plan
aws/setup-bucket stack.json
```

| `stack.json` field | Default | |
|---|---|---|
| `provider` | — | `aws`, `digitalocean` or `s3-compatible`. |
| `region` | — | e.g. `ap-south-1`; for Spaces `blr1`, `nyc3`, ... |
| `bucket` | — | Globally unique on AWS and on Spaces. |
| `endpoint` | `https://<region>.digitaloceanspaces.com` on `digitalocean` | `s3-compatible`: required, the provider's S3 endpoint. |
| `doctl_context` | the current one | `digitalocean` only: the `doctl auth` context signed in to the right team. |
| `retention.daily_days` | 35 | Days each daily dump is kept. |
| `retention.monthly_days` | 365 | Days each monthly dump and run manifest is kept. |
| `retention.noncurrent_file_version_days` | 90 | Days a mirrored file's previous version is kept. |
| `retention.dump_version_recovery_days` | 7 | Days a replaced or expired dump is kept as a previous version. |
| `stack_name` | `offsite-backups` | `aws` only. |
| `github_repository` | empty | `aws`: `owner/repo` allowed to run the storage sync; empty skips that role. `digitalocean`: the repository whose `DESTINATION_*` secrets get the storage sync's key; empty skips that key. |
| `github_branch` | `main` | `aws` only. |
| `github_subject_prefix` | detected | `aws` only. Set when the repository uses GitHub's immutable OIDC subject format (`repo:owner@id/name@id`, the default for new repositories). With `gh` installed, `setup-bucket` detects it. |
| `create_github_oidc_provider` | `true` | `aws` only. `false` if the account already has GitHub's OIDC provider. |

On AWS it deploys [`aws/backup-bucket.yml`](aws/backup-bucket.yml) with your
AWS CLI profile. For `s3-compatible` it uses `AWS_ACCESS_KEY_ID` and
`AWS_SECRET_ACCESS_KEY` set to that provider's keys. Outside AWS, if the
provider rejects part of the retention rules, it applies what it can and says
what is missing.

On `digitalocean`, DigitalOcean's API (and so `doctl`) manages Spaces keys but
not buckets, so the script:

1. creates a temporary full-access Spaces key with `doctl`, and deletes it when
   it exits, on success or failure (if that delete fails, or the script is
   killed outright, it prints, or you run, `doctl spaces keys list` to find
   the `setup-bucket-temporary-…` key and delete it);
2. with that key and the AWS CLI, creates the bucket, turns versioning on and
   applies the retention rules;
3. creates the storage sync's key, `<bucket>-storage-sync`, limited to this
   bucket, and stores it as `github_repository`'s `DESTINATION_ACCESS_KEY_ID`
   and `DESTINATION_SECRET_ACCESS_KEY` secrets with `gh`.

Once a deployment exists (step 3 below), give it the database key:

```bash
aws/setup-bucket stack.json --db-key <environment id or name>
```

The first time, this creates `<bucket>-db-backup`, limited to this bucket, and
stores it as the Laravel Cloud organization secrets `BACKUP_S3_KEY` and
`BACKUP_S3_SECRET`; every time, it attaches them to that environment. Run it
once per deployment.

Keys are piped from one tool to the next and never displayed. A key's secret
cannot be read back later, so a re-run reuses a key only when its secrets are
already stored; if one exists without the other, the script says which to
delete before running it again. Spaces keys that can write can also delete.

Either way the bucket is versioned and encrypted at rest, and has **no Object
Lock**: an account administrator can delete anything in it. On AWS everything
is stored in S3 Standard-IA, which bills each object for at least 30 days and
128 KB.

The script ends by printing the next steps, including the command that creates
the uploader's access key on AWS.

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
- **Scale to zero:** Cloud wakes a sleeping environment for its scheduled
  tasks, but it stays awake only for the sleep timeout. Leave scale to zero
  off unless a full run (including waiting for suspended clusters, up to
  `BACKUP_WAKE_TIMEOUT` each) reliably finishes well inside that timeout.
- **Logs:** leave `LOG_CHANNEL` unset; Cloud collects the app's logs itself.
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
| `BACKUP_CLUSTER_<NAME>_SSL` | — | MySQL: `true` encrypts, `verify` also checks the server certificate. PostgreSQL: a libpq `sslmode`; `verify-full` checks the certificate against the system CA store. Use a verifying mode for any cluster reached over the internet. |
| `BACKUP_CLUSTER_<NAME>_MAINTENANCE_DATABASE` | `postgres` | PostgreSQL: database used to list the others. |
| `BACKUP_S3_BUCKET` / `_REGION` / `_KEY` / `_SECRET` | — | |
| `BACKUP_S3_PREFIX` | `db` | |
| `BACKUP_S3_ENDPOINT` | — | Set for any S3-compatible provider, e.g. `https://blr1.digitaloceanspaces.com`. Empty means AWS. |
| `BACKUP_S3_USE_PATH_STYLE` | `false` | Some providers (MinIO) need path-style URLs. |
| `BACKUP_S3_STORAGE_CLASS` | `STANDARD_IA` | Set empty for providers without storage classes, such as Spaces. |
| `BACKUP_AGE_RECIPIENTS` | — | age public keys. |
| `BACKUP_HEALTHCHECK_URL` | — | healthchecks.io ping URL. Unset means no pings. Strongly recommended. |
| `BACKUP_SCHEDULE` | `30 21 * * *` | Cron, UTC. The default is 03:00 IST. |
| `BACKUP_WAKE_TIMEOUT` | 300 | Seconds to wait for a suspended cluster. |
| `BACKUP_WAKE_RETRY` | 10 | Seconds between connection attempts while a cluster wakes. |
| `BACKUP_DUMP_TIMEOUT` | 3600 | Seconds allowed per dump. |
| `BACKUP_WORK_DIR` | `storage/app/backup-work` | Where dumps are staged before upload. |
| `BACKUP_AGE_BINARY` | `.bin/age-<arch>` | Path to age, if not installed by `bin/install-age`. |
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
