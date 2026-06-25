<?php

declare(strict_types=1);

namespace Botta\CQBus\Identity;

final class AnonymousIdentity implements Identity
{
    public function name(): string
    {
        return 'Anonymous';
    }

    public function isAuthenticated(): bool
    {
        return false;
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
