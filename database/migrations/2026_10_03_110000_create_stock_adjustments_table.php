<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_adjustments', function (Blueprint $table) {
            $table->engine = 'InnoDB'; // transactional: the server default is MyISAM
            $table->id();
            $table->unsignedBigInteger('account_id')->index();
            $table->unsignedBigInteger('warehouse_id');
            $table->unsignedBigInteger('product_variation_attribute_id');
            $table->unsignedBigInteger('account_user_id')->nullable();
            $table->decimal('old_quantity', 12, 2);
            $table->decimal('new_quantity', 12, 2);
            $table->string('reason', 255);
            $table->timestamps();

            $table->index(['warehouse_id', 'product_variation_attribute_id'], 'stock_adjustments_wh_pva_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_adjustments');
    }
};
