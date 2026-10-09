<?php

namespace App\Support\Orders;

use App\Models\Order;
use Illuminate\Support\Facades\DB;

/**
 * What can come back from a sale and what it is worth: one place for the return / exchange endpoint,
 * the order page and the customer page, so the screens offer exactly what the API accepts.
 */
final class ReturnRules
{
    /** The customer has the parcel: in delivery (handed over at the door), delivered, paid. */
    public const RETURNABLE_STATUSES = [OrderStatus::IN_DELIVERY, OrderStatus::DELIVERED, OrderStatus::PAID];

    /** Lines that are not part of the order any more (removed, out of stock). */
    public const INACTIVE_LINE_STATUSES = [2, 3];

    /** A return that was abandoned or cancelled gives its pieces back: they can be returned again. */
    public const VOID_RETURN_STATUSES = [OrderStatus::ABANDONED, OrderStatus::CANCELLED];

    public static function isReturnable(Order $order): bool
    {
        return ($order->type ?? 'sale') === 'sale'
            && in_array((int) $order->order_status_id, self::RETURNABLE_STATUSES, true);
    }

    /**
     * Pieces of each line already on a return that still counts.
     *
     * @param  int[]  $lineIds  order_pva.id of the sale
     * @return array<int, int> line id => quantity
     */
    public static function returnedQuantities(array $lineIds): array
    {
        if (! $lineIds) {
            return [];
        }

        return DB::table('order_pva as rl')
            ->join('orders as r', 'r.id', '=', 'rl.order_id')
            ->whereIn('rl.source_order_pva_id', $lineIds)
            ->where('r.type', 'return')
            ->whereNull('r.deleted_at')
            ->whereNull('rl.deleted_at')
            ->whereNotIn('r.order_status_id', self::VOID_RETURN_STATUSES)
            ->whereNotIn('rl.order_status_id', [...self::INACTIVE_LINE_STATUSES, OrderStatus::CANCELLED])
            ->groupBy('rl.source_order_pva_id')
            ->selectRaw('rl.source_order_pva_id as line_id, SUM(rl.quantity) as quantity')
            ->pluck('quantity', 'line_id')
            ->map(fn ($quantity) => (int) $quantity)
            ->all();
    }

    /**
     * What one piece of each active line cost the customer: its price less its share of the order discount
     * (spread over the lines in proportion to their value). Shipping is never refunded.
     *
     * @return array<int, float> line id => unit value
     */
    public static function unitValues(Order $order): array
    {
        $lines = $order->orderPvas()->whereNotIn('order_status_id', self::INACTIVE_LINE_STATUSES)->get(['id', 'price', 'quantity']);

        return self::unitValuesOf($lines, (float) $order->discount);
    }

    /**
     * unitValues() over lines already loaded (active lines only).
     *
     * @param  iterable<object{id: int, price: mixed, quantity: mixed}>  $lines
     * @return array<int, float>
     */
    public static function unitValuesOf(iterable $lines, float $orderDiscount): array
    {
        $lines = collect($lines);
        $subtotal = $lines->sum(fn ($line) => (float) $line->price * (int) $line->quantity);
        $discount = min(max(0.0, $orderDiscount), $subtotal);
        $ratio = $subtotal > 0 ? 1 - $discount / $subtotal : 1;

        return $lines->mapWithKeys(fn ($line) => [(int) $line->id => round((float) $line->price * $ratio, 2)])->all();
    }

    /**
     * Per active line of the order: what was sold, already returned, still returnable and the unit value.
     *
     * @return array<int, array{quantity: int, returned_quantity: int, returnable_quantity: int, refund_unit_price: float}>
     */
    public static function lines(Order $order): array
    {
        $values = self::unitValues($order);
        $returned = self::returnedQuantities(array_keys($values));
        $returnable = self::isReturnable($order);

        $lines = [];
        foreach ($order->orderPvas()->whereIn('id', array_keys($values))->get(['id', 'quantity']) as $line) {
            $already = $returned[$line->id] ?? 0;
            $lines[(int) $line->id] = [
                'quantity' => (int) $line->quantity,
                'returned_quantity' => $already,
                'returnable_quantity' => $returnable ? max(0, (int) $line->quantity - $already) : 0,
                'refund_unit_price' => $values[$line->id],
            ];
        }

        return $lines;
    }
}
