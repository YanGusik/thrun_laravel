<?php

declare(strict_types=1);

use Thrun\Transport\Strategy\PriorityStrategy;
use Thrun\Transport\Strategy\RoundRobinStrategy;
use Thrun\Transport\Policy\MaxConcurrencyPolicy;

return [
    'redis'       => [
        'host'    => env('THRUN_REDIS_HOST', '127.0.0.1'),
        'port'    => (int) env('THRUN_REDIS_PORT', 6379),
        'prefix'  => env('THRUN_REDIS_PREFIX', 'thrun:queue'),
        'timeout' => 1.0,
    ],

    // Queue definitions — each queue has its own transport.
    'queues'      => [
        'emails'           => [
            'transport' => 'redis',
        ],
        'notifications'    => [
            'transport' => 'memory',
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
                'threads'     => (int) env('THRUN_WORKER_THREADS', 2),
                'concurrency' => (int) env('THRUN_WORKER_CONCURRENCY', 100),
                'queue_size'  => (int) env('THRUN_WORKER_QUEUE_SIZE', 1000),
            ],

            'supervisor' => [
                'max_crashes'     => (int) env('THRUN_SUPERVISOR_MAX_CRASHES', 3),
                'restart_window'  => (int) env('THRUN_SUPERVISOR_RESTART_WINDOW', 300),
                'restart_backoff' => (float) env('THRUN_SUPERVISOR_RESTART_BACKOFF', 1.0),
            ],

            'strategy' => [
                'class'      => PriorityStrategy::class,
                'priorities' => ['emails' => 3, 'notifications' => 1],
            ],

            'policy' => [
                'enabled' => false,
                'class'   => MaxConcurrencyPolicy::class,
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

            'middleware' => [
                // List of middleware class names implementing WorkerMiddlewareInterface.
                // Resolved via Laravel container (constructor injection supported).
                //
                // \App\Middleware\LogMiddleware::class,
                // \App\Middleware\MetricsMiddleware::class,
                \Thrun\Middleware\CatchMessageMiddleware::class,
            ],
        ],

        'heavy_cpu' => [
            'queues' => ['video_processing'],

            'worker' => [
                'threads'     => 1,
                'concurrency' => 100,
                'query_size'  => 1000,
            ],

            'supervisor' => [
                'max_crashes'     => 5,
                'restart_window'  => 600,
                'restart_backoff' => 2.0,
            ],

            'strategy' => [
                'class'      => RoundRobinStrategy::class,
                'priorities' => ['video_processing' => 1],
            ],

            'policy' => [
                'enabled' => true,
                'class'   => MaxConcurrencyPolicy::class,
                'options' => ['max_per_partition' => 2],
            ],

            'handlers' => [
                // App\Messages\ProcessVideoMessage::class => App\Handlers\ProcessVideoHandler::class,
            ],

            'middleware' => [],
        ],
    ],

    'rpc' => [
        'enabled'     => env('THRUN_RPC_ENABLED', true),
        'transport'   => env('THRUN_RPC_TRANSPORT', 'unix'), // unix|tcp
        'socket_path' => env('THRUN_RPC_SOCKET', sys_get_temp_dir().'/thrun_rpc.sock'),
        'host'        => env('THRUN_RPC_HOST', '127.0.0.1'),
        'port'        => (int) env('THRUN_RPC_PORT', 9000),
    ],

    'failed' => [
        'driver' => env('THRUN_FAILED_DRIVER', 'redis'),
        'redis'  => [
            'prefix' => env('THRUN_FAILED_PREFIX', 'thrun:failed'),
        ],
    ],

    'auto_discover' => [
        'App\\Handlers',
        'App\\Jobs',
        'App\\Events',
    ],

    /*
    |--------------------------------------------------------------------------
    | Native Laravel Jobs
    |--------------------------------------------------------------------------
    |
    | Settings for `thrun:work-native`, which runs the application's ordinary
    | Laravel jobs — the ones dispatched with dispatch() — on thrun threads
    | instead of `queue:work`. Jobs and dispatch sites stay as they are.
    |
    | Requires yangusik/laravel-spawn: its async mode is what keeps concurrent
    | jobs on one thread from sharing container state.
    |
    */

    'native' => [
        // Polled in order, so the first name is the highest priority.
        'queues'      => ['default'],

        'threads'     => (int) env('THRUN_NATIVE_THREADS', 4),

        // Jobs running at once per thread. Total = threads * concurrency.
        'concurrency' => (int) env('THRUN_NATIVE_CONCURRENCY', 10),

        // Seconds to wait after finding every queue empty.
        'sleep'       => (float) env('THRUN_NATIVE_SLEEP', 1.0),
    ],

    /*
    |--------------------------------------------------------------------------
    | Job Payload Compression
    |--------------------------------------------------------------------------
    |
    | Compresses the serialized job body of ordinary Laravel jobs (the
    | `data.command` field) with lz4. The JSON envelope stays as it is, so
    | Redis-side Lua and Horizon keep working.
    |
    | The setting controls writing only — compressed bodies are always read, so
    | that turning it off leaves the jobs already queued runnable. To remove the
    | package: turn this off, drain the queues, then uninstall.
    |
    | Requires ext-lz4: https://github.com/kjdev/php-ext-lz4
    |
    */

    'compression' => [
        'enabled'   => (bool) env('THRUN_COMPRESSION', false),

        // Bodies below this size are stored as they are: lz4 plus base64 costs
        // more than it saves on small ones.
        'min_bytes' => (int) env('THRUN_COMPRESSION_MIN_BYTES', 512),
    ],
];
