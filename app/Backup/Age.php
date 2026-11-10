<?php

namespace App\Backup;

/**
 * The age binary and the public keys every dump is encrypted to. Only public
 * keys ever reach this app; the private key stays offline with whoever restores.
 */
final readonly class Age
{
    /**
     * @param  list<string>  $recipients
     */
    public function __construct(
        private string $binary,
        public array $recipients,
    ) {}

    public static function fromConfig(): self
    {
        $binary = config('backup.age.binary');

        return new self(
            filled($binary) ? (string) $binary : base_path('.bin/age-'.self::architecture()),
            config('backup.age.recipients', []),
        );
    }

    /**
     * Release architecture name for this machine, as age publishes them.
     */
    public static function architecture(): string
    {
        return match (php_uname('m')) {
            'aarch64', 'arm64' => 'arm64',
            default => 'amd64',
        };
    }

    public function binary(): string
    {
        return $this->binary;
    }

    /**
     * @return list<string>
     */
    public function recipientArguments(): array
    {
        $arguments = [];

        foreach ($this->recipients as $recipient) {
            $arguments[] = '-r';
            $arguments[] = $recipient;
        }

        return $arguments;
    }

    /**
     * Everything wrong with the setup; empty when it is usable.
     *
     * @return list<string>
     */
    public function problems(): array
    {
        $problems = [];

        if ($this->recipients === []) {
            $problems[] = 'BACKUP_AGE_RECIPIENTS is not set.';
        }

        foreach ($this->recipients as $recipient) {
            if (! str_starts_with($recipient, 'age1')) {
                $problems[] = "\"{$recipient}\" is not an age public key (they start with \"age1\").";
            }
        }

        if (! is_executable($this->binary)) {
            $problems[] = "No executable age binary at {$this->binary}. Run bin/install-age during the build.";
        }

        return $problems;
    }
}
