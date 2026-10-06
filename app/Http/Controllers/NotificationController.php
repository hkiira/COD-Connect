<?php

namespace App\Http\Controllers;

/** The dashboard bell: the connected account user's database notifications. */
class NotificationController extends Controller
{
    private const LIMIT = 30;

    public function index()
    {
        $user = getAccountUser();

        return response()->json([
            'unread' => $user->unreadNotifications()->count(),
            'data'   => $user->notifications()->limit(self::LIMIT)->get()->map(fn ($n) => [
                'id'         => $n->id,
                'created_at' => $n->created_at,
                'read_at'    => $n->read_at,
                ...$n->data, // title, message, category, type, link
            ]),
        ]);
    }

    public function markRead(string $id)
    {
        getAccountUser()->notifications()->whereKey($id)->firstOrFail()->markAsRead();

        return response()->json(['success' => true]);
    }

    public function markAllRead()
    {
        getAccountUser()->unreadNotifications()->update(['read_at' => now()]);

        return response()->json(['success' => true]);
    }
}
