<?php

declare(strict_types=1);

namespace App\Domains\Qurilish\Models;

/**
 * Obyekt o'zgarishlari jurnali — kim, qachon, qaysi maydonni o'zgartirdi.
 *
 * `updated_at` yo'q: jurnal yozuvi hech qachon o'zgarmaydi (append-only).
 */
class ObjectAuditLog extends QurilishModel
{
    protected $table = 'object_audit_log';

    public $timestamps = false;

    protected $guarded = [];

    /** @var array<int, string> */
    public const ACTIONS = ['create', 'update', 'stage_change', 'document_upload', 'document_delete'];

    protected $casts = ['created_at' => 'datetime'];
}
