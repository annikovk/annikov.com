<?php
/**
 * ActionTracker class - Core logic for tracking actions and statistics
 */

class ActionTracker
{
    private Database $db;
    private string $actionNamePattern;
    private int $maxActionLength;

    /**
     * @param Database $db Database instance
     * @param string $actionNamePattern Regex pattern for valid action names
     * @param int $maxActionLength Maximum action name length
     */
    public function __construct(
        Database $db,
        string $actionNamePattern = '/^[a-zA-Z0-9_-]{1,64}$/',
        int $maxActionLength = 64
    ) {
        $this->db = $db;
        $this->actionNamePattern = $actionNamePattern;
        $this->maxActionLength = $maxActionLength;
    }

    /**
     * Track an action
     *
     * @param string $actionName Name of the action to track
     * @param string $installationId Installation ID (defaults to '0' for backward compatibility)
     * @return int Total count for this action
     * @throws InvalidArgumentException If action name is invalid
     * @throws RuntimeException If tracking fails
     */
    public function track(string $actionName, string $installationId = '0'): int
    {
        // Validate action name
        if (!$this->isValidActionName($actionName)) {
            throw new InvalidArgumentException('Invalid action name format');
        }

        // Extract metadata
        $metadata = $this->extractMetadata();

        try {
            // Insert action record
            $this->db->execute(
                'INSERT INTO actions (action_name, timestamp, ip_address, installation_id)
                 VALUES (?, ?, ?, ?)',
                [
                    $actionName,
                    time(),
                    $metadata['ip'],
                    $installationId,
                ]
            );

            // Get updated count
            $count = $this->getCount($actionName);

            return $count;

        } catch (PDOException $e) {
            throw new RuntimeException('Failed to track action');
        }
    }

    /**
     * Get total count for a specific action
     *
     * @param string $actionName Name of the action
     * @return int Total count
     */
    public function getCount(string $actionName): int
    {
        try {
            $result = $this->db->fetchOne(
                'SELECT COUNT(*) as count FROM actions WHERE action_name = ?',
                [$actionName]
            );
            return $result !== null ? (int)$result['count'] : 0;
        } catch (PDOException $e) {
            return 0;
        }
    }

    /**
     * Get statistics with optional filters
     *
     * @param array $filters Optional filters (ip, installation_id, version)
     * @return array Statistics data
     */
    public function getStats(array $filters = []): array
    {
        try {
            $stats = [
                'total_actions' => 0,
                'unique_action_types' => 0,
                'unique_visitors' => 0,
                'unique_visitors_24h' => 0,
                'unique_visitors_7d' => 0,
                'unique_visitors_30d' => 0,
                'actions_24h' => 0,
                'actions_7d' => 0,
                'actions_30d' => 0,
            ];

            // Total actions
            $params = [];
            $filterClause = $this->buildFilterConditions($filters, $params);
            $result = $this->db->fetchOne('SELECT COUNT(*) as count FROM actions WHERE 1=1' . $filterClause, $params);
            $stats['total_actions'] = $result !== null ? (int)$result['count'] : 0;

            // Unique action types
            $params = [];
            $filterClause = $this->buildFilterConditions($filters, $params);
            $result = $this->db->fetchOne('SELECT COUNT(DISTINCT action_name) as count FROM actions WHERE 1=1' . $filterClause, $params);
            $stats['unique_action_types'] = $result !== null ? (int)$result['count'] : 0;

            // Unique visitors by IP (all-time)
            $params = [];
            $filterClause = $this->buildFilterConditions($filters, $params);
            $result = $this->db->fetchOne(
                'SELECT COUNT(DISTINCT ip_address) as count FROM actions WHERE ip_address IS NOT NULL' . $filterClause,
                $params
            );
            $stats['unique_visitors'] = $result !== null ? (int)$result['count'] : 0;

            // Unique visitors in last 24 hours
            $cutoff24h = time() - (24 * 3600);
            $params = [$cutoff24h];
            $filterClause = $this->buildFilterConditions($filters, $params);
            $result = $this->db->fetchOne(
                'SELECT COUNT(DISTINCT ip_address) as count FROM actions WHERE ip_address IS NOT NULL AND timestamp >= ?' . $filterClause,
                $params
            );
            $stats['unique_visitors_24h'] = $result !== null ? (int)$result['count'] : 0;

            // Unique visitors in last 7 days
            $cutoff7d = time() - (7 * 24 * 3600);
            $params = [$cutoff7d];
            $filterClause = $this->buildFilterConditions($filters, $params);
            $result = $this->db->fetchOne(
                'SELECT COUNT(DISTINCT ip_address) as count FROM actions WHERE ip_address IS NOT NULL AND timestamp >= ?' . $filterClause,
                $params
            );
            $stats['unique_visitors_7d'] = $result !== null ? (int)$result['count'] : 0;

            // Unique visitors in last 30 days
            $cutoff30d = time() - (30 * 24 * 3600);
            $params = [$cutoff30d];
            $filterClause = $this->buildFilterConditions($filters, $params);
            $result = $this->db->fetchOne(
                'SELECT COUNT(DISTINCT ip_address) as count FROM actions WHERE ip_address IS NOT NULL AND timestamp >= ?' . $filterClause,
                $params
            );
            $stats['unique_visitors_30d'] = $result !== null ? (int)$result['count'] : 0;

            // Actions in last 24 hours
            $cutoff24h = time() - (24 * 3600);
            $params = [$cutoff24h];
            $filterClause = $this->buildFilterConditions($filters, $params);
            $result = $this->db->fetchOne(
                'SELECT COUNT(*) as count FROM actions WHERE timestamp >= ?' . $filterClause,
                $params
            );
            $stats['actions_24h'] = $result !== null ? (int)$result['count'] : 0;

            // Actions in last 7 days
            $cutoff7d = time() - (7 * 24 * 3600);
            $params = [$cutoff7d];
            $filterClause = $this->buildFilterConditions($filters, $params);
            $result = $this->db->fetchOne(
                'SELECT COUNT(*) as count FROM actions WHERE timestamp >= ?' . $filterClause,
                $params
            );
            $stats['actions_7d'] = $result !== null ? (int)$result['count'] : 0;

            // Actions in last 30 days
            $cutoff30d = time() - (30 * 24 * 3600);
            $params = [$cutoff30d];
            $filterClause = $this->buildFilterConditions($filters, $params);
            $result = $this->db->fetchOne(
                'SELECT COUNT(*) as count FROM actions WHERE timestamp >= ?' . $filterClause,
                $params
            );
            $stats['actions_30d'] = $result !== null ? (int)$result['count'] : 0;

            return $stats;

        } catch (PDOException $e) {
            return [];
        }
    }

