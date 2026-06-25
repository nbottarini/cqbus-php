<?php

declare(strict_types=1);

namespace Botta\CQBus;

use ArrayAccess;
use Botta\CQBus\Identity\AnonymousIdentity;
use Botta\CQBus\Identity\Identity;
use InvalidArgumentException;
use LogicException;

final class ExecutionContext implements ArrayAccess
{
    private Identity $identity;

    /**
     * @var array<string, mixed>
     */
    private array $data = [];

    public function __construct(?Identity $identity = null)
    {
        $this->identity = $identity ?? new AnonymousIdentity();
    }

    public static function empty(): self
    {
        return new self();
    }

    public function identity(): Identity
    {
        return $this->identity;
    }

    public function setIdentity(Identity $identity): void
    {
        $this->identity = $identity;
    }

    public function withIdentity(Identity $identity): self
    {
        $this->identity = $identity;

        return $this;
    }

    public function with(string $key, mixed $value): self
    {
        $this->set($key, $value);

        return $this;
    }

    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    public function setObject(object $value): void
    {
        $this->set($value::class, $value);
    }

    public function withObject(object $value): self
    {
        $this->setObject($value);

        return $this;
    }

    public function getObject(string $class): ?object
    {
        $value = $this->get($class);

        return is_object($value) ? $value : null;
    }

    public function hasObject(string $class): bool
    {
        return $this->getObject($class) !== null;
    }

    public function offsetExists(mixed $offset): bool
    {
        return $this->has($this->normalizeOffset($offset));
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->get($this->normalizeOffset($offset));
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        if ($offset === null) {
            throw new LogicException('ExecutionContext does not support null offsets');
        }

        $this->set($this->normalizeOffset($offset), $value);
    }

    public function offsetUnset(mixed $offset): void
    {
        unset($this->data[$this->normalizeOffset($offset)]);
    }

    private function normalizeOffset(mixed $offset): string
    {
        if (!is_string($offset)) {
            throw new InvalidArgumentException('ExecutionContext offsets must be strings');
        }

        return $offset;
    }
}
