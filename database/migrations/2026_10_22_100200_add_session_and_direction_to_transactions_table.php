<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('transactions', function (Blueprint $table) {
            if (!Schema::hasColumn('transactions', 'cash_drawer_session_id')) {
                $table->unsignedBigInteger('cash_drawer_session_id')->nullable()->after('cash_drawer_id');
                $table->foreign('cash_drawer_session_id')->references('id')->on('cash_drawer_sessions')->onDelete('set null');
            }
            if (!Schema::hasColumn('transactions', 'direction')) {
                $table->string('direction', 3)->nullable()->after('amount');
            }
        });
    }

    public function down()
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropForeign(['cash_drawer_session_id']);
            $table->dropColumn(['cash_drawer_session_id', 'direction']);
        });
    }
};
