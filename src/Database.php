<?php
namespace Reymark\Database;

use PDO;
use PDOException;

class Database
{
    protected static array $config = [];
    protected static int $indexdb = 0;
    protected PDO $pdo;

    // Query builder components
    protected string $table = '';
    protected array $where = [];
    protected string $orderBy = '';
    protected ?int $limit = null;
    protected array $bindings = [];

    public function __construct(?array $config = null)
    {
        if ($config) {
            self::setConfig([$config]);
        }

        $this->pdo = $this->connect(self::$indexdb);
    }

    public static function setConfig(array $config): void
    {
        self::$config = $config;
    }

    public static function setIndexdb(int $indexdb): void
    {
        self::$indexdb = $indexdb;
    }

    protected function connect(int $dbIndex): PDO
    {
        if (empty(self::$config)) {
            throw new \Exception("Database config not set. Use Database::setConfig() first.");
        }

        $db = self::$config[$dbIndex] ?? null;
        if (!$db) {
            throw new \Exception("Database index {$dbIndex} not found in configuration.");
        }

        $dsn = "mysql:host={$db['host']};dbname={$db['dbname']};charset=utf8mb4";
        $pdo = new PDO($dsn, $db['username'], $db['password']);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $pdo;
    }

    // ===========================
    // STATIC QUERY FUNCTIONS
    // ===========================
    public static function query(string $sql, array $params = [], ?int $dbIndex = null, bool $single = false)
    {
        $db = new self();
        $pdo = $db->connect($dbIndex ?? self::$indexdb);
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        if (preg_match('/^\s*(SELECT)\b/i', $sql)) {
            return $single ? $stmt->fetch() : $stmt->fetchAll();
        } elseif (preg_match('/^\s*(INSERT)\b/i', $sql)) {
            return $pdo->lastInsertId();
        } elseif (preg_match('/^\s*(UPDATE|DELETE)\b/i', $sql)) {
            return $stmt->rowCount();
        }

        return true;
    }

    // ===========================
    // PICO-LIKE BUILDER
    // ===========================
    public function table(string $table): self
    {
        $this->table = $table;
        $this->where = [];
        $this->bindings = [];
        $this->orderBy = '';
        $this->limit = null;
        return $this;
    }

    public function where(string $column, $operatorOrValue, $value = null): self
    {
        if ($value === null) {
            $value = $operatorOrValue;
            $operator = '=';
        } else {
            $operator = $operatorOrValue;
        }

        $param = ':' . str_replace('.', '_', $column) . count($this->bindings);
        $this->where[] = "`$column` $operator $param";
        $this->bindings[$param] = $value;
        return $this;
    }

    public function orderBy(string $order): self
    {
        $this->orderBy = "ORDER BY $order";
        return $this;
    }

    public function limit(int $limit): self
    {
        $this->limit = $limit;
        return $this;
    }

    // ===========================
    // FETCH METHODS
    // ===========================
    public function fetchAll(): array
    {
        $sql = $this->buildSelect();
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($this->bindings);
        return $stmt->fetchAll();
    }

    public function fetchOne(): ?array
    {
        $sql = $this->buildSelect() . " LIMIT 1";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($this->bindings);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    public function insert(array $data): int
    {
        $columns = array_keys($data);
        $params = array_map(fn($c) => ':' . $c, $columns);
        $sql = sprintf(
            "INSERT INTO `%s` (%s) VALUES (%s)",
            $this->table,
            implode(',', $columns),
            implode(',', $params)
        );
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($data);
        return (int)$this->pdo->lastInsertId();
    }

    public function update(array $data): int
    {
        $setParts = [];
        foreach ($data as $col => $val) {
            $param = ':upd_' . $col;
            $setParts[] = "`$col` = $param";
            $this->bindings[$param] = $val;
        }

        $sql = sprintf("UPDATE `%s` SET %s %s", $this->table, implode(',', $setParts), $this->buildWhere());
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($this->bindings);
        return $stmt->rowCount();
    }

    public function delete(): int
    {
        $sql = sprintf("DELETE FROM `%s` %s", $this->table, $this->buildWhere());
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($this->bindings);
        return $stmt->rowCount();
    }

    // ===========================
    // HELPERS
    // ===========================
    protected function buildSelect(): string
    {
        $sql = "SELECT * FROM `{$this->table}`";
        $sql .= $this->buildWhere();
        if ($this->orderBy) $sql .= " {$this->orderBy}";
        if ($this->limit !== null) $sql .= " LIMIT {$this->limit}";
        return $sql;
    }

    protected function buildWhere(): string
    {
        return empty($this->where) ? '' : ' WHERE ' . implode(' AND ', $this->where);
    }

    // ===========================
    // RAW AND TRANSACTION
    // ===========================
    public function raw(string $sql, array $params = []): \PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    public function transaction(callable $callback): bool
    {
        try {
            $this->pdo->beginTransaction();
            $callback($this->pdo);
            $this->pdo->commit();
            return true;
        } catch (PDOException $e) {
            $this->pdo->rollBack();
            throw new \Exception("Transaction failed: " . $e->getMessage());
        }
    }
}
