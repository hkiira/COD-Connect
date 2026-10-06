<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Covers the order-line aggregations of the catalog dashboard (delivery rates per product / size).
        if (! Schema::hasIndex('order_pva', 'order_pva_order_pva_qty_idx')) {
            Schema::table('order_pva', function (Blueprint $table) {
                $table->index(['order_id', 'product_variation_attribute_id', 'quantity'], 'order_pva_order_pva_qty_idx');
            });
        }

        if (! Schema::hasIndex('attributes', 'attributes_types_attribute_id_idx')) {
            Schema::table('attributes', function (Blueprint $table) {
                $table->index('types_attribute_id', 'attributes_types_attribute_id_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::table('order_pva', fn (Blueprint $t) => $t->dropIndex('order_pva_order_pva_qty_idx'));
        Schema::table('attributes', fn (Blueprint $t) => $t->dropIndex('attributes_types_attribute_id_idx'));
    }
};
