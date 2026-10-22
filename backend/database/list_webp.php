<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$k = $app->make(Illuminate\Contracts\Console\Kernel::class);
$k->bootstrap();

$files = glob(storage_path('app/public/hotel/*.webp'));
echo "Total hotel webp files on disk: " . count($files) . "\n";

foreach ($files as $f) {
    echo basename($f) . " (" . round(filesize($f)/1024) . " KB)\n";
}
