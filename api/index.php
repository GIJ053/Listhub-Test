<?php
require '../config/database.php';
require '../src/Listing.php';

use Src\Listing;

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET,POST");
header("Access-Control-Max-Age: 3600");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$uri = explode('/', $uri);

$connection = (new Database())->getConnection();

if (!isset($uri[1]) || $uri[1] !== 'v1') {
    header("HTTP/1.1 404 Not Found");
    exit();
}

if (!isset($uri[2]) || $uri[2] !== 'listings') {
    header("HTTP/1.1 404 Not Found");
    exit();
}

$status = null;
$dbfield = null;
if (isset($uri[3])) {
    $dbfield = $uri[3];
}

if (isset($uri[4])) {
    $status = $uri[4];
}

$requestMethod = $_SERVER["REQUEST_METHOD"];

$controller = new Listing($connection, $requestMethod, $dbfield, $status);
$controller->processRequest();
