<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Simple key-value storage
        Schema::create('queue_metrics_keys', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->text('value');
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamp('updated_at');
        });

        // Hash storage (JSON-backed)
        Schema::create('queue_metrics_hashes', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->json('data');
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamp('updated_at');
        });

        // Set storage
        Schema::create('queue_metrics_sets', function (Blueprint $table) {
            $table->string('key')->index();
            $table->string('member');
            $table->timestamp('created_at');

            $table->unique(['key', 'member']);
        });

        // Sorted set storage
        Schema::create('queue_metrics_sorted_sets', function (Blueprint $table) {
            $table->string('key')->index();
            $table->string('member');
            $table->decimal('score', 20, 4)->index();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamp('updated_at');

            $table->unique(['key', 'member']);
            $table->index(['key', 'score']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('queue_metrics_sorted_sets');
        Schema::dropIfExists('queue_metrics_sets');
        Schema::dropIfExists('queue_metrics_hashes');
        Schema::dropIfExists('queue_metrics_keys');
    }
};
