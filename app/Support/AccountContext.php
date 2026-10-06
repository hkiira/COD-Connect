<?php

namespace App\Support;

use Illuminate\Support\Facades\Auth;

/**
 * Resolves the account (tenant) the current execution belongs to.
 *
 * - HTTP request: the first account of the authenticated user, memoized per
 *   user (same account getAccountUser() returns).
 * - Console / webhooks / queue without a user: null, so no scope is applied
 *   unless the caller pins an account with run().
 */
class AccountContext
{
    /** @var array<int|string, int|null> */
    private static array $resolved = [];

    private static ?int $pinned = null;

    private static bool $isPinned = false;

    public static function id(): ?int
    {
        if (! config('tenancy.enforce', true)) {
            return null;
        }

        if (self::$isPinned) {
            return self::$pinned;
        }

        $user = Auth::user();
        if (! $user) {
            return null;
        }

        $key = $user->getAuthIdentifier();
        if (! array_key_exists($key, self::$resolved)) {
            self::$resolved[$key] = $user->accountUsers()->first()?->account_id;
        }

        return self::$resolved[$key];
    }

    /**
     * Run a callback bound to an account (null = unscoped, e.g. for super-admin tooling).
     */
    public static function run(?int $accountId, callable $callback): mixed
    {
        $previous = [self::$pinned, self::$isPinned];
        self::$pinned = $accountId;
        self::$isPinned = true;

        try {
            return $callback();
        } finally {
            [self::$pinned, self::$isPinned] = $previous;
        }
    }

    public static function flush(): void
    {
        self::$resolved = [];
        self::$pinned = null;
        self::$isPinned = false;
    }
}
