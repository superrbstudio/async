<?php
use Superrb\Async\Handler;

if (!function_exists('async')) {
    /**
     * Run a closure asynchronously and disown it
     *
     * @param Closure $func
     * @param array ...$args
     *
     * @return Handler
     */
    function async(Closure $func, ...$args)
    {
        $handler = new Handler($func, true);
        $handler->run(...$args);

        return $handler;
    }
}