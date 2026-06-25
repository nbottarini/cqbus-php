<?php

declare(strict_types=1);

namespace Botta\CQBus\Identity;

interface Identity
{
    public function name(): string;

    public function isAuthenticated(): bool;

    public function authenticationType(): ?string;

    /**
     * @return list<string>
     */
    public function roles(): array;

    /**
     * @return array<string, mixed>
     */
    public function properties(): array;
}
