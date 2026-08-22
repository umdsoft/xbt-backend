<?php

declare(strict_types=1);

namespace App\Domains\Yoshlar\Http\Controllers\Api;

use App\Domains\Yoshlar\Services\NotificationService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * In-app bildirishnomalar (TZ 5.8).
 *
 * Ruxsat tekshirilmaydi: har foydalanuvchi FAQAT o'zining yozuvlarini
 * ko'radi (`user_id` bo'yicha filtr servisda) — bu domen ruxsatiga emas,
 * shaxsiy pochtaga o'xshaydi.
 */
class NotificationController extends Controller
{
    public function __construct(private readonly NotificationService $service) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->service->listFor($request->user()),
            'unread' => $this->service->unreadCount($request->user()),
        ]);
    }

    public function markRead(Request $request): JsonResponse
    {
        $id = $request->input('id');

        $count = $this->service->markRead($request->user(), is_string($id) ? $id : null);

        return response()->json(['marked' => $count, 'unread' => $this->service->unreadCount($request->user())]);
    }
}
