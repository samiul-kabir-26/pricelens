<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_endpoints', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('url', 2048);
            $table->text('secret');
            $table->json('events');
            $table->boolean('enabled')->default(true);
            $table->unsignedInteger('failure_count')->default(0);
            $table->timestamp('disabled_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'enabled']);
        });

        Schema::create('webhook_deliveries', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('endpoint_id')->constrained('webhook_endpoints')->cascadeOnDelete();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('event', 64);
            $table->string('event_id', 40);
            $table->json('payload');
            $table->string('status', 16)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->string('response_body_excerpt', 1024)->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();
            $table->index(['endpoint_id', 'created_at']);
            $table->index(['status', 'next_attempt_at']);
        });

        Schema::create('usage_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('metric', 32);
            $table->unsignedInteger('quantity')->default(1);
            $table->string('reference_type', 32)->nullable();
            $table->string('reference_id', 64)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['tenant_id', 'metric', 'created_at']);
        });

        Schema::create('usage_daily', function (Blueprint $table) {
            $table->unsignedBigInteger('tenant_id');
            $table->date('date');
            $table->string('metric', 32);
            $table->unsignedBigInteger('quantity')->default(0);
            $table->primary(['tenant_id', 'date', 'metric']);
        });

        Schema::create('fx_rates', function (Blueprint $table) {
            $table->date('date');
            $table->char('base', 3);
            $table->char('quote', 3);
            $table->decimal('rate', 18, 8);
            $table->primary(['date', 'base', 'quote']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fx_rates');
        Schema::dropIfExists('usage_daily');
        Schema::dropIfExists('usage_records');
        Schema::dropIfExists('webhook_deliveries');
        Schema::dropIfExists('webhook_endpoints');
    }
};
