<?php

namespace App\Backup;

use RuntimeException;

/**
 * A failure that belongs to one database or cluster, reported in the run's
 * summary rather than aborting the whole run.
 */
class BackupException extends RuntimeException {}
