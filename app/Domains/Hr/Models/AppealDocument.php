<?php

declare(strict_types=1);

namespace App\Domains\Hr\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AppealDocument extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $connection = 'hr';

    protected $fillable = [
        'appeal_id', 'file_path', 'original_name', 'document_type',
        'file_size', 'mime_type', 'uploaded_by', 'created_at',
    ];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function appeal(): BelongsTo
    {
        return $this->belongsTo(CitizenAppeal::class, 'appeal_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(HrProfile::class, 'uploaded_by');
    }
}
