<?php

namespace App\Models\Scopes;

use App\Support\AccountContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class AccountScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $accountId = AccountContext::id();

        if ($accountId !== null) {
            $builder->where($model->qualifyColumn('account_id'), $accountId);
        }
    }
}
