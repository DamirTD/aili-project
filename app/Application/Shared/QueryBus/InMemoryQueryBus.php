<?php

namespace App\Application\Shared\QueryBus;

use Illuminate\Contracts\Container\Container;
use RuntimeException;

class InMemoryQueryBus implements QueryBus
{
    /**
     * @param array<class-string, class-string> $handlersMap
     */
    public function __construct(
        private readonly Container $container,
        private readonly array $handlersMap
    ) {
    }

    public function ask(object $query): mixed
    {
        $queryClass = $query::class;
        $handlerClass = $this->handlersMap[$queryClass] ?? null;

        if (! is_string($handlerClass)) {
            throw new RuntimeException("Handler for query {$queryClass} is not registered.");
        }

        $handler = $this->container->make($handlerClass);

        if (! is_callable($handler)) {
            throw new RuntimeException("Handler {$handlerClass} must be invokable.");
        }

        return $handler($query);
    }
}

