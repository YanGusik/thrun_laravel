<?php

declare(strict_types=1);

use Thrun\Transport\Strategy\PriorityStrategy;
use Thrun\Transport\Strategy\RoundRobinStrategy;
use Thrun\Transport\Policy\MaxConcurrencyPolicy;

return [
    'redis' => [
        'host' => env('THRUN_REDIS_HOST', '127.0.0.1'),
        'port' => (int) env('THRUN_REDIS_PORT', 6379),
        'prefix' => env('THRUN_REDIS_PREFIX', 'thrun:queue'),
        'timeout' => 1.0,
    ],

    // Queue definitions — each queue has its own transport.
    'queues' => [
        'emails' => [
            'transport' => 'redis',
        ],
        'notifications' => [
            'transport' => 'redis',
        ],
        'video_processing' => [
            'transport' => 'redis',
        ],
    ],

    // Supervisors — each is an isolated Supervisor + Worker pair.
    // php artisan thrun:work           → starts ALL supervisors
    // php artisan thrun:work --supervisor=heavy_cpu  → starts one
    'supervisors' => [
        'default' => [
            'queues' => ['emails', 'notifications'],

            'worker' => [
                'threads' => (int) env('THRUN_WORKER_THREADS', 2),
                'concurrency' => (int) env('THRUN_WORKER_CONCURRENCY', 100),
            ],

            'supervisor' => [
                'max_crashes' => (int) env('THRUN_SUPERVISOR_MAX_CRASHES', 3),
                'restart_window' => (int) env('THRUN_SUPERVISOR_RESTART_WINDOW', 300),
                'restart_backoff' => (float) env('THRUN_SUPERVISOR_RESTART_BACKOFF', 1.0),
            ],

            'strategy' => [
                'class' => PriorityStrategy::class,
                'priorities' => ['emails' => 3, 'notifications' => 1],
            ],

            'policy' => [
                'enabled' => false,
                'class' => MaxConcurrencyPolicy::class,
                'options' => ['max_per_partition' => 5],
            ],

            'handlers' => [
                // Key = routeKey (string). Can be PHP class name or any string (e.g. for Go interop).
                // Value = class-string or static Closure.
                //
                // App\Messages\SendEmailMessage::class => App\Handlers\SendEmailHandler::class,
                // 'python_key' => App\Handlers\SendEmailHandler::class,
                // 'go_key' => static function ($message, $ack) { ... },
            ],

            'failure_transport' => null,
        ],

        'heavy_cpu' => [
            'queues' => ['video_processing'],

            'worker' => [
                'threads' => 1,
                'concurrency' => 100,
            ],

            'supervisor' => [
                'max_crashes' => 5,
                'restart_window' => 600,
                'restart_backoff' => 2.0,
            ],

            'strategy' => [
                'class' => RoundRobinStrategy::class,
                'priorities' => ['video_processing' => 1],
            ],

            'policy' => [
                'enabled' => true,
                'class' => MaxConcurrencyPolicy::class,
                'options' => ['max_per_partition' => 2],
            ],

            'handlers' => [
                // App\Messages\ProcessVideoMessage::class => App\Handlers\ProcessVideoHandler::class,
            ],

            'failure_transport' => null,
        ],
    ],

    'auto_discover' => [
        'App\Handlers',
    ],
];
