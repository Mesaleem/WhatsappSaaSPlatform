<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InAppNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A user's own in-app notification inbox. Deliberately routed OUTSIDE the
 * tenant.isolation/subscription.guard group (directly under auth:sanctum) —
 * subscription.guard 403s any request from a tenant whose subscription has
 * lapsed, which is exactly the user who most needs to still see a broadcast
 * telling them their subscription expired. Every action here is scoped to
 * $request->user()->id only; there is no cross-user or cross-account view,
 * so no ResolvesTenantAccount/permission gate is needed beyond being logged in.
 */
class InAppNotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) $request->integer('per_page', 15), 100);

        $notifications = InAppNotification::query()
            ->where('user_id', $request->user()->id)
            ->when($request->boolean('unread_only'), fn ($q) => $q->where('is_read', false))
            ->orderByDesc('created_at')
            ->paginate($perPage);

        return response()->json($notifications);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        $count = InAppNotification::query()
            ->where('user_id', $request->user()->id)
            ->where('is_read', false)
            ->count();

        return response()->json(['unread_count' => $count]);
    }

    public function markRead(Request $request, int $id): JsonResponse
    {
        $notification = InAppNotification::query()
            ->where('user_id', $request->user()->id)
            ->findOrFail($id);

        if (! $notification->is_read) {
            $notification->update(['is_read' => true, 'read_at' => now()]);
        }

        return response()->json($notification);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        InAppNotification::query()
            ->where('user_id', $request->user()->id)
            ->where('is_read', false)
            ->update(['is_read' => true, 'read_at' => now()]);

        return response()->json(['message' => 'All notifications marked as read.']);
    }
}
