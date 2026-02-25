<?php

namespace App\Http\Controllers;

use App\Models\OrderStatus;
use Illuminate\Support\Collection;
use Illuminate\Http\Request;
use App\Models\Comment;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class CommentController extends Controller
{

    public static function index(Request $request)
    {
        $request = collect($request->query())->toArray();
        $associated = [];
        $model = 'App\\Models\\Comment';
        $request['where'] = ['column' => 'comment_id', 'value' => NULL];
        $datas = FilterController::searchs(new Request($request), $model, ['id', 'title'], true, $associated);
        return $datas;
    }
    public function create(Request $request)
    {
        $request = collect($request->query())->toArray();
        $statues = [];
        if (isset($request['statuses']['inactive'])) {
            $model = 'App\\Models\\OrderStatus';
            //permet de récupérer la liste des regions inactive filtrés
            $statues['statuses']['inactive'] = FilterController::searchs(new Request($request['statuses']['inactive']), $model, ['id', 'title'], true);
        }

        return response()->json([
            'statut' => 1,
            'data' => $statues,
        ]);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->except('_method'), [
            '*.title' => [ // Validate title field
                'required', // Title is required
                'max:255', // Title should not exceed 255 characters
                function ($attribute, $value, $fail) { // Custom validation rule
                    // Call the function to rename removed records
                    RestoreController::renameRemovedRecords('Comment', 'title', $value);
                    $account_id = getAccountUser()->account_id;
                    $titleModel = Comment::where('title', $value)->where('account_id', $account_id)->first();
                    if ($titleModel) {
                        $fail("exist");
                    }
                },
            ],
            '*.orderStatuses' => 'required|exists:order_statuses,id',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'statut' => 0,
                'data' => $validator->errors(),
            ]);
        };
        $comments = collect($request->except('_method'))->map(function ($commentData) {
            $comment_only = collect($commentData)->only('title', 'statut');
            $comment = Comment::create($comment_only->all());
            if ($commentData['orderStatuses']) {
                foreach ($commentData['orderStatuses'] as $key => $orderStatusId) {
                    $comment->orderStatuses()->syncWithoutDetaching([$orderStatusId => ['statut' => 1, 'created_at' => now(), 'updated_at' => now()]]);
                }
            }
            return $comment;
        });
        return response()->json([
            'statut' => 1,
            'data' =>  $comments,
        ]);
    }


    public function show($id)
    {
        //
    }

    public function edit(Request $request, $id)
    {
        $request = collect($request->query())->toArray();
        $data = [];
        $comment = Comment::find($id);
        if (!$comment)
            return response()->json([
                'statut' => 0,
                'data' => 'not exist'
            ]);
        if (isset($request['commentInfo'])) {
            $info = collect($comment->only('title', 'statut'))->toArray();
            $data["commentInfo"]['data'] = $info;
        }

        if (isset($request['statuses']['active'])) {
            $model = 'App\\Models\\OrderStatus';
            $request['statuses']['active']['whereIn'][0] = ['table' => 'comments', 'column' => 'order_status_comment.comment_id', 'value' => $comment->id];
            $data['statuses']['active'] = FilterController::searchs(new Request($request['statuses']['active']), $model, ['id', 'title'], true);
        }

        if (isset($request['statuses']['inactive'])) {
            $model = 'App\\Models\\OrderStatus';
            $request['statuses']['inactive']['whereNotIn'][0] = ['table' => 'comments', 'column' => 'order_status_comment.comment_id', 'value' => $comment->id];
            $data['statuses']['inactive'] = FilterController::searchs(new Request($request['statuses']['inactive']), $model, ['id', 'title'], true);
        }

        return response()->json([
            'statut' => 1,
            'data' => $data
        ]);
    }



    public function update(Request $requests, $id)
    {
        $validator = Validator::make($requests->except('_method'), [
            '*.id' => 'required|exists:comments,id',
            '*.title' => [ // Validate title field
                'max:255', // Title should not exceed 255 characters
                function ($attribute, $value, $fail) { // Custom validation rule
                    // Call the function to rename removed records
                    RestoreController::renameRemovedRecords('brand', 'title', $value);
                    $titleModel = Comment::where('title', $value)->first();
                    if ($titleModel) {
                        $fail("exist");
                    }
                },
            ],
            '*.statusesToActive.*' => [function ($attribute, $value, $fail) use ($requests) {
                $sourceExist = OrderStatus::where(['id' => $value])->first();
                if (!$sourceExist) {
                    $fail('not exist');
                }
            }],

            '*.statusesToInactive.*' => [function ($attribute, $value, $fail) use ($requests) {
                $sourceExist = OrderStatus::where(['id' => $value])->first();
                if (!$sourceExist) {
                    $fail('not exist');
                }
            }],
        ]);
        if ($validator->fails()) {
            return response()->json([
                'statut' => 0,
                'data' => $validator->errors(),
            ]);
        };
        $comments = collect($requests->except('_method'))->map(function ($request) {
            $comment_all = collect($request)->all();
            $comment = Comment::find($comment_all['id']);
            $comment->update($comment_all);
            if (isset($comment_all['statusesToInactive'])) {
                foreach ($comment_all['statusesToInactive'] as $key => $statusId) {
                    $status = OrderStatus::find($statusId);
                    $status->comments()->detach($comment);
                    $status->save();
                }
            }
            if (isset($comment_all['statusesToActive'])) {
                foreach ($comment_all['statusesToActive'] as $key => $statusId) {
                    $comment->orderStatuses()->syncWithoutDetaching([$statusId => ['statut' => 1, 'created_at' => now(), 'updated_at' => now()]]);
                }
            }
            $comment = Comment::with('orderStatuses')->find($comment->id);
            return $comment;
        });

        return response()->json([
            'statut' => 1,
            'data' => $comments,
        ]);
    }

    public function destroy($id)
    {
        $comment = Comment::find($id);
        $comment->delete();
        return response()->json([
            'statut' => 1,
            'data' => $comment,
        ]);
    }
}
