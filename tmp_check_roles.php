<?php
require 'vendor/autoload.php';

$conn = \Doctrine\DBAL\DriverManager::getConnection([
    'url' => 'postgresql://postgres:@127.0.0.1:5432/edupulse_ai'
]);

$rows = $conn->fetchAllAssociative('SELECT id, email, roles FROM users');
foreach ($rows as $row) {
    echo $row['id'] . ' | ' . $row['email'] . ' | ' . $row['roles'] . PHP_EOL;
}
