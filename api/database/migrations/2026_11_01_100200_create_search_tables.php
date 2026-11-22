<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('searches', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('monitor_id')->nullable()->index();
            $table->string('status', 24)->default('queued');
            $table->json('input');
            $table->json('product_query')->nullable();
            $table->json('options');
            $table->char('target_currency', 3);
            $table->char('country', 2);
            $table->unsignedInteger('progress_total')->default(0);
            $table->unsignedInteger('progress_done')->default(0);
            $table->json('stats')->nullable();
            $table->text('error')->nullable();
            $table->string('idempotency_key')->nullable();
            $table->string('batch_id', 36)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'idempotency_key']);
            $table->index(['tenant_id', 'status', 'created_at']);
        });

        Schema::create('search_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('search_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_id')->nullable()->constrained()->nullOnDelete();
            $table->string('key', 128); // stable key within the search: source:{id} | domain:{host} | engine:{provider}:{mode}
            $table->string('type', 24);
            $table->string('label');
            $table->string('domain')->nullable();
            $table->json('plan')->nullable();
            $table->string('status', 24)->default('pending');
            $table->unsignedInteger('candidates_found')->default(0);
            $table->unsignedInteger('offers_found')->default(0);
            $table->string('error')->nullable();
            $table->timestamps();
            $table->unique(['search_id', 'key']);
        });

        Schema::create('search_tasks', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('search_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('search_source_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('stage', 16); // discover|fetch|render|extract
            $table->text('url')->nullable();
            $table->char('url_hash', 40)->nullable();
            $table->string('status', 24)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->string('error_message', 512)->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamps();
            $table->index(['search_id', 'stage', 'status']);
            $table->index(['source_id', 'created_at']);
        });

        Schema::create('offers', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('search_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_id')->nullable()->constrained()->nullOnDelete();
            $table->string('site_domain');
            $table->string('site_name')->nullable();
            $table->text('url');
            $table->char('url_hash', 40);
            $table->string('title', 512)->nullable();
            $table->string('brand')->nullable();
            $table->string('gtin', 14)->nullable();
            $table->string('mpn')->nullable();
            $table->string('sku')->nullable();
            $table->text('image_url')->nullable();
            $table->string('image_phash', 32)->nullable();
            $table->bigInteger('price_minor');
            $table->bigInteger('list_price_minor')->nullable();
            $table->bigInteger('shipping_minor')->nullable();
            $table->char('currency', 3);
            $table->bigInteger('converted_price_minor')->nullable();
            $table->bigInteger('converted_total_minor')->nullable();
            $table->date('fx_rate_date')->nullable();
            $table->string('availability', 16)->default('unknown');
            $table->string('condition', 16)->default('unknown');
            $table->string('seller_name')->nullable();
            $table->decimal('rating', 3, 2)->nullable();
            $table->unsignedInteger('review_count')->nullable();
            $table->json('variant')->nullable();
            $table->decimal('match_confidence', 4, 3);
            $table->string('match_band', 16);
            $table->string('match_type', 16);
            $table->json('match_breakdown')->nullable();
            $table->string('extraction_method', 24);
            $table->boolean('price_outlier')->default(false);
            $table->boolean('is_duplicate')->default(false);
            $table->string('snapshot_path')->nullable();
            $table->timestamp('observed_at');
            $table->timestamps();
            $table->unique(['search_id', 'url_hash']);
            $table->index(['tenant_id', 'search_id', 'match_band', 'converted_total_minor'], 'offers_tenant_search_band_total_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offers');
        Schema::dropIfExists('search_tasks');
        Schema::dropIfExists('search_sources');
        Schema::dropIfExists('searches');
    }
};
