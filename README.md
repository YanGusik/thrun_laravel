# Thrun Laravel

Laravel adapter for the async queue worker [**Thrun**](https://github.com/yangusik/thrun). Built on real OS threads (TrueAsync) and provides two workflow styles: **clean architecture** (recommended) and **self-handling jobs** (for simple tasks).

---

## Benchmarks

Measured on WSL2, 8GB RAM, PHP 8.6 TrueAsync fork:

| Scenario     | Config                   | Jobs   | Time   | Throughput | RSS     |
| ------------ | ------------------------ | ------ | ------ | ---------- | ------- |
| Horizon IO   | 12 workers               | 1,000  | 12.1s  | 83/s       | 872 MB  |
| Thrun IO     | 1 thread, 100 coroutines | 1,000  | 2.3s   | 434/s      | 80 MB   |
| Horizon IO   | 12 workers               | 10,000 | 55.0s  | 182/s      | 1019 MB |
| Thrun IO     | 1 thread, 100 coroutines | 10,000 | 6.3s   | 1580/s     | 84 MB   |
| Horizon CPU  | 12 workers               | 100    | 18.4s  | 5.4/s      | 1022 MB |
| Thrun CPU    | 12 threads               | 100    | 16.3s  | 6.1/s      | 100 MB  |
| Horizon CPU  | 12 workers               | 1,000  | 162.6s | 6.2/s      | 1023 MB |
| Thrun CPU    | 12 threads               | 1,000  | 139.5s | 7.2/s      | 101 MB  |
| Horizon NOOP | 12 workers               | 1,000  | 5.0s   | 198/s      | 656 MB  |
| Thrun NOOP   | 12 threads               | 1,000  | 2.3s   | 434/s      | 103 MB  |



TrueAsync 12x10 uses **17x less RSS** than Horizon 12 workers, **11x more IO throughput**.

---

## Installation

```bash
composer require yangusik/thrun-laravel
```

Publish the configuration file:

```bash
php artisan vendor:publish --tag=thrun-config
```

---

## Configuration (`config/thrun.php`)

### Redis

```php
'redis' => [
    'host'    => env('THRUN_REDIS_HOST', '127.0.0.1'),
    'port'    => (int) env('THRUN_REDIS_PORT', 6379),
    'prefix'  => env('THRUN_REDIS_PREFIX', 'thrun:queue'),
    'timeout' => 1.0,
],
```

> **Note:** `prefix` affects Redis keys. Default is `thrun:queue`, but you can change it when multiple environments share the same Redis instance.

### Queues

Each queue is a separate transport. Currently supported: `redis` and `memory`.

```php
'queues' => [
    'emails'        => ['transport' => 'redis'],
    'notifications' => ['transport' => 'memory'],
],
```

`memory` transport holds jobs in process memory with no external dependencies.
Primarily useful for local development and testing when you don't want to run Redis.

All `memory` queues are automatically exposed on the RPC server —
external processes can push jobs into them over a socket.

> **Note:** `memory` queues are designed for up to `queue_size` jobs in flight.
> Sending significantly more jobs than `queue_size` allows provides no delivery
> guarantees. Use `redis` for workloads that require guaranteed delivery.

> FOR MEMORY USE: `$bus->dispatchViaRpc();`

### Worker configuration

```php
'worker' => [
    'threads'     => env('THRUN_WORKER_THREADS', 2),
    'concurrency' => env('THRUN_WORKER_CONCURRENCY', 100),
    'queue_size'  => env('THRUN_WORKER_QUEUE_SIZE', 1000),
],
```

- `threads` — number of OS threads. For I/O-bound workloads `1` is enough; increase for CPU-bound tasks.
- `concurrency` — number of coroutines per thread.
- `queue_size` — internal job buffer size. For `redis` queues this is a soft throughput limiter — Redis keeps the rest. For `memory` queues the buffer is the only storage, so tune this to match your expected peak load.


### Supervisors

Each supervisor is an isolated worker group with its own queues, strategy, and policies.

```php
'supervisors' => [
    'default' => [
        'queues'    => ['emails', 'notifications'],
        'worker'    => ['threads' => 2, 'concurrency' => 100, 'queue_size' => 1000],
        'supervisor'=> ['max_crashes' => 3, 'restart_window' => 300, 'restart_backoff' => 1.0],
        'strategy'  => ['class' => PriorityStrategy::class, 'priorities' => ['emails' => 3, 'notifications' => 1]],
        'policy'    => ['enabled' => false, 'class' => MaxConcurrencyPolicy::class, 'options' => ['max_per_partition' => 5]],
        'handlers'  => [],              // manual routing
    ],
],
```

### RPC Server

The RPC server runs alongside the worker and enables cross-process job dispatch and event broadcasting.

```php
'rpc' => [
    'enabled'     => env('THRUN_RPC_ENABLED', true),
    'transport'   => env('THRUN_RPC_TRANSPORT', 'unix'), // unix | tcp
    'socket_path' => env('THRUN_RPC_SOCKET', '/tmp/thrun_rpc.sock'),
    'host'        => env('THRUN_RPC_HOST', '127.0.0.1'),
    'port'        => (int) env('THRUN_RPC_PORT', 9000),
],
```

### Failed Jobs

When a message exhausts all retries, it is sent to the global failed job store (configured separately from supervisors):

```php
'failed' => [
    'driver' => env('THRUN_FAILED_DRIVER', 'redis'),
    'redis' => [
        'prefix' => env('THRUN_FAILED_PREFIX', 'thrun:failed'),
    ],
],
```

Supported drivers: `redis` (default) and `null` (no-op).

You can register custom failed job drivers:

```php
use Thrun\Laravel\Transport\TransportFactory;

$factory = app(TransportFactory::class);
$factory->extendFailed('database', function (array $config) {
    return new DatabaseFailedJobSender(
        table: $config['database']['table'] ?? 'thrun_failed_jobs',
    );
});
```

### Auto-discover

```php
'auto_discover' => [
    'App\\Handlers',   // regular Handler classes
    'App\\Jobs',       // self-handling Job classes
    'App\\Events',     // event listeners
],
```

### Native Laravel Jobs

Runs the jobs an application already has — `implements ShouldQueue`, dispatched
with `dispatch()` — on thrun threads instead of `queue:work`. No `#[ThrunJob]`, no
Message/Handler split, no change to the dispatch sites. The queue stays Laravel's
own, so a stock worker can drain it at the same time.

Laravel's queue is declared as one more thrun queue, with the `laravel`
transport, and a supervisor picks it up like any other:

```php
'queues' => [
    'emails'       => ['transport' => 'redis'],

    'laravel_jobs' => [
        'transport'  => 'laravel',
        'connection' => null,        // null takes queue.default
        'queues'     => ['high', 'default'],
        'sleep'      => 1.0,
        'tries'      => 1,
        'timeout'    => 60,
        'backoff'    => 0,
    ],
],

'supervisors' => [
    'default' => [
        'queues' => ['emails', 'laravel_jobs'],
        'worker' => ['threads' => 4, 'concurrency' => 10],
    ],
],
```

```bash
php artisan thrun:work
```

One supervisor can carry both kinds at once, which is what a gradual migration
needs: messages already rewritten for thrun and jobs still written for Laravel
share the same threads, and `strategy` decides who goes first. Keeping them in
separate supervisors is equally valid — same process, same command, own threads
each.

**Coming from Horizon?** If `config/horizon.php` is present, the supervisors for
this connection supply the defaults — every one of their queues, plus `tries`,
`timeout`, `maxTime` and `maxJobs` — so the settings the application already runs
on are not restated. A key written in `config/thrun.php` always wins; its absence
is what lets Horizon's value through. Where Horizon runs several supervisors on
one connection with different retries, one worker cannot: the queues are all
served, the numbers come from the first supervisor, and that is logged at
startup.

Requires a **redis** queue connection, checked while the worker is being built.

`yangusik/laravel-spawn` is optional and decides how many jobs may share a
thread. With `bootstrap/app.php` pointed at its `AsyncApplication`, each
coroutine gets its own container-scoped services, database connection and log
context, and `concurrency` applies as configured. Without it there is nothing to
keep concurrent jobs apart, so the whole supervisor drops to one job per thread
and logs why — parallelism then comes from `threads` alone. A supervisor that
carries no Laravel jobs is unaffected, which is a reason to give them one of
their own.

**How it works.** The main thread reserves jobs through Laravel's own
`RedisQueue::pop()`, so the Lua scripts, the `:reserved` set and the `attempts`
counter behave exactly as under `queue:work`. Four strings cross into a worker
thread, which rebuilds the job there and hands it to a subclass of Laravel's
`Worker`. Deleting, releasing and failing all happen through the framework, which
is how `maxTries`, `backoff`, `retryUntil`, job middleware, batches and
`failed()` keep working unchanged.

Reserving stops once the jobs handed over reach `threads × concurrency`. A
reservation ages from the moment `pop()` returns, so filling a deep buffer would
spend the window jobs need to run in; the queue therefore keeps its own count and
is unaffected by the supervisor's `queue_size`.

**Supported**, as keys of the queue rather than command options: `tries`,
`timeout`, `backoff`, `max_jobs`, `max_time`, `stop_when_empty`, `force`, plus
`queue:restart` and maintenance mode. A job's own `$tries`, `$timeout` and
`$backoff` still win over the configured ones, as under `queue:work`.

**Stopping.** `queue:restart` is the graceful way out and the one to use on
deploy: the worker stops reserving, waits for the jobs in flight
(`worker.shutdown_grace`, 60s by default) and only then exits, so a supervisor
outside can start the new code. **SIGTERM and SIGINT do not drain** — thrun's
supervisor cancels the thread pool where it stands, and jobs caught mid-run lose
their thread and stay reserved until `retry_after` returns them to the queue.
With `tries: 1` such a job is failed on redelivery without having finished.

**A run that ends, ends the process.** `thrun:work` cancels its remaining
supervisors as soon as the first one returns, and a composed receiver drops
whatever its other queues had already buffered. That is what `queue:restart` is
supposed to do, but it makes `stop_when_empty`, `max_jobs` and `max_time` a poor
neighbour: give a queue that uses them a process of its own
(`thrun:work --supervisor=drain`), not merely a supervisor of its own.

On timeout the behaviour matches `queue:work`: a job with attempts left is left
reserved and comes back when the reservation expires; out of attempts, it is
failed once with `TimeoutExceededException`.

**Not supported, and worth knowing before adopting:**

- The timeout is cooperative. A job stuck in a blocking C call or a CPU loop
  cannot be interrupted the way `pcntl_alarm` kills a process — it holds its
  thread until it returns.
- `--memory` and Horizon's `queue:pause` are not implemented.
- A lost database connection does not recycle the worker. `queue:work` exits so
  its supervisor restarts it with a fresh connection; here the thread keeps
  going, so run behind something that restarts on failure and watch your logs.
- `timeout` must stay below `retry_after`, the same rule `queue:work` has: a job
  that outlives its reservation gets reserved a second time.
- `retry_after` must also exceed the time a worker thread needs to boot the
  application — a second or two on a cold cache. Set below that, the first jobs
  of a run can have their reservations expire while the threads are still
  starting; with `--tries=1` such a job is then failed without ever running.

See `tests/e2e/native/` for the end-to-end check and how to run it.

### Job Payload Compression

Compresses the serialized body of **ordinary Laravel jobs** — the `data.command`
field — with lz4. It applies to jobs dispatched the usual way, on Laravel's
`redis` queue connection, and both `queue:work` and Horizon keep running them.
(Thrun's own queues are a separate world with their own serializer; this setting
does not touch them.)

```php
'compression' => [
    'enabled'   => (bool) env('THRUN_COMPRESSION', false),
    'min_bytes' => (int) env('THRUN_COMPRESSION_MIN_BYTES', 512),
],
```

Requires [ext-lz4](https://github.com/kjdev/php-ext-lz4).

Measured on PHP 8.6 (ZTS, TrueAsync), `serialize()` output of three job shapes:

| Job | Plain | Compressed | Saved | Compress | Decompress |
|---|---|---|---|---|---|
| Notification, two scalars | 67 B | 67 B | — | 0.1 µs | — |
| Mail to 50 recipients | 1709 B | 823 B | 52% | 2.0 µs | 2.4 µs |
| Report export, 500 rows | 32210 B | 10851 B | 66% | 32.4 µs | 56.8 µs |

Bodies below `min_bytes` are stored untouched — lz4 plus base64 costs more than
it saves on small ones, as the first row shows.

**Why only the body.** The JSON envelope around it stays JSON: Redis parses it
server-side (`LuaScripts::pop()` runs `cjson.decode` to increment `attempts`) and
Horizon reads the same JSON for its dashboard. Compressing the whole payload —
for example by turning on phpredis' own serializer — breaks both, which is
[laravel/horizon#1534](https://github.com/laravel/horizon/issues/1534).

**Rollout and rollback.** The setting controls **writing** only; compressed
bodies are always read. So a queue may hold both shapes at once, and turning the
setting off leaves everything already queued runnable. To remove the package:
turn compression off, drain the queues, then uninstall.

Reading always on has a consequence worth stating plainly: installing this
package replaces the framework's `CallQueuedHandler` and extends `queue:retry`,
compression enabled or not. Both replacements only add the "unpack the body if it
carries our marker" step and defer to the framework for everything else.

---

## Two Workflow Styles

### 1. Clean Architecture (recommended) — Message + Handler

Message is a DTO with data. Handler is an invokable class with business logic. Pure Symfony-style separation of concerns.

**Message:**

```php
namespace App\Messages;

use Thrun\Laravel\Handler\Attribute\Delay;
use Thrun\Laravel\Handler\Attribute\Queue;
use Thrun\Laravel\Handler\Attribute\Retry;

#[Queue('emails')]
#[Retry(backoff: [1000, 2000, 4000], maxAttempts: 3)]
#[Delay(5000)]
final readonly class SendEmailMessage
{
    public function __construct(
        public string $to,
        public string $subject,
    ) {}
}
```

**Handler:**

```php
namespace App\Handlers;

use App\Messages\SendEmailMessage;
use Thrun\Laravel\Handler\AsThrunHandler;
use Thrun\Worker\Acknowledger;

#[AsThrunHandler] // auto-wired to SendEmailMessage
final class SendEmailHandler
{
    public function __construct(private MailerInterface $mailer) {}

    public function __invoke(SendEmailMessage $message, Acknowledger $ack): void
    {
        $this->mailer->send($message->to, $message->subject);
        $ack->ack();
    }
}
```

**Dispatch:**

```php
use Thrun\Laravel\Bus\ThrunMessageBus;
use Thrun\Laravel\Bus\DispatchOptions;

$bus->dispatch(new SendEmailMessage('user@test.com', 'Hello'));

// or with option override:
$bus->dispatch(
    new SendEmailMessage('user@test.com', 'Hello'),
    'emails',
    new DispatchOptions(delayMs: 10_000, messageId: 'email-42'),
);
```

---

### 2. Alternative — Self-handling Job

A single class acts as both Message and Handler. Data goes in the constructor, logic in `__invoke()`. For simple tasks when you don't want extra files.

```php
namespace App\Jobs;

use Thrun\Laravel\Handler\Attribute\Queue;
use Thrun\Laravel\Handler\Attribute\Retry;
use Thrun\Laravel\Handler\Attribute\ThrunJob;
use Thrun\Worker\Acknowledger;

#[ThrunJob]
#[Queue('emails')]
#[Retry(backoff: [1000, 2000, 4000], maxAttempts: 3)]
final readonly class SendEmailJob
{
    public function __construct(
        public string $to,
        public string $subject,
    ) {}

    public function __invoke(MailerInterface $mailer, Acknowledger $ack): void
    {
        $mailer->send($this->to, $this->subject);
        $ack->ack();
    }
}
```

```php
// queue is taken from #[Queue] attribute
$bus->dispatch(new SendEmailJob('user@test.com', 'Hello'));

// or override:
$bus->dispatch(new SendEmailJob('user@test.com', 'Hello'), 'urgent-emails');
```

> **Important:** the constructor accepts **scalar data only** (`int`, `string`, `array`, etc.) — these values get serialized to Redis. Never inject services or objects into the constructor. All services are injected via DI in `__invoke()` — Laravel `Container::call()` resolves them automatically.

---

## Attributes

| Attribute                                  | Purpose                            | Applies to     |
|--------------------------------------------|------------------------------------|----------------|
| `#[ThrunJob]`                              | Self-handling job marker           | Job            |
| `#[Queue('emails')]`                       | Default queue for the message      | Job / Message  |
| `#[Retry(backoff: [...], maxAttempts: 3)]` | Retry policy                       | Job / Message  |
| `#[Delay(5000)]`                           | Delay in ms                        | Job / Message  |
| `#[Timeout(30000)]`                        | Hard execution timeout             | Job / Message  |
| `#[AsThrunHandler(messageClass: ...)]`     | Explicit Handler → Message binding | Handler        |
| `#[ThrunEventListener('order.completed')]` | Event listener binding             | Event Listener |

**Dispatch priority:**
1. `dispatch()` argument (explicit)
2. `#[Queue]` attribute on class
3. `'default'`

---

## Acknowledger

`Acknowledger` is the explicit processing acknowledgement object.

```php
public function __invoke(MyMessage $message, Acknowledger $ack): void
{
    // ... logic ...
    $ack->ack();   // confirm success
    // $ack->nack(); // reject (goes to retry or failure transport)
}
```

> **Recommendation:** always accept `Acknowledger $ack` explicitly and call `$ack->ack()`. This gives you full control over the message lifecycle.

---

## Events

Thrun provides a lightweight pub/sub event system over the RPC socket. Events are **ephemeral** (fire-and-forget, no persistence) — use jobs for guaranteed delivery.

### Emitting an event from a handler

```php
use Thrun\Laravel\Rpc\RpcPublisher;

final class ProcessOrderHandler
{
    public function __construct(private RpcPublisher $rpc) {}

    public function __invoke(ProcessOrderMessage $message, Acknowledger $ack): void
    {
        // ... business logic ...
        $this->rpc->emit('order.completed', ['order_id' => $message->orderId]);
        $ack->ack();
    }
}
```

### Registering a listener

```php
namespace App\Events;

use Thrun\Laravel\Event\Attribute\ThrunEventListener;

#[ThrunEventListener('order.completed')]
final class OrderCompletedListener
{
    public function __construct(private readonly MailService $mail) {}

    public function __invoke(array $payload): void
    {
        $this->mail->sendConfirmation($payload['order_id']);
    }
}

```
Wildcard subscriptions:

```php
#[ThrunEventListener('*')]                // all events
#[ThrunEventListener('payment.*')]        // all payment.* events
#[ThrunEventListener]                     // uses class name as identifier (PHP-only)
```

### Running the listener process

```bash
php artisan thrun:event

# subscribe to specific patterns
php artisan thrun:event --subscribe=order.completed --subscribe=payment.*
```

### Emitting from another language (Go example)

```go
payload := map[string]any{
    "event": "order.completed",
    "data":  map[string]any{"order_id": 123},
}
body, _ := json.Marshal(payload)

// FrameType::Event = 0x02
frame := make([]byte, 5+len(body))
binary.BigEndian.PutUint32(frame[:4], uint32(len(body)))
frame[4] = 0x02
copy(frame[5:], body)
conn.Write(frame)
```

---

## Wire Protocol

All RPC communication uses a simple length-prefixed binary framing:
[4 bytes BE uint32 — payload length\][1 byte — frame type\][N bytes — JSON payload\]

| Type        | Byte   | Direction       | Purpose                                   |
|-------------|--------|-----------------|-------------------------------------------|
| `Job`       | `0x01` | client → server | Push job into a local memory queue        |
| `Event`     | `0x02` | client → server | Broadcast event to subscribers            |
| `Subscribe` | `0x03` | client → server | Register interest in an event name/pattern |
| `RpcRequest` | `0x04` | client → server | Synchronous call, expects `RpcReply`      |
| `RpcReply`  | `0x05` | server → client | Response to `RpcRequest`                  |
| `Error`     | `0x06` | server → client | Error response                            |

---



---

## Auto-discover

| Convention | Result |
|---|---|
| `#[AsThrunHandler]` on handler class | Explicit binding to `messageClass` |
| `SendEmailHandler` → `SendEmailMessage` | Naming convention auto-wire |
| `#[ThrunJob]` on invokable class | Class registers as its own handler |
| `#[ThrunEventListener('event.name')]` | Binds listener to event name/pattern |

---

## Middleware

You can register worker middleware per supervisor via config. Classes are resolved through the Laravel container (constructor injection is supported).

```php
'supervisors' => [
    'default' => [
        // ...
        'middleware' => [
            \App\Middleware\LogMiddleware::class,
            \App\Middleware\MetricsMiddleware::class,
        ],
    ],
],
```

A middleware must implement `WorkerMiddlewareInterface` from the core `thrun` package:

```php
namespace App\Middleware;

use Thrun\Worker\Acknowledger;
use Thrun\Contract\WorkerMiddlewareInterface;

final class LogMiddleware implements WorkerMiddlewareInterface
{
    public function handle(object $message, Acknowledger $ack, \Closure $next): void
    {
        try {
            $next($message, $ack);
        } catch (\Throwable $e) {
            // log, metrics, etc.
            throw $e;
        }
    }
}
```

---

## Failed Jobs & CLI

When a message exhausts all retries, it is persisted to the failed job store (configured in `config/thrun.php` under `failed`).

### List failed jobs

```bash
php artisan thrun:failed
php artisan thrun:failed --queue=emails
php artisan thrun:failed --limit=100
```

### Show details of a failed job

```bash
php artisan thrun:failed:show 019e9c83-c3d7-7216-b37d-04b1c154a5c8
```

Shows: type, queue, exception, message, file, line, full trace, payload, stamps.

### Retry a failed job

```bash
php artisan thrun:retry 019e9c83-c3d7-7216-b37d-04b1c154a5c8
php artisan thrun:retry --all
```

Retry creates a **new** message with a fresh `JobIdStamp` but preserves `MessageIdStamp`.

### Flush failed jobs

```bash
php artisan thrun:failed:flush
```

### Flush queues

```bash
# Flush a specific queue (ready, processing, delayed)
php artisan thrun:flush emails

# Flush all configured queues
php artisan thrun:flush

# Flush queues + failed jobs
php artisan thrun:flush --failed
```

---

## Running the Worker

```bash
php artisan thrun:work                    # all supervisors + RPC server
php artisan thrun:work --supervisor=X     # single supervisor
php artisan thrun:work --stats            # with real-time throughput stats
php artisan thrun:work --no-rpc           # without RPC server
```

---

---

## Commands Reference

| Command | Description |
|---|---|
| `thrun:work` | Start all supervisors + RPC server |
| `thrun:work --supervisor=X` | Start a single supervisor + RPC server |
| `thrun:work --stats` | Real-time throughput stats |
| `thrun:work --no-rpc` | Disable RPC server for this process |
| `thrun:event` | Listen and dispatch incoming events |
| `thrun:event --subscribe=X` | Subscribe to specific pattern |
| `thrun:failed` | List failed jobs |
| `thrun:failed:show {id}` | Show details of a failed job |
| `thrun:retry {id}` | Retry a specific failed job |
| `thrun:retry --all` | Retry all failed jobs |
| `thrun:failed:flush` | Delete all failed jobs |
| `thrun:flush {queue?}` | Flush queue(s) |
| `thrun:flush --failed` | Flush queues + failed jobs |

---

## DispatchOptions

Explicit stamp control when dispatching (overrides class attributes):

```php
use Thrun\Laravel\Bus\DispatchOptions;

$bus->dispatch($message, 'emails', new DispatchOptions(
    messageId: 'uuid-42',
    delayMs: 5000,
    retryBackoff: [1000, 2000, 4000],
    maxAttempts: 3,
    timeoutMs: 30000,
));
```

For edge cases you can use `dispatchCustom()` with a ready-made `Envelope`:

```php
use Thrun\Envelope\Envelope;
use Thrun\Envelope\Stamp\QueueStamp;

$bus->dispatchCustom(
    Envelope::wrap($message, new QueueStamp('custom')),
    'emails',
);
```

---

## Message IDs

You can generate dynamic message IDs directly from the message payload using `IdentifiableMessage`:

```php
use Thrun\Laravel\Contract\IdentifiableMessage;

#[Queue('emails')]
final readonly class SendEmailMessage implements IdentifiableMessage
{
    public function __construct(
        public string $to,
        public string $subject,
        public int $userId,
        public int $productId,
    ) {}

    public function getId(): string
    {
        return "{$this->userId}-{$this->productId}";
    }
}
```

```php
$bus->dispatch(new SendEmailMessage('a@b.com', 'Hi', 42, 7));
// messageId = "42-7" automatically
```

Priority:
1. `DispatchOptions->messageId`
2. `IdentifiableMessage->getId()`
3. `null` (no ID)

---

## Fluent Builder

For quick one-off overrides without creating a `DispatchOptions` object:

```php
$bus->builder()
    ->id('custom-42')
    ->retry([1000, 2000], 3)
    ->delay(5000)
    ->timeout(30000)
    ->send($message, 'emails');
```

---

## Extending Transports

You can register custom transports (e.g. RabbitMQ) via closures or config drivers.

### Closure driver

```php
use Thrun\Laravel\Transport\TransportFactory;

$factory = app(TransportFactory::class);
$factory->extend('rabbitmq', function (string $name, array $config) {
    return new RabbitMQTransport(
        host: $config['host'],
        port: $config['port'],
        queue: $name,
    );
});
```

### Config-based driver

```php
'queues' => [
    'orders' => [
        'transport' => 'custom',
        'driver'    => \App\Transport\RabbitMQTransport::class,
        'host'      => 'localhost',
    ],
],
```

The factory will try to resolve the class via Laravel `Container::make()` with `['name' => ..., 'config' => ...]`, or fall back to `new $driverClass($name, $config)`.

---

## Requirements

- PHP (TrueAsync Core) ^8.6
- Laravel ^11.0
- [ext-async](https://github.com/true-async/php-async) (TrueAsync extension)
- [ext-phpredis](https://github.com/true-async/phpredis) (TrueAsync fork) (if using Redis transport)
