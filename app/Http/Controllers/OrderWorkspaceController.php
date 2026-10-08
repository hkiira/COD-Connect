<?php

namespace App\Http\Controllers;

use App\Models\AccountUser;
use App\Models\MessageTemplate;
use App\Models\Order;
use App\Support\Orders\OrderAge;
use App\Support\Orders\OrderListFilters;
use App\Support\Orders\OrderQueues;
use App\Support\Orders\OrderReasons;
use App\Support\Orders\OrderStatus;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * The tools of the order workspaces: queue counters, assignment to agents, the focus mode queue
 * (one order at a time, never the same order for two agents), team figures and the message templates.
 * Every outcome goes through the existing reasons (PUT orders/{id} → order_comment); nothing here
 * changes a status.
 */
class OrderWorkspaceController extends Controller
{
    /** Statuses an order is still being worked on in: an agent's open load. */
    private const OPEN_STATUSES = [
        OrderStatus::PENDING, OrderStatus::OUT_OF_STOCK, OrderStatus::CONFIRMED,
        OrderStatus::IN_PREPARATION, OrderStatus::IN_DELIVERY, OrderStatus::IN_TROUBLE,
    ];

    /** How long a focus-mode claim keeps an order away from the other agents. */
    private const CLAIM_MINUTES = 10;

    /** A customer who did not answer is not offered again in focus mode before this many hours. */
    private const RETRY_AFTER_HOURS = 2;

    /** GET orders/queues/counts?workspace=confirmation&assigned_to[]=me — one count per queue, same filters as the list. */
    public function queueCounts(Request $request)
    {
        $query = collect($request->query())->toArray();
        $workspace = $query['workspace'] ?? null;
        if (! is_string($workspace) || ! isset(OrderQueues::WORKSPACES[$workspace])) {
            return response()->json(['statut' => 0, 'message' => 'Unknown workspace.'], 422);
        }

        $options = OrderListFilters::ageOptions($query);
        $counts = [];
        foreach (OrderQueues::WORKSPACES[$workspace] as $queue) {
            // the age filter is the stuck threshold: it only applies to that queue
            $counts[$queue] = OrderQueues::apply(
                OrderListFilters::query($query),
                "{$workspace}.{$queue}",
                $queue === 'stuck' ? $options : []
            )->count();
        }

        $data = ['counts' => $counts];
        if ($workspace === 'recovery') {
            $data['unpaid_buckets'] = $this->ageBuckets(OrderQueues::apply(OrderListFilters::query($query), 'recovery.unpaid'));
        }

        return response()->json(['statut' => 1, 'data' => $data]);
    }

    /** GET orders/agents — the account's agents with their open load, for the assignment menus. */
    public function agents()
    {
        $accountId = getAccountUser()->account_id;
        $loads = $this->openLoads($accountId);

        $agents = AccountUser::where('account_id', $accountId)->where('statut', 1)->with('user')->get()
            ->filter(fn ($accountUser) => $accountUser->user !== null)
            ->map(fn ($accountUser) => OrderController::agentSummary($accountUser) + [
                'open_orders' => (int) ($loads[$accountUser->id] ?? 0),
            ])
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values();

        return response()->json(['statut' => 1, 'data' => $agents]);
    }

    /** POST orders/assign {ids, account_user_id|null} — null takes the orders back from their agent. */
    public function assign(Request $request)
    {
        $accountId = getAccountUser()->account_id;
        $validator = Validator::make($request->all(), [
            'ids' => 'required|array|min:1|max:500',
            'ids.*' => 'integer',
            'account_user_id' => ['nullable', 'integer', Rule::exists('account_user', 'id')->where('account_id', $accountId)],
        ]);
        if ($validator->fails()) {
            return response()->json(['statut' => 0, 'data' => $validator->errors()], 422);
        }

        $agentId = $request['account_user_id'];
        $updated = Order::where('orders.account_id', $accountId)->whereIn('orders.id', $request['ids'])
            ->update(['assigned_to' => $agentId, 'assigned_at' => $agentId ? now() : null]);

        return response()->json(['statut' => 1, 'data' => ['updated' => $updated]]);
    }

