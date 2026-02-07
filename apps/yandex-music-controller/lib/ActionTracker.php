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
     * @return int Total count for this action
     * @throws InvalidArgumentException If action name is invalid
     * @throws RuntimeException If tracking fails
     */
    public function track(string $actionName): int
    {
        // Validate action name
        if (!$this->isValidActionName($actionName)) {
            throw new InvalidArgumentException('Invalid action name format');
        }

        // Extract metadata
        $metadata = $this->extractMetadata();

        try {
            $this->db->beginTransaction();

            // Insert action record
            $this->db->execute(
                'INSERT INTO actions (action_name, timestamp, ip_address, user_agent, referer)
                 VALUES (?, ?, ?, ?, ?)',
                [
                    $actionName,
                    time(),
                    $metadata['ip'],
                    $metadata['user_agent'],
                    $metadata['referer'],
                ]
            );

            // Update action stats
            $this->db->execute(
                'INSERT INTO action_stats (action_name, total_count, last_updated)
                 VALUES (?, 1, ?)
                 ON DUPLICATE KEY UPDATE
                 total_count = total_count + 1,
                 last_updated = ?',
                [$actionName, time(), time()]
            );

            // Get updated count
            $count = $this->getCount($actionName);

            $this->db->commit();

            return $count;

        } catch (PDOException $e) {
            $this->db->rollback();
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
                'SELECT total_count FROM action_stats WHERE action_name = ?',
                [$actionName]
            );
            return $result !== null ? (int)$result['total_count'] : 0;
        } catch (PDOException $e) {
            return 0;
        }
    }

    /**
     * Get statistics with optional filters
     *
     * @param array $filters Optional filters (date_from, date_to)
     * @return array Statistics data
     */
    public function getStats(array $filters = []): array
    {
        try {
            $dateFrom = $filters['date_from'] ?? null;
            $dateTo = $filters['date_to'] ?? null;

            $stats = [
                'total_actions' => 0,
                'unique_action_types' => 0,
                'actions_24h' => 0,
                'actions_7d' => 0,
                'actions_30d' => 0,
            ];

            // Total actions
            $result = $this->db->fetchOne('SELECT SUM(total_count) as total FROM action_stats');
            $stats['total_actions'] = $result !== null ? (int)$result['total'] : 0;

            // Unique action types
            $result = $this->db->fetchOne('SELECT COUNT(*) as count FROM action_stats');
            $stats['unique_action_types'] = $result !== null ? (int)$result['count'] : 0;

            // Actions in last 24 hours
            $cutoff24h = time() - (24 * 3600);
            $result = $this->db->fetchOne(
                'SELECT COUNT(*) as count FROM actions WHERE timestamp >= ?',
                [$cutoff24h]
            );
            $stats['actions_24h'] = $result !== null ? (int)$result['count'] : 0;

            // Actions in last 7 days
            $cutoff7d = time() - (7 * 24 * 3600);
            $result = $this->db->fetchOne(
                'SELECT COUNT(*) as count FROM actions WHERE timestamp >= ?',
                [$cutoff7d]
            );
            $stats['actions_7d'] = $result !== null ? (int)$result['count'] : 0;

            // Actions in last 30 days
            $cutoff30d = time() - (30 * 24 * 3600);
            $result = $this->db->fetchOne(
                'SELECT COUNT(*) as count FROM actions WHERE timestamp >= ?',
                [$cutoff30d]
            );
            $stats['actions_30d'] = $result !== null ? (int)$result['count'] : 0;

            return $stats;

        } catch (PDOException $e) {
            return [];
        }
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
     * @return array Top actions
     */
    public function getTopActions(int $limit = 10): array
    {
        try {
            return $this->db->fetchAll(
                'SELECT action_name, total_count
                 FROM action_stats
                 ORDER BY total_count DESC
                 LIMIT ?',
                [$limit]
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
     * Extract request metadata
     *
     * @return array Metadata (ip, user_agent, referer)
     */
    private function extractMetadata(): array
    {
        return [
            'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
            'referer' => $_SERVER['HTTP_REFERER'] ?? null,
        ];
    }
}
