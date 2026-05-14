<?php
/**
 * PHPStan/Psalm stub: WHMCS AbstractWidget base class.
 *
 * Admin dashboard widgets extend this class. It lives in a separate
 * stub file because the main whmcs.php stub is in the global namespace
 * and mixing namespace blocks in a single stub file breaks PHPStan.
 */

namespace WHMCS\Module;

abstract class AbstractWidget
{
    /** @var string */
    protected $title = '';
    /** @var string */
    protected $description = '';
    /** @var int */
    protected $weight = 0;
    /** @var int */
    protected $columns = 1;
    /** @var bool */
    protected $cache = false;
    /** @var int */
    protected $cacheExpiry = 120;
    /** @var string */
    protected $requiredPermission = '';

    /** @return array<string, mixed> */
    abstract public function getData();

    /** @param array<string, mixed> $data
     *  @return string */
    abstract public function generateOutput($data);
}
