<?php
namespace Illuminate\Support;

/**
 * PHPStan stub for the Illuminate Collection shape used by FPS.
 *
 * @template TKey of array-key
 * @template TValue
 * @implements \IteratorAggregate<TKey, TValue>
 */
class Collection implements \IteratorAggregate, \Countable, \ArrayAccess
{
    public function __construct(array $items = []) {}
    public function isEmpty(): bool { return true; }
    public function isNotEmpty(): bool { return false; }
    public function count(): int { return 0; }
    public function first() { return null; }
    public function last() { return null; }
    public function values(): self { return $this; }
    public function keys(): self { return $this; }
    public function pluck(string $value, ?string $key = null): self { return $this; }
    public function map(callable $callback): self { return $this; }
    public function filter(?callable $callback = null): self { return $this; }
    public function each(callable $callback): self { return $this; }
    public function toArray(): array { return []; }
    public function all(): array { return []; }
    public function unique($key = null, bool $strict = false): self { return $this; }
    public function keyBy($keyBy): self { return $this; }
    public function groupBy($groupBy, bool $preserveKeys = false): self { return $this; }
    public function sortBy($callback, int $options = 0, bool $descending = false): self { return $this; }
    public function reduce(callable $callback, $initial = null) { return $initial; }
    public function reverse(): self { return $this; }
    public function slice(int $offset, ?int $length = null): self { return $this; }
    public function take(int $limit): self { return $this; }
    public function chunk(int $size): self { return $this; }
    public function contains($key, $operator = null, $value = null): bool { return false; }
    public function search($value, bool $strict = false) { return false; }
    public function flatten(int $depth = INF): self { return $this; }
    public function merge($items): self { return $this; }
    public function diff($items): self { return $this; }
    public function intersect($items): self { return $this; }
    public function getIterator(): \ArrayIterator { return new \ArrayIterator([]); }
    public function offsetExists($offset): bool { return false; }
    public function offsetGet($offset): mixed { return null; }
    public function offsetSet($offset, $value): void {}
    public function offsetUnset($offset): void {}
}
