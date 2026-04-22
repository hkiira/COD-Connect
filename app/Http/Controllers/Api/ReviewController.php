<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Review;
use App\Models\ReviewAnswer;
use App\Models\ReviewQuestion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class ReviewController extends Controller
{
    /**
     * Get the list of active review questions.
     * Your Next.js app will call this endpoint to dynamically build the review form.
     */
    public function index()
    {
        $questions = ReviewQuestion::where('is_active', true)
            ->with('options') // Eager load options for questions that have them
            ->get();

        return response()->json(['data' => $questions]);
    }

    /**
     * Store the submitted review data.
     * Your Next.js app will POST the form data to this endpoint.
     */
    public function store(Request $request, Order $order)
    {
        // Dynamically build validation rules from the questions in the database
        $questions = ReviewQuestion::where('is_active', true)->get();
        $validationRules = [];
        foreach ($questions as $question) {
            $validationRules['answers.' . $question->id] = 'required';
        }

        $validator = Validator::make($request->all(), $validationRules, [
            'answers.*.required' => 'The answer for this question is required.'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Optional but recommended: Check if the user is authorized to review this order
        // if ($order->user_id !== Auth::id()) {
        //     return response()->json(['message' => 'You are not authorized to review this order.'], 403);
        // }

        // Prevent duplicate reviews
        if (Review::where('order_id', $order->id)->exists()) {
            return response()->json(['message' => 'A review for this order has already been submitted.'], 409); // 409 Conflict
        }

        // Use a database transaction to ensure all data is saved or none at all
        try {
            $review = DB::transaction(function () use ($request, $order) {
                $review = Review::create([
                    'order_id' => $order->id,
                    'user_id'  => Auth::id(), // Assumes the user is authenticated
                ]);

                foreach ($request->input('answers') as $questionId => $answerValue) {
                    // For multiselect answers, the value will be an array. We'll store it as JSON.
                    $storedValue = is_array($answerValue) ? json_encode($answerValue) : $answerValue;
                    
                    ReviewAnswer::create([
                        'review_id'          => $review->id,
                        'review_question_id' => $questionId,
                        'answer_value'       => $storedValue,
                    ]);
                }
                return $review;
            });

            return response()->json([
                'message'   => 'Thank you for your review!',
                'review_id' => $review->id
            ], 201); // 201 Created

        } catch (\Exception $e) {
            // Log the error and return a generic server error
            report($e);
            return response()->json(['message' => 'An unexpected error occurred.'], 500);
        }
    }
}
