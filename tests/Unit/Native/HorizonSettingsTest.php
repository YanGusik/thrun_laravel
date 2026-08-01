<?php

declare(strict_types=1);

namespace Thrun\Laravel\Tests\Unit\Native;

use Testo\Assert;
use Testo\Test;
use Thrun\Laravel\Native\HorizonSettings;
use Thrun\Laravel\Tests\Fixture\ArrayConfig;

/**
 * An application migrating off Horizon should not have to restate its queues and
 * retries on the command line — that is where a migration silently changes
 * behaviour.
 */
#[Test]
final class HorizonSettingsTest
{
    public function readsTheSupervisorForTheConnection(): void
    {
        $settings = HorizonSettings::forConnection($this->config(), 'redis', 'production');

        Assert::same($settings->queues, ['high', 'default']);
        Assert::same($settings->tries, 5);
        Assert::same($settings->timeout, 120);
        Assert::same($settings->maxTime, 3600);
        Assert::same($settings->maxJobs, 1000);
    }

    public function fillsGapsFromTheDefaultsBlock(): void
    {
        // Horizon's own merge: an environment supervisor states only what differs.
        $settings = HorizonSettings::forConnection($this->config(), 'redis', 'staging');

        Assert::same($settings->queues, ['default']);
        Assert::same($settings->tries, 1);
        Assert::same($settings->timeout, 30);
    }

    public function saysNothingWhenTheConnectionIsNotHorizons(): void
    {
        $settings = HorizonSettings::forConnection($this->config(), 'sqs', 'production');

        Assert::same($settings->queues, []);
        Assert::null($settings->tries);
        Assert::null($settings->timeout);
    }

    public function saysNothingWhenHorizonIsNotConfigured(): void
    {
        $settings = HorizonSettings::forConnection(new ArrayConfig(), 'redis', 'production');

        Assert::same($settings->queues, []);
        Assert::null($settings->timeout);
    }

    private function config(): ArrayConfig
    {
        return new ArrayConfig([
            'horizon' => [
                'defaults' => [
                    'supervisor-1' => [
                        'connection' => 'redis',
                        'queue' => ['default'],
                        'tries' => 1,
                        'timeout' => 60,
                    ],
                ],
                'environments' => [
                    'production' => [
                        'supervisor-1' => [
                            'connection' => 'redis',
                            'queue' => ['high', 'default'],
                            'tries' => 5,
                            'timeout' => 120,
                            'maxTime' => 3600,
                            'maxJobs' => 1000,
                        ],
                    ],
                    'staging' => [
                        'supervisor-1' => [
                            'timeout' => 30,
                        ],
                    ],
                ],
            ],
        ]);
    }
}
