<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tracked_products', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('external_ref')->nullable();
            $table->string('title', 512);
            $table->string('brand')->nullable();
            $table->string('gtin', 14)->nullable();
            $table->string('mpn')->nullable();
            $table->text('image_url')->nullable();
            $table->string('image_path')->nullable();
            $table->string('image_phash', 32)->nullable();
            $table->bigInteger('reference_price_minor')->nullable();
            $table->bigInteger('map_price_minor')->nullable();
            $table->char('currency', 3);
            $table->json('attributes')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'external_ref']);
        });

        Schema::create('monitors', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tracked_product_id')->constrained()->cascadeOnDelete();
            $table->string('schedule', 64);
            $table->string('timezone', 64)->default('UTC');
            $table->json('source_ids')->nullable();
            $table->json('options')->nullable();
            $table->json('alert_rules')->nullable();
            $table->string('status', 16)->default('active');
            $table->timestamp('next_run_at')->nullable();
            $table->timestamp('last_run_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'next_run_at']);
            $table->index(['tenant_id', 'tracked_product_id']);
        });

        if (DB::getDriverName() === 'mysql') {
            // Range-partitioned by month on observed_at; MySQL requires the partition key in every unique key.
            DB::statement(<<<'SQL'
                CREATE TABLE price_snapshots (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    tenant_id BIGINT UNSIGNED NOT NULL,
                    tracked_product_id BIGINT UNSIGNED NOT NULL,
                    site_domain VARCHAR(255) NOT NULL,
                    url_hash CHAR(40) NOT NULL,
                    url TEXT NULL,
                    seller_name VARCHAR(255) NULL,
                    price_minor BIGINT NOT NULL,
                    total_minor BIGINT NULL,
                    currency CHAR(3) NOT NULL,
                    converted_minor BIGINT NULL,
                    availability VARCHAR(16) NOT NULL DEFAULT 'unknown',
                    search_id BIGINT UNSIGNED NULL,
                    observed_at TIMESTAMP NOT NULL,
                    PRIMARY KEY (id, observed_at),
                    KEY price_snapshots_lookup (tenant_id, tracked_product_id, site_domain, observed_at)
                )
                PARTITION BY RANGE (UNIX_TIMESTAMP(observed_at)) (
                    PARTITION p_start VALUES LESS THAN (UNIX_TIMESTAMP('2026-01-01 00:00:00')),
                    PARTITION p_future VALUES LESS THAN MAXVALUE
                )
            SQL);
        } else {
            Schema::create('price_snapshots', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('tracked_product_id');
                $table->string('site_domain');
                $table->char('url_hash', 40);
                $table->text('url')->nullable();
                $table->string('seller_name')->nullable();
                $table->bigInteger('price_minor');
                $table->bigInteger('total_minor')->nullable();
                $table->char('currency', 3);
                $table->bigInteger('converted_minor')->nullable();
                $table->string('availability', 16)->default('unknown');
                $table->unsignedBigInteger('search_id')->nullable();
                $table->timestamp('observed_at');
                $table->index(['tenant_id', 'tracked_product_id', 'site_domain', 'observed_at'], 'price_snapshots_lookup');
            });
        }

        // Nightly rollup used for long price-history ranges.
        Schema::create('price_daily', function (Blueprint $table) {
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('tracked_product_id');
            $table->string('site_domain');
            $table->date('date');
            $table->bigInteger('min_minor');
            $table->bigInteger('avg_minor');
            $table->bigInteger('max_minor');
            $table->char('currency', 3);
            $table->unsignedInteger('samples');
            $table->primary(['tenant_id', 'tracked_product_id', 'site_domain', 'date'], 'price_daily_pk');
        });

        Schema::create('alerts', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('monitor_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tracked_product_id')->constrained()->cascadeOnDelete();
            $table->string('type', 32);
            $table->string('site_domain')->nullable();
            $table->json('payload');
            $table->timestamp('triggered_at');
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'triggered_at']);
            $table->index(['monitor_id', 'type', 'site_domain', 'triggered_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alerts');
        Schema::dropIfExists('price_daily');
        Schema::dropIfExists('price_snapshots');
        Schema::dropIfExists('monitors');
        Schema::dropIfExists('tracked_products');
    }
};
