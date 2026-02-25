<?php
// Helper functions for scoring system
use Carbon\Carbon;

/**
 * POSTPONED COMMENTS LOGIC:
 * 
 * When a user adds a "postpone" field to an order_comment:
 * 1. Automatic scoring stops until the postponed time expires
 * 2. No automatic negative comments are created during postponed periods
 * 3. When calculating scores, postponed time is excluded from day calculations
 * 4. Once postponed time expires, normal scoring resumes from that point
 * 
 * Example:
 * - Order created at 09:00 on Day 1
 * - Comment added at 11:00 with postpone until 15:00 Day 2
 * - At 16:00 Day 2, scoring resumes as if only 1 hour has passed (16:00 - 15:00)
 */

/**
 * Calculate working hours between two timestamps.
 * Working hours: 09:00–13:00 and 14:00–18:00 (Mon–Sat).
 * Sunday is a day off.
 */
function getWorkHoursDiff(Carbon $start, Carbon $end)
{
    $workPeriods = [
        ['09:00', '13:00'],
        ['14:00', '18:00'],
    ];
    $totalMinutes = 0;
    $current = $start->copy();
    while ($current->lt($end)) {
        // Sunday is day off (0 = Sunday in Carbon)
        if ($current->dayOfWeek === 0) {
            $current->addDay()->setTime(9, 0);
            continue;
        }
        foreach ($workPeriods as [$from, $to]) {
            $periodStart = $current->copy()->setTimeFromTimeString($from);
            $periodEnd = $current->copy()->setTimeFromTimeString($to);
            if ($end->lt($periodStart)) continue;
            $startTime = $current->greaterThan($periodStart) ? $current : $periodStart;
            $endTime = $end->lessThan($periodEnd) ? $end : $periodEnd;
            if ($startTime->lt($endTime)) {
                $totalMinutes += $endTime->diffInMinutes($startTime);
            }
        }
        $current->addDay()->setTime(9, 0);
    }
    return $totalMinutes / 60;
}

/**
 * Calculate score based on days passed since order creation, considering postponed periods.
 * @param Carbon $orderCreatedAt
 * @param Carbon $actionTime
 * @param int $orderId (optional) - if provided, will account for postponed periods
 * @return int
 */
function calculateDayBasedScore(Carbon $orderCreatedAt, Carbon $actionTime, int $orderId = null)
{
    $effectiveStartTime = $orderCreatedAt;
    
    // If order ID is provided, check for postponed periods that should be excluded
    if ($orderId) {
        // Get all postponed comments that were active before the action time
        $postponedComments = \DB::table('order_comment')
            ->where('order_id', $orderId)
            ->whereNotNull('postpone')
            ->where('created_at', '<=', $actionTime)
            ->orderBy('created_at', 'asc')
            ->get();
        
        $totalPostponedHours = 0;
        
        foreach ($postponedComments as $postponedComment) {
            $commentTime = Carbon::parse($postponedComment->created_at);
            $postponeUntil = Carbon::parse($postponedComment->postpone);
            
            // Only count postponed time if the postpone period overlaps with our scoring period
            if ($commentTime->lte($actionTime) && $postponeUntil->gte($effectiveStartTime)) {
                $postponeStart = $commentTime->gt($effectiveStartTime) ? $commentTime : $effectiveStartTime;
                $postponeEnd = $postponeUntil->lt($actionTime) ? $postponeUntil : $actionTime;
                
                if ($postponeStart->lt($postponeEnd)) {
                    $totalPostponedHours += $postponeStart->diffInHours($postponeEnd);
                }
            }
        }
        
        // Adjust the effective start time by adding back the postponed hours
        $effectiveStartTime = $orderCreatedAt->copy()->subHours($totalPostponedHours);
    }
    
    $daysPassed = $effectiveStartTime->startOfDay()->diffInDays($actionTime->startOfDay());
    
    switch ($daysPassed) {
        case 0:
            return 10; // First day
        case 1:
            return 7;  // Second day
        case 2:
            return 1;  // Third day
        default:
            return 0;  // Fourth day and beyond
    }
}

/**
 * Calculate comment score based on timing.
 * @param Carbon $expectedTime The time when the comment was expected
 * @param Carbon $actualTime The actual time when the comment was made
 * @return int
 */
function calculateCommentScore(Carbon $expectedTime, Carbon $actualTime)
{
    // If comment is made on time or before, score is 0
    // If comment is late, score is -1
    return $actualTime->gt($expectedTime) ? -1 : 0;
}

