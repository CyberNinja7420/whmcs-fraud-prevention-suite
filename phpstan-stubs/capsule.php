<?php
namespace WHMCS\Database;

/**
 * PHPStan stub for the WHMCS-vendored Illuminate Capsule facade.
 *
 * @method static \Illuminate\Database\Query\Builder table(string $table, ?string $as = null)
 * @method static \Illuminate\Database\Schema\Builder schema()
 * @method static \Illuminate\Database\Query\Expression raw(mixed $value)
 * @method static mixed connection(?string $name = null)
 */
class Capsule
{
    /** @return \Illuminate\Database\Query\Builder */
    public static function table(string $table, ?string $as = null) { return new \Illuminate\Database\Query\Builder(); }

    /** @return \Illuminate\Database\Schema\Builder */
    public static function schema() { return new \Illuminate\Database\Schema\Builder(); }

    /** @return \Illuminate\Database\Query\Expression */
    public static function raw($value) { return new \Illuminate\Database\Query\Expression($value); }

    /** @return mixed */
    public static function connection(?string $name = null) { return null; }

    /** @return mixed */
    public function addConnection(array $config, string $name = 'default') { return null; }

    /** @return void */
    public function setAsGlobal(): void {}

    /** @return void */
    public function bootEloquent(): void {}
}
