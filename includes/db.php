<?php
/**
 * PDO database access. All queries use prepared statements (SQL-injection safe).
 */

function db_connect(bool $withDatabase = true): PDO
{
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];
    if (DB_DRIVER === 'sqlite') {
        $pdo = new PDO('sqlite:' . DB_SQLITE_PATH, null, null, $options);
        $pdo->exec('PRAGMA foreign_keys = ON');
        return $pdo;
    }
    if (DB_DRIVER === 'sqlsrv') {
        // Microsoft SQL Server (managed with SSMS). Needs the pdo_sqlsrv PHP extension.
        $dsn = 'sqlsrv:Server=' . DB_HOST . (DB_PORT ? ',' . DB_PORT : '')
             . ';Database=' . ($withDatabase ? DB_NAME : 'master')
             . ';TrustServerCertificate=1';
        // Empty DB_USER = Windows Authentication (same as "Windows Authentication" in SSMS)
        $pdo = new PDO($dsn, DB_USER === '' ? null : DB_USER, DB_USER === '' ? null : DB_PASS, $options);
        return $pdo;
    }
    $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';charset=utf8mb4';
    if ($withDatabase) {
        $dsn .= ';dbname=' . DB_NAME;
    }
    $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
    $pdo->exec("SET time_zone = '" . (new DateTime())->format('P') . "'");
    return $pdo;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        try {
            $pdo = db_connect();
        } catch (PDOException $e) {
            if (PHP_SAPI === 'cli') {
                fwrite(STDERR, 'Database connection failed: ' . $e->getMessage() . PHP_EOL);
                exit(1);
            }
            http_response_code(500);
            echo '<div style="font-family:sans-serif;max-width:640px;margin:60px auto;padding:24px;border:1px solid #f5c2c7;background:#f8d7da;border-radius:12px">'
               . '<h2 style="margin-top:0">Cannot connect to the database</h2>'
               . '<p>Make sure the database server is running (SQL Server service / SQL Server Express) and the DB_* settings in <code>config.php</code> are correct.</p>'
               . '<p><small>' . htmlspecialchars($e->getMessage()) . '</small></p>'
               . '<p><a href="' . BASE_PATH . '/install.php">Open the installer</a></p></div>';
            exit;
        }
    }
    return $pdo;
}

/** Row limit in the right SQL dialect. SQL Server needs ORDER BY ... OFFSET/FETCH. */
function sql_limit(int $limit, int $offset = 0): string
{
    return DB_DRIVER === 'sqlsrv'
        ? " OFFSET $offset ROWS FETCH NEXT $limit ROWS ONLY"
        : " LIMIT $limit OFFSET $offset";
}

function q(string $sql, array $params = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}

function db_one(string $sql, array $params = []): ?array
{
    $row = q($sql, $params)->fetch();
    return $row === false ? null : expand_services_row($row);
}

function db_all(string $sql, array $params = []): array
{
    return array_map('expand_services_row', q($sql, $params)->fetchAll());
}

/** Extra service ids stored on an appointment (",3,5," → [3, 5]). */
function extra_service_ids($raw): array
{
    return array_values(array_filter(array_map('intval', explode(',', (string) $raw))));
}

/**
 * Appointments can have several services: service_id is the first one and extra_services
 * holds the rest. Rows that carry a service name are completed here so every page shows
 * all services ("Cleaning + Whitening"), the total minutes and the total starting rate.
 */
function expand_services_row(array $row): array
{
    if (empty($row['extra_services']) || !array_key_exists('service_name', $row)) {
        return $row;
    }
    static $all = null;
    if ($all === null) {
        $all = [];
        foreach (q('SELECT id, name, duration_min, price FROM services')->fetchAll() as $s) {
            $all[(int) $s['id']] = $s;
        }
    }
    $names = [$row['service_name']];
    $dur = (int) ($row['duration_min'] ?? 0);
    $price = (float) ($row['price'] ?? 0);
    foreach (extra_service_ids($row['extra_services']) as $id) {
        if (isset($all[$id])) {
            $names[] = $all[$id]['name'];
            $dur += (int) $all[$id]['duration_min'];
            $price += (float) $all[$id]['price'];
        }
    }
    $row['service_name'] = implode(' + ', $names);
    if (array_key_exists('duration_min', $row)) $row['duration_min'] = $dur;
    if (array_key_exists('price', $row)) $row['price'] = $price;
    return $row;
}

function db_val(string $sql, array $params = [])
{
    $v = q($sql, $params)->fetchColumn();
    return $v === false ? null : $v;
}

function db_insert(string $table, array $data): int
{
    $cols = array_keys($data);
    $sql = 'INSERT INTO ' . $table . ' (' . implode(', ', $cols) . ') VALUES (:' . implode(', :', $cols) . ')';
    q($sql, $data);
    return (int) db()->lastInsertId();
}

function db_update(string $table, array $data, string $where, array $whereParams = []): int
{
    $sets = [];
    $params = [];
    foreach ($data as $col => $val) {
        $sets[] = "$col = :set_$col";
        $params["set_$col"] = $val;
    }
    $sql = 'UPDATE ' . $table . ' SET ' . implode(', ', $sets) . ' WHERE ' . $where;
    return q($sql, array_merge($params, $whereParams))->rowCount();
}

function is_installed(): bool
{
    if (file_exists(ROOT_DIR . '/storage/installed.lock')) {
        return true;
    }
    return false;
}

function setting_get(string $key, $default = null)
{
    $v = db_val('SELECT svalue FROM settings WHERE skey = ?', [$key]);
    return $v === null ? $default : $v;
}

function setting_set(string $key, $value): void
{
    if (db_val('SELECT COUNT(*) FROM settings WHERE skey = ?', [$key])) {
        q('UPDATE settings SET svalue = ? WHERE skey = ?', [(string) $value, $key]);
    } else {
        q('INSERT INTO settings (skey, svalue) VALUES (?, ?)', [$key, (string) $value]);
    }
}
