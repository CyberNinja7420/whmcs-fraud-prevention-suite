<?php
namespace Illuminate\Database\Schema;

/**
 * PHPStan stub for the Illuminate Schema builder + blueprint shape used by FPS.
 */
class Builder
{
    /** @return bool */
    public function hasTable(string $table): bool { return false; }
    /** @return bool */
    public function hasColumn(string $table, string $column): bool { return false; }
    /** @return void */
    public function create(string $table, callable $callback): void {}
    /** @return void */
    public function table(string $table, callable $callback): void {}
    /** @return void */
    public function drop(string $table): void {}
    /** @return void */
    public function dropIfExists(string $table): void {}
}

class Blueprint
{
    /** @return mixed */
    public function id(string $column = 'id') { return null; }
    /** @return mixed */
    public function increments(string $column) { return null; }
    /** @return $this */
    public function string(string $column, ?int $length = null) { return $this; }
    /** @return $this */
    public function integer(string $column) { return $this; }
    /** @return $this */
    public function bigInteger(string $column) { return $this; }
    /** @return $this */
    public function tinyInteger(string $column) { return $this; }
    /** @return $this */
    public function smallInteger(string $column) { return $this; }
    /** @return $this */
    public function decimal(string $column, int $precision = 8, int $scale = 2) { return $this; }
    /** @return $this */
    public function float(string $column) { return $this; }
    /** @return $this */
    public function double(string $column) { return $this; }
    /** @return $this */
    public function boolean(string $column) { return $this; }
    /** @return $this */
    public function text(string $column) { return $this; }
    /** @return $this */
    public function longText(string $column) { return $this; }
    /** @return $this */
    public function mediumText(string $column) { return $this; }
    /** @return $this */
    public function timestamp(string $column) { return $this; }
    /** @return $this */
    public function dateTime(string $column) { return $this; }
    /** @return $this */
    public function date(string $column) { return $this; }
    /** @return $this */
    public function timestamps() { return $this; }
    /** @return $this */
    public function nullable(bool $value = true) { return $this; }
    /** @return $this */
    public function default($value) { return $this; }
    /** @return $this */
    public function unique(...$args) { return $this; }
    /** @return $this */
    public function index(...$args) { return $this; }
    /** @return $this */
    public function primary(...$args) { return $this; }
    /** @return $this */
    public function unsigned() { return $this; }
    /** @return $this */
    public function useCurrent() { return $this; }
    /** @return $this */
    public function comment(string $comment) { return $this; }
    /** @return $this */
    public function dropColumn(string|array $columns) { return $this; }
}
