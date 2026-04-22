<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ReviewQuestion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class ReviewQuestionController extends Controller
{
    /**
     * Display a listing of the review questions.
     */
    public function index()
    {
        $questions = ReviewQuestion::with('options')->orderBy('id')->get();
        return response()->json(['data' => $questions]);
    }

    /**
     * Store a newly created review question in storage.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'text' => 'required|string|max:255',
            'type' => 'required|string|in:stars,multiselect,text',
            'is_active' => 'required|boolean',
            'options' => 'nullable|array|required_if:type,multiselect',
            'options.*.label' => 'required|string|max:255',
            'options.*.value' => 'required|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $question = DB::transaction(function () use ($request) {
            $question = ReviewQuestion::create($request->only(['text', 'type', 'is_active']));

            if ($request->type === 'multiselect' && $request->has('options')) {
                $question->options()->createMany($request->options);
            }
            return $question;
        });

        return response()->json(['data' => $question->load('options'), 'message' => 'Question created successfully.'], 201);
    }

    /**
     * Display the specified review question.
     */
    public function show(ReviewQuestion $question)
    {
        return response()->json(['data' => $question->load('options')]);
    }

    /**
     * Update the specified review question in storage.
     */
    public function update(Request $request, ReviewQuestion $question)
    {
        $validator = Validator::make($request->all(), [
            'text' => 'required|string|max:255',
            'type' => 'required|string|in:stars,multiselect,text',
            'is_active' => 'required|boolean',
            'options' => 'nullable|array|required_if:type,multiselect',
            'options.*.id' => 'nullable|integer|exists:review_question_options,id',
            'options.*.label' => 'required|string|max:255',
            'options.*.value' => 'required|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        DB::transaction(function () use ($request, $question) {
            $question->update($request->only(['text', 'type', 'is_active']));

            if ($request->type === 'multiselect') {
                $optionIdsToKeep = [];
                
                if ($request->has('options')) {
                    foreach ($request->options as $optionData) {
                        if (isset($optionData['id'])) {
                            $option = $question->options()->find($optionData['id']);
                            if ($option) {
                                $option->update($optionData);
                                $optionIdsToKeep[] = $option->id;
                            }
                        } else {
                            $newOption = $question->options()->create($optionData);
                            $optionIdsToKeep[] = $newOption->id;
                        }
                    }
                }
                
                $question->options()->whereNotIn('id', $optionIdsToKeep)->delete();
            } else {
                $question->options()->delete();
            }
        });

        return response()->json(['data' => $question->load('options'), 'message' => 'Question updated successfully.']);
    }

    /**
     * Remove the specified review question from storage.
     */
    public function destroy(ReviewQuestion $question)
    {
        $question->delete();
        return response()->json(null, 204);
    }
}
