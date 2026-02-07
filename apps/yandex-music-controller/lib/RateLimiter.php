<?php
/**
 * RateLimiter class - Implements rate limiting using sliding window
 */

class RateLimiter
{
    private Database $db;
    private int $maxRequests;
    private int $windowSeconds;
    private float $cleanupProbability;

    /**
     * @param Database $db Database instance
     * @param int $maxRequests Maximum requests per window
     * @param int $windowSeconds Time window in seconds
     * @param float $cleanupProbability Probability of cleanup (0.0 to 1.0)
     */
    public function __construct(
        Database $db,
        int $maxRequests = 1000,
        int $windowSeconds = 3600,
        float $cleanupProbability = 0.01
    ) {
        $this->db = $db;
        $this->maxRequests = $maxRequests;
        $this->windowSeconds = $windowSeconds;
        $this->cleanupProbability = $cleanupProbability;
    }

    /**
     * Check if IP address is within rate limit
     *
     * @param string $ipAddress IP address to check
     * @return bool True if within limit, false if exceeded
     */
    public function check(string $ipAddress): bool
    {
        $currentTime = time();
        $windowStart = $currentTime - $this->windowSeconds;

        try {
            // Get current rate limit record
            $record = $this->db->fetchOne(
                'SELECT request_count, window_start FROM rate_limits WHERE ip_address = ?',
                [$ipAddress]
            );

            if ($record === null) {
                // No record exists, within limit
                return true;
            }

            // Check if current window is still valid
            if ($record['window_start'] < $windowStart) {
                // Window expired, reset counter
                $this->db->execute(
                    'UPDATE rate_limits SET request_count = 0, window_start = ? WHERE ip_address = ?',
                    [$currentTime, $ipAddress]
                );
                return true;
            }

            // Check if under limit
            if ($record['request_count'] < $this->maxRequests) {
                return true;
            }

            // Rate limit exceeded
            return false;

        } catch (PDOException $e) {
            // On error, allow the request to proceed
            return true;
        }
    }

    /**
     * Increment request counter for IP address
     *
     * @param string $ipAddress IP address to increment
     * @return void
     */
    public function increment(string $ipAddress): void
    {
        $currentTime = time();

        try {
            // Try to update existing record
            $updated = $this->db->execute(
                'UPDATE rate_limits
                 SET request_count = request_count + 1
                 WHERE ip_address = ? AND window_start >= ?',
                [$ipAddress, $currentTime - $this->windowSeconds]
            );

            // If no rows updated, insert new record
            if ($updated === false || $this->db->query('SELECT ROW_COUNT()')->fetchColumn() === 0) {
                $this->db->execute(
                    'INSERT INTO rate_limits (ip_address, request_count, window_start)
                     VALUES (?, 1, ?)
                     ON DUPLICATE KEY UPDATE request_count = 1, window_start = ?',
                    [$ipAddress, $currentTime, $currentTime]
                );
            }

            // Probabilistic cleanup of old records
            if (mt_rand() / mt_getrandmax() < $this->cleanupProbability) {
                $this->cleanup();
            }

        } catch (PDOException $e) {
            // Silent failure
        }
    }

    /**
     * Clean up expired rate limit records
     *
     * @return void
     */
    private function cleanup(): void
    {
        try {
            $cutoffTime = time() - (24 * 3600); // Remove records older than 24 hours
            $this->db->execute(
                'DELETE FROM rate_limits WHERE window_start < ?',
                [$cutoffTime]
            );
        } catch (PDOException $e) {
            // Silent failure
        }
    }
}
