<?php

namespace App\Http\Controllers;

use App\Models\AccountUser;
use App\Models\Order;
use App\Models\OrderCall;
use App\Support\Orders\OrderAge;
use App\Support\Orders\OrderListFilters;
use App\Support\Orders\OrderQueues;
use App\Support\Orders\OrderStatus;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * The tools of the order workspaces: queue counters, the call log, assignment to agents and the
 * focus mode queue (one order at a time, never the same order for two agents).
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

    /** A customer who did not answer is not called again before this many hours in focus mode. */
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

    /** GET orders/{id}/calls — newest first. */
    public function calls($id)
    {
        $order = $this->findOrder($id);
        if (! $order) {
            return response()->json(['statut' => 0, 'message' => 'Order not found.'], 404);
        }

        $calls = $order->calls()->with('employee.user')->orderByDesc('called_at')->orderByDesc('id')->get()
            ->map(fn (OrderCall $call) => self::callPayload($call));

        return response()->json(['statut' => 1, 'data' => $calls]);
    }

    /**
     * POST orders/{id}/calls {result, note?, callback_at?, call_duration?}. Sets or clears the callback, and
     * gives an unassigned order to the agent who called.
     */
    public function logCall(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'result' => ['required', Rule::in(OrderCall::RESULTS)],
            'note' => 'nullable|string|max:1000',
            'callback_at' => 'required_if:result,callback|nullable|date|after:now',
            'call_duration' => 'nullable|integer|min:0|max:86400',
        ]);
        if ($validator->fails()) {
            return response()->json(['statut' => 0, 'data' => $validator->errors()], 422);
        }

        $order = $this->findOrder($id);
        if (! $order) {
            return response()->json(['statut' => 0, 'message' => 'Order not found.'], 404);
        }

        $agent = getAccountUser();
        $call = DB::transaction(function () use ($order, $agent, $request) {
            // the row lock keeps two calls logged at the same moment from getting the same number
            $order = Order::whereKey($order->id)->lockForUpdate()->first();

            $call = $order->calls()->create([
                'employee_id' => $agent->id,
                'called_at' => now(),
                'call_number' => min(255, $order->calls()->count() + 1),
                'result' => $request['result'],
                'note' => $request['note'],
                'call_duration' => $request['call_duration'],
            ]);

            $order->callback_at = $request['result'] === 'callback' ? Carbon::parse($request['callback_at']) : null;
            if ($order->assigned_to === null) {
                $order->assigned_to = $agent->id;
                $order->assigned_at = now();
            }
            $order->save();

            return $call;
        });

        $order->refresh()->load('assignee.user', 'latestCall');

        return response()->json([
            'statut' => 1,
            'data' => [
                'call' => self::callPayload($call->load('employee.user')),
                'order' => ['id' => $order->id] + OrderController::workspaceFields($order),
            ],
        ]);
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
     * callbacks that are due first (oldest first), then new orders (newest first), then the customers who
     * did not answer (fewest calls first, not called in the last hours). Orders of other agents and orders
     * another agent has open are never returned.
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

        $candidates = [
            'confirmation.callbacks_due' => fn ($q) => $q->orderBy('orders.callback_at'),
            'confirmation.new' => fn ($q) => $q->orderByDesc('orders.created_at'),
            'confirmation.no_answer' => fn ($q) => $q
                ->whereDoesntHave('calls', fn ($c) => $c->where('called_at', '>', now()->subHours(self::RETRY_AFTER_HOURS)))
                ->withCount('calls')->orderBy('calls_count')->orderByDesc('orders.created_at'),
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

    // ------------------------------------------------------------------

    private function findOrder($id): ?Order
    {
        return Order::where('orders.account_id', getAccountUser()->account_id)->find($id);
    }

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

    private static function callPayload(OrderCall $call): array
    {
        return [
            'id' => $call->id,
            'called_at' => $call->called_at,
            'call_number' => (int) $call->call_number,
            'result' => $call->result,
            'note' => $call->note,
            'call_duration' => $call->call_duration,
            'agent' => OrderController::agentSummary($call->employee),
        ];
    }
}
