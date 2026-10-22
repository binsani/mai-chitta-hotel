<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$k = $app->make(Illuminate\Contracts\Console\Kernel::class);
$k->bootstrap();

use App\Models\MediaAsset;
use App\Models\RoomContent;
use App\Models\Setting;
use App\Models\Page;

echo "Updating MediaAssets to point to existing webp files...\n";
$assets = MediaAsset::all();
$updated = 0;
foreach ($assets as $a) {
    if (str_ends_with($a->path, '.jpg') || str_ends_with($a->path, '.png')) {
        $webpPath = preg_replace('/\.(jpe?g|png)$/i', '.webp', $a->path);
        if (file_exists(storage_path('app/public/' . $webpPath))) {
            $a->path = $webpPath;
            $a->filename = basename($webpPath);
            $a->mime_type = 'image/webp';
            $a->save();
            $updated++;
        }
    }
}
echo "Updated $updated media asset records to .webp.\n";

// Register all other webp hotel photos as MediaAssets if not registered
$files = glob(storage_path('app/public/hotel/*.webp'));
$added = 0;
foreach ($files as $f) {
    $rel = 'hotel/' . basename($f);
    $exists = MediaAsset::where('path', $rel)->exists();
    if (!$exists) {
        MediaAsset::create([
            'disk' => 'public',
            'path' => $rel,
            'filename' => basename($f),
            'original_name' => basename($f),
            'mime_type' => 'image/webp',
            'size_bytes' => filesize($f),
            'is_public' => true,
            'in_gallery' => true,
            'category' => 'general',
            'title' => ucwords(str_replace(['-', '_', '.webp'], ' ', basename($f))),
            'alt_text' => 'Mai Chitta Hotel, Zaria, Kaduna State',
        ]);
        $added++;
    }
}
echo "Registered $added additional hotel photos in MediaAsset table.\n";

// Ensure hero_media_id is set to the real night exterior webp
$hero = MediaAsset::where('path', 'hotel/mai-chitta-hotel-night-exterior.webp')->first();
if ($hero) {
    Setting::updateOrCreate(
        ['group' => 'website', 'key' => 'hero_media_id'],
        ['value' => (string) $hero->id, 'type' => 'integer', 'is_public' => true]
    );
    $home = Page::where('slug', 'home')->first();
    if ($home) {
        $home->featured_media_id = $hero->id;
        $home->save();
    }
    echo "Hero media configured to ID {$hero->id} ({$hero->path}).\n";
}

// Update RoomContent featured media to real hotel room/interior photos
$rooms = RoomContent::all();
$roomPhotos = [
    'hotel/PXL_20261019_193436703.webp', // luxury interior
    'hotel/PXL_20261019_193614111.webp', // deluxe suite
    'hotel/atrium-ceiling-light.webp',   // executive
    'hotel/corridor-art-gallery.webp',   // presidential / corridor
];

foreach ($rooms as $idx => $r) {
    $photoPath = $roomPhotos[$idx % count($roomPhotos)];
    $media = MediaAsset::where('path', $photoPath)->first();
    if ($media) {
        $r->featured_media_id = $media->id;
        $r->save();
        echo "Room '{$r->title}' mapped to media {$media->id} ({$media->path})\n";
    }
}

// Clear CMS cache
\Illuminate\Support\Facades\Cache::flush();
echo "Cache flushed successfully.\n";