    /**
     * POST orders/assign/auto {user_ids, ids? | queue?} — shares orders between agents, each order going to
     * the agent with the smallest open load. Without ids, takes the unassigned orders of the queue.
     */
    public function autoAssign(Request $request)
    {
        $accountId = getAccountUser()->account_id;
        $validator = Validator::make($request->all(), [
            'user_ids' => 'required|array|min:1|max:200',
            'user_ids.*' => ['integer', Rule::exists('account_user', 'id')->where('account_id', $accountId)],
            'ids' => 'required_without:queue|array|max:2000',
            'ids.*' => 'integer',
            'queue' => ['required_without:ids', 'string', fn ($attribute, $value, $fail) => OrderQueues::exists($value) ?: $fail('Unknown queue.')],
        ]);
        if ($validator->fails()) {
            return response()->json(['statut' => 0, 'data' => $validator->errors()], 422);
        }

        if ($request->filled('ids')) {
            $orderIds = Order::where('orders.account_id', $accountId)->whereIn('orders.id', $request['ids'])
                ->orderBy('orders.created_at')->pluck('orders.id')->all();
        } else {
            $orderIds = OrderQueues::apply(OrderListFilters::query([]), $request['queue'])
                ->whereNull('orders.assigned_to')
                ->orderBy('orders.created_at')->limit(2000)->pluck('orders.id')->all();
        }

        $agentIds = array_values(array_unique(array_map('intval', $request['user_ids'])));
        $loads = $this->openLoads($accountId, $agentIds);
        $shares = array_fill_keys($agentIds, []);
        foreach ($orderIds as $orderId) {
            // the least loaded agent; on a tie, the first one in the request
            $agentId = $agentIds[0];
            foreach ($agentIds as $candidate) {
                if (($loads[$candidate] ?? 0) < ($loads[$agentId] ?? 0)) {
                    $agentId = $candidate;
                }
            }
            $shares[$agentId][] = $orderId;
            $loads[$agentId] = ($loads[$agentId] ?? 0) + 1;
        }

        DB::transaction(function () use ($shares, $accountId) {
            foreach ($shares as $agentId => $ids) {
                foreach (array_chunk($ids, 500) as $chunk) {
                    Order::where('orders.account_id', $accountId)->whereIn('orders.id', $chunk)
                        ->update(['assigned_to' => $agentId, 'assigned_at' => now()]);
                }
            }
        });

        return response()->json([
            'statut' => 1,
            'data' => [
                'assigned' => count($orderIds),
                'by_agent' => array_map('count', $shares),
            ],
        ]);
    }

