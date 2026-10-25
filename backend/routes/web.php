<?php

use App\Http\Controllers\SitemapController;
use App\Http\Controllers\SpaController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web & SPA Routes
|--------------------------------------------------------------------------
| Both the public Mai Chitta Hotel website (/, /about, /rooms, /offers, etc.)
| and the staff administration portal (/admin/*) are served by the React SPA
| with server-rendered SEO metadata injected by SpaController.
*/

Route::get('/sitemap.xml', SitemapController::class)->name('sitemap');

Route::get('/{any?}', SpaController::class)
    ->where('any', '^(?!api|sanctum|storage|up).*$');
