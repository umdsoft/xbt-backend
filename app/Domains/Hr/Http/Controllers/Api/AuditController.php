<?php

declare(strict_types=1);

namespace App\Domains\Hr\Http\Controllers\Api;

use App\Domains\Hr\Models\Activity;
use App\Domains\Hr\Support\ActivityTranslator;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditController extends HrController
{
    public function index(Request $request): JsonResponse
    {
        $query = Activity::with('causer')->latest();

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->whereLike('description', "%{$search}%");
        }

        if ($request->filled('subject_type')) {
            $query->where('subject_type', $request->input('subject_type'));
        }

        return response()->json([
            'activities' => $query->paginate(25)->withQueryString()->through(function (Activity $a): array {
                /** @var HrProfile|null $causer */
                $causer = $a->causer;

                $subject = class_basename((string) $a->subject_type);

                return [
                    'id' => $a->id,
                    'log_name' => $a->log_name,
                    'description' => ActivityTranslator::event($a->description),
                    'subject_type' => ActivityTranslator::subject($subject),
                    'subject_id' => $a->subject_id,
                    'causer' => $causer !== null ? $causer->name : 'Тизим',
                    'properties' => $a->properties,
                    'created_at' => $a->created_at?->format('d.m.Y H:i:s'),
                ];
            }),
            'filters' => $request->only(['search', 'subject_type']),
        ]);
    }
}
