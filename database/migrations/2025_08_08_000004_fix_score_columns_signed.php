<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // Modify score columns to allow negative values
        DB::statement('ALTER TABLE account_user_order_status MODIFY score TINYINT DEFAULT 0');
        DB::statement('ALTER TABLE order_comment MODIFY score TINYINT DEFAULT 0');
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        DB::statement('ALTER TABLE account_user_order_status MODIFY score TINYINT UNSIGNED DEFAULT 0');
        DB::statement('ALTER TABLE order_comment MODIFY score TINYINT UNSIGNED DEFAULT 0');
    }
};
