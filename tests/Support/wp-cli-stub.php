<?php

if (! class_exists('WP_CLI')) {
    class WP_CLI
    {
        public static $errors = array();
        public static $success = array();
        public static $lines = array();

        public static function error($message)
        {
            self::$errors[] = $message;
            throw new \Exception($message);
        }

        public static function success($message)
        {
            self::$success[] = $message;
        }

        public static function log($message)
        {
            self::$lines[] = $message;
        }

        public static function line($message)
        {
            self::$lines[] = $message;
        }
    }
}
