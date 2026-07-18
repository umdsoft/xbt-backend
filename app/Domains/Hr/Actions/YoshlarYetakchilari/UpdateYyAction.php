<?php

declare(strict_types=1);

namespace App\Domains\Hr\Actions\YoshlarYetakchilari;

use App\Domains\Hr\Models\YoshlarYetakchisi;

class UpdateYyAction
{
    /** @param array<string, mixed> $data */
    public function execute(YoshlarYetakchisi $yy, array $data): YoshlarYetakchisi
    {
        $yy->update($data);

        return $yy->refresh();
    }
}
