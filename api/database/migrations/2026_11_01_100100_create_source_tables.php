<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sources', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->boolean('is_global')->default(false);
            $table->string('type', 24); // site|search_engine|shopping_engine|reverse_image|feed
            $table->string('name');
            $table->string('domain')->nullable();
            $table->char('country', 2)->nullable();
            $table->char('currency', 3)->nullable();
            $table->json('config');
            $table->enum('render', ['never', 'auto', 'always'])->default('auto');
            $table->boolean('enabled')->default(true);
            $table->string('health_status', 16)->default('healthy');
            $table->foreignId('forked_from_id')->nullable()->constrained('sources')->nullOnDelete();
            $table->timestamp('feed_refreshed_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'type', 'enabled']);
            $table->index('domain');
        });

        // Tenant opt-in to platform (global) adapters.
        Schema::create('tenant_global_sources', function (Blueprint $table) {
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_id')->constrained()->cascadeOnDelete();
            $table->boolean('enabled')->default(true);
            $table->timestamps();
            $table->primary(['tenant_id', 'source_id']);
        });

        Schema::create('source_health_stats', function (Blueprint $table) {
            $table->foreignId('source_id')->constrained()->cascadeOnDelete();
            $table->timestamp('window_start');
            $table->unsignedInteger('fetch_ok')->default(0);
            $table->unsignedInteger('fetch_fail')->default(0);
            $table->unsignedInteger('extract_ok')->default(0);
            $table->unsignedInteger('extract_fail')->default(0);
            $table->unsignedInteger('blocked')->default(0);
            $table->unsignedInteger('p50_ms')->nullable();
            $table->primary(['source_id', 'window_start']);
        });

        Schema::create('domain_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('domain');
            $table->string('policy', 16); // allow|block|throttle
            $table->json('rate_limit')->nullable();
            $table->string('reason')->nullable();
            $table->timestamps();
            $table->index(['domain', 'tenant_id']);
        });

        Schema::create('provider_credentials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 32);
            $table->text('credentials');
            $table->timestamps();
            $table->unique(['tenant_id', 'provider']);
        });

        Schema::create('proxy_pools', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('proxies');
            $table->char('country', 2)->nullable();
            $table->string('type', 16)->default('datacenter');
            $table->timestamps();
            $table->index('tenant_id');
        });

        // Indexed merchant-feed rows (Google Merchant format) for `feed` sources.
        Schema::create('feed_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_id')->constrained()->cascadeOnDelete();
            $table->string('item_id');
            $table->string('title', 512);
            $table->string('brand')->nullable();
            $table->string('gtin', 14)->nullable();
            $table->string('mpn')->nullable();
            $table->text('link');
            $table->text('image_link')->nullable();
            $table->string('price')->nullable();
            $table->string('sale_price')->nullable();
            $table->string('availability')->nullable();
            $table->string('condition')->nullable();
            $table->string('shipping')->nullable();
            $table->timestamps();
            $table->unique(['source_id', 'item_id']);
            $table->index(['tenant_id', 'gtin']);
            $table->index(['tenant_id', 'mpn']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feed_items');
        Schema::dropIfExists('proxy_pools');
        Schema::dropIfExists('provider_credentials');
        Schema::dropIfExists('domain_policies');
        Schema::dropIfExists('source_health_stats');
        Schema::dropIfExists('tenant_global_sources');
        Schema::dropIfExists('sources');
    }
};
