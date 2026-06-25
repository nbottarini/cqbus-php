<?php

declare(strict_types=1);

namespace Botta\CQBus;

use Botta\CQBus\Exceptions\CannotAccessInternalRequest;
use Botta\CQBus\Exceptions\HandlerAlreadyRegistered;
use Botta\CQBus\Exceptions\InvalidHandlerSignature;
use Botta\CQBus\Exceptions\InvalidMiddlewareSignature;
use Botta\CQBus\Exceptions\RequestHandlerNotRegistered;
use Botta\CQBus\Identity\Identity;
use Botta\CQBus\Internal\HandlerDefinition;
use Botta\CQBus\Internal\MiddlewareDefinition;
use Botta\CQBus\Requests\Handlers\ContextAwareRequestHandler;
use Botta\CQBus\Requests\Handlers\RequestHandler;
use Botta\CQBus\Requests\InternalRequest;
use Botta\CQBus\Requests\Request;
use Closure;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionType;

final class CQBus
{
    /**
     * @var array<string, HandlerDefinition>
     */
    private array $handlers = [];

    /**
     * @var array<int, list<MiddlewareDefinition>>
     */
    private array $middlewares = [];

    /**
     * @var callable(string):object
     */
    private $resolver;

    public function __construct(
        ?callable $resolver = null,
        private bool $internalRequestsEnabled = false,
    ) {
        $this->resolver = $resolver ?? static fn (string $class): object => new $class();
    }

    public function registerHandler(string $handlerClass, ?callable $factory = null): void
    {
        $requestClass = $this->validateHandlerClass($handlerClass, RequestHandler::class, Identity::class);
        $this->failIfHandlerAlreadyRegistered($requestClass);

        $this->handlers[$requestClass] = new HandlerDefinition(
            $handlerClass,
            false,
            $factory !== null ? Closure::fromCallable($factory) : null,
        );
    }

    public function registerContextAwareHandler(string $handlerClass, ?callable $factory = null): void
    {
        $requestClass = $this->validateHandlerClass($handlerClass, ContextAwareRequestHandler::class, ExecutionContext::class);
        $this->failIfHandlerAlreadyRegistered($requestClass);

        $this->handlers[$requestClass] = new HandlerDefinition(
            $handlerClass,
            true,
            $factory !== null ? Closure::fromCallable($factory) : null,
        );
    }

    public function registerMiddleware(
        string|Middleware $middleware,
        MiddlewarePriority $priority = MiddlewarePriority::Normal,
        ?callable $factory = null,
    ): void {
        if (!isset($this->middlewares[$priority->value])) {
            $this->middlewares[$priority->value] = [];
        }

        if (is_string($middleware)) {
            $this->validateMiddlewareClass($middleware);
            $this->middlewares[$priority->value][] = new MiddlewareDefinition(
                middlewareClass: $middleware,
                factory: $factory !== null ? Closure::fromCallable($factory) : null,
            );

            return;
        }

        if ($factory !== null) {
            throw new InvalidMiddlewareSignature('Cannot provide a middleware factory when registering an instance');
        }

        $this->middlewares[$priority->value][] = new MiddlewareDefinition(instance: $middleware);
    }

    public function execute(Request $request, ?ExecutionContext $context = null): mixed
    {
        $context ??= ExecutionContext::empty();
        $this->failIfInternalRequest($request::class);

        $definition = $this->handlers[$request::class] ?? null;
        if ($definition === null) {
            throw new RequestHandlerNotRegistered($request::class);
        }

        $execute = function (Request $currentRequest) use ($definition, $context): mixed {
            $handler = $this->resolveHandler($definition);

            return $definition->contextAware
                ? $handler->execute($currentRequest, $context)
                : $handler->execute($currentRequest, $context->identity());
        };

        foreach ($this->sortedMiddlewarePriorities() as $priority) {
            foreach ($this->middlewares[$priority] ?? [] as $middlewareDefinition) {
                $previous = $execute;
                $execute = fn (Request $currentRequest): mixed => $this->resolveMiddleware($middlewareDefinition)
                    ->process($currentRequest, $previous, $context);
            }
        }

        return $execute($request);
    }

