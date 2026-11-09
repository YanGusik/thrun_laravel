<?php
/** Dispatches jobs through Laravel's own dispatcher, exactly as an app would. */
require __DIR__ . '/vendor/autoload.php';

$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

@unlink($app->storagePath('e2e/executions.log'));
$app->make('redis')->connection()->flushdb();

$count = (int) ($argv[1] ?? 20);

for ($i = 1; $i <= $count; $i++) {
    App\Jobs\RecordJob::dispatch($i, $i === 1 ? 300 : 0);
}

echo "dispatched {$count}\n";