    /**
     * Build WHERE filter conditions from filters array
     *
     * @param array $filters Filters (ip, installation_id, version)
     * @param array $params Query params array (modified by reference)
     * @param string $tablePrefix Optional table alias prefix (e.g. 'a')
     * @return string SQL condition string (starts with ' AND ' if non-empty)
     */
    private function buildFilterConditions(array $filters, array &$params, string $tablePrefix = ''): string
    {
        $conditions = [];
        $p = $tablePrefix ? $tablePrefix . '.' : '';
        if (!empty($filters['ip'])) {
            $conditions[] = "{$p}ip_address = ?";
            $params[] = $filters['ip'];
        }
        if (!empty($filters['installation_id'])) {
            $conditions[] = "{$p}installation_id = ?";
            $params[] = $filters['installation_id'];
        }
        if (!empty($filters['version'])) {
            $conditions[] = "{$p}installation_id IN (SELECT DISTINCT installation_id FROM installations WHERE plugin_version = ? AND installation_id IS NOT NULL AND installation_id != '')";
            $params[] = $filters['version'];
        }
        return $conditions ? ' AND ' . implode(' AND ', $conditions) : '';
    }

    /**
     * Get recent actions
     *
     * @param int $limit Maximum number of actions to return
     * @return array Recent actions
     */
    public function getRecentActions(int $limit = 100): array
    {
        try {
            return $this->db->fetchAll(
                'SELECT action_name, timestamp, ip_address
                 FROM actions
                 ORDER BY id DESC
                 LIMIT ?',
                [$limit]
            );
        } catch (PDOException $e) {
            return [];
        }
    }

    /**
     * Get top actions by count
     *
     * @param int $limit Maximum number of actions to return
     * @param array $filters Optional filters (ip, installation_id, version)
     * @return array Top actions with unique visitor counts
     */
    public function getTopActions(int $limit = 10, array $filters = []): array
    {
        try {
            $params = [];
            $filterClause = $this->buildFilterConditions($filters, $params);
            $params[] = $limit;
            return $this->db->fetchAll(
                'SELECT
                    action_name,
                    COUNT(*) as total_count,
                    COUNT(DISTINCT ip_address) as unique_visitors
                 FROM actions
                 WHERE 1=1' . $filterClause . '
                 GROUP BY action_name
                 ORDER BY total_count DESC
                 LIMIT ?',
                $params
            );
        } catch (PDOException $e) {
            return [];
        }
    }

    /**
     * Validate action name format
     *
     * @param string $actionName Action name to validate
     * @return bool True if valid, false otherwise
     */
    private function isValidActionName(string $actionName): bool
    {
        if (empty($actionName) || strlen($actionName) > $this->maxActionLength) {
            return false;
        }

        return preg_match($this->actionNamePattern, $actionName) === 1;
    }

    /**
     * Get visitor summary grouped by IP address
     *
     * @param array $filters Optional filters (ip, installation_id, version)
     * @return array Visitor data with actions used and execution timestamps
     */
    public function getVisitorSummary(array $filters = []): array
    {
        try {
            $params = [];
            $filterClause = $this->buildFilterConditions($filters, $params);
            return $this->db->fetchAll(
                'SELECT
                    ip_address,
                    GROUP_CONCAT(DISTINCT action_name ORDER BY action_name SEPARATOR \', \') AS actions_used,
                    FROM_UNIXTIME(MIN(timestamp)) AS first_executed,
                    FROM_UNIXTIME(MAX(timestamp)) AS last_executed,
                    COUNT(*) as total_actions
                 FROM actions
                 WHERE ip_address IS NOT NULL' . $filterClause . '
                 GROUP BY ip_address
                 ORDER BY ip_address',
                $params
            );
        } catch (PDOException $e) {
            return [];
        }
    }

    /**
     * Extract request metadata
     *
     * @return array Metadata (ip)
     */
    private function extractMetadata(): array
    {
        return [
            'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
        ];
    }
}
