<?php

declare(strict_types=1);

namespace Botta\CQBus\Tests;

use Botta\CQBus\ExecutionContext;

it('can get data', function (): void {
    $context = new ExecutionContext();
    $context['some-key'] = 'some-value';

    expect($context['some-key'])->toBe('some-value');
});

it('can get data by type name', function (): void {
    $context = new ExecutionContext();
    $context->setObject(new SampleUser('alice'));

    expect($context->getObject(SampleUser::class)?->name)->toBe('alice');
});

it('returns null if type not set', function (): void {
    $context = new ExecutionContext();

    expect($context->getObject(SampleUser::class))->toBeNull();
});

it('supports has by string key', function (): void {
    $context = new ExecutionContext();
    $context['some-key'] = 'some-value';

    expect($context->has('some-key'))->toBeTrue()
        ->and($context->has('some-other-key'))->toBeFalse();
});

it('supports hasObject', function (): void {
    $context = new ExecutionContext();
    $context->setObject(new SampleUser('alice'));

    expect($context->hasObject(SampleUser::class))->toBeTrue();
});

final readonly class SampleUser
{
    public function __construct(public string $name)
    {
    }
}
