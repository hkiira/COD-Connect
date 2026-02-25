<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('mouvements', function (Blueprint $table) {
            $table->renameColumn('warehouse_id', 'from_warehouse');
            $table->renameColumn('warehoused', 'to_warehouse');
            $table->renameColumn('warehouse_nature_id', 'from_nature');
            $table->renameColumn('warehouse_natured', 'to_nature');
        });
    }

    public function down()
    {
        Schema::table('mouvements', function (Blueprint $table) {
            $table->renameColumn('from_warehouse', 'warehouse_id');
            $table->renameColumn('to_warehouse', 'warehoused');
            $table->renameColumn('from_nature', 'warehouse_nature_id');
            $table->renameColumn('to_nature', 'warehouse_natured');
        });
    }
};
