<?php
$dir = __DIR__ . '/../../backend/storage/app/public/hotel';
$files = glob("$dir/*.{jpg,jpeg,JPG,png,PNG}", GLOB_BRACE);
$totalBefore = 0;
$totalAfter = 0;
$count = 0;

echo "Converting " . count($files) . " hotel photos to WebP...\n";

foreach ($files as $file) {
    $info = @getimagesize($file);
    if (!$info) continue;
    $totalBefore += filesize($file);
    
    $mime = $info['mime'];
    if ($mime === 'image/jpeg') {
        $img = @imagecreatefromjpeg($file);
    } elseif ($mime === 'image/png') {
        $img = @imagecreatefrompng($file);
    } else {
        continue;
    }
    
    if (!$img) continue;

    // Handle orientation if jpeg
    if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
        $exif = @exif_read_data($file);
        $orientation = $exif['Orientation'] ?? 1;
        if ($orientation == 3) $img = imagerotate($img, 180, 0);
        elseif ($orientation == 6) $img = imagerotate($img, -90, 0);
        elseif ($orientation == 8) $img = imagerotate($img, 90, 0);
    }

    $webpFile = preg_replace('/\.(jpe?g|png)$/i', '.webp', $file);
    
    // Max dimension 1400px for ultra fast web loading
    $w = imagesx($img);
    $h = imagesy($img);
    $maxDim = 1400;
    if (max($w, $h) > $maxDim) {
        $scale = $maxDim / max($w, $h);
        $newW = (int)round($w * $scale);
        $newH = (int)round($h * $scale);
        $resized = imagecreatetruecolor($newW, $newH);
        imagecopyresampled($resized, $img, 0, 0, 0, 0, $newW, $newH, $w, $h);
        imagedestroy($img);
        $img = $resized;
    }

    // Convert to webp with 75% quality (optimal web standard)
    imagewebp($img, $webpFile, 75);
    imagedestroy($img);

    clearstatcache();
    $totalAfter += filesize($webpFile);
    $count++;
    
    // Remove original jpg/png if name changed
    if ($file !== $webpFile && file_exists($webpFile)) {
        unlink($file);
    }
}

// Convert public logo and favicon to webp/optimize as well
$logo = __DIR__ . '/../../backend/public/logo.jpg';
if (file_exists($logo)) {
    $img = imagecreatefromjpeg($logo);
    if ($img) {
        imagewebp($img, __DIR__ . '/../../backend/public/logo.webp', 80);
        imagedestroy($img);
    }
}

printf("Converted %d images. Total size: %.2f MB -> %.2f MB\n", $count, $totalBefore/1048576, $totalAfter/1048576);
