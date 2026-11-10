<?php

/*
|--------------------------------------------------------------------------
| Database backup configuration
|--------------------------------------------------------------------------
|
| Everything is read from the environment so one codebase serves every
| deployment: one per Laravel Cloud account per region. Clusters are listed by
| name in BACKUP_CLUSTERS; each name's settings live under
| BACKUP_CLUSTER_<NAME>_*, where <NAME> is the name upper-cased with every run
| of non-alphanumeric characters turned into "_" ("ranium-mysql-1" becomes
| BACKUP_CLUSTER_RANIUM_MYSQL_1_HOST).
|
*/

$list = static fn (?string $value): array => array_values(array_filter(array_map('trim', explode(',', (string) $value))));

$clusters = [];

foreach ($list(env('BACKUP_CLUSTERS')) as $name) {
    $prefix = 'BACKUP_CLUSTER_'.strtoupper((string) preg_replace('/[^A-Za-z0-9]+/', '_', $name)).'_';

    $clusters[$name] = [
        'driver' => env($prefix.'DRIVER', 'mysql'),
        'host' => env($prefix.'HOST'),
        'port' => env($prefix.'PORT'),
        'username' => env($prefix.'USERNAME'),
        'password' => env($prefix.'PASSWORD'),
        'include' => $list(env($prefix.'INCLUDE')),
        'exclude' => $list(env($prefix.'EXCLUDE')),
        // MySQL: "true" adds --ssl. PostgreSQL: any libpq sslmode (Neon needs "require").
        'ssl' => env($prefix.'SSL'),
        // PostgreSQL only: the database psql connects to for listing the others.
        'maintenance_database' => env($prefix.'MAINTENANCE_DATABASE', 'postgres'),
        'env_prefix' => $prefix,
    ];
}

return [

    /*
     * Names this deployment in S3 keys and the run manifest, e.g.
     * "ranium-us-east-2". Keep it unique per Cloud account and region.
     */
    'deployment' => env('BACKUP_DEPLOYMENT', 'default'),

    'clusters' => $clusters,

    /*
     * Cron expression, evaluated in UTC. The default is 03:00 IST.
     */
    'schedule' => env('BACKUP_SCHEDULE', '30 21 * * *'),

    /*
     * A suspended cluster can take minutes to accept connections. Keep retrying
     * for this long before giving up on it.
     */
    'wake_timeout_seconds' => (int) env('BACKUP_WAKE_TIMEOUT', 300),
    'wake_retry_seconds' => (int) env('BACKUP_WAKE_RETRY', 10),

    /*
     * Ceiling for any single dump or encryption step.
     */
    'dump_timeout_seconds' => (int) env('BACKUP_DUMP_TIMEOUT', 3600),

    /*
     * Day of the month whose dump is also kept as the monthly copy.
     */
    'monthly_day' => (int) env('BACKUP_MONTHLY_DAY', 1),

    /*
     * Where dumps are staged before upload. Deleted after each database.
     */
    'work_dir' => env('BACKUP_WORK_DIR', storage_path('app/backup-work')),

    's3' => [
        'bucket' => env('BACKUP_S3_BUCKET'),
        'region' => env('BACKUP_S3_REGION'),
        'key' => env('BACKUP_S3_KEY'),
        'secret' => env('BACKUP_S3_SECRET'),
        'prefix' => trim((string) env('BACKUP_S3_PREFIX', 'db'), '/'),
    ],

    'age' => [
        // One or more age public keys; every one of them can decrypt.
        'recipients' => $list(env('BACKUP_AGE_RECIPIENTS')),
        // Installed by bin/install-age during the Cloud build.
        'binary' => env('BACKUP_AGE_BINARY'),
    ],

    /*
     * Optional healthchecks.io ping URL. Unset means no pings at all.
     */
    'healthcheck_url' => env('BACKUP_HEALTHCHECK_URL'),

];
