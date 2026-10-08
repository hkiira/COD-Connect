<?php

namespace App\Support\Orders;

/**
 * What the order history (order_comment) says about an order, as SQL expressions on `orders`.
 * The workspaces read the reasons the agents already apply (the `comments` tree), never a parallel log.
 */
final class OrderReasons
{
    /** comments.id of the reasons that record an unanswered call (config orders.no_answer_comments). */
    public static function noAnswer(): array
    {
        $ids = array_values(array_filter(array_map('intval', (array) config('orders.no_answer_comments', [])), fn ($id) => $id > 0));

        return $ids ?: [0];
    }

    /**
     * comments.id of the last reason an agent applied (visible reasons only: comments.statut = 1).
     * Notes, system rows (new order, sheet sync, product changes) and auto-scoring rows are skipped.
     */
    public static function lastReasonSql(): string
    {
        return "(SELECT oc.comment_id FROM order_comment oc JOIN comments c ON c.id = oc.comment_id
            WHERE oc.order_id = orders.id AND oc.type = 'comment' AND oc.deleted_at IS NULL AND c.statut = 1
            ORDER BY oc.created_at DESC, oc.id DESC LIMIT 1)";
    }

    /** Number of unanswered calls recorded on the order. */
    public static function attemptsSql(): string
    {
        $ids = implode(',', self::noAnswer());

        return "(SELECT COUNT(*) FROM order_comment oc
            WHERE oc.order_id = orders.id AND oc.deleted_at IS NULL AND oc.comment_id IN ($ids))";
    }

    /** SQL list of the reasons that postpone the order to a date ("Reporté"). */
    public static function postponedIdsSql(): string
    {
        return '(SELECT id FROM comments WHERE postponed = 1)';
    }

    /** The last reason was "no answer" (never NULL, so NOT (...) works too). */
    public static function lastIsNoAnswerSql(): string
    {
        return 'COALESCE(' . self::lastReasonSql() . ', 0) IN (' . implode(',', self::noAnswer()) . ')';
    }

    /** The last reason was a postponement ("Reporté"). */
    public static function lastIsPostponedSql(): string
    {
        return 'COALESCE(' . self::lastReasonSql() . ', 0) IN ' . self::postponedIdsSql();
    }
}
