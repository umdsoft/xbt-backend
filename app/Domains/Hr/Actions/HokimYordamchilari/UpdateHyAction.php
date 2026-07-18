<?php

declare(strict_types=1);

namespace App\Domains\Hr\Actions\HokimYordamchilari;

use App\Domains\Hr\Models\HokimYordamchisi;

class UpdateHyAction
{
    /** @param array<string, mixed> $data */
    public function execute(HokimYordamchisi $hy, array $data): HokimYordamchisi
    {
        $hy->update($data);

        return $hy->refresh();
    }
}
