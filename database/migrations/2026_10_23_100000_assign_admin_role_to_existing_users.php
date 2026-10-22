<?php

use Illuminate\Database\Migrations\Migration;
use App\Models\AccountUser;
use Spatie\Permission\Models\Role;

return new class extends Migration
{
    public function up(): void
    {
        $adminRole = Role::findOrCreate('admin', 'api');

        AccountUser::whereDoesntHave('roles')
            ->each(function ($accountUser) use ($adminRole) {
                $accountUser->assignRole($adminRole);
            });
    }

    public function down(): void
    {
        // Intentionally left empty — removing roles would lock everyone out
    }
};
