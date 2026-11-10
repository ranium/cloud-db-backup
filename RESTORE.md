# Restoring a database

You need: the age **private key** (from your password manager), read access to
the backup bucket, and the `age`, `gunzip` and `mysql` (or `pg_restore`)
command-line tools. PostgreSQL archives are written by pg_dump 18, so restore
them with **pg_restore 18 or newer**.

What a dump contains: one database's schema and data, plus its routines,
triggers and events (MySQL). It does **not** contain users, roles or grants;
recreate those on the target first.

## 1. Find the dump

The newest run's manifest lists every file it wrote, with its size and SHA-256:

```bash
aws s3 ls s3://ranium-offsite-backups/db/manifests/ranium-us-east-2/ --recursive | tail -1
aws s3 cp s3://ranium-offsite-backups/db/manifests/ranium-us-east-2/2026/11/14/20261114T213000Z.json -
```

Or browse directly:

```bash
aws s3 ls s3://ranium-offsite-backups/db/daily/ranium-mysql-1/laratix_production/ --recursive | tail -5
aws s3 ls s3://ranium-offsite-backups/db/monthly/ranium-mysql-1/laratix_production/ --recursive
```

## 2. Download and check it

```bash
aws s3 cp s3://ranium-offsite-backups/db/daily/ranium-mysql-1/laratix_production/2026/11/14/laratix_production-20261114T213000Z.sql.gz.age .
shasum -a 256 laratix_production-20261114T213000Z.sql.gz.age   # compare with the manifest
```

## 3. Restore

**Always restore into a new, empty database first** and check it before
pointing anything at it. Never restore over a live database.

### MySQL (`.sql.gz.age`)

```bash
mysql -h <host> -u <user> -p -e "CREATE DATABASE laratix_restore"

age -d -i offsite-backups.agekey laratix_production-20261114T213000Z.sql.gz.age \
  | gunzip \
  | mysql -h <host> -u <user> -p laratix_restore
```

The dump has no `CREATE DATABASE` or `USE` statement, so it restores into
whichever database you name. Routines, triggers and views carry the
`DEFINER` of the original user; restoring them needs a user with
`SET_USER_ID` (or `SUPER`), or the original user to exist on the target.

### PostgreSQL (`.dump.age`)

```bash
createdb -h <host> -U <user> kognit_restore

age -d -i offsite-backups.agekey kognit-20261114T213000Z.dump.age > kognit.dump
pg_restore -h <host> -U <user> --dbname=kognit_restore --no-owner --no-acl kognit.dump
rm kognit.dump
```

`--no-owner --no-acl` avoids errors from roles that exist on the source
cluster but not on the target.

## 4. After restoring

- Compare row counts on a few key tables with what you expect.
- Delete decrypted files from your machine when you are done: they contain
  personal data.
- A restored database contains everything as of the dump, **including people
  whose data was erased after it was taken**. Re-apply any erasures made since
  then before the restored copy is used.
