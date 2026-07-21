<?php

declare(strict_types=1);

namespace App\Domains\Hr\Http\Requests\YoshlarYetakchilari;

class StoreYyRequest extends YyRequest
{
    protected function ability(): string
    {
        return 'yoshlar.create';
    }
}
