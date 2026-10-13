<?php

if (!function_exists('fileExists')) {
    function fileExists($file="") {
        for ($i=0; $i<10 && !file_exists($file); $i++) {
            $file = "../" . $file;
        } return $file;
    }
}

require_once fileExists('app/config/App.php');

class Database extends App {
    private static $host, $user, $pass, $dbname;

    public function __construct() {
        parent::__construct();
        self::$host = getenv('DB_HOST');
        self::$user = getenv('DB_USERNAME');
        self::$pass = getenv('DB_PASSWORD');
        self::$dbname = getenv('DB_DATABASE');
    }

    public function connection() {
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
