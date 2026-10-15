<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Organic publishing formats (owner, 2026-10-15): besides a normal post,
     * a Reel (Facebook / Instagram), a Story (Facebook / Instagram) and a
     * carousel (2–10 items: Facebook album of images, Instagram images and
     * videos). `media_items` holds a carousel's items [{url, type}];
     * media_url / media_type stay the single item of every other format.
     * Existing rows are normal posts.
     */
    public function up(): void
    {
        Schema::table('organic_posts', function (Blueprint $table) {
            $table->string('post_format', 16)->default('post')->after('media_type');
            $table->json('media_items')->nullable()->after('post_format');
        });
    }

    public function down(): void
    {
        Schema::table('organic_posts', function (Blueprint $table) {
            $table->dropColumn(['post_format', 'media_items']);
        });
    }
};
