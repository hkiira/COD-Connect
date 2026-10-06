<?php

use App\Casts\EncryptedCredential;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Encrypts the carrier passwords / tokens that were stored in clear text.
 * Values that are already encrypted (Afra) are left alone, so the migration can run twice.
 * The columns become TEXT: an encrypted session token no longer fits in 255 characters.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('account_carrier', function (Blueprint $table) {
            $table->text('password')->nullable()->change();
            $table->text('token')->nullable()->change();
        });

        DB::table('account_carrier')
            ->where(fn ($q) => $q->whereNotNull('password')->orWhereNotNull('token'))
            ->orderBy('id')
            ->each(function ($row) {
                $changes = [];
                foreach (['password', 'token'] as $column) {
                    $value = $row->{$column};
                    if ($value !== null && $value !== '' && EncryptedCredential::decrypt($value) === null) {
                        $changes[$column] = Crypt::encryptString($value);
                    }
                }
                if ($changes) {
                    DB::table('account_carrier')->where('id', $row->id)->update($changes);
                }
            });
    }

    public function down(): void
    {
        // Not reversible on purpose: secrets must not go back to clear text.
    }
};
