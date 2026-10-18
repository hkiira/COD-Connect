<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_order_pva', function (Blueprint $table) {
            if (!Schema::hasColumn('supplier_order_pva', 'received_quantity')) {
                $table->integer('received_quantity')->default(0)->after('quantity');
            }
            if (!Schema::hasColumn('supplier_order_pva', 'cancelled_quantity')) {
                $table->integer('cancelled_quantity')->default(0)->after('received_quantity');
            }
            if (!Schema::hasColumn('supplier_order_pva', 'hors_commande_quantity')) {
                $table->integer('hors_commande_quantity')->default(0)->after('cancelled_quantity');
            }
        });

        Schema::dropIfExists('supplier_order_receipt_allocations');

        Schema::create('supplier_order_receipt_allocations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('supplier_order_id')->index('sora_order_idx');
            $table->unsignedBigInteger('supplier_order_pva_id')->index('sora_order_pva_idx');
            $table->unsignedBigInteger('supplier_receipt_id')->index('sora_receipt_idx');
            $table->unsignedBigInteger('supplier_receipt_pva_id')->index('sora_receipt_pva_idx');
            $table->unsignedBigInteger('product_variation_attribute_id')->index('sora_pva_idx');
            $table->integer('allocated_quantity')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_order_receipt_allocations');

        Schema::table('supplier_order_pva', function (Blueprint $table) {
            $cols = [];
            foreach (['received_quantity', 'cancelled_quantity', 'hors_commande_quantity'] as $col) {
                if (Schema::hasColumn('supplier_order_pva', $col)) {
                    $cols[] = $col;
                }
            }
            if (!empty($cols)) {
                $table->dropColumn($cols);
            }
        });
    }
};
