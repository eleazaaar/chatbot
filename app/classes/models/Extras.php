<?php

if (!function_exists('fileExists')) {
    function fileExists($file="") {
        for ($i=0; $i<10 && !file_exists($file); $i++) {
            $file = "../" . $file;
        } return $file;
    }
}

include_once fileExists('app/config/Database.class.php');

class Extras extends Database {
    protected $db;

    public function __construct() {
        parent::__construct();
        $this->db = Database::connection();
    }

    public static function fileExists($file="") {
        for ($i=0; $i<10 && !file_exists($file); $i++) {
            $file = "../" . $file;
        } return $file;
    }

    public function getInventory() {
        $sql = "SELECT i.id, i.item_code, i.item_name, i.item_type, i.inventory_category, i.brand, i.location, i.campus_id, i.course_id, d.size_code, d.quantity, d.price 
                FROM inventory_item i 
                LEFT JOIN inventory_item_details d ON d.item_id = i.id 
                ORDER BY i.item_name ASC, d.size_code ASC";
        $result = $this->db->query($sql);
        return $result->num_rows > 0 ? $result->fetch_all(MYSQLI_ASSOC) : [];
    }

    public function getCampusID($search = '') {
        $where = "WHERE 1";
        if ($search !== '') {
            $search = $this->db->real_escape_string($search);
            $where .= " AND description LIKE '%$search%'";
        }
        $sql = "SELECT id, description FROM inventory_campus $where ORDER BY description ASC";
        $result = $this->db->query($sql);
        return $result->num_rows > 0 ? $result->fetch_all(MYSQLI_ASSOC) : [];
    }

    public function getCourseID($search = '') {
        $where = "WHERE 1";
        if ($search !== '') {
            $search = $this->db->real_escape_string($search);
            $where .= " AND description LIKE '%$search%'";
        }
        $sql = "SELECT id, description FROM inventory_course $where ORDER BY description ASC"; 
        $result = $this->db->query($sql);
        return $result->num_rows > 0 ? $result->fetch_all(MYSQLI_ASSOC) : [];
    }
}