<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('orders', 'boolean') && !Schema::hasColumn('orders', 'sync')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->renameColumn('boolean', 'sync');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('orders', 'sync') && !Schema::hasColumn('orders', 'boolean')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->renameColumn('sync', 'boolean');
            });
        }
    }
};
