<?php

namespace App\Console\Commands;

use App\Models\MediaAsset;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ImportHotelMediaCommand extends Command
{
    protected $signature = 'hotel:import-media 
                            {source_folder? : Directory path containing images} 
                            {--category=general : Default category (rooms, dining, exterior, general, amenities)} 
                            {--gallery : Whether to include images in public photo gallery}';

    protected $description = 'Bulk import hotel photographs into the Media Library and public storage';

    public function handle(): int
    {
        $folderPath = $this->argument('source_folder') ?? base_path('../bulk_uploads');

        if (! File::exists($folderPath)) {
            $this->error("Directory not found: {$folderPath}");
            return 1;
        }

        $files = File::files($folderPath);
        $validExtensions = ['jpg', 'jpeg', 'png', 'webp', 'svg'];
        $importedCount = 0;

        $category = $this->option('category') ?: 'general';
        $inGallery = (bool) $this->option('gallery') || true;

        $this->info("Scanning [{$folderPath}] for hotel images...");

        foreach ($files as $file) {
            $ext = strtolower($file->getExtension());
            if (! in_array($ext, $validExtensions, true)) {
                continue;
            }

            $originalName = $file->getFilename();
            $slug = Str::slug(pathinfo($originalName, PATHINFO_FILENAME));
            $destinationFilename = "{$slug}.{$ext}";
            $storageRelativePath = "hotel/{$destinationFilename}";

            // Copy to storage disk
            Storage::disk('public')->put($storageRelativePath, File::get($file->getRealPath()));

            // Extract image dimensions if supported
            $dimensions = @getimagesize($file->getRealPath());
            $width = $dimensions ? $dimensions[0] : null;
            $height = $dimensions ? $dimensions[1] : null;

            $title = ucwords(str_replace(['-', '_'], ' ', pathinfo($originalName, PATHINFO_FILENAME)));
            $altText = "{$title} — Mai Chitta Hotel, Zaria";

            $mimeType = File::mimeType($file->getRealPath()) ?: ($ext === 'svg' ? 'image/svg+xml' : "image/{$ext}");

            MediaAsset::updateOrCreate(
                ['path' => $storageRelativePath, 'disk' => 'public'],
                [
                    'original_name' => $originalName,
                    'filename' => $destinationFilename,
                    'mime_type' => $mimeType,
                    'extension' => $ext,
                    'size_bytes' => $file->getSize(),
                    'width' => $width,
                    'height' => $height,
                    'alt_text' => $altText,
                    'title' => $title,
                    'caption' => "{$title} at Mai Chitta Hotel, Zaria, Kaduna State",
                    'category' => $category,
                    'is_public' => true,
                    'in_gallery' => $inGallery,
                ]
            );

            $this->line("  ✓ Imported: <info>{$originalName}</info> → storage/app/public/{$storageRelativePath}");
            $importedCount++;
        }

        $this->info("Bulk import completed! Total images imported: {$importedCount}");
        return 0;
    }
}
