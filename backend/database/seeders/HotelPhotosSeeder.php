<?php

namespace Database\Seeders;

use App\Models\MediaAsset;
use Illuminate\Database\Seeder;

class HotelPhotosSeeder extends Seeder
{
    public function run(): void
    {
        $photos = [
            [
                'path' => 'hotel/lobby-cultural-mural.jpg',
                'title' => 'Cultural Heritage Mural',
                'alt' => 'Staircase wall mural honoring northern Nigerian culture',
                'cat' => 'general',
            ],
            [
                'path' => 'hotel/lobby-monstera-planters.jpg',
                'title' => 'Lobby & Indoor Planters',
                'alt' => 'Polished marble lobby with monstera planters and reception hallway',
                'cat' => 'general',
            ],
            [
                'path' => 'hotel/reception-hallway-planters.jpg',
                'title' => 'Reception Hallway',
                'alt' => 'Hallway with dark wood paneling, marble flooring and planters',
                'cat' => 'general',
            ],
            [
                'path' => 'hotel/atrium-ceiling-light.jpg',
                'title' => 'Atrium & Halo Chandelier',
                'alt' => 'Atrium ceiling with circular pendant chandelier and modern balustrades',
                'cat' => 'exterior',
            ],
            [
                'path' => 'hotel/corridor-art-gallery.jpg',
                'title' => 'Corridor Art Gallery',
                'alt' => 'Upper corridor with wall art framing and warm ambient lighting',
                'cat' => 'general',
            ],
        ];

        foreach ($photos as $idx => $p) {
            $fullPath = storage_path('app/public/' . $p['path']);
            $size = file_exists($fullPath) ? filesize($fullPath) : 500000;

            MediaAsset::updateOrCreate(
                ['path' => $p['path'], 'disk' => 'public'],
                [
                    'original_name' => basename($p['path']),
                    'filename' => basename($p['path']),
                    'mime_type' => 'image/jpeg',
                    'extension' => 'jpg',
                    'size_bytes' => $size,
                    'width' => 1200,
                    'height' => 1600,
                    'alt_text' => $p['alt'],
                    'title' => $p['title'],
                    'caption' => $p['title'] . ' — Mai Chitta Hotel, Zaria',
                    'category' => $p['cat'],
                    'is_public' => true,
                    'in_gallery' => true,
                    'sort_order' => $idx + 1,
                ]
            );
        }
    }
}
