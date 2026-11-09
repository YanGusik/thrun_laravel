# Native mode end-to-end check

Proves the claim the native runner is built on: an ordinary Laravel job,
dispatched the ordinary way, runs on thrun threads exactly once and leaves no
reservation behind. Unit tests cannot show that — it needs a real application, a
real Redis and real threads.

## Setup

In a Laravel application that has `yangusik/thrun-laravel` and
`yangusik/laravel-spawn` installed:

1. Point `bootstrap/app.php` at the async application, as laravel-spawn requires:

   ```php
   use Spawn\Laravel\Foundation\AsyncApplication as Application;
   ```

2. Copy `RecordJob.php` into `app/Jobs/`, and `seed.php` and `verify.php` into
   the application root.
3. Set `QUEUE_CONNECTION=redis` and point `REDIS_HOST` at a running Redis.
4. Declare the queue and a supervisor of its own for it in `config/thrun.php`:

   ```php
   'queues' => [
       'laravel_jobs' => [
           'transport'       => 'laravel',
           'queues'          => ['default'],
           'stop_when_empty' => true,
       ],
   ],

   'supervisors' => [
       'e2e' => [
           'queues' => ['laravel_jobs'],
           'worker' => ['threads' => 4, 'concurrency' => 10],
       ],
   ],
   ```

## Run

```bash
php seed.php 20                              # dispatch 20 jobs
php artisan thrun:work --supervisor=e2e      # stop_when_empty ends the run
php verify.php 20
```

`verify.php` prints one line and exits non-zero unless all of it holds:

```
executed=20 unique=20 duplicates=0 missing=0 reserved=0 ready=0 delayed=0
E2E OK
```

- `duplicates=0` — no job ran twice, which is what a mishandled reservation
  produces.
- `reserved=0` — Laravel's worker deleted every reservation from inside the
  thread. A non-zero count here means the delete went somewhere else.
- `ready=0 delayed=0` — nothing was quietly pushed back.

## Known trap

Run this against a PHP build whose phpredis includes
`redis_pool: propagate template setOption() state to pooled connections`. Before
that commit the pooled connection drops `OPT_PREFIX`, so every key is written
unprefixed: the jobs run, and every reservation stays behind because the delete
addresses a key that does not exist. The image
`trueasync/php-true-async:0.8.4-php8.6-alpine` is built one commit earlier.
