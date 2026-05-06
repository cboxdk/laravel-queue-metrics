<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('queue_metrics_sets', 'expires_at')) {
            return;
        }

        Schema::table('queue_metrics_sets', function (Blueprint $table) {
            $table->timestamp('expires_at')->nullable()->index()->after('created_at');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('queue_metrics_sets', 'expires_at')) {
            return;
        }

        Schema::table('queue_metrics_sets', function (Blueprint $table) {
            $table->dropIndex(['expires_at']);
            $table->dropColumn('expires_at');
        });
    }
};
