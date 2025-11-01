# phpdb

A simple and fast MySQL database library for PHP, by Mark6286.

## 🧠 What is phpdb?
phpdb is a lightweight PHP library designed to simplify and speed up database querying for MySQL databases. It provides basic functions to connect, query, and fetch results without the overhead of full ORM frameworks.

## 🎯 Key Features
- Easy to configure and use: just include the library and setup your credentials.
- Basic CRUD query helpers for rapid development.
- Minimal dependencies — focused on simplicity and performance.
- Compatible with MySQL via PHP mysqli or PDO (depending on implementation).
- Good for small to mid-size PHP projects where an ORM is overkill.

## 🚀 Getting Started

### 1. Install via Composer
```bash
composer require mark6286/phpdb
```

### 2. Set up your configuration
```php
require_once 'vendor/autoload.php';

$dbConfig = [
  'host'     => 'localhost',
  'username' => 'your_db_user',
  'password' => 'your_db_pass',
  'database' => 'your_db_name',
];

$db = new \phpdb\Database($dbConfig);
```

### 3. Run queries
```php
// Simple SELECT
$rows = $db->query("SELECT * FROM users WHERE active = 1");

// Insert
$insertId = $db->insert('users', [
  'name'  => 'John Doe',
  'email' => 'john@example.com'
]);

// Update
$affected = $db->update('users', ['active' => 0], "id = {$insertId}");

// Delete
$deleted = $db->delete('users', "id = {$insertId}");
```

## 📁 Repository Structure
```
phpdb/
├── src/              ← Library source code
├── examples/         ← Example usage scripts
├── composer.json     ← Package metadata & dependencies
├── README.md         ← This document
└── LICENSE           ← MIT License
```

## 🛠️ Why use phpdb?
- **Lightweight**: Minimal setup and simple API.
- **Fast**: Less overhead than full-blown ORMs.
- **Flexible**: Use it in small projects, utilities, or as a base for custom DB layers.
- **Open Source**: MIT licensed, feel free to fork, modify or contribute.

## 🔧 Contributing & Next Steps
- Submit bug reports or feature requests via GitHub Issues.
- Consider adding features like prepared statement support, transaction handling, or support for multiple DB backends (PostgreSQL, SQLite).
- Add unit tests to validate library behavior.
- Document more advanced use-cases (bulk inserts, joins, query builder, etc).

## 📄 License
MIT License — see the `LICENSE` file for full terms.

---

**Thank you for using phpdb!**  
*Last updated: 2025-11-01*