    /**
     * POST orders/queue/next {release_id?, skip?} — the next order to confirm, claimed for this agent:
     * "Reporté" callbacks that are due (oldest first), then pending orders (newest first), then the orders
     * abandoned for "no answer" (fewest unanswered calls first, none in the last hours). Orders of other
     * agents and orders another agent has open are never returned.
     */
    public function next(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'release_id' => 'nullable|integer',
            'skip' => 'nullable|array|max:100',
            'skip.*' => 'integer',
        ]);
        if ($validator->fails()) {
            return response()->json(['statut' => 0, 'data' => $validator->errors()], 422);
        }

        $agent = getAccountUser();
        if ($request->filled('release_id')) {
            $this->releaseClaim($agent, (int) $request['release_id']);
        }
        $skip = array_map('intval', $request['skip'] ?? []);
        $noAnswer = implode(',', OrderReasons::noAnswer());

        $candidates = [
            'confirmation.callbacks_due' => fn ($q) => $q->orderByRaw('orders.callback_at IS NULL, orders.callback_at'),
            'confirmation.new' => fn ($q) => $q->orderByDesc('orders.created_at'),
            'confirmation.no_answer' => fn ($q) => $q
                ->whereRaw("NOT EXISTS (SELECT 1 FROM order_comment oc WHERE oc.order_id = orders.id AND oc.deleted_at IS NULL
                    AND oc.comment_id IN ($noAnswer) AND oc.created_at > ?)", [now()->subHours(self::RETRY_AFTER_HOURS)])
                ->orderByRaw(OrderReasons::attemptsSql())->orderByDesc('orders.created_at'),
        ];

        $claimed = DB::transaction(function () use ($candidates, $agent, $skip) {
            foreach ($candidates as $queue => $sort) {
                $query = OrderQueues::apply(Order::where('orders.account_id', $agent->account_id), $queue)
                    ->where(fn ($q) => $q->whereNull('orders.assigned_to')->orWhere('orders.assigned_to', $agent->id))
                    ->where(fn ($q) => $q->whereNull('orders.claimed_until')->orWhere('orders.claimed_until', '<', now()))
                    ->when($skip, fn ($q) => $q->whereNotIn('orders.id', $skip));

                // SKIP LOCKED: an order another agent is claiming right now is passed over, not waited for
                $found = $sort($query)->lock('for update skip locked')->first();
                if (! $found) {
                    continue;
                }

                $found->claimed_by = $agent->id;
                $found->claimed_until = now()->addMinutes(self::CLAIM_MINUTES);
                if ($found->assigned_to === null) {
                    $found->assigned_to = $agent->id;
                    $found->assigned_at = now();
                }
                $found->save();

                return ['id' => $found->id, 'code' => $found->code, 'queue' => $queue, 'claimed_until' => $found->claimed_until];
            }

            return null;
        });

        return response()->json(['statut' => 1, 'data' => $claimed]);
    }

    /** POST orders/{id}/release — gives back an order this agent has open in focus mode. */
    public function release($id)
    {
        $released = $this->releaseClaim(getAccountUser(), (int) $id);

        return response()->json(['statut' => 1, 'data' => ['released' => $released > 0]]);
    }

    /**
     * GET orders/agents/stats?start_date=&end_date= — what each agent did in the period, read from the
     * history the reasons write (order_comment): confirmations, unanswered calls, postponements,
     * abandons, cancellations, and what became of the orders they confirmed.
     */
    public function agentStats(Request $request)
    {
        $validator = Validator::make($request->query(), [
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
        ]);
        if ($validator->fails()) {
            return response()->json(['statut' => 0, 'data' => $validator->errors()], 422);
        }

        $accountId = getAccountUser()->account_id;
        $start = Carbon::parse($request->query('start_date', now()->subDays(6)->toDateString()))->startOfDay();
        $end = Carbon::parse($request->query('end_date', now()->toDateString()))->endOfDay();
        $noAnswer = implode(',', OrderReasons::noAnswer());

        // one row per reason an agent applied in the period, on this account's orders
        $reasons = fn () => DB::table('order_comment as oc')
            ->join('comments as c', 'c.id', '=', 'oc.comment_id')
            ->join('orders as o', 'o.id', '=', 'oc.order_id')
            ->where('o.account_id', $accountId)->whereNull('o.deleted_at')
            ->where('oc.type', 'comment')->whereNull('oc.deleted_at')
            ->where('c.statut', 1)
            ->whereNotNull('oc.account_user_id')
            ->whereBetween('oc.created_at', [$start, $end]);

        $rows = $reasons()
            ->groupBy('oc.account_user_id')
            ->selectRaw("oc.account_user_id as agent_id,
                COUNT(*) as actions,
                COUNT(DISTINCT oc.order_id) as orders,
                COUNT(DISTINCT CASE WHEN oc.order_status_id = ? THEN oc.order_id END) as confirmed,
                SUM(oc.comment_id IN ($noAnswer)) as no_answer,
                SUM(c.postponed = 1) as postponed,
                COUNT(DISTINCT CASE WHEN oc.order_status_id = ? AND oc.comment_id NOT IN ($noAnswer) THEN oc.order_id END) as abandoned,
                COUNT(DISTINCT CASE WHEN oc.order_status_id = ? THEN oc.order_id END) as cancelled,
                COUNT(DISTINCT CASE WHEN oc.order_status_id IN (?, ?, ?) THEN oc.order_id END) as decided",
                [OrderStatus::CONFIRMED, OrderStatus::ABANDONED, OrderStatus::CANCELLED,
                    OrderStatus::CONFIRMED, OrderStatus::ABANDONED, OrderStatus::CANCELLED])
            ->get()->keyBy('agent_id');

        // what became of the orders each agent confirmed in the period
        $outcomes = DB::query()->fromSub(
            $reasons()->where('oc.order_status_id', OrderStatus::CONFIRMED)
                ->select('oc.account_user_id', 'oc.order_id')->distinct(),
            'confirmed'
        )
            ->join('orders as o', 'o.id', '=', 'confirmed.order_id')
            ->groupBy('confirmed.account_user_id')
            ->selectRaw('confirmed.account_user_id as agent_id,
                SUM(o.order_status_id IN (?, ?)) as delivered,
                SUM(o.order_status_id IN (?, ?)) as returned', [
                OrderStatus::DELIVERED, OrderStatus::PAID, OrderStatus::RETURNED, OrderStatus::IN_TROUBLE,
            ])
            ->get()->keyBy('agent_id');

        $loads = $this->openLoads($accountId);
        $callbacks = Order::where('orders.account_id', $accountId)->whereNotNull('orders.assigned_to')
            ->where('orders.callback_at', '<=', now())->whereIn('orders.order_status_id', OrderQueues::CALLBACK_STATUSES)
            ->groupBy('orders.assigned_to')->selectRaw('orders.assigned_to, COUNT(*) as due')->pluck('due', 'assigned_to');

        $agentIds = collect($rows->keys())->merge(array_keys($loads))->unique()->values();
        // no account filter on the names: an agent of a linked account can work this account's orders,
        // and only agents who acted on (or own) this account's orders are listed
        $agents = AccountUser::whereIn('id', $agentIds)->with('user')->get()->keyBy('id');

        $rate = fn ($part, $whole) => $whole > 0 ? round($part / $whole * 100, 1) : null;
        $list = $agentIds->map(function ($agentId) use ($rows, $outcomes, $loads, $callbacks, $agents, $rate) {
            $row = $rows[$agentId] ?? null;
            $outcome = $outcomes[$agentId] ?? null;
            $delivered = (int) ($outcome->delivered ?? 0);
            $returned = (int) ($outcome->returned ?? 0);

            return [
                'agent' => OrderController::agentSummary($agents[$agentId] ?? null) ?? ['id' => (int) $agentId, 'name' => "#$agentId"],
                'actions' => (int) ($row->actions ?? 0),
                'orders' => (int) ($row->orders ?? 0),
                'confirmed' => (int) ($row->confirmed ?? 0),
                'no_answer' => (int) ($row->no_answer ?? 0),
                'postponed' => (int) ($row->postponed ?? 0),
                'abandoned' => (int) ($row->abandoned ?? 0),
                'cancelled' => (int) ($row->cancelled ?? 0),
                'confirmation_rate' => $rate((int) ($row->confirmed ?? 0), (int) ($row->decided ?? 0)),
                'delivered' => $delivered,
                'returned' => $returned,
                'delivery_rate' => $rate($delivered, $delivered + $returned),
                'open_orders' => (int) ($loads[$agentId] ?? 0),
                'callbacks_due' => (int) ($callbacks[$agentId] ?? 0),
            ];
        })->sortByDesc('actions')->values();

        // time from a new order to the first reason applied on it, for the orders created in the period
        $firstResponse = DB::query()->fromSub(
            DB::table('order_comment as oc')->join('comments as c', 'c.id', '=', 'oc.comment_id')
                ->join('orders as o', 'o.id', '=', 'oc.order_id')
                ->where('o.account_id', $accountId)->whereNull('o.deleted_at')->whereBetween('o.created_at', [$start, $end])
                ->where('oc.type', 'comment')->whereNull('oc.deleted_at')->where('c.statut', 1)
                ->groupBy('oc.order_id', 'o.created_at')
                ->selectRaw('o.created_at, MIN(oc.created_at) as first_at'),
            'first'
        )->selectRaw('AVG(TIMESTAMPDIFF(MINUTE, first.created_at, first.first_at)) as minutes')->value('minutes');

        $sum = fn ($key) => $list->sum($key);

        return response()->json([
            'statut' => 1,
            'data' => [
                'range' => ['start' => $start->toDateString(), 'end' => $end->toDateString()],
                'totals' => [
                    'orders_created' => Order::where('orders.account_id', $accountId)->whereBetween('orders.created_at', [$start, $end])->count(),
                    'actions' => $sum('actions'),
                    'confirmed' => $sum('confirmed'),
                    'no_answer' => $sum('no_answer'),
                    'postponed' => $sum('postponed'),
                    'abandoned' => $sum('abandoned'),
                    'cancelled' => $sum('cancelled'),
                    'confirmation_rate' => $rate($sum('confirmed'), $rows->sum('decided')),
                    'delivery_rate' => $rate($sum('delivered'), $sum('delivered') + $sum('returned')),
                    'first_response_minutes' => $firstResponse !== null ? (int) round((float) $firstResponse) : null,
                ],
                'agents' => $list,
            ],
        ]);
    }

    /** GET orders/message-templates — the account's WhatsApp messages (empty: the screens use their defaults). */
    public function templates()
    {
        $templates = MessageTemplate::where('account_id', getAccountUser()->account_id)
            ->orderBy('stage')->orderBy('position')->orderBy('id')
            ->get(['id', 'stage', 'title', 'language', 'body', 'position']);

        return response()->json(['statut' => 1, 'data' => $templates]);
    }

    /** PUT orders/message-templates {templates: [...]} — replaces the whole set; an empty list goes back to the defaults. */
    public function saveTemplates(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'templates' => 'present|array|max:60',
            'templates.*.stage' => ['required', Rule::in(MessageTemplate::STAGES)],
            'templates.*.title' => 'required|string|max:80',
            'templates.*.language' => ['required', Rule::in(MessageTemplate::LANGUAGES)],
            'templates.*.body' => 'required|string|max:1000',
        ]);
        if ($validator->fails()) {
            return response()->json(['statut' => 0, 'data' => $validator->errors()], 422);
        }

        $accountId = getAccountUser()->account_id;
        DB::transaction(function () use ($request, $accountId) {
            MessageTemplate::where('account_id', $accountId)->delete();
            foreach (array_values($request['templates']) as $position => $template) {
                MessageTemplate::create([
                    'account_id' => $accountId,
                    'stage' => $template['stage'],
                    'title' => trim($template['title']),
                    'language' => $template['language'],
                    'body' => trim($template['body']),
                    'position' => $position,
                ]);
            }
        });

        return $this->templates();
    }

    // ------------------------------------------------------------------

    private function releaseClaim(AccountUser $agent, int $orderId): int
    {
        return Order::where('orders.account_id', $agent->account_id)->whereKey($orderId)
            ->where('claimed_by', $agent->id)
            ->update(['claimed_by' => null, 'claimed_until' => null]);
    }

    /** @return array<int, int> account_user id => number of open orders assigned to it */
    private function openLoads(int $accountId, ?array $agentIds = null): array
    {
        return Order::where('orders.account_id', $accountId)
            ->whereIn('orders.order_status_id', self::OPEN_STATUSES)
            ->whereNotNull('orders.assigned_to')
            ->when($agentIds !== null, fn ($q) => $q->whereIn('orders.assigned_to', $agentIds))
            ->groupBy('orders.assigned_to')
            ->selectRaw('orders.assigned_to, COUNT(*) as open_orders')
            ->pluck('open_orders', 'assigned_to')
            ->map(fn ($count) => (int) $count)
            ->all();
    }

    /** Unpaid deliveries by days since delivery, as the logistics exceptions buckets. */
    private function ageBuckets($query): array
    {
        $age = OrderAge::daysSql();
        $row = $query->toBase()->selectRaw("SUM($age <= 7) as b0, SUM($age BETWEEN 8 AND 14) as b8,
            SUM($age BETWEEN 15 AND 30) as b15, SUM($age > 30) as b30")->first();

        return [
            '0-7' => (int) ($row->b0 ?? 0),
            '8-14' => (int) ($row->b8 ?? 0),
            '15-30' => (int) ($row->b15 ?? 0),
            '30+' => (int) ($row->b30 ?? 0),
        ];
    }
}
