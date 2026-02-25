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
        Schema::table('type_attributes', function (Blueprint $table) {
            $table->string('meta')->nullable()->after('code'); // Replace 'existing_column' with a relevant column
        });
    }

    public function down()
    {
        Schema::table('type_attributes', function (Blueprint $table) {
            $table->dropColumn('meta');
        });
    }
};
