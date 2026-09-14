<?php

require_once 'database.php';


/*
|--------------------------------------------------------------------------
| Get inventory summary
|--------------------------------------------------------------------------
*/

function getInventorySummary($conn)
{
    $sql = "
        SELECT
            COUNT(DISTINCT i.id) AS total_items,
            COALESCE(SUM(d.quantity), 0) AS total_quantity
        FROM inventory_item i
        LEFT JOIN inventory_item_details d
            ON d.item_id = i.id
    ";

    ($result = $conn->query($sql))
        or die($conn->error);

    if ($result->num_rows > 0) {
        return $result->fetch_assoc();
    }

    return [
        'total_items' => 0,
        'total_quantity' => 0
    ];
}


/*
|--------------------------------------------------------------------------
| Get all inventory
|--------------------------------------------------------------------------
*/

function getInventory($conn)
{
    $sql = "
        SELECT
            i.id,
            i.item_code,
            i.item_name,
            i.item_type,
            i.inventory_category,
            i.brand,
            i.location,
            i.campus_id,
            i.course_id,
            d.size_code,
            d.quantity,
            d.price

        FROM inventory_item i

        LEFT JOIN inventory_item_details d
            ON d.item_id = i.id

        ORDER BY
            i.item_name ASC,
            d.size_code ASC
    ";

    ($result = $conn->query($sql))
        or die($conn->error);

    if ($result->num_rows > 0) {
        return $result->fetch_all(MYSQLI_ASSOC);
    }

    return [];
}


/*
|--------------------------------------------------------------------------
| Get out-of-stock items
|--------------------------------------------------------------------------
*/

function getOutOfStockItems($conn)
{
    $sql = "
        SELECT
            i.id,
            i.item_code,
            i.item_name,
            i.brand,
            d.size_code,
            d.quantity

        FROM inventory_item i

        INNER JOIN inventory_item_details d
            ON d.item_id = i.id

        WHERE d.quantity <= 0

        ORDER BY
            i.item_name ASC,
            d.size_code ASC
    ";

    ($result = $conn->query($sql))
        or die($conn->error);

    if ($result->num_rows > 0) {
        return $result->fetch_all(MYSQLI_ASSOC);
    }

    return [];
}


/*
|--------------------------------------------------------------------------
| Search inventory
|--------------------------------------------------------------------------
*/

function searchInventory($conn, $search)
{
    $search = $conn->real_escape_string(
        trim($search)
    );

    $searchValue = '%' . $search . '%';

    $sql = "
        SELECT
            i.id,
            i.item_code,
            i.item_name,
            i.item_type,
            i.inventory_category,
            i.brand,
            i.location,
            i.campus_id,
            i.course_id,
            d.size_code,
            d.quantity,
            d.price

        FROM inventory_item i

        LEFT JOIN inventory_item_details d
            ON d.item_id = i.id

        WHERE
            i.item_name LIKE '$searchValue'
            OR i.item_code LIKE '$searchValue'
            OR i.brand LIKE '$searchValue'

        ORDER BY
            i.item_name ASC,
            d.size_code ASC
    ";

    ($result = $conn->query($sql))
        or die($conn->error);

    if ($result->num_rows > 0) {
        return $result->fetch_all(MYSQLI_ASSOC);
    }

    return [];
}


/*
|--------------------------------------------------------------------------
| Get inventory by item name
|--------------------------------------------------------------------------
*/

function getInventoryByItemName($conn, $itemName)
{
    $itemName = $conn->real_escape_string(
        trim($itemName)
    );

    $searchValue = '%' . $itemName . '%';

    $sql = "
        SELECT
            i.id,
            i.item_code,
            i.item_name,
            i.item_type,
            i.inventory_category,
            i.brand,
            i.location,
            i.campus_id,
            i.course_id,
            d.size_code,
            d.quantity,
            d.price

        FROM inventory_item i

        LEFT JOIN inventory_item_details d
            ON d.item_id = i.id

        WHERE
            i.item_name LIKE '$searchValue'

        ORDER BY
            i.item_name ASC,
            d.size_code ASC
    ";

    ($result = $conn->query($sql))
        or die($conn->error);

    if ($result->num_rows > 0) {
        return $result->fetch_all(MYSQLI_ASSOC);
    }

    return [];
}


/*
|--------------------------------------------------------------------------
| Get total stock for specific item
|--------------------------------------------------------------------------
*/

