<?php

/**
 * PHPStan stubs for WP-CLI (provided by the wp binary at runtime, never loaded by the plugin).
 *
 * @package SWCalendar
 */

class WP_CLI
{
    /**
     * @param string $message
     */
    public static function log($message): void
    {
    }

    /**
     * @param string $message
     */
    public static function success($message): void
    {
    }

    /**
     * @param string $message
     */
    public static function warning($message): void
    {
    }

    /**
     * @param string|\WP_Error $message
     * @param bool|int         $exit
     * @return ($exit is false ? void : never)
     */
    public static function error($message, $exit = true)
    {
    }

    /**
     * @param int $return_code
     * @return never
     */
    public static function halt($return_code)
    {
    }

    /**
     * @param string $message
     */
    public static function line($message): void
    {
    }

    /**
     * @param string $name
     * @param callable|object|string $callable
     * @param array<string, mixed> $args
     */
    public static function add_command($name, $callable, $args = []): bool
    {
        return true;
    }
}
