<?php

class Database {
    private static $host = 'localhost';
    private static $dbname = 'inventory_main';
    private static $user = 'root';
    private static $pass = '';

    public static function connection() {
        try {
            date_default_timezone_set('Asia/Manila');
            $conn = new mysqli(self::$host, self::$user, self::$pass, self::$dbname);
            return $conn;
        } catch (Exception $e) {
            print 'Error!: ' . $e->getMessage() . '<br/>';
            die();
        }
    }
}
