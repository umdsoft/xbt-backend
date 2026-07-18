<?php

declare(strict_types=1);

namespace App\Domains\Hr\Actions\HokimYordamchilari;

use App\Domains\Hr\Models\HokimYordamchisi;

class CreateHyAction
{
    /** @param array<string, mixed> $data */
    public function execute(array $data, int $createdBy): HokimYordamchisi
    {
        return HokimYordamchisi::create([
            ...$data,
            'created_by' => $createdBy,
        ]);
    }
}
