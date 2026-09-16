<?php
require_once 'database.php';

class Inventory {
    private $conn;

    public function __construct() {
        $this->conn = Database::connection();
    }

    public function getInventory() {
        $sql = "SELECT i.id, i.item_code, i.item_name, i.item_type, i.inventory_category, i.brand, i.location, i.campus_id, i.course_id, d.size_code, d.quantity, d.price 
                FROM inventory_item i 
                LEFT JOIN inventory_item_details d ON d.item_id = i.id 
                ORDER BY i.item_name ASC, d.size_code ASC";
        $result = $this->conn->query($sql);
        if (!$result) return [];
        if ($result->num_rows > 0) {
            return $result->fetch_all(MYSQLI_ASSOC);
        }
        return [];
    }
}