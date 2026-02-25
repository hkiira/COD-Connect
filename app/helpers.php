<?php

use Illuminate\Support\Facades\Auth;

if (!function_exists('formatPhoneNumber')) {
    function formatPhoneNumber($phone)
    {
        // Remove all non-numeric characters (spaces, dashes, plus signs, etc.)
        $phone = preg_replace('/[^0-9]/', '', $phone);

        // Handle Moroccan country code (212)
        if (str_starts_with($phone, '212')) {
            $phone = '0' . substr($phone, 3);
        } elseif (str_starts_with($phone, '00212')) {
            $phone = '0' . substr($phone, 5);
        }

        // If it's 9 digits and starts with 5, 6, 7, or 8, prepend 0
        if (strlen($phone) === 9 && in_array($phone[0], ['5', '6', '7', '8'])) {
            $phone = '0' . $phone;
        }

        return $phone;
    }
}

function getAccountUser()
{
    $user = Auth::user();
    if ($user) {
        return $user->accountUsers()->first();
    }
    return null;
}