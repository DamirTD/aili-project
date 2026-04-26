<?php

namespace App\Application\Shared\QueryBus;

interface QueryBus
{
    public function ask(object $query): mixed;
}

