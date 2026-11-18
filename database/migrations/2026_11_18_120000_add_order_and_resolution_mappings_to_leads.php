<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->foreignId('order_id')->nullable()->after('imported_file')->unique()->constrained('orders')->nullOnDelete();
        });

        Schema::table('lead_import_mappings', function (Blueprint $table) {
            $table->json('product_mapping')->nullable()->after('mapping');
            $table->json('variant_mapping')->nullable()->after('product_mapping');
            $table->json('city_mapping')->nullable()->after('variant_mapping');
        });
    }

    public function down(): void
    {
        Schema::table('lead_import_mappings', function (Blueprint $table) {
            $table->dropColumn(['product_mapping', 'variant_mapping', 'city_mapping']);
        });

        Schema::table('leads', function (Blueprint $table) {
            $table->dropForeign(['order_id']);
            $table->dropUnique(['order_id']);
            $table->dropColumn('order_id');
        });
    }
};
