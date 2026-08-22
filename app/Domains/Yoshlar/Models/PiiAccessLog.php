<?php

declare(strict_types=1);

namespace App\Domains\Yoshlar\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Maxfiy maydon ochilishi jurnali — faqat yoziladi, hech qachon o'chirilmaydi. */
class PiiAccessLog extends Model
{
    use HasUuids;

    protected $connection = 'yoshlar';

    protected $table = 'pii_access_log';

    public $timestamps = false;

    protected $fillable = ['user_id', 'youth_id', 'fields', 'ip', 'created_at'];
}
