<?php
/**
 * Database class - Singleton PDO wrapper for MySQL connection
 */

class Database
{
    private static ?Database $instance = null;
    private PDO $connection;

    /**
     * Private constructor to prevent direct instantiation
     *
     * @param array $config Database configuration
     * @throws PDOException
     */
    private function __construct(array $config)
    {

        $dsn = sprintf(
            'mysql:host=%s;dbname=%s;charset=%s',
            $config['host'],
            $config['name'],
            $config['charset']
        );

        try {
            $this->connection = new PDO(
                $dsn,
                $config['user'],
                $config['password'],
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]
            );
        } catch (PDOException $e) {
            throw $e;
        }
    }

    /**
     * Get singleton instance of Database
     *
     * @param array $config Database configuration
     * @return Database
     */
    public static function getInstance(array $config): Database
    {
        if (self::$instance === null) {
            self::$instance = new self($config);
        }
        return self::$instance;
    }

    /**
     * Execute a query and return statement
     *
     * @param string $sql SQL query
     * @param array $params Query parameters
     * @return PDOStatement
     */
    public function query(string $sql, array $params = []): PDOStatement
    {
        try {
            $stmt = $this->connection->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        } catch (PDOException $e) {
            throw $e;
        }
    }

    /**
     * Execute a query without returning results
     *
     * @param string $sql SQL query
     * @param array $params Query parameters
     * @return bool
     */
    public function execute(string $sql, array $params = []): bool
    {
        try {
            $stmt = $this->connection->prepare($sql);
            return $stmt->execute($params);
        } catch (PDOException $e) {
            throw $e;
        }
    }

    /**
     * Fetch all rows from query
     *
     * @param string $sql SQL query
     * @param array $params Query parameters
     * @return array
     */
    public function fetchAll(string $sql, array $params = []): array
    {
        $stmt = $this->query($sql, $params);
        return $stmt->fetchAll();
    }

    /**
     * Fetch single row from query
     *
     * @param string $sql SQL query
     * @param array $params Query parameters
     * @return array|null
     */
    public function fetchOne(string $sql, array $params = []): ?array
    {
        $stmt = $this->query($sql, $params);
        $result = $stmt->fetch();
        return $result !== false ? $result : null;
    }

    /**
     * Begin transaction
     *
     * @return bool
     */
    public function beginTransaction(): bool
    {
        return $this->connection->beginTransaction();
    }

    /**
     * Commit transaction
     *
     * @return bool
     */
    public function commit(): bool
    {
        return $this->connection->commit();
    }

    /**
     * Rollback transaction
     *
     * @return bool
     */
    public function rollback(): bool
    {
        return $this->connection->rollBack();
    }

    /**
     * Initialize database tables
     *
     * @return void
     */
    public function initializeTables(): void
    {
        try {
            // Create actions table
            $this->execute("
                CREATE TABLE IF NOT EXISTS actions (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    action_name VARCHAR(255) NOT NULL,
                    timestamp INT UNSIGNED NOT NULL,
                    ip_address VARCHAR(45),
                    installation_id VARCHAR(255) DEFAULT '0',
                    INDEX idx_action_name (action_name),
                    INDEX idx_timestamp (timestamp),
                    INDEX idx_installation_id (installation_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");

            // Migrate existing actions table if needed
            $this->migrateActionsTable();

            // Create rate_limits table
            $this->execute("
                CREATE TABLE IF NOT EXISTS rate_limits (
                    ip_address VARCHAR(45),
                    endpoint_type VARCHAR(50) DEFAULT 'action',
                    request_count INT UNSIGNED DEFAULT 1,
                    window_start INT UNSIGNED NOT NULL,
                    PRIMARY KEY (ip_address, endpoint_type),
                    INDEX idx_window_start (window_start)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");

            // Migrate existing rate_limits table if needed
            $this->migrateRateLimitsTable();

            // Create action_stats table
            $this->execute("
                CREATE TABLE IF NOT EXISTS action_stats (
                    action_name VARCHAR(255) PRIMARY KEY,
                    total_count INT UNSIGNED DEFAULT 0,
                    last_updated INT UNSIGNED NOT NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");

            // Create installations table
            $this->execute("
                CREATE TABLE IF NOT EXISTS installations (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    timestamp INT UNSIGNED NOT NULL,
                    ip_address VARCHAR(45),
                    user_agent TEXT,

                    platform VARCHAR(50) NOT NULL,
                    os_version VARCHAR(100),
                    os_release VARCHAR(100),
                    plugin_version VARCHAR(50) NOT NULL,
                    node_version VARCHAR(50),

                    yandex_music_connected TINYINT(1) DEFAULT 0,
                    yandex_music_path VARCHAR(500),

                    stream_deck_version VARCHAR(50),
                    stream_deck_language VARCHAR(20),

                    installation_id VARCHAR(255) NOT NULL,
                    extra_data TEXT,

                    INDEX idx_timestamp (timestamp),
                    INDEX idx_ip_address (ip_address),
                    INDEX idx_platform (platform),
                    INDEX idx_plugin_version (plugin_version),
                    INDEX idx_installation_id (installation_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        } catch (PDOException $e) {
            throw $e;
        }
    }

    /**
     * Migrate existing actions table to add installation_id column and remove unused columns
     *
     * @return void
     */
    private function migrateActionsTable(): void
    {
        try {
            // First, remove unused columns if they exist
            $refererColumns = $this->fetchAll("SHOW COLUMNS FROM actions LIKE 'referer'");
            if (!empty($refererColumns)) {
                $this->execute("ALTER TABLE actions DROP COLUMN referer");
            }

            $userAgentColumns = $this->fetchAll("SHOW COLUMNS FROM actions LIKE 'user_agent'");
            if (!empty($userAgentColumns)) {
                $this->execute("ALTER TABLE actions DROP COLUMN user_agent");
            }

            // Then, add installation_id column if it doesn't exist
            $columns = $this->fetchAll("SHOW COLUMNS FROM actions LIKE 'installation_id'");
            if (empty($columns)) {
                // Add installation_id column after ip_address
                $this->execute("
                    ALTER TABLE actions
                    ADD COLUMN installation_id VARCHAR(255) DEFAULT '0' AFTER ip_address,
                    ADD INDEX idx_installation_id (installation_id)
                ");
            }
        } catch (PDOException $e) {
            // If migration fails, table might already be in new format
            // or doesn't exist yet (will be created by CREATE TABLE IF NOT EXISTS)
        }
    }

    /**
     * Migrate existing rate_limits table to support endpoint types
     *
     * @return void
     */
    private function migrateRateLimitsTable(): void
    {
        try {
            // Check if endpoint_type column exists
            $columns = $this->fetchAll("SHOW COLUMNS FROM rate_limits LIKE 'endpoint_type'");

            if (empty($columns)) {
                // Old schema detected - need to migrate
                // Add endpoint_type column
                $this->execute("
                    ALTER TABLE rate_limits
                    ADD COLUMN endpoint_type VARCHAR(50) DEFAULT 'action' AFTER ip_address
                ");

                // Drop old primary key and create new composite one
                $this->execute("
                    ALTER TABLE rate_limits
                    DROP PRIMARY KEY,
                    ADD PRIMARY KEY (ip_address, endpoint_type)
                ");
            }
        } catch (PDOException $e) {
            // If migration fails, table might already be in new format
            // or doesn't exist yet (will be created by CREATE TABLE IF NOT EXISTS)
        }
    }
}
