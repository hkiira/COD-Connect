<?php

use Illuminate\Database\Migrations\Migration;
use App\Models\DefaultCode;
use App\Models\Account;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $codes = [
            [
                'name' => 'Caisse',
                'prefix' => 'CSH',
                'controller' => 'CashDrawer',
            ],
            [
                'name' => 'Session Caisse',
                'prefix' => 'SESS',
                'controller' => 'CashDrawerSession',
            ],
        ];

        $accounts = Account::all();

        foreach ($codes as $item) {
            $defaultCode = DefaultCode::firstOrCreate(
                ['controller' => $item['controller']],
                $item
            );

            foreach ($accounts as $account) {
                $account->defaultCodes()->syncWithoutDetaching([
                    $defaultCode->id => [
                        'prefixe' => $defaultCode->prefix,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                ]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
    }
};
