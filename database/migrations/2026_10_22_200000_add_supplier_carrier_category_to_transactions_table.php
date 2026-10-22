<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            if (!Schema::hasColumn('transactions', 'supplier_id')) {
                $table->unsignedBigInteger('supplier_id')->nullable()->after('account_user_id');
                $table->index('supplier_id');
            }
            if (!Schema::hasColumn('transactions', 'carrier_id')) {
                $table->unsignedBigInteger('carrier_id')->nullable()->after('supplier_id');
                $table->index('carrier_id');
            }
            if (!Schema::hasColumn('transactions', 'category')) {
                $table->string('category', 50)->nullable()->after('direction');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            if (Schema::hasColumn('transactions', 'category')) {
                $table->dropColumn('category');
            }
            if (Schema::hasColumn('transactions', 'carrier_id')) {
                $table->dropIndex(['carrier_id']);
                $table->dropColumn('carrier_id');
            }
            if (Schema::hasColumn('transactions', 'supplier_id')) {
                $table->dropIndex(['supplier_id']);
                $table->dropColumn('supplier_id');
            }
        });
    }
};
