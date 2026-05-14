<?php
namespace Illuminate\Database\Query;

/**
 * PHPStan stub for the Illuminate (Laravel) query builder shape used by FPS.
 * Methods are typed permissively (return $this for fluent calls; mixed for
 * terminal accessors) so call sites do not require precise type docs.
 */
class Builder
{
    /** @return $this */
    public function select(...$columns) { return $this; }
    /** @return $this */
    public function selectRaw(string $expression, array $bindings = []) { return $this; }
    /** @return $this */
    public function where($column, $operatorOrValue = null, $value = null) { return $this; }
    /** @return $this */
    public function whereIn(string $column, $values) { return $this; }
    /** @return $this */
    public function whereNotIn(string $column, $values) { return $this; }
    /** @return $this */
    public function whereNotNull(string $column) { return $this; }
    /** @return $this */
    public function whereNull(string $column) { return $this; }
    /** @return $this */
    public function whereExists($callback) { return $this; }
    /** @return $this */
    public function whereBetween(string $column, array $values) { return $this; }
    /** @return $this */
    public function orWhere(...$args) { return $this; }
    /** @return $this */
    public function orderBy(string $column, string $dir = 'asc') { return $this; }
    /** @return $this */
    public function orderByDesc(string $column) { return $this; }
    /** @return $this */
    public function groupBy(...$columns) { return $this; }
    /** @return $this */
    public function having(...$args) { return $this; }
    /** @return $this */
    public function limit(int $n) { return $this; }
    /** @return $this */
    public function offset(int $n) { return $this; }
    /** @return $this */
    public function leftJoin(string $table, ...$args) { return $this; }
    /** @return $this */
    public function join(string $table, ...$args) { return $this; }
    /** @return $this */
    public function distinct(...$args) { return $this; }
    /** @return $this */
    public function whereRaw(string $sql, array $bindings = []) { return $this; }
    /** @return $this */
    public function havingRaw(string $sql, array $bindings = []) { return $this; }
    /** @return $this */
    public function orderByRaw(string $sql, array $bindings = []) { return $this; }
    /** @return $this */
    public function groupByRaw(string $sql, array $bindings = []) { return $this; }
    /** @return bool */
    public function truncate(): bool { return true; }
    /** @return $this */
    public function lockForUpdate() { return $this; }
    /** @return $this */
    public function sharedLock() { return $this; }
    /** @return $this */
    public function chunk(int $count, callable $callback) { return $this; }
    /** @return mixed */
    public function find($id, array $columns = ['*']) { return null; }

    /** @return mixed */
    public function value(string $column) { return null; }
    /** @return \stdClass|null */
    public function first(array $columns = ['*']) { return null; }
    /** @return \Illuminate\Support\Collection */
    public function get(array $columns = ['*']) { return new \Illuminate\Support\Collection(); }
    /** @return mixed */
    public function pluck(string $column, ?string $key = null) { return null; }
    /** @return int */
    public function count(string $columns = '*'): int { return 0; }
    /** @return int|float|null */
    public function sum(string $column) { return 0; }
    /** @return int|float|null */
    public function avg(string $column) { return 0; }
    /** @return int|float|null */
    public function max(string $column) { return 0; }
    /** @return int|float|null */
    public function min(string $column) { return 0; }

    /** @return int */
    public function insert(array $values): int { return 0; }
    /** @return int */
    public function insertGetId(array $values, ?string $sequence = null): int { return 0; }
    /** @return int */
    public function insertOrIgnore(array $values): int { return 0; }
    /** @return int */
    public function update(array $values): int { return 0; }
    /** @return int */
    public function updateOrInsert(array $attributes, array $values = []): bool { return false; }
    /** @return int */
    public function delete($id = null): int { return 0; }
    /** @return int */
    public function increment(string $column, int $amount = 1, array $extra = []): int { return 0; }
    /** @return int */
    public function decrement(string $column, int $amount = 1, array $extra = []): int { return 0; }

    /** @return bool */
    public function exists(): bool { return false; }
}

class Expression
{
    public function __construct(mixed $value) {}
    public function getValue(): mixed { return null; }
}
