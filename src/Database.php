<?php
namespace Database;

use PDO;
use PDOException;
use Exception;

final class Database
{
    /** @var array<int,array<string,string>> */
    protected static array $config = [];
    protected static int $indexdb = 0;
    protected readonly PDO $pdo;

    // Query builder parts
    protected string $table = '';
    protected array $columns = ['*'];
    protected array $joins = [];
    protected array $where = [];
    protected array $groupBy = [];
    protected array $having = [];
    protected string $orderBy = '';
    protected ?int $limit = null;
    protected ?int $offset = null;
    protected array $bindings = [];
    protected bool $debug = false;

    // ===========================
    // CORE CONNECTION
    // ===========================
    public function __construct(?array $config = null)
    {
        if ($config !== null) {
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
        if (self::$config === []) {
            throw new Exception("Database config not set. Use Database::setConfig() first.");
        }

        $db = self::$config[$dbIndex] ?? throw new Exception("Database index {$dbIndex} not found in configuration.");

        $dsn = "mysql:host={$db['host']};dbname={$db['dbname']};charset=utf8mb4";
        $pdo = new PDO($dsn, $db['username'], $db['password']);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $pdo;
    }

    // ===========================
    // STATIC QUICK QUERY
    // ===========================
    public static function query(
        string $sql,
        array $params = [],
        ?int $dbIndex = null,
        bool $single = false
    ): mixed {
        $db = new self();
        $pdo = $db->connect($dbIndex ?? self::$indexdb);
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return match (strtoupper(strtok(trim($sql), ' '))) {
            'SELECT' => $single ? $stmt->fetch() : $stmt->fetchAll(),
            'INSERT' => $pdo->lastInsertId(),
            'UPDATE', 'DELETE' => $stmt->rowCount(),
            default => true,
        };
    }

    // ===========================
    // BUILDER METHODS
    // ===========================
    public function table(string $table): self
    {
        $this->reset();
        $this->table = $table;
        return $this;
    }

    /**
     * Select columns with or without aliases.
     * Accepts:
     *   ->select(['id', 'name AS username'])
     *   ->select(['username' => 'name'])
     */
    public function select(array|string $columns = ['*']): self
    {
        if (is_string($columns)) {
            $columns = array_map('trim', explode(',', $columns));
        }

        $this->columns = [];
        foreach ($columns as $key => $col) {
            if (is_string($key)) {
                // Associative array: ['alias' => 'column']
                $this->columns[] = "`$col` AS `$key`";
            } elseif (str_contains($col, ' AS ') || str_contains($col, ' as ')) {
                // Raw alias string
                $this->columns[] = $col;
            } else {
                $this->columns[] = "`" . str_replace('.', '`.`', $col) . "`";
            }
        }

        return $this;
    }

    /**
     * Add raw select columns (no escaping).
     */
    public function selectRaw(string $raw): self
    {
        $this->columns[] = $raw;
        return $this;
    }

    public function join(string $table, string $first, string $operator, string $second, string $type = 'INNER'): self
    {
        $this->joins[] = strtoupper($type) . " JOIN `$table` ON $first $operator $second";
        return $this;
    }

    public function leftJoin(string $table, string $first, string $operator, string $second): self
    {
        return $this->join($table, $first, $operator, $second, 'LEFT');
    }

    public function rightJoin(string $table, string $first, string $operator, string $second): self
    {
        return $this->join($table, $first, $operator, $second, 'RIGHT');
    }

    public function where(string $column, mixed $operatorOrValue, mixed $value = null): self
    {
        [$operator, $val] = $value === null
            ? ['=', $operatorOrValue]
            : [$operatorOrValue, $value];

        $param = ':w_' . count($this->bindings);
        $this->where[] = "`$column` $operator $param";
        $this->bindings[$param] = $val;
        return $this;
    }

    public function whereRaw(string $condition, array $params = []): self
    {
        $this->where[] = $condition;
        $this->bindings += $params;
        return $this;
    }

    public function groupBy(string|array $columns): self
    {
        $this->groupBy = (array)$columns;
        return $this;
    }

    public function having(string $condition, array $params = []): self
    {
        $this->having[] = $condition;
        $this->bindings += $params;
        return $this;
    }

    public function orderBy(string $order): self
    {
        $this->orderBy = "ORDER BY $order";
        return $this;
    }

    public function limit(int $limit, ?int $offset = null): self
    {
        $this->limit = $limit;
        $this->offset = $offset;
        return $this;
    }

    public function debug(bool $state = true): self
    {
        $this->debug = $state;
        return $this;
    }

    // ===========================
    // EXECUTION
    // ===========================
    public function get(): array
    {
        $sql = $this->buildSelect();
        if ($this->debug) {
            echo $this->toSql($sql), PHP_EOL;
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($this->bindings);
        return $stmt->fetchAll();
    }

    public function first(): ?array
    {
        $this->limit(1);
        return $this->get()[0] ?? null;
    }

    // Aggregate helpers
    public function count(string $column = '*'): int { return (int)$this->aggregate("COUNT($column)"); }
    public function sum(string $column): float { return (float)$this->aggregate("SUM($column)"); }
    public function avg(string $column): float { return (float)$this->aggregate("AVG($column)"); }
    public function min(string $column): mixed { return $this->aggregate("MIN($column)"); }
    public function max(string $column): mixed { return $this->aggregate("MAX($column)"); }

    protected function aggregate(string $func): mixed
    {
        $sql = $this->buildAggregate($func);
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($this->bindings);
        return $stmt->fetchColumn();
    }

    public function insert(array $data): int
    {
        $columns = array_keys($data);
        $params = array_map(fn(string $c): string => ":$c", $columns);
        $sql = sprintf(
            "INSERT INTO `%s` (%s) VALUES (%s)",
            $this->table,
            implode(',', $columns),
            implode(',', $params)
        );
        if ($this->debug) {
            echo $this->toSql($sql, $data), PHP_EOL;
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($data);
        return (int)$this->pdo->lastInsertId();
    }

    public function update(array $data): int
    {
        $setParts = [];
        foreach ($data as $col => $val) {
            $param = ":upd_$col";
            $setParts[] = "`$col` = $param";
            $this->bindings[$param] = $val;
        }

        $sql = sprintf("UPDATE `%s` SET %s %s", $this->table, implode(',', $setParts), $this->buildWhere());
        if ($this->debug) {
            echo $this->toSql($sql), PHP_EOL;
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($this->bindings);
        return $stmt->rowCount();
    }

    public function delete(): int
    {
        $sql = sprintf("DELETE FROM `%s` %s", $this->table, $this->buildWhere());
        if ($this->debug) {
            echo $this->toSql($sql), PHP_EOL;
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($this->bindings);
        return $stmt->rowCount();
    }

    // ===========================
    // BUILD HELPERS
    // ===========================
    protected function buildSelect(): string
    {
        $cols = implode(', ', $this->columns);
        $sql = "SELECT $cols FROM `{$this->table}`";
        if ($this->joins !== []) {
            $sql .= ' ' . implode(' ', $this->joins);
        }
        $sql .= $this->buildWhere();
        if ($this->groupBy !== []) {
            $sql .= ' GROUP BY ' . implode(',', $this->groupBy);
        }
        if ($this->having !== []) {
            $sql .= ' HAVING ' . implode(' AND ', $this->having);
        }
        if ($this->orderBy !== '') {
            $sql .= ' ' . $this->orderBy;
        }
        if ($this->limit !== null) {
            $sql .= " LIMIT {$this->limit}";
            if ($this->offset !== null) {
                $sql .= " OFFSET {$this->offset}";
            }
        }
        return $sql;
    }

    protected function buildAggregate(string $func): string
    {
        $sql = "SELECT $func as aggregate FROM `{$this->table}`";
        if ($this->joins !== []) {
            $sql .= ' ' . implode(' ', $this->joins);
        }
        $sql .= $this->buildWhere();
        return $sql;
    }

    protected function buildWhere(): string
    {
        return $this->where === [] ? '' : ' WHERE ' . implode(' AND ', $this->where);
    }

    protected function reset(): void
    {
        $this->table = '';
        $this->columns = ['*'];
        $this->joins = [];
        $this->where = [];
        $this->groupBy = [];
        $this->having = [];
        $this->orderBy = '';
        $this->limit = $this->offset = null;
        $this->bindings = [];
    }

    protected function toSql(string $sql, array $params = []): string
    {
        $replacements = $this->bindings + $params;
        foreach ($replacements as $key => $val) {
            $safe = is_numeric($val) ? $val : "'" . addslashes((string)$val) . "'";
            $sql = str_replace($key, $safe, $sql);
        }
        return $sql;
    }

    // ===========================
    // RAW + TRANSACTIONS
    // ===========================
    public function raw(string $sql, array $params = []): \PDOStatement
    {
        if ($this->debug) {
            echo $this->toSql($sql, $params), PHP_EOL;
        }
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
            throw new Exception("Transaction failed: {$e->getMessage()}");
        }
    }
}
