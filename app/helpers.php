<?php

use Illuminate\Support\Facades\Auth;

if (!function_exists('formatPhoneNumber')) {
    function formatPhoneNumber($phone)
    {
        // Remove all non-numeric characters (spaces, dashes, plus signs, etc.)
        $phone = preg_replace('/[^0-9]/', '', (string) $phone);

        // Handle Moroccan country code (00212 / 212) including optional trunk 0
        if (str_starts_with($phone, '002120') && strlen($phone) === 14) {
            $phone = '0' . substr($phone, 6);
        } elseif (str_starts_with($phone, '00212')) {
            $phone = '0' . substr($phone, 5);
        } elseif (str_starts_with($phone, '2120') && strlen($phone) === 13) {
            $phone = '0' . substr($phone, 4);
        } elseif (str_starts_with($phone, '212')) {
            $phone = '0' . substr($phone, 3);
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
    $user = Auth::user() ?? (Auth::guard('api')->check() ? Auth::guard('api')->user() : null);
    $pinnedAccountId = class_exists(\App\Support\AccountContext::class) ? \App\Support\AccountContext::id() : null;

    if ($user) {
        if ($pinnedAccountId) {
            $pinnedAu = $user->accountUsers()->where('account_id', $pinnedAccountId)->first();
            if ($pinnedAu) {
                return $pinnedAu;
            }
        }
        $au = $user->accountUsers()->first();
        if ($au) {
            return $au;
        }
    }

    if ($pinnedAccountId) {
        return \App\Models\AccountUser::where('account_id', $pinnedAccountId)->where('statut', 1)->first()
            ?? \App\Models\AccountUser::where('account_id', $pinnedAccountId)->first();
    }

    return null;
}