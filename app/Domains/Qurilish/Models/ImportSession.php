<?php

declare(strict_types=1);

namespace App\Domains\Qurilish\Models;

/** Har Excel/CSV import urinishi — audit uchun. */
class ImportSession extends QurilishModel
{
    protected $table = 'import_sessions';

    protected $guarded = [];

    protected $casts = [
        'records_count' => 'integer',
        'is_active' => 'boolean',
    ];
}
