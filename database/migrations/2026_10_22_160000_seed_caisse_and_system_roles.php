<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Role;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $roles = [
            'admin',
            'manager',
            'comptable',
            'caissier',
            'confirmateur',
        ];

        foreach ($roles as $roleName) {
            Role::findOrCreate($roleName, 'api');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Don't drop roles in reverse to preserve user assignments
    }
};
