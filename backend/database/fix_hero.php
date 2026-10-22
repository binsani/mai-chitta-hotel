<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$k = $app->make(Illuminate\Contracts\Console\Kernel::class);
$k->bootstrap();

use App\Models\MediaAsset;
use App\Models\Setting;
use App\Models\Page;

$hero = MediaAsset::where('path', 'like', '%night-exterior%')->first();
if ($hero) {
    echo "Hero asset ID: {$hero->id} ({$hero->path})\n";
    Setting::updateOrCreate(
        ['group' => 'website', 'key' => 'hero_media_id'],
        ['value' => (string) $hero->id, 'type' => 'integer', 'is_public' => true]
    );
    
    $home = Page::where('slug', 'home')->first();
    if ($home) {
        $home->featured_media_id = $hero->id;
        $home->save();
        echo "Home page featured_media_id updated to {$hero->id}\n";
    }
} else {
    echo "Night exterior asset not found in DB\n";
}
