<?php
require __DIR__ . '/../vendor/autoload.php';

use Database\Database;

// Configure database
Database::setConfig([
    [
        'host' => 'localhost',
        'dbname' => 'testdb',
        'username' => 'root',
        'password' => ''
    ]
]);

Database::setIndexdb(0);

$db = new Database();

// Pico-style select
$users = $db->table('users')
    ->where('status', 'active')
    ->orderBy('id DESC')
    ->limit(5)
    ->fetchAll();
print_r($users);

// Legacy query()
$user = Database::query("SELECT * FROM users WHERE id = ?", [1], null, true);
print_r($user);
