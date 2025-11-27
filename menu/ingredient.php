<?php

require_once '../connection/db.php';
require_once '../vendor/autoload.php';
require_once '../general.php';
require_once '../config.php';

function createIngredient($conn, $input, $username){
    $ingredient_name = $input['ingredient_name'];
    $ingredient_category = $input['ingredient_category'];
    $uom = $input['uom'];
    $sku = $input['sku'];
    $company_id = $input['company_id'];
    $is_active = $input['is_active'];
    $price = $input['price'];

    if ($ingredient_name === null || $ingredient_name === "" || $ingredient_category === "" || $ingredient_category === null || $uom === "" || $uom === null || $company_id === "" || $company_id === null || $price === "") {
        jsonResponse(400, 'ingredient_name, ingredient_category , uom, company_id, price are required');
    }

    $ingredient_name = mysqli_real_escape_string($conn, $ingredient_name);
    $ingredient_category = mysqli_real_escape_string($conn, $ingredient_category);
    $uom = mysqli_real_escape_string($conn, $uom);
    $sku = mysqli_real_escape_string($conn, $sku);
    $company_id = mysqli_real_escape_string($conn, $company_id);
    $is_active = $is_active ? 1 : 0;

    $check_app_query = "SELECT * FROM raki_dev.ingredient WHERE ingredient_name = '$ingredient_name' AND company_id = '$company_id'";
    $check_app_result = mysqli_query($conn, $check_app_query);

    $check_category_query = "SELECT * FROM raki_dev.ingredient_category WHERE category_id = '$ingredient_category'";
    $check_category_result = mysqli_query($conn, $check_category_query);

    $check_uom_query = "SELECT * FROM raki_dev.unit_of_measurement WHERE uom_id = '$uom'";
    $check_uom_result = mysqli_query($conn, $check_uom_query);

    if (mysqli_num_rows($check_app_result) > 0) {
        jsonResponse(400, 'Ingredient has already exists in database !!');    
    } else {
        if(mysqli_num_rows($check_category_result) < 1){
            jsonResponse(400, 'Ingredients category not exists in database !!');    
        }

        if(mysqli_num_rows($check_uom_result) < 1){
            jsonResponse(400, 'UOM not exists in database !!');    
        }

        $ingredientsID = "ingredients" . uniqid();
        $now = getCurrentDateTimeJakarta();

        $category_query = "INSERT INTO raki_dev.ingredient (ingredient_id, ingredient_name, ingredient_category, uom, sku, company_id, is_active, created_at, created_by, price) 
        VALUES ('$ingredientsID', '$ingredient_name', '$ingredient_category', '$uom', '$sku', '$company_id', '$is_active', '$now', '$username', '$price')";

        if (mysqli_query($conn, $category_query)) {
            jsonResponse(201, 'New ingredients has been created successfully', ['ingredients' => $ingredient_name]);
        } else {
            jsonResponse(500, 'Failed to create a new ingredients: ' . mysqli_error($conn));
        }
    }
}

function getAllIngredient($conn, $params, $page = 1, $limit = 10){
    $offset = ($page - 1) * $limit;

    $countQuery = "SELECT COUNT(*) as total FROM raki_dev.ingredient WHERE (ingredient_name LIKE '%$params%')";
    $countResult = mysqli_query($conn, $countQuery);
    $totalRow = mysqli_fetch_assoc($countResult);
    $total = $totalRow['total'];

    $query = "SELECT ingredient_id, ingredient_name, UOM.uom_name, IC.category_name
    FROM raki_dev.ingredient I
    LEFT JOIN raki_dev.unit_of_measurement UOM ON I.uom = UOM.uom_id
    LEFT JOIN raki_dev.ingredient_category IC ON I.ingredient_category = IC.category_id WHERE (ingredient_name LIKE '%$params%') LIMIT $limit OFFSET $offset";
    $result = mysqli_query($conn, $query);

    if ($result && mysqli_num_rows($result) > 0) {
        $data = mysqli_fetch_all($result, MYSQLI_ASSOC);
        $response = [
            'data' => $data,
            'pagination' => [
                'total' => (int)$total,
                'page' => (int)$page,
                'limit' => (int)$limit,
                'total_pages' => ceil($total / $limit),
            ]
        ];
        jsonResponse(200, 'Ingredient found', $response);
    } else {
        jsonResponse(404, 'Ingredient not found');
    }
}

function getDetailIngredient($conn, $ingredient_id){
    $query = "SELECT ingredient_id, ingredient_name, IC.category_id, IC.category_name, I.sku, UOM.uom_id, UOM.uom_name
        FROM raki_dev.ingredient I
        LEFT JOIN raki_dev.ingredient_category IC ON I.ingredient_category = IC.category_id 
        LEFT JOIN raki_dev.unit_of_measurement UOM ON I.uom = UOM.uom_id 
        WHERE ingredient_id = '$ingredient_id'";
    $result = mysqli_query($conn, $query);

    if ($result && mysqli_num_rows($result) > 0) {
        $data = mysqli_fetch_all($result, MYSQLI_ASSOC);
        $response = [
            'data' => $data,
            'pagination' => [
                'total' => 1,
                'page' => 1,
                'limit' => 1,
                'total_pages' => 1,
            ]
        ];
        jsonResponse(200, 'Ingredient found', $response);
    } else {
        jsonResponse(404, 'Ingredient not found');
    }
}

