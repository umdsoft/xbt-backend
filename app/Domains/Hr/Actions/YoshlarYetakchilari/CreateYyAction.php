<?php

declare(strict_types=1);

namespace App\Domains\Hr\Actions\YoshlarYetakchilari;

use App\Domains\Hr\Models\YoshlarYetakchisi;

class CreateYyAction
{
    /** @param array<string, mixed> $data */
    public function execute(array $data, int $createdBy): YoshlarYetakchisi
    {
        return YoshlarYetakchisi::create([
            ...$data,
            'created_by' => $createdBy,
        ]);
    }
}
