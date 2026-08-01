<?php

declare(strict_types=1);

namespace Thrun\Laravel\Native;

use Illuminate\Contracts\Config\Repository as Config;

/**
 * Reads what Horizon was already told about a connection.
 *
 * An application moving off Horizon has its queues, retries and timeouts spelled
 * out in `config/horizon.php`. Making the operator repeat them on the command
 * line is how a migration acquires its first behaviour change, so this reads
 * them instead — explicit command options still win.
 */
final readonly class HorizonSettings
{
    /**
     * @param list<string> $queues     from the supervisor's `queue`
     * @param int|null     $tries      null when the supervisor does not say
     * @param int|null     $timeout    seconds
     * @param int|null     $maxTime    seconds, 0 means no limit in Horizon too
     * @param int|null     $maxJobs
     */
    private function __construct(
        public array $queues = [],
        public ?int $tries = null,
        public ?int $timeout = null,
        public ?int $maxTime = null,
        public ?int $maxJobs = null,
    ) {
    }

    /**
     * The supervisor Horizon would use for this connection, or an empty set when
     * Horizon is absent, configured for other connections, or silent.
     *
     * The first matching supervisor wins, as it does in Horizon itself when
     * several cover one connection.
     */
    public static function forConnection(Config $config, string $connection, string $environment): self
    {
        $supervisors = $config->get("horizon.environments.{$environment}")
            ?? $config->get('horizon.defaults')
            ?? [];

        foreach ($supervisors as $name => $supervisor) {
            $defaults = $config->get("horizon.defaults.{$name}", []);
            $merged = array_merge(is_array($defaults) ? $defaults : [], is_array($supervisor) ? $supervisor : []);

            if (($merged['connection'] ?? null) !== $connection) {
                continue;
            }

            return new self(
                queues: array_values((array) ($merged['queue'] ?? [])),
                tries: isset($merged['tries']) ? (int) $merged['tries'] : null,
                timeout: isset($merged['timeout']) ? (int) $merged['timeout'] : null,
                maxTime: isset($merged['maxTime']) ? (int) $merged['maxTime'] : null,
                maxJobs: isset($merged['maxJobs']) ? (int) $merged['maxJobs'] : null,
            );
        }

        return new self();
    }
}
