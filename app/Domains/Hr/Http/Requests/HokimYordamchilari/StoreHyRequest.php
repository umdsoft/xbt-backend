<?php

declare(strict_types=1);

namespace App\Domains\Hr\Http\Requests\HokimYordamchilari;

class StoreHyRequest extends HyRequest
{
    protected function ability(): string
    {
        return 'hokim-yordamchilari.create';
    }
}
