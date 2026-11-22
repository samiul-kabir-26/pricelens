<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-search `options.callback_url` deliveries reuse the webhook delivery log (endpoint_id NULL + target_url),
 * signed with a tenant-level callback secret.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Guarded steps: MySQL DDL isn't transactional, so a partially applied run must be resumable.
        if (! Schema::hasColumn('tenants', 'callback_secret')) {
            Schema::table('tenants', function (Blueprint $table) {
                $table->text('callback_secret')->nullable()->after('settings');
            });
        }

        if (! Schema::hasColumn('webhook_deliveries', 'target_url')) {
            Schema::table('webhook_deliveries', function (Blueprint $table) {
                $table->dropForeign(['endpoint_id']);
            });
            Schema::table('webhook_deliveries', function (Blueprint $table) {
                $table->unsignedBigInteger('endpoint_id')->nullable()->change();
                $table->foreign('endpoint_id')->references('id')->on('webhook_endpoints')->cascadeOnDelete();
                $table->string('target_url', 2048)->nullable()->after('endpoint_id');
            });
        }

        if (! Schema::hasIndex('webhook_deliveries', 'webhook_deliveries_callbacks_idx')) {
            Schema::table('webhook_deliveries', function (Blueprint $table) {
                // Callback deliveries are listed per tenant with endpoint_id NULL (target_url is too long to index).
                $table->index(['tenant_id', 'endpoint_id'], 'webhook_deliveries_callbacks_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::table('webhook_deliveries', function (Blueprint $table) {
            $table->dropIndex('webhook_deliveries_callbacks_idx');
            $table->dropColumn('target_url');
        });
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('callback_secret');
        });
    }
};
