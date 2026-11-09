<?php

declare(strict_types=1);

namespace Thrun\Laravel\Native;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Support\Str;

/**
 * Reads what Horizon was already told about a connection.
 *
 * An application moving off Horizon has its queues, retries and timeouts spelled
 * out in `config/horizon.php`. Making the operator repeat them on the command
 * line is how a migration acquires its first behaviour change, so this reads them
 * instead — explicit command options still win.
 *
 * Only what a single worker command can honour is read. Horizon's per-supervisor
 * balancing, process counts and memory limits have no equivalent here, and a
 * setting that cannot be honoured is better absent than half applied.
 */
final readonly class HorizonSettings
{
    /**
     * @param list<string> $queues     every queue Horizon would work on this
     *                                 connection, in supervisor order
     * @param int|null     $tries      null when no supervisor says
     * @param int|null     $timeout    seconds
     * @param int|null     $maxTime    seconds; 0 means unlimited, as in Horizon
     * @param int|null     $maxJobs    0 means unlimited, as in Horizon
     * @param int          $supervisors how many supervisors covered this
     *                                  connection; above one, the numbers come
     *                                  from the first and the caller should say so
     */
    private function __construct(
        public array $queues = [],
        public ?int $tries = null,
        public ?int $timeout = null,
        public ?int $maxTime = null,
        public ?int $maxJobs = null,
        public int $supervisors = 0,
    ) {
    }

    /**
     * What Horizon would run for this connection in this environment, or an empty
     * set when Horizon is absent, aimed elsewhere, or would deploy nothing here.
     *
     * Queues are collected from every matching supervisor, because Horizon runs
     * them all; the remaining numbers come from the first, since one worker
     * cannot hold two sets.
     */
    public static function forConnection(Config $config, string $connection, string $environment): self
    {
        $defaults = (array) $config->get('horizon.defaults', []);
        $plan = self::planFor($config, $environment);

        if ($plan === null) {
            // Horizon deploys nothing when no environment matches, and neither
            // should the settings taken from it.
            return new self();
        }

        $queues = [];
        $first = null;
        $matched = 0;

        foreach ($plan as $name => $supervisor) {
            $merged = array_replace_recursive((array) ($defaults[$name] ?? []), (array) $supervisor);

            if (($merged['connection'] ?? null) !== $connection || (int) ($merged['maxProcesses'] ?? 1) <= 0) {
                continue;
            }

            $matched++;
            $first ??= $merged;
            $queues = [...$queues, ...self::queueNames($merged['queue'] ?? [])];
        }

        if ($first === null) {
            return new self();
        }

        return new self(
            queues: array_values(array_unique($queues)),
            tries: isset($first['tries']) ? (int) $first['tries'] : null,
            timeout: isset($first['timeout']) ? (int) $first['timeout'] : null,
            maxTime: isset($first['maxTime']) ? (int) $first['maxTime'] : null,
            maxJobs: isset($first['maxJobs']) ? (int) $first['maxJobs'] : null,
            supervisors: $matched,
        );
    }

    /**
     * The environment's supervisors, matched the way Horizon matches them — by
     * pattern, so a `*` key covers everything.
     *
     * @return array<string, mixed>|null null when nothing matches
     */
    private static function planFor(Config $config, string $environment): ?array
    {
        $environments = (array) $config->get('horizon.environments', []);

        foreach ($environments as $pattern => $plan) {
            if (Str::is((string) $pattern, $environment)) {
                return (array) $plan;
            }
        }

        return null;
    }

    /**
     * Horizon stores a supervisor's queues either as a list or as one
     * comma-separated string, and both reach here.
     *
     * @return list<string>
     */
    private static function queueNames(mixed $queue): array
    {
        $names = is_string($queue) ? explode(',', $queue) : (array) $queue;

        return array_values(array_filter(array_map(trim(...), $names), static fn(string $n): bool => $n !== ''));
    }
}
