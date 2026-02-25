<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Comment;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class SubCommentController extends Controller
{

    public static function index(Request $request)
    {
        $request = collect($request->query())->toArray();
        $associated=[];
        $model = 'App\\Models\\Comment';
        $request['whereNot']=['column'=>'comment_id','value'=>NULL];
        $datas = FilterController::searchs(new Request($request),$model,['id','title'], true,$associated);
        return $datas;
    }
    public function create(Request $request)
    {
        $request = collect($request->query())->toArray();
        $statues= [];
        if (isset($request['comments']['inactive'])){ 
            $model = 'App\\Models\\Comment';
            $request['comments']['inactive']['where']=['column'=>'comment_id','value'=>NULL];
        //permet de récupérer la liste des regions inactive filtrés
            $statues['comments']['inactive'] = FilterController::searchs(new Request($request['comments']['inactive']),$model,['id','title'], true);
        }
        
        return response()->json([
            'statut' => 1,
            'data' => $statues,
        ]);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            '*.title' => [ // Validate title field
                'required', // Title is required
                'max:255', // Title should not exceed 255 characters
                function ($attribute, $value, $fail){ // Custom validation rule
                    // Call the function to rename removed records
                    RestoreController::renameRemovedRecords('Comment', 'title', $value);
                    $account_id=getAccountUser()->account_id;
                    $titleModel = Comment::where('title',$value)->where('account_id',$account_id)->first();
                    if ($titleModel) {
                        $fail("exist"); 
                    }
                },
            ],
            '*.comment_id' => 'required|exists:comments,id',
        ]);
        if($validator->fails()){
            return response()->json([
                'statut' => 0,
                'data' => $validator->errors(),
            ]);       
        };
        $comments = collect($request->all())->map(function ($commentData) {
            $comment_only=collect($commentData)->only('title','statut','comment_id','postponed','is_change');
            $comment = Comment::create($comment_only->all());
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
        $comment = Comment::find($id);
        if(!$comment)
            return response()->json([
                'statut'=>0,
                'data'=> 'not exist'
            ]);
        return response()->json([
            'statut' => 1,
            'data' =>$comment
        ]);
    }



    public function update(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            '*.id' => 'required|exists:types_attributes,id',
            '*.title' => [ // Validate title field
                'required', // Title is required
                'max:255', // Title should not exceed 255 characters
                function ($attribute, $value, $fail) use ($request) { // Custom validation rule
                    // Call the function to rename removed records
                    RestoreController::renameRemovedRecords('Comment', 'title', $value);
                    
                    // Extract index from attribute name
                    $index = str_replace(['*', '.title'], '', $attribute);
                    // Get the ID and title from the request
                    $id = $request->input("{$index}.id"); // Get ID from request
                    $account_id=getAccountUser()->account_id;
                    $account_users='App\\Models\\AccountUser'::where('account_id',$account_id)->get()->pluck('id')->toArray();
                    $titleModel = Comment::where('title',$value)->whereIn('account_user_id',$account_users)->first();
                    $idModel = Comment::where('id', $id)->whereIn('account_user_id',$account_users)->first(); // Find model by ID
                    
                    // Check if a country with the same title exists but with a different ID
                    if ($titleModel && $idModel && $titleModel->id !== $idModel->id) {
                        $fail("exist"); // Validation fails with custom message
                    }
                },
            ],
        ]);
        if($validator->fails()){
            return response()->json([
                'statut' => 0,
                'data' => $validator->errors(),
            ]);       
        };
        $comments = collect($request->all())->map(function ($comment){
            $comment_all=collect($comment)->all();
            $comment = Comment::find($comment_all['id']);
            $comment->update($comment_all);
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
