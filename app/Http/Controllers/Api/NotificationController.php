<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;

/** The in-app notification inbox. */
class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $page = $user->notifications()->latest()->paginate(30);

        return response()->json([
            'data' => collect($page->items())->map(fn (DatabaseNotification $n) => self::toApi($n)),
            'meta' => [
                'unread_count' => $user->unreadNotifications()->count(),
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
            ],
        ]);
    }

    /** Marks the given ids read, or everything when `ids` is omitted. */
    public function markRead(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['sometimes', 'array', 'max:100'],
            'ids.*' => ['uuid'],
        ]);

        $request->user()->unreadNotifications()
            ->when(isset($data['ids']), fn ($q) => $q->whereIn('id', $data['ids']))
            ->update(['read_at' => now()]);

        return response()->json(['meta' => ['unread_count' => $request->user()->unreadNotifications()->count()]]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $request->user()->notifications()->whereKey($id)->delete();

        return response()->json(['meta' => ['unread_count' => $request->user()->unreadNotifications()->count()]]);
    }

    /** @return array<string, mixed> */
    public static function toApi(DatabaseNotification $n): array
    {
        return [
            'id' => $n->id,
            'kind' => $n->data['kind'] ?? $n->type,
            'title' => $n->data['title'] ?? '',
            'body' => $n->data['body'] ?? '',
            'route' => $n->data['route'] ?? null,
            'icon' => $n->data['icon'] ?? 'notifications',
            'read_at' => $n->read_at?->toIso8601String(),
            'created_at' => $n->created_at?->toIso8601String(),
        ];
    }
}
