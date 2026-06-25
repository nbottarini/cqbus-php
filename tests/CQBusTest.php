<?php

declare(strict_types=1);

namespace Botta\CQBus\Tests;

use Botta\CQBus\CQBus;
use Botta\CQBus\Exceptions\CannotAccessInternalRequest;
use Botta\CQBus\Exceptions\HandlerAlreadyRegistered;
use Botta\CQBus\Exceptions\InvalidHandlerSignature;
use Botta\CQBus\Exceptions\RequestHandlerNotRegistered;
use Botta\CQBus\ExecutionContext;
use Botta\CQBus\Identity\Identity;
use Botta\CQBus\Middleware;
use Botta\CQBus\MiddlewarePriority;
use Botta\CQBus\Requests\Command;
use Botta\CQBus\Requests\Handlers\ContextAwareRequestHandler;
use Botta\CQBus\Requests\Handlers\RequestHandler;
use Botta\CQBus\Requests\InternalRequest;
use Botta\CQBus\Requests\PureCommand;
use Botta\CQBus\Requests\Query;
use Botta\CQBus\Requests\Request;

it('executes request with registered handler and returns result', function (): void {
    $bus = new CQBus();
    $bus->registerHandler(CreateFullNameHandler::class);

    $result = $bus->execute(new CreateFullName('John', 'Doe'));

    expect($result)->toBe('John Doe');
});

it('context-aware handlers can receive data via execution context', function (): void {
    $bus = new CQBus();
    $bus->registerContextAwareHandler(MyCommandAwareHandler::class);

    $result = $bus->execute(new MyCommand(), ExecutionContext::empty()->with('sample-key', 'some-value'));

    expect($result)->toBe('some-value');
});

it('request handlers receive the identity executing the request', function (): void {
    $bus = new CQBus();
    $bus->registerHandler(IdentityCommandHandler::class);
    $alice = new UserIdentity('Alice');

    $result = $bus->execute(new IdentityCommand(), ExecutionContext::empty()->withIdentity($alice));

    expect($result)->toBe($alice);
});

it('fails if request handler is not registered', function (): void {
    $bus = new CQBus();

    $bus->execute(new MyCommand());
})->throws(RequestHandlerNotRegistered::class);

it('executes middleware', function (): void {
    $log = new Log();
    $bus = new CQBus();
    $bus->registerHandler(CreateFullNameLoggingHandler::class, fn (): CreateFullNameLoggingHandler => new CreateFullNameLoggingHandler($log));
    $bus->registerMiddleware(new LoggingMiddleware($log));

    $bus->execute(new CreateFullName('John', 'Doe'));

    expect($log->entries)->toBe(['before', 'John Doe', 'after']);
});

it('executes multiple middlewares in reverse registration order', function (): void {
    $log = new Log();
    $bus = new CQBus();
    $bus->registerHandler(CreateFullNameLoggingHandler::class, fn (): CreateFullNameLoggingHandler => new CreateFullNameLoggingHandler($log));
    $bus->registerMiddleware(new LoggingMiddleware($log, '1'));
    $bus->registerMiddleware(new LoggingMiddleware($log, '2'));

    $bus->execute(new CreateFullName('John', 'Doe'));

    expect($log->entries)->toBe(['before2', 'before1', 'John Doe', 'after1', 'after2']);
});

it('middlewares can pass data between each other via the execution context', function (): void {
    $log = new Log();
    $receivedValue = null;
    $bus = new CQBus();
    $bus->registerHandler(CreateFullNameLoggingHandler::class, fn (): CreateFullNameLoggingHandler => new CreateFullNameLoggingHandler($log));
    $bus->registerMiddleware(new CallbackMiddleware(static function (ExecutionContext $context) use (&$receivedValue): void {
        $receivedValue = $context['sample-key'];
    }));
    $bus->registerMiddleware(new CallbackMiddleware(static function (ExecutionContext $context): void {
        $context['sample-key'] = 'some-value';
    }));

    $bus->execute(new CreateFullName('John', 'Doe'));

    expect($receivedValue)->toBe('some-value');
});

it('executes multiple middlewares in reverse registration order by priority', function (): void {
    $log = new Log();
    $bus = new CQBus();
    $bus->registerHandler(CreateFullNameLoggingHandler::class, fn (): CreateFullNameLoggingHandler => new CreateFullNameLoggingHandler($log));
    $bus->registerMiddleware(new LoggingMiddleware($log, 'Low'), MiddlewarePriority::Low);
    $bus->registerMiddleware(new LoggingMiddleware($log, 'VeryHigh1'), MiddlewarePriority::VeryHigh);
    $bus->registerMiddleware(new LoggingMiddleware($log, 'VeryHigh2'), MiddlewarePriority::VeryHigh);
    $bus->registerMiddleware(new LoggingMiddleware($log, 'Normal'));

    $bus->execute(new CreateFullName('John', 'Doe'));

    expect($log->entries)->toBe([
        'beforeVeryHigh2',
        'beforeVeryHigh1',
        'beforeNormal',
        'beforeLow',
        'John Doe',
        'afterLow',
        'afterNormal',
        'afterVeryHigh1',
        'afterVeryHigh2',
    ]);
});

