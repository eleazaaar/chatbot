<?php
require_once 'database.php';

class Extras {
    private $conn;

    public function __construct() {
        $this->conn = Database::connection();
    }

    public function getCampusID($search = '') {
        $where = "WHERE 1";
        if ($search !== '') {
            $search = $this->conn->real_escape_string($search);
            $where .= " AND description LIKE '%$search%'";
        }
        $sql = "SELECT id, description FROM inventory_campus $where ORDER BY description ASC";
        $result = $this->conn->query($sql);
        if (!$result) return [];
        if ($result->num_rows > 0) {
            return $result->fetch_all(MYSQLI_ASSOC);
        }
        return [];
    }

    public function getCourseID($search = '') {
        $where = "WHERE 1";
        if ($search !== '') {
            $search = $this->conn->real_escape_string($search);
            $where .= " AND description LIKE '%$search%'";
        }
        $sql = "SELECT id, description FROM inventory_course $where ORDER BY description ASC"; 
        $result = $this->conn->query($sql);
        if (!$result) return [];
        if ($result->num_rows > 0) {
            return $result->fetch_all(MYSQLI_ASSOC);
        }
        return [];
    }
}