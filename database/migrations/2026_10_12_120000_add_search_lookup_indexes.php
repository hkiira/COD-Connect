<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additional lookup indexes to optimize order search by customer name, phone, and address.
 * Created only if missing so it is safely idempotent.
 */
return new class extends Migration
{
    private const INDEXES = [
        'customers' => [
            'customers_name_idx' => ['name'],
        ],
        'phones' => [
            'phones_title_idx' => ['title'],
        ],
        'addresses' => [
            'addresses_title_idx' => ['title'],
        ],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $table => $indexes) {
            foreach ($indexes as $name => $columns) {
                if (! Schema::hasIndex($table, $name)) {
                    Schema::table($table, fn (Blueprint $t) => $t->index($columns, $name));
                }
            }
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $table => $indexes) {
            foreach (array_keys($indexes) as $name) {
                if (Schema::hasIndex($table, $name)) {
                    Schema::table($table, fn (Blueprint $t) => $t->dropIndex($name));
                }
            }
        }
    }
};
