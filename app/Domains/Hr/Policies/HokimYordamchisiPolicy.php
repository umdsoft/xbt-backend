<?php

declare(strict_types=1);

namespace App\Domains\Hr\Policies;

class HokimYordamchisiPolicy extends LeaderPolicy
{
    protected function prefix(): string
    {
        return 'hokim-yordamchilari';
    }
}
