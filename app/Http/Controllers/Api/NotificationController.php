<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** In-app notifications (FR-12). UI pending: neither portal has a notification list yet. */
class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $page = $request->user()->notifications()->latest()->paginate(20);

        return response()->json([
            'data' => collect($page->items())->map(fn ($n) => [
                'id' => $n->id,
                ...$n->data,
                'read' => $n->read_at !== null,
                'at' => $n->created_at->setTimezone(config('admission.display_timezone'))->format('Y-m-d\TH:i'),
            ]),
            'unread' => $request->user()->unreadNotifications()->count(),
            'meta' => ['page' => $page->currentPage(), 'last_page' => $page->lastPage()],
        ]);
    }

    public function read(Request $request, string $id): JsonResponse
    {
        $request->user()->notifications()->findOrFail($id)->markAsRead();

        return response()->json(['unread' => $request->user()->unreadNotifications()->count()]);
    }

    public function readAll(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications->markAsRead();

        return response()->json(['unread' => 0]);
    }
}
