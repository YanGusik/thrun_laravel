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

        // The application's own Laravel queue, drained by thrun instead of
        // `queue:work`. Jobs and dispatch() calls stay exactly as they are.
        //
        // A supervisor that lists this queue boots a full Laravel application in
        // every thread, and its concurrency drops to one job per thread unless
        // bootstrap/app.php builds an AsyncApplication (yangusik/laravel-spawn):
        // without it the jobs on a thread would share one container.
        //
        // Everything `queue:work` takes on the command line is written here, and
        // what Horizon was already configured with fills in whatever is left out.
        //
        // 'laravel_jobs'  => [
        //     'transport'       => 'laravel',
        //
        //     // null takes queue.default. Redis connections only.
        //     'connection'      => null,
        //
        //     // Polled in order, so the first name is the highest priority.
        //     // Omit the key to take Horizon's queues for this connection.
        //     'queues'          => ['default'],
        //
        //     // Seconds to wait after finding every queue empty.
        //     'sleep'           => 1.0,
        //
        //     // As --tries, --timeout and --backoff. A job that declares its own
        //     // $tries or $timeout still wins, as it does under queue:work.
        //     'tries'           => 1,
        //     'timeout'         => 60,
        //     'backoff'         => 0,
        //
        //     // Limits that end the run — and with it the whole process, since
        //     // thrun:work stops its other supervisors once one of them returns.
        //     // A queue that uses them wants a process of its own. 0 = no limit.
        //     'max_jobs'        => 0,
        //     'max_time'        => 0,
        //     'stop_when_empty' => false,
        //
        //     // Keep working while the application is down for maintenance.
        //     'force'           => false,
        // ],
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

                // Seconds a shutdown waits for jobs that do not end on their own.
                // Only a run that can finish reaches it — see the laravel transport.
                // 'shutdown_grace' => 60,
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
