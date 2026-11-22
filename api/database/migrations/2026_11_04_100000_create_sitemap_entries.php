<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Local index of product URLs harvested from a site's sitemaps (robots-compliant discovery for shops whose
 * on-site search is disallowed). Titles are derived from the URL slug.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sitemap_entries')) {
            Schema::create('sitemap_entries', function (Blueprint $table) {
                $table->id();
                $table->foreignId('source_id')->constrained()->cascadeOnDelete();
                $table->char('url_hash', 40);
                $table->text('url');
                $table->string('title', 512);
                $table->timestamp('lastmod')->nullable();
                $table->timestamp('seen_at');
                $table->unique(['source_id', 'url_hash']);
                $table->index(['source_id', 'seen_at']);
            });
        }

        if (DB::getDriverName() === 'mysql' && ! Schema::hasIndex('sitemap_entries', 'sitemap_entries_title_fulltext')) {
            Schema::table('sitemap_entries', function (Blueprint $table) {
                $table->fullText('title', 'sitemap_entries_title_fulltext');
            });
        }

        if (! Schema::hasColumn('sources', 'sitemap_refreshed_at')) {
            Schema::table('sources', function (Blueprint $table) {
                $table->timestamp('sitemap_refreshed_at')->nullable()->after('feed_refreshed_at');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sitemap_entries');
        if (Schema::hasColumn('sources', 'sitemap_refreshed_at')) {
            Schema::table('sources', function (Blueprint $table) {
                $table->dropColumn('sitemap_refreshed_at');
            });
        }
    }
};
