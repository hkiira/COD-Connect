<?php

namespace App\Support\Orders;

use App\Models\Order;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Stops the same order from being created twice (double click, retry, two tabs).
 *
 * An order is a duplicate when, within the last few minutes, the same account already created an order
 * for one of the same phone numbers with the same products AND the same quantities. Phones are compared
 * in their normalized form (+212 6.., 06.. and 6.. are one number).
 *
 * Two requests arriving together would both pass a plain "look for a recent order" check, so the check
 * and the insert run under a lock per account and phone, released once the transaction has committed.
 */
class DuplicateOrderGuard
{
    /** @var array<int, \Illuminate\Contracts\Cache\Lock> */
    private array $locks = [];

    public static function normalizePhones(array $titles): array
    {
        return collect($titles)
            ->filter()
            ->map(fn ($title) => formatPhoneNumber($title))
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /** product_variation_attribute_id => total quantity, order independent. */
    public static function signature(iterable $lines): array
    {
        $signature = [];
        foreach ($lines as $line) {
            $pvaId = (int) (is_array($line) ? ($line['id'] ?? $line['product_variation_attribute_id'] ?? 0) : ($line->product_variation_attribute_id ?? 0));
            $quantity = (int) (is_array($line) ? ($line['quantity'] ?? 1) : ($line->quantity ?? 1));
            $signature[$pvaId] = ($signature[$pvaId] ?? 0) + $quantity;
        }
        ksort($signature);

        return $signature;
    }

    /**
     * Takes the locks of the given phones (always in the same order, so two requests cannot wait on each
     * other). Returns false when another request is creating an order for the same phone right now.
     */
    public function acquire(int $accountId, array $phones, int $waitSeconds = 5): bool
    {
        foreach ($phones as $phone) {
            $lock = Cache::lock("order-create:{$accountId}:{$phone}", 30);

            try {
                $held = $lock->block($waitSeconds);
            } catch (\Throwable $e) {
                $held = false;
            }

            if (! $held) {
                $this->release();

                return false;
            }
            $this->locks[] = $lock;
        }

        return true;
    }

    /**
     * The locks must outlive the transaction: another request would otherwise not see our order yet.
     * Call this once the locks are taken, inside the transaction.
     */
    public function releaseAfterTransaction(): void
    {
        if (DB::transactionLevel() > 0) {
            DB::afterCommit(fn () => $this->release());
            DB::afterRollBack(fn () => $this->release());
        } else {
            $this->release();
        }
    }

    public function release(): void
    {
        foreach ($this->locks as $lock) {
            try {
                $lock->release();
            } catch (\Throwable $e) {
                // an expired lock is already free
            }
        }
        $this->locks = [];
    }

    /** The recent order that already contains the same products and quantities, if any. */
    public function findDuplicate(int $accountId, array $phones, array $newLines, int $windowMinutes = 5): ?Order
    {
        if (empty($phones) || empty($newLines)) {
            return null;
        }

        $newSignature = self::signature($newLines);

        $recent = Order::where('account_id', $accountId)
            ->where('created_at', '>=', now()->subMinutes($windowMinutes))
            ->whereHas('phones', fn ($query) => $query->whereIn('title', $phones))
            ->with('orderPvas')
            ->get();
        foreach ($recent as $order) {
            if (self::signature($order->orderPvas) === $newSignature) {
                return $order;
            }
        }

        return null;
    }
}
