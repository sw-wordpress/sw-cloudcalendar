<?php

// phpcs:disable PSR1.Classes.ClassDeclaration.MissingNamespace,Squiz.Classes.ValidClassName.NotCamelCaps,PSR1.Methods.CamelCapsMethodName.NotCamelCaps,PSR2.Methods.MethodDeclaration.Underscore -- stand-in for WordPress wpdb in unit tests that do not boot WordPress.

/**
 * Minimal wpdb stand-in for unit tests that cannot boot WordPress.
 *
 * prepare()/_real_escape() follow WordPress 6.8.3: a non-scalar value
 * including null is escaped to an empty string, so `%s` + null becomes ''.
 * insert() writes SQL NULL for null column values, matching wpdb::insert().
 */

declare(strict_types=1);

if (! function_exists('wp_json_encode')) {
    /**
     * @param mixed $data
     */
    function wp_json_encode($data, $options = 0, $depth = 512): string|false
    {
        return json_encode($data, $options, $depth);
    }
}

if (! class_exists('wpdb')) {
    class wpdb
    {
        public string $prefix = 'wp_';
        public ?int $insert_id = null;
        /** @var list<string> */
        public array $queries = array();

        /**
         * @param string     $query
         * @param mixed      ...$args
         */
        public function prepare($query, ...$args)
        {
            if (isset($args[0]) && is_array($args[0])) {
                $args = $args[0];
            }
            $escaped = array();
            foreach ($args as $value) {
                if (is_int($value) || is_float($value)) {
                    $escaped[] = $value;
                } else {
                    $escaped[] = "'" . $this->_real_escape($value) . "'";
                }
            }
            return vsprintf($query, $escaped);
        }

        /**
         * @param mixed $data
         */
        public function _real_escape($data): string
        {
            if (! is_scalar($data)) {
                return '';
            }
            return addslashes((string) $data);
        }

        /**
         * @param mixed $sql
         */
        public function query($sql): int
        {
            $this->queries[] = (string) $sql;
            return 1;
        }

        /**
         * @param string               $table
         * @param array<string, mixed> $data
         * @param mixed                $format
         */
        public function insert($table, $data, $format = null): int
        {
            $parts = array();
            foreach ($data as $col => $val) {
                if (is_null($val)) {
                    $parts[] = "`{$col}` = NULL";
                } else {
                    $parts[] = "`{$col}` = '" . $this->_real_escape($val) . "'";
                }
            }
            $this->queries[] = "INSERT INTO {$table} SET " . implode(', ', $parts);
            $this->insert_id = 1;
            return 1;
        }
    }
}
