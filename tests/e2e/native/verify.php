<?php
/** Checks the two things that matter: every job ran, and each ran exactly once. */
require __DIR__ . '/vendor/autoload.php';

$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$expected = (int) ($argv[1] ?? 20);
$lines = array_filter(explode("\n", (string) @file_get_contents($app->storagePath('e2e/executions.log'))), 'strlen');
$counts = array_count_values($lines);

$redis = $app->make('redis')->connection();
$reserved = $redis->zcard('queues:default:reserved');
$ready = $redis->llen('queues:default');
$delayed = $redis->zcard('queues:default:delayed');

$duplicates = array_filter($counts, fn($n) => $n > 1);
$missing = array_values(array_diff(range(1, $expected), array_map('intval', array_keys($counts))));

printf("executed=%d unique=%d duplicates=%d missing=%d reserved=%d ready=%d delayed=%d\n",
    count($lines), count($counts), count($duplicates), count($missing), $reserved, $ready, $delayed);

$ok = count($lines) === $expected && $duplicates === [] && $missing === []
    && $reserved === 0 && $ready === 0 && $delayed === 0;

echo $ok ? "E2E OK\n" : "E2E FAILED\n";
exit($ok ? 0 : 1);