it('fails if request is internal and internal requests are not enabled', function (): void {
    $bus = new CQBus();
    $bus->registerHandler(MyInternalCommandHandler::class);

    $bus->execute(new MyInternalCommand());
})->throws(CannotAccessInternalRequest::class);

it('allows internal requests when enabled', function (): void {
    $bus = new CQBus(internalRequestsEnabled: true);
    $bus->registerHandler(MyInternalCommandHandler::class);

    $result = $bus->execute(new MyInternalCommand());

    expect($result)->toBeNull();
});

it('uses custom resolver to instantiate handlers', function (): void {
    $dependency = new Prefixer('resolved: ');
    $bus = new CQBus(
        resolver: static fn (string $class): object => new $class($dependency),
    );
    $bus->registerHandler(ResolverBackedHandler::class);

    $result = $bus->execute(new ResolverBackedCommand('Alice'));

    expect($result)->toBe('resolved: Alice');
});

it('explicit factory overrides resolver', function (): void {
    $bus = new CQBus(
        resolver: static fn (string $class): object => new $class(new Prefixer('resolver: ')),
    );
    $bus->registerHandler(ResolverBackedHandler::class, static fn (): ResolverBackedHandler => new ResolverBackedHandler(new Prefixer('factory: ')));

    $result = $bus->execute(new ResolverBackedCommand('Alice'));

    expect($result)->toBe('factory: Alice');
});

it('fails when registering two handlers for the same request', function (): void {
    $bus = new CQBus();
    $bus->registerHandler(CreateFullNameHandler::class);
    $bus->registerHandler(AlternativeCreateFullNameHandler::class);
})->throws(HandlerAlreadyRegistered::class);

it('fails when handler signature does not match the contract', function (): void {
    $bus = new CQBus();
    $bus->registerHandler(InvalidHandler::class);
})->throws(InvalidHandlerSignature::class);

final readonly class CreateFullName implements Command
{
    public function __construct(
        public string $firstName,
        public string $lastName,
    ) {
    }
}

final class CreateFullNameHandler implements RequestHandler
{
    public function execute(CreateFullName $request, Identity $identity): string
    {
        return $request->firstName . ' ' . $request->lastName;
    }
}

final class AlternativeCreateFullNameHandler implements RequestHandler
{
    public function execute(CreateFullName $request, Identity $identity): string
    {
        return strtoupper($request->firstName . ' ' . $request->lastName);
    }
}

final class MyCommand implements PureCommand
{
}

#[InternalRequest]
final class MyInternalCommand implements Command
{
}

final class MyInternalCommandHandler implements RequestHandler
{
    public function execute(MyInternalCommand $request, Identity $identity): null
    {
        return null;
    }
}

final class MyCommandAwareHandler implements ContextAwareRequestHandler
{
    public function execute(MyCommand $request, ExecutionContext $context): string
    {
        return $context->get('sample-key');
    }
}

final class IdentityCommand implements Query
{
}

final class IdentityCommandHandler implements RequestHandler
{
    public function execute(IdentityCommand $request, Identity $identity): Identity
    {
        return $identity;
    }
}

final class CreateFullNameLoggingHandler implements RequestHandler
{
    public function __construct(private Log $log)
    {
    }

    public function execute(CreateFullName $request, Identity $identity): string
    {
        $result = $request->firstName . ' ' . $request->lastName;
        $this->log->entries[] = $result;

        return $result;
    }
}

final class LoggingMiddleware implements Middleware
{
    public function __construct(
        private Log $log,
        private string $suffix = '',
    ) {
    }

    public function process(Request $request, callable $next, ExecutionContext $context): mixed
    {
        $this->log->entries[] = 'before' . $this->suffix;
        $result = $next($request);
        $this->log->entries[] = 'after' . $this->suffix;

        return $result;
    }
}

final class CallbackMiddleware implements Middleware
{
    public function __construct(private $callback)
    {
    }

    public function process(Request $request, callable $next, ExecutionContext $context): mixed
    {
        ($this->callback)($context);

        return $next($request);
    }
}

final readonly class UserIdentity implements Identity
{
    public function __construct(private string $value)
    {
    }

    public function name(): string
    {
        return $this->value;
    }

    public function isAuthenticated(): bool
    {
        return true;
    }

    public function authenticationType(): ?string
    {
        return null;
    }

    public function roles(): array
    {
        return [];
    }

    public function properties(): array
    {
        return [];
    }
}

final readonly class ResolverBackedCommand implements Command
{
    public function __construct(public string $name)
    {
    }
}

final class ResolverBackedHandler implements RequestHandler
{
    public function __construct(private Prefixer $prefixer)
    {
    }

    public function execute(ResolverBackedCommand $request, Identity $identity): string
    {
        return $this->prefixer->prefix($request->name);
    }
}

final readonly class Prefixer
{
    public function __construct(private string $prefix)
    {
    }

    public function prefix(string $value): string
    {
        return $this->prefix . $value;
    }
}

final class Log
{
    /**
     * @var list<string>
     */
    public array $entries = [];
}

final class InvalidHandler implements RequestHandler
{
    public function execute(string $request, Identity $identity): string
    {
        return $request;
    }
}