/**
 * Calculate final score for canceled orders based on call times.
 * @param array $calls Array of Carbon call timestamps (sorted ascending)
 * @param Carbon $orderCreatedAt
 * @return int
 */
function calculateScore(array $calls, Carbon $orderCreatedAt)
{
    $score = 10;
    $prevCall = $orderCreatedAt;
    foreach ($calls as $call) {
        $diff = getWorkHoursDiff($prevCall, $call);
        if ($diff > 4) {
            $score -= 1;
        }
        $prevCall = $call;
    }
    return max($score, 0);
}

/**
 * Calculate total order score by summing all status and comment scores.
 * The comment scores are capped at a maximum of 10.
 * @param int $orderId
 * @return int
 */
function calculateTotalOrderScore(int $orderId)
{
    // Get all account_user_order_status records for this order
    $statusScores = \DB::table('account_user_order_status')
        ->where('order_id', $orderId)
        ->sum('score');
    
    // Get all order_comment records for this order
    $commentScores = \DB::table('order_comment')
        ->where('order_id', $orderId)
        ->sum('score');
    
    // Cap comment scores at maximum of 10
    $commentScores = min($commentScores, 10);
    
    return $statusScores + $commentScores;
}

/**
 * Get total order score - alias for calculateTotalOrderScore for convenience
 * @param int $orderId
 * @return int
 */
function getOrderScore(int $orderId)
{
    return calculateTotalOrderScore($orderId);
}

/**
 * Check if an order is currently postponed
 * @param int $orderId
 * @return bool
 */
function isOrderPostponed(int $orderId)
{
    $postponedComment = \DB::table('order_comment')
        ->where('order_id', $orderId)
        ->whereNotNull('postpone')
        ->where('postpone', '>', Carbon::now())
        ->exists();
        
    return $postponedComment;
}

/**
 * Get the active postponed comment for an order (if any)
 * @param int $orderId
 * @return object|null
 */
function getActivePostponedComment(int $orderId)
{
    return \DB::table('order_comment')
        ->where('order_id', $orderId)
        ->whereNotNull('postpone')
        ->where('postpone', '>', Carbon::now())
        ->orderBy('created_at', 'desc')
        ->first();
}

/**
 * Check if automatic comment is needed and create it.
 * @param int $orderId
 * @return void
 */
function checkAndCreateAutomaticComment(int $orderId)
{
    $order = \App\Models\Order::find($orderId);
    if (!$order) return;
    
    // Get the last comment for this order
    $lastComment = \DB::table('order_comment')
        ->where('order_id', $orderId) // Direct order_id reference
        ->orderBy('created_at', 'desc')
        ->first();
    
    // Check if there's a postponed comment that's still active
    $postponedComment = \DB::table('order_comment')
        ->where('order_id', $orderId)
        ->whereNotNull('postpone')
        ->where('postpone', '>', Carbon::now())
        ->orderBy('created_at', 'desc')
        ->first();
    
    // If there's an active postponed comment, skip automatic scoring
    if ($postponedComment) {
        return; // Don't create automatic comments during postponed period
    }
    
    // Check for expired postponed comments and resume scoring from their postpone time
    $expiredPostponedComment = \DB::table('order_comment')
        ->where('order_id', $orderId)
        ->whereNotNull('postpone')
        ->where('postpone', '<=', Carbon::now())
        ->orderBy('postpone', 'desc')
        ->first();
    
    // Determine the last relevant time for scoring
    $lastTime = $order->created_at; // Default to order creation
    
    if ($expiredPostponedComment) {
        // If there's an expired postponed comment, use its postpone time
        $lastTime = Carbon::parse($expiredPostponedComment->postpone);
    } elseif ($lastComment) {
        // Otherwise, use the last comment time
        $lastTime = Carbon::parse($lastComment->created_at);
    }
    
    $now = Carbon::now();
    
    // Check if 4 hours have passed since the last relevant time
    $hoursSinceLastTime = $lastTime->diffInHours($now);
    
    if ($hoursSinceLastTime >= 4) {
        // Create automatic comment with -1 score
        \DB::table('order_comment')->insert([
            'order_id' => $orderId,
            'title' => 'Automatic: No contact made within 4 hours',
            'comment_id' => 45, // You may need to adjust this based on your comments table
            'order_status_id' => $order->order_status_id,
            'account_user_id' => 1, // System user, adjust as needed
            'score' => -1,
            'type' => 'auto_scoring',
            'created_at' => $now,
            'updated_at' => $now
        ]);
        // Note: No need to update orders table anymore since we removed the score column
        // The total score can be calculated on-demand using calculateTotalOrderScore()
    }
}