    public function enableInternalRequests(): void
    {
        $this->internalRequestsEnabled = true;
    }

    public function disableInternalRequests(): void
    {
        $this->internalRequestsEnabled = false;
    }

    private function resolveHandler(HandlerDefinition $definition): object
    {
        $handler = $definition->factory !== null
            ? ($definition->factory)()
            : ($this->resolver)($definition->handlerClass);

        if (!$handler instanceof $definition->handlerClass) {
            throw new InvalidHandlerSignature("Factory for {$definition->handlerClass} must return an instance of {$definition->handlerClass}");
        }

        return $handler;
    }

    private function resolveMiddleware(MiddlewareDefinition $definition): Middleware
    {
        if ($definition->instance !== null) {
            return $definition->instance;
        }

        $class = $definition->middlewareClass;
        $middleware = $definition->factory !== null
            ? ($definition->factory)()
            : ($this->resolver)($class);

        if (!$middleware instanceof $class) {
            throw new InvalidMiddlewareSignature("Factory for {$class} must return an instance of {$class}");
        }

        return $middleware;
    }

    private function failIfHandlerAlreadyRegistered(string $requestClass): void
    {
        if (isset($this->handlers[$requestClass])) {
            throw new HandlerAlreadyRegistered($requestClass);
        }
    }

    private function failIfInternalRequest(string $requestClass): void
    {
        if ($this->internalRequestsEnabled) {
            return;
        }

        $reflection = new ReflectionClass($requestClass);
        $attributes = $reflection->getAttributes(InternalRequest::class, ReflectionAttribute::IS_INSTANCEOF);

        if ($attributes !== []) {
            throw new CannotAccessInternalRequest($requestClass);
        }
    }

    private function validateMiddlewareClass(string $middlewareClass): void
    {
        if (!class_exists($middlewareClass)) {
            throw new InvalidMiddlewareSignature("Middleware class {$middlewareClass} does not exist");
        }

        if (!is_a($middlewareClass, Middleware::class, true)) {
            throw new InvalidMiddlewareSignature("Middleware class {$middlewareClass} must implement " . Middleware::class);
        }
    }

    private function validateHandlerClass(
        string $handlerClass,
        string $expectedInterface,
        string $expectedSecondParameterType,
    ): string {
        if (!class_exists($handlerClass)) {
            throw new InvalidHandlerSignature("Handler class {$handlerClass} does not exist");
        }

        if (!is_a($handlerClass, $expectedInterface, true)) {
            throw new InvalidHandlerSignature("Handler class {$handlerClass} must implement {$expectedInterface}");
        }

        try {
            $method = new ReflectionMethod($handlerClass, 'execute');
        } catch (ReflectionException $exception) {
            throw new InvalidHandlerSignature("Handler class {$handlerClass} must declare an execute() method", 0, $exception);
        }

        $parameters = $method->getParameters();
        if (count($parameters) !== 2) {
            throw new InvalidHandlerSignature("Handler {$handlerClass}::execute() must declare exactly two parameters");
        }

        $requestType = $parameters[0]->getType();
        $requestClass = $this->extractNamedType($requestType, "First parameter of {$handlerClass}::execute()");
        if (!is_a($requestClass, Request::class, true)) {
            throw new InvalidHandlerSignature("First parameter of {$handlerClass}::execute() must implement " . Request::class);
        }

        $secondType = $parameters[1]->getType();
        $secondParameterClass = $this->extractNamedType($secondType, "Second parameter of {$handlerClass}::execute()");
        if ($secondParameterClass !== $expectedSecondParameterType) {
            throw new InvalidHandlerSignature("Second parameter of {$handlerClass}::execute() must be {$expectedSecondParameterType}");
        }

        return $requestClass;
    }

    private function extractNamedType(?ReflectionType $type, string $label): string
    {
        if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
            throw new InvalidHandlerSignature("{$label} must be a non-builtin named type");
        }

        return $type->getName();
    }

    /**
     * @return list<int>
     */
    private function sortedMiddlewarePriorities(): array
    {
        $priorities = array_map(static fn (MiddlewarePriority $priority): int => $priority->value, MiddlewarePriority::cases());
        sort($priorities);

        return $priorities;
    }
}