function getItemStockTotal($conn, $itemName)
{
    $itemName = $conn->real_escape_string(
        trim($itemName)
    );

    $searchValue = '%' . $itemName . '%';

    $sql = "
        SELECT
            COUNT(DISTINCT i.id) AS item_count,
            COALESCE(SUM(d.quantity), 0) AS total_quantity

        FROM inventory_item i

        LEFT JOIN inventory_item_details d
            ON d.item_id = i.id

        WHERE
            i.item_name LIKE '$searchValue'
    ";

    ($result = $conn->query($sql))
        or die($conn->error);

    if ($result->num_rows > 0) {
        return $result->fetch_assoc();
    }

    return [
        'item_count' => 0,
        'total_quantity' => 0
    ];
}


/*
|--------------------------------------------------------------------------
| Get item stock by size
|--------------------------------------------------------------------------
*/

function getItemStockBySize($conn, $itemName)
{
    $itemName = $conn->real_escape_string(
        trim($itemName)
    );

    $searchValue = '%' . $itemName . '%';

    $sql = "
        SELECT
            i.id,
            i.item_code,
            i.item_name,
            d.size_code,
            d.quantity,
            d.price

        FROM inventory_item i

        INNER JOIN inventory_item_details d
            ON d.item_id = i.id

        WHERE
            i.item_name LIKE '$searchValue'

        ORDER BY
            i.item_name ASC,
            d.size_code ASC
    ";

    ($result = $conn->query($sql))
        or die($conn->error);

    if ($result->num_rows > 0) {
        return $result->fetch_all(MYSQLI_ASSOC);
    }

    return [];
}


/*
|--------------------------------------------------------------------------
| Get stock by size and gender
|--------------------------------------------------------------------------
|
| Size examples:
|
| Small
| Medium
| Large
| XSmall
| XLarge
|
| Database values:
|
| (M)Small
| (F)Small
| (M)Medium
| (F)Medium
|
| If gender is empty:
|   returns Male + Female
|
| If gender = male:
|   returns Male only
|
| If gender = female:
|   returns Female only
|--------------------------------------------------------------------------
*/

function getStockBySize($conn, $size, $gender = '')
{
    $size = trim($size);
    $gender = strtolower(trim($gender));

    $size = $conn->real_escape_string($size);

    if ($gender == 'male') {

        $sizeValue = $conn->real_escape_string(
            '(M)' . $size
        );

        $sql = "
            SELECT
                COALESCE(SUM(d.quantity), 0) AS total_quantity

            FROM inventory_item_details d

            WHERE
                d.size_code = '$sizeValue'
        ";

    } elseif ($gender == 'female') {

        $sizeValue = $conn->real_escape_string(
            '(F)' . $size
        );

        $sql = "
            SELECT
                COALESCE(SUM(d.quantity), 0) AS total_quantity

            FROM inventory_item_details d

            WHERE
                d.size_code = '$sizeValue'
        ";

    } else {

        $maleSize = $conn->real_escape_string(
            '(M)' . $size
        );

        $femaleSize = $conn->real_escape_string(
            '(F)' . $size
        );

        $sql = "
            SELECT
                COALESCE(SUM(d.quantity), 0) AS total_quantity

            FROM inventory_item_details d

            WHERE
                d.size_code IN (
                    '$maleSize',
                    '$femaleSize'
                )
        ";
    }

    ($result = $conn->query($sql))
        or die($conn->error);

    if ($result->num_rows > 0) {
        return $result->fetch_assoc();
    }

    return [
        'total_quantity' => 0
    ];
}


/*
|--------------------------------------------------------------------------
| Get inventory records by size and gender
|--------------------------------------------------------------------------
*/

function getInventoryBySize($conn, $size, $gender = '')
{
    $size = trim($size);
    $gender = strtolower(trim($gender));

    $size = $conn->real_escape_string($size);

    if ($gender == 'male') {

        $sizeValue = $conn->real_escape_string(
            '(M)' . $size
        );

        $where = "d.size_code = '$sizeValue'";

    } elseif ($gender == 'female') {

        $sizeValue = $conn->real_escape_string(
            '(F)' . $size
        );

        $where = "d.size_code = '$sizeValue'";

    } else {

        $maleSize = $conn->real_escape_string(
            '(M)' . $size
        );

        $femaleSize = $conn->real_escape_string(
            '(F)' . $size
        );

        $where = "
            d.size_code IN (
                '$maleSize',
                '$femaleSize'
            )
        ";
    }

    $sql = "
        SELECT
            i.item_name,
            i.item_code,
            d.size_code,
            d.quantity

        FROM inventory_item i

        INNER JOIN inventory_item_details d
            ON d.item_id = i.id

        WHERE
            $where

        ORDER BY
            i.item_name ASC,
            d.size_code ASC
    ";

    ($result = $conn->query($sql))
        or die($conn->error);

    if ($result->num_rows > 0) {
        return $result->fetch_all(MYSQLI_ASSOC);
    }

    return [];
}