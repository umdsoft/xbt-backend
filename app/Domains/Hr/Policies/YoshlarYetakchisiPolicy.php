<?php

declare(strict_types=1);

namespace App\Domains\Hr\Policies;

class YoshlarYetakchisiPolicy extends LeaderPolicy
{
    protected function prefix(): string
    {
        return 'yoshlar';
    }
}