function updateIngredient($conn, $input){
    if (!isset($input['ingredient_id'])) {
        jsonResponse(400, 'Missing required fields (ingredient_id)');
    }

    $ingredient_id = mysqli_real_escape_string($conn, $input['ingredient_id']);

    $query = "SELECT * FROM raki_dev.ingredient WHERE ingredient_id = '$ingredient_id'";
    $result = mysqli_query($conn, $query);

    $updates = [];
    if (isset($input['ingredient_name'])) {
        $updates[] = "ingredient_name = '" . mysqli_real_escape_string($conn, $input['ingredient_name']) . "'";
    }
    if (isset($input['ingredient_category'])) {
        $updates[] = "ingredient_category = '" . mysqli_real_escape_string($conn, $input['ingredient_category']) . "'";
    }
    if (isset($input['uom'])) {
        $updates[] = "uom = '" . mysqli_real_escape_string($conn, $input['uom']) . "'";
    }
    if (isset($input['sku'])) {
        $updates[] = "sku = '" . mysqli_real_escape_string($conn, $input['sku']) . "'";
    }
    if (isset($input['company_id'])) {
        $updates[] = "company_id = '" . mysqli_real_escape_string($conn, $input['company_id']) . "'";
    }
    if (isset($input['price'])) {
        $updates[] = "price = '" . mysqli_real_escape_string($conn, $input['price']) . "'";
    }

    if (empty($updates)) {
        jsonResponse(400, 'No fields provided for update');
    }

    if(mysqli_num_rows($result) > 0) {
        $updateQuery = "UPDATE raki_dev.ingredient SET " . implode(', ', $updates) . " WHERE ingredient_id = '$ingredient_id'";

        if (mysqli_query($conn, $updateQuery)) {
            jsonResponse(200, 'Ingredient updated successfully', ['ingredient_id' => $ingredient_id]);
        } else {
            jsonResponse(500, 'Failed to update ingredient');
        }
    } else {
        jsonResponse(404, message: 'Ingredient not registered in systems');
    }

}

function deleteIngredient($conn, $ingredient_id){
    if($ingredient_id === null || $ingredient_id === ''){
        jsonResponse(400, 'Missing required fields (ingredient_id)');
    }

    $query = "SELECT * FROM raki_dev.ingredient WHERE ingredient_id = '$ingredient_id'";
    $result = mysqli_query($conn, $query);

    if(mysqli_num_rows($result) > 0) {
        $query = "DELETE FROM raki_dev.ingredient WHERE ingredient_id = '$ingredient_id'";

        if (mysqli_query($conn, $query)) {
            jsonResponse(200, 'Ingredient menu deleted successfully');
        } else {
            jsonResponse(500, 'Failed to delete ingredient menu');
        }

    } else {
        jsonResponse(404, 'Ingredient menu is not registered in systems');
    }
}

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

$headers = getallheaders();
if (!isset($headers['Authorization'])) {
    jsonResponse(401, 'Authorization header not found');
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header("Access-Control-Allow-Origin: *");
    header("Access-Control-Allow-Methods: POST, GET, OPTIONS, PUT, DELETE");
    header("Access-Control-Allow-Headers: Origin, X-Requested-With, Content-Type, Accept");
    http_response_code(200);
    exit();
}

try {
    $token = str_replace('Bearer ', '', $headers['Authorization']);
    $decoded = JWT::decode($token, new Key($_ENV['JWT_SECRET'], 'HS256'));

    $conn = DB::conn();
    
    $token_username = $decoded->username;
    $method = $_SERVER['REQUEST_METHOD'];

    switch($method){
        case 'POST':
            $input = json_decode(file_get_contents('php://input'), true);
            createIngredient($conn, $input, $token_username);
            break;
        case 'GET':
            $params = $_GET['params'] ?? null;
            $page = $_GET['page'] ?? 1;
            $limit = $_GET['limit'] ?? 10;
            $ingredient_id = $_GET['ingredient_id'] ?? null;
            if($ingredient_id != null){
                getDetailIngredient($conn, $ingredient_id);
            } else {
                getAllIngredient($conn, $params, $page, $limit);
             }
            break;
        case 'PUT':
            $input = json_decode(file_get_contents('php://input'), true);
            updateIngredient($conn, $input);
            break;
        case 'DELETE':
            $ingredient_id = $_GET['ingredient_id'] ?? null;
            deleteIngredient($conn, $ingredient_id);
            break;
    }


} catch (Exception $e){
    jsonResponse(500, 'Internal Server Error', ['error' => $e->getMessage()]);
}

?>