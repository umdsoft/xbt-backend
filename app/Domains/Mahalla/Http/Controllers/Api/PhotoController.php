<?php

declare(strict_types=1);

namespace App\Domains\Mahalla\Http\Controllers\Api;

use App\Domains\Mahalla\Models\House;
use App\Domains\Mahalla\Models\HousePhoto;
use App\Domains\Mahalla\Support\MahallaAccess;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class PhotoController extends Controller
{
    public function __construct(private readonly MahallaAccess $access)
    {
    }

    /**
     * Rasmni maxfiy diskdan vakolat bilan uzatish (URL orqali ochib bo'lmaydi).
     */
    public function show(Request $request, HousePhoto $photo): StreamedResponse
    {
        $user = $request->user();

        $visible = House::query()
            ->visibleTo($this->access->scopeFor($user))
            ->whereKey($photo->house_id)
            ->exists();

        if (! $visible || ! $this->access->can($user, 'photos.view')) {
            throw new NotFoundHttpException();
        }

        $disk = (string) config('mahalla.photos_disk', 'local');
        if (! Storage::disk($disk)->exists($photo->image_path)) {
            throw new NotFoundHttpException();
        }

        return Storage::disk($disk)->response($photo->image_path);
    }
}
