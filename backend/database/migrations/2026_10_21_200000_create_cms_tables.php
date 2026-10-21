<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 CMS & Public Website schema:
 * media_assets, pages, page_sections, page_revisions,
 * navigation_menus, navigation_items, room_contents,
 * offers, testimonials, contact_inquiries.
 *
 * Additive only; preserves all Phase 1 tables and data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_assets', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('disk', 32)->default('public'); // 'public' or 'local' (private)
            $table->string('path', 255);
            $table->string('original_name', 255);
            $table->string('filename', 255);
            $table->string('mime_type', 120);
            $table->string('extension', 24);
            $table->unsignedInteger('size_bytes');
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->string('alt_text', 255);
            $table->string('title', 160)->nullable();
            $table->string('caption', 500)->nullable();
            $table->string('category', 64)->default('general')->index();
            $table->boolean('is_public')->default(true)->index();
            $table->boolean('in_gallery')->default(false)->index();
            $table->integer('sort_order')->default(0);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('pages', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 120)->unique();
            $table->string('title', 180);
            $table->string('subtitle', 255)->nullable();
            $table->text('excerpt')->nullable();
            $table->longText('body')->nullable();
            $table->string('template', 64)->default('standard')->index();
            $table->string('status', 24)->default('draft')->index(); // draft | published | archived
            $table->boolean('is_system')->default(false);
            $table->foreignId('featured_media_id')->nullable()->constrained('media_assets')->nullOnDelete();
            $table->string('seo_title', 160)->nullable();
            $table->string('seo_description', 320)->nullable();
            $table->string('canonical_url', 255)->nullable();
            $table->foreignId('og_image_id')->nullable()->constrained('media_assets')->nullOnDelete();
            $table->boolean('noindex')->default(false);
            $table->timestamp('published_at')->nullable()->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('page_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('page_id')->constrained('pages')->cascadeOnDelete();
            $table->string('type', 64); // hero | rich_text | feature_grid | image_text | cta_banner | faq | notice | contact_block
            $table->string('title', 180)->nullable();
            $table->string('subtitle', 255)->nullable();
            $table->text('body')->nullable();
            $table->foreignId('media_id')->nullable()->constrained('media_assets')->nullOnDelete();
            $table->string('cta_label', 120)->nullable();
            $table->string('cta_url', 255)->nullable();
            $table->json('data')->nullable();
            $table->boolean('is_visible')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            $table->index(['page_id', 'sort_order']);
        });

        Schema::create('page_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('page_id')->constrained('pages')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('note', 255)->nullable();
            $table->json('snapshot');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['page_id', 'version']);
        });

        Schema::create('navigation_menus', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 64)->unique(); // header | footer
            $table->string('name', 120);
            $table->string('description', 255)->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('navigation_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('menu_id')->constrained('navigation_menus')->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('navigation_items')->cascadeOnDelete();
            $table->string('label', 120);
            $table->string('url', 255);
            $table->foreignId('page_id')->nullable()->constrained('pages')->nullOnDelete();
            $table->string('target', 16)->default('_self');
            $table->boolean('is_visible')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            $table->index(['menu_id', 'sort_order']);
        });

        Schema::create('room_contents', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 120)->unique();
            $table->string('title', 180);
            $table->string('subtitle', 255)->nullable();
            $table->text('summary')->nullable();
            $table->longText('description')->nullable();
            $table->string('occupancy_label', 120)->nullable();
            $table->string('bed_configuration', 120)->nullable();
            $table->decimal('indicative_rate', 12, 2)->nullable();
            $table->json('amenities')->nullable();
            $table->foreignId('featured_media_id')->nullable()->constrained('media_assets')->nullOnDelete();
            $table->string('status', 24)->default('draft')->index(); // draft | published | archived
            $table->boolean('is_featured')->default(false)->index();
            $table->integer('sort_order')->default(0);
            $table->string('seo_title', 160)->nullable();
            $table->string('seo_description', 320)->nullable();
            $table->timestamp('published_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('offers', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 120)->unique();
            $table->string('title', 180);
            $table->string('subtitle', 255)->nullable();
            $table->text('summary')->nullable();
            $table->longText('body')->nullable();
            $table->string('badge_text', 64)->nullable();
            $table->text('terms_text')->nullable();
            $table->string('cta_label', 120)->nullable();
            $table->string('cta_url', 255)->nullable();
            $table->foreignId('featured_media_id')->nullable()->constrained('media_assets')->nullOnDelete();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->string('status', 24)->default('draft')->index(); // draft | published | archived
            $table->boolean('is_featured')->default(false)->index();
            $table->integer('sort_order')->default(0);
            $table->string('seo_title', 160)->nullable();
            $table->string('seo_description', 320)->nullable();
            $table->timestamp('published_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('testimonials', function (Blueprint $table) {
            $table->id();
            $table->string('guest_name', 120);
            $table->string('guest_title', 160)->nullable();
            $table->text('content');
            $table->unsignedTinyInteger('rating')->nullable();
            $table->string('source', 120)->nullable();
            $table->string('status', 24)->default('draft')->index(); // draft | published | archived
            $table->boolean('is_featured')->default(false)->index();
            $table->integer('sort_order')->default(0);
            $table->timestamp('published_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('contact_inquiries', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('email', 255);
            $table->string('phone', 32)->nullable();
            $table->string('subject', 180);
            $table->text('message');
            $table->string('ip_address', 45)->nullable();
            $table->string('status', 24)->default('new')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_inquiries');
        Schema::dropIfExists('testimonials');
        Schema::dropIfExists('offers');
        Schema::dropIfExists('room_contents');
        Schema::dropIfExists('navigation_items');
        Schema::dropIfExists('navigation_menus');
        Schema::dropIfExists('page_revisions');
        Schema::dropIfExists('page_sections');
        Schema::dropIfExists('pages');
        Schema::dropIfExists('media_assets');
    }
};
