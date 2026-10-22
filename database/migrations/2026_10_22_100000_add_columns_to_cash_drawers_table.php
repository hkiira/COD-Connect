<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('cash_drawers', function (Blueprint $table) {
            if (!Schema::hasColumn('cash_drawers', 'account_user_id')) {
                $table->unsignedBigInteger('account_user_id')->nullable()->after('account_id');
                $table->foreign('account_user_id')->references('id')->on('account_user')->onDelete('set null');
            }
            if (!Schema::hasColumn('cash_drawers', 'description')) {
                $table->text('description')->nullable()->after('title');
            }
            if (!Schema::hasColumn('cash_drawers', 'deleted_at')) {
                $table->softDeletes();
            }
        });
    }

    public function down()
    {
        Schema::table('cash_drawers', function (Blueprint $table) {
            $table->dropForeign(['account_user_id']);
            $table->dropColumn(['account_user_id', 'description']);
            $table->dropSoftDeletes();
        });
    }
};
