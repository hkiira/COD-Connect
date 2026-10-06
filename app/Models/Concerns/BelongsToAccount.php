<?php

namespace App\Models\Concerns;

use App\Models\Scopes\AccountScope;
use App\Support\AccountContext;

/**
 * Isolates a model by account_id: every query is filtered to the current
 * account, and new rows inherit it. Use Model::withoutGlobalScope(AccountScope::class)
 * or AccountContext::run(null, ...) for deliberate cross-account access.
 */
trait BelongsToAccount
{
    public static function bootBelongsToAccount(): void
    {
        static::addGlobalScope(new AccountScope());

        static::creating(function ($model) {
            if ($model->getAttribute('account_id') === null && ($accountId = AccountContext::id()) !== null) {
                $model->setAttribute('account_id', $accountId);
            }
        });
    }
}
