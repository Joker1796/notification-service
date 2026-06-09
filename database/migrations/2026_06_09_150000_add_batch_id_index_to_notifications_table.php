<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            // PostgreSQL does not auto-create indexes for foreign keys (unlike MySQL).
            // Without this index, queries like $batch->notifications() and the recovery
            // command's cursor scan perform a full table scan on large datasets.
            $table->index('batch_id');
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropIndex(['batch_id']);
        });
    }
};
