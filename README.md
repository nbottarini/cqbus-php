<p align="center">
  <img alt="CQBus" src="docs/logo_readme.png" width="60%">
</p>

<!-- A spacer -->
<p>&nbsp;</p>

<h2 align="center">Simple PHP command/query bus</h2>

[![Packagist](https://img.shields.io/packagist/v/botta/cqbus.svg)](https://packagist.org/packages/botta/cqbus)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)
[![CI Status](https://github.com/nbottarini/cqbus-php/actions/workflows/main.yml/badge.svg?branch=main)](https://github.com/nbottarini/cqbus-php/actions?query=branch%3Amain+workflow%3Aci)

## Installation

```bash
composer require botta/cqbus
```

## Usage

### Commands
```php
use Botta\CQBus\CQBus;
use Botta\CQBus\Identity\Identity;
use Botta\CQBus\Requests\Command;
use Botta\CQBus\Requests\Handlers\RequestHandler;

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

$cqBus = new CQBus();
$cqBus->registerHandler(CreateFullNameHandler::class);

$cqBus->execute(new CreateFullName('John', 'Doe')); // returns 'John Doe'
```

### Queries
```php
use Botta\CQBus\CQBus;
use Botta\CQBus\Identity\Identity;
use Botta\CQBus\Requests\Handlers\RequestHandler;
use Botta\CQBus\Requests\Query;

final class GetNews implements Query
{
}

final class GetNewsHandler implements RequestHandler
{
    public function execute(GetNews $request, Identity $identity): array
    {
        return ['news 1', 'news 2'];
    }
}

$cqBus = new CQBus();
$cqBus->registerHandler(GetNewsHandler::class);

$cqBus->execute(new GetNews()); // returns ['news 1', 'news 2']
```

### Execution Identity

```php
use Botta\CQBus\CQBus;
use Botta\CQBus\ExecutionContext;
use Botta\CQBus\Identity\Identity;
use Botta\CQBus\Requests\Command;
use Botta\CQBus\Requests\Handlers\RequestHandler;

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

final class MyCommand implements Command
{
}

final class MyCommandHandler implements RequestHandler
{
    public function execute(MyCommand $request, Identity $identity): string
    {
        return $identity->name();
    }
}

$cqBus = new CQBus();
$cqBus->registerHandler(MyCommandHandler::class);

$cqBus->execute(new MyCommand(), ExecutionContext::empty()->withIdentity(new UserIdentity('Alice'))); // returns 'Alice'
$cqBus->execute(new MyCommand()); // returns 'Anonymous'
```

### Context-aware handlers

```php
use Botta\CQBus\CQBus;
use Botta\CQBus\ExecutionContext;
use Botta\CQBus\Requests\Command;
use Botta\CQBus\Requests\Handlers\ContextAwareRequestHandler;

final class MyCommand implements Command
{
}

final class MyCommandHandler implements ContextAwareRequestHandler
{
    public function execute(MyCommand $request, ExecutionContext $context): string
    {
        return $context->get('some-key');
    }
}

$cqBus = new CQBus();
$cqBus->registerContextAwareHandler(MyCommandHandler::class);

$cqBus->execute(new MyCommand(), ExecutionContext::empty()->with('some-key', 'some-value')); // returns 'some-value'
```

### Middlewares

```php
use Botta\CQBus\CQBus;
use Botta\\CQBus\\ExecutionContext;
use Botta\\CQBus\\Identity\\Identity;
use Botta\\CQBus\\Middleware;
use Botta\CQBus\Requests\Command;
use Botta\CQBus\Requests\Handlers\RequestHandler;
use Botta\CQBus\Requests\Request;

final class MyCommand implements Command
{
}

final class MyCommandHandler implements RequestHandler
{
    public function execute(MyCommand $request, Identity $identity): string
    {
        return 'handler';
    }
}

final class Log
{
    /** @var list<string> */
    public array $entries = [];
}

final class LoggingMiddleware implements Middleware
{
    public function __construct(private Log $log, private string $suffix = '')
    {
    }

    public function process(Request $request, callable $next, ExecutionContext $context): mixed
    {
        $this->log->entries[] = 'before' . $this->suffix;
        $result = $next($request);
        $this->log->entries[] = 'after' . $this->suffix;

        return $result;
    }
}

$log = new Log();
$cqBus = new CQBus();
$cqBus->registerHandler(MyCommandHandler::class);
$cqBus->registerMiddleware(new LoggingMiddleware($log, '1'));
$cqBus->registerMiddleware(new LoggingMiddleware($log, '2'));

$cqBus->execute(new MyCommand());

// $log->entries contains ['before2', 'before1', 'handler', 'after1', 'after2']
```

Middleware registration by priority
```php
use Botta\CQBus\MiddlewarePriority;

$log = new Log();
$cqBus = new CQBus();
$cqBus->registerHandler(MyCommandHandler::class);
$cqBus->registerMiddleware(new LoggingMiddleware($log, 'Low'), MiddlewarePriority::Low);
$cqBus->registerMiddleware(new LoggingMiddleware($log, 'VeryHigh1'), MiddlewarePriority::VeryHigh);
$cqBus->registerMiddleware(new LoggingMiddleware($log, 'VeryHigh2'), MiddlewarePriority::VeryHigh);
$cqBus->registerMiddleware(new LoggingMiddleware($log, 'Normal'));

$cqBus->execute(new MyCommand());

// $log->entries contains:
//   'beforeVeryHigh2'
//   'beforeVeryHigh1'
//   'beforeNormal'
//   'beforeLow'
//   'handler'
//   'afterLow'
//   'afterNormal'
//   'afterVeryHigh1'
//   'afterVeryHigh2'
```

## Handler instantiation

By default, `CQBus` resolves handlers and middleware classes with `new $class()`.

```php
$cqBus = new CQBus();
```

To integrate with a container, provide a resolver:

```php
$cqBus = new CQBus(
    resolver: fn (string $class): object => $container->get($class),
);
```

You can also override instantiation for a specific handler:

```php
$cqBus->registerHandler(
    CreateFullNameHandler::class,
    fn (): CreateFullNameHandler => new CreateFullNameHandler($repository),
);
```

The explicit factory takes precedence over the global resolver.

## Internal requests

```php
use Botta\CQBus\Requests\Command;
use Botta\CQBus\Requests\InternalRequest;

#[InternalRequest]
final class RebuildProjection implements Command
{
}
```

```php
$cqBus = new CQBus(internalRequestsEnabled: true);
```

