# Thrun Laravel

Laravel adapter for the async queue worker [**Thrun**](https://github.com/yangusik/thrun). Built on real OS threads (TrueAsync) and provides two workflow styles: **clean architecture** (recommended) and **self-handling jobs** (for simple tasks).

---

## Benchmarks

Measured on WSL2, 8GB RAM, PHP 8.6 TrueAsync fork:

| Scenario | IO throughput | CPU throughput | Stable RSS |
|---|---|---|---|
| Horizon 1 worker | 18/s | 73/s | 72 MB |
| Horizon 12 workers | 210/s | 514/s | 949 MB |
| TrueAsync 1x100 | 1,869/s | 452/s | 44 MB |
| TrueAsync 12x10 | 2,355/s | 2,059/s | 54 MB |

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
    'notifications' => ['transport' => 'redis'],
],
```

### Supervisors

Each supervisor is an isolated worker group with its own queues, strategy, and policies.

```php
'supervisors' => [
    'default' => [
        'queues'    => ['emails', 'notifications'],
        'worker'    => ['threads' => 2, 'concurrency' => 100],
        'supervisor'=> ['max_crashes' => 3, 'restart_window' => 300, 'restart_backoff' => 1.0],
        'strategy'  => ['class' => PriorityStrategy::class, 'priorities' => ['emails' => 3, 'notifications' => 1]],
        'policy'    => ['enabled' => false, 'class' => MaxConcurrencyPolicy::class, 'options' => ['max_per_partition' => 5]],
        'handlers'  => [],              // manual routing
    ],
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
],
```

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

| Attribute | Purpose | Applies to |
|---|---|---|
| `#[ThrunJob]` | Self-handling job marker | Job |
| `#[Queue('emails')]` | Default queue for the message | Job / Message |
| `#[Retry(backoff: [...], maxAttempts: 3)]` | Retry policy | Job / Message |
| `#[Delay(5000)]` | Delay in ms | Job / Message |
| `#[Timeout(30000)]` | Hard execution timeout | Job / Message |
| `#[AsThrunHandler(messageClass: ...)]` | Explicit Handler → Message binding | Handler |

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

## Auto-discover

Class scanning happens automatically for namespaces listed in `auto_discover`.

### Handlers
- `#[AsThrunHandler]` — explicit binding to `messageClass`
- Naming convention: `SendEmailHandler` → `SendEmailMessage`

### Self-handling Jobs
- `#[ThrunJob]` on an invokable class — the class registers as its own handler

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
# All supervisors
php artisan thrun:work

# Single supervisor
php artisan thrun:work --supervisor=default

# With stats
php artisan thrun:work --stats
```

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

- PHP ^8.4
- Laravel ^11.0
- [TrueAsync](https://github.com/yangusik/true-async) (PHP extension)
- Redis (if using Redis transport)
