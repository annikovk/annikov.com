<?php
/**
 * Statistics Dashboard for Action Tracker
 *
 * Protected by HTTP Basic Auth
 */

// Load configuration and classes
$config = require dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/lib/Database.php';
require_once dirname(__DIR__) . '/lib/ActionTracker.php';

try {
    $db = Database::getInstance($config['database']);
    $tracker = new ActionTracker($db);

    // Get statistics
    $stats = $tracker->getStats();
    $topActions = $tracker->getTopActions(10);
    $visitorSummary = $tracker->getVisitorSummary();

    // Handle CSV export
    if (isset($_GET['export']) && $_GET['export'] === 'csv') {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="visitor-summary-export-' . date('Y-m-d') . '.csv"');

        $output = fopen('php://output', 'w');
        fputcsv($output, ['IP Address', 'Actions Used', 'Total Actions', 'First Executed', 'Last Executed']);

        foreach ($visitorSummary as $visitor) {
            fputcsv($output, [
                $visitor['ip_address'],
                $visitor['actions_used'],
                $visitor['total_actions'],
                $visitor['first_executed'],
                $visitor['last_executed']
            ]);
        }

        fclose($output);
        exit;
    }

} catch (Exception $e) {
    $stats = [];
    $topActions = [];
    $visitorSummary = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Action Tracker Dashboard</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
            color: #333;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
        }

        .header {
            background: white;
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 8px 16px rgba(0,0,0,0.1);
            margin-bottom: 30px;
        }

        h1 {
            font-size: 28px;
            color: #667eea;
            margin-bottom: 10px;
        }

        .subtitle {
            color: #666;
            font-size: 14px;
        }

        .actions {
            display: flex;
            gap: 10px;
            margin-top: 20px;
        }

        .btn {
            background: #667eea;
            color: white;
            padding: 10px 20px;
            border-radius: 6px;
            text-decoration: none;
            font-size: 14px;
            border: none;
            cursor: pointer;
            transition: background 0.3s;
        }

        .btn:hover {
            background: #5568d3;
        }

        .btn-secondary {
            background: #6c757d;
        }

        .btn-secondary:hover {
            background: #5a6268;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            padding: 25px;
            border-radius: 12px;
            box-shadow: 0 8px 16px rgba(0,0,0,0.1);
        }

        .stat-label {
            font-size: 14px;
            color: #666;
            margin-bottom: 10px;
        }

        .stat-value {
            font-size: 32px;
            font-weight: bold;
            color: #667eea;
        }

        .section {
            background: white;
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 8px 16px rgba(0,0,0,0.1);
            margin-bottom: 30px;
        }

        h2 {
            font-size: 20px;
            margin-bottom: 20px;
            color: #333;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th, td {
            text-align: left;
            padding: 12px;
            border-bottom: 1px solid #eee;
        }

        th {
            background: #f8f9fa;
            font-weight: 600;
            color: #666;
            font-size: 14px;
        }

        td {
            font-size: 14px;
        }

        tr:hover {
            background: #f8f9fa;
        }

        .timestamp {
            color: #666;
            font-size: 12px;
        }

        .no-data {
            text-align: center;
            padding: 40px;
            color: #999;
        }

        .sortable {
            cursor: pointer;
            user-select: none;
            position: relative;
            padding-right: 20px;
        }

        .sortable:hover {
            background: #e9ecef;
        }

        .sortable::after {
            content: '⇅';
            position: absolute;
            right: 8px;
            opacity: 0.3;
            font-size: 12px;
        }

        .sortable.asc::after {
            content: '▲';
            opacity: 1;
        }

        .sortable.desc::after {
            content: '▼';
            opacity: 1;
        }

        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }

            table {
                font-size: 12px;
            }

            th, td {
                padding: 8px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Action Tracker Dashboard</h1>
            <p class="subtitle">Yandex Music Controller Analytics</p>
            <div class="actions">
                <button class="btn" onclick="location.reload()">Refresh</button>
                <a href="?export=csv" class="btn btn-secondary">Export CSV</a>
                <button class="btn btn-secondary" onclick="toggleAutoRefresh()">Auto-Refresh: <span id="autoRefreshStatus">OFF</span></button>
            </div>
        </div>

        <?php if (!empty($stats)): ?>
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-label">Total Actions</div>
                <div class="stat-value"><?= number_format($stats['total_actions']) ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Unique Actions</div>
                <div class="stat-value"><?= number_format($stats['unique_action_types']) ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Unique Visitors</div>
                <div class="stat-value"><?= number_format($stats['unique_visitors']) ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Actions (24h)</div>
                <div class="stat-value"><?= number_format($stats['actions_24h']) ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Visitors (24h)</div>
                <div class="stat-value"><?= number_format($stats['unique_visitors_24h']) ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Actions (7d)</div>
                <div class="stat-value"><?= number_format($stats['actions_7d']) ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Visitors (7d)</div>
                <div class="stat-value"><?= number_format($stats['unique_visitors_7d']) ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Actions (30d)</div>
                <div class="stat-value"><?= number_format($stats['actions_30d']) ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Visitors (30d)</div>
                <div class="stat-value"><?= number_format($stats['unique_visitors_30d']) ?></div>
            </div>
        </div>
        <?php endif; ?>

        <?php if (!empty($topActions)): ?>
        <div class="section">
            <h2>Top 10 Most Popular Actions</h2>
            <table id="topActionsTable">
                <thead>
                    <tr>
                        <th class="sortable" data-column="0" data-type="string">Action Name</th>
                        <th class="sortable" data-column="1" data-type="number" style="text-align: right;">Total Count</th>
                        <th class="sortable" data-column="2" data-type="number" style="text-align: right;">Unique Visitors</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($topActions as $action): ?>
                    <tr>
                        <td><?= htmlspecialchars($action['action_name']) ?></td>
                        <td style="text-align: right;" data-value="<?= $action['total_count'] ?>"><?= number_format($action['total_count']) ?></td>
                        <td style="text-align: right;" data-value="<?= $action['unique_visitors'] ?>"><?= number_format($action['unique_visitors']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <?php if (!empty($visitorSummary)): ?>
        <div class="section">
            <h2>Visitor Summary</h2>
            <table id="visitorSummaryTable">
                <thead>
                    <tr>
                        <th class="sortable" data-column="0" data-type="ip">IP Address</th>
                        <th class="sortable" data-column="1" data-type="string">Actions Used</th>
                        <th class="sortable" data-column="2" data-type="number" style="text-align: right;">Total Actions</th>
                        <th class="sortable" data-column="3" data-type="date">First Executed</th>
                        <th class="sortable" data-column="4" data-type="date">Last Executed</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($visitorSummary as $visitor): ?>
                    <tr>
                        <td><?= htmlspecialchars($visitor['ip_address']) ?></td>
                        <td style="max-width: 400px; word-wrap: break-word;"><?= htmlspecialchars($visitor['actions_used']) ?></td>
                        <td style="text-align: right;" data-value="<?= $visitor['total_actions'] ?>"><?= number_format($visitor['total_actions']) ?></td>
                        <td class="timestamp" data-value="<?= htmlspecialchars($visitor['first_executed']) ?>"><?= htmlspecialchars($visitor['first_executed']) ?></td>
                        <td class="timestamp" data-value="<?= htmlspecialchars($visitor['last_executed']) ?>"><?= htmlspecialchars($visitor['last_executed']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <div class="section">
            <div class="no-data">No visitors tracked yet</div>
        </div>
        <?php endif; ?>
    </div>

    <script>
        let autoRefreshInterval = null;

        function toggleAutoRefresh() {
            if (autoRefreshInterval === null) {
                autoRefreshInterval = setInterval(() => {
                    location.reload();
                }, 30000); // 30 seconds
                document.getElementById('autoRefreshStatus').textContent = 'ON';
            } else {
                clearInterval(autoRefreshInterval);
                autoRefreshInterval = null;
                document.getElementById('autoRefreshStatus').textContent = 'OFF';
            }
        }

        // Table sorting functionality
        function sortTable(table, column, type, direction) {
            const tbody = table.querySelector('tbody');
            const rows = Array.from(tbody.querySelectorAll('tr'));

            rows.sort((a, b) => {
                let aVal = a.cells[column].getAttribute('data-value') || a.cells[column].textContent.trim();
                let bVal = b.cells[column].getAttribute('data-value') || b.cells[column].textContent.trim();

                // Convert values based on type
                if (type === 'number') {
                    aVal = parseFloat(aVal.replace(/,/g, '')) || 0;
                    bVal = parseFloat(bVal.replace(/,/g, '')) || 0;
                } else if (type === 'date') {
                    aVal = new Date(aVal).getTime() || 0;
                    bVal = new Date(bVal).getTime() || 0;
                } else if (type === 'ip') {
                    // Sort IP addresses correctly (e.g., 192.168.1.1)
                    aVal = aVal.split('.').map(num => num.padStart(3, '0')).join('.');
                    bVal = bVal.split('.').map(num => num.padStart(3, '0')).join('.');
                } else {
                    // String comparison (case-insensitive)
                    aVal = aVal.toLowerCase();
                    bVal = bVal.toLowerCase();
                }

                if (aVal < bVal) return direction === 'asc' ? -1 : 1;
                if (aVal > bVal) return direction === 'asc' ? 1 : -1;
                return 0;
            });

            // Re-append rows in sorted order
            rows.forEach(row => tbody.appendChild(row));
        }

        // Initialize sorting for all tables
        document.addEventListener('DOMContentLoaded', function() {
            document.querySelectorAll('.sortable').forEach(header => {
                header.addEventListener('click', function() {
                    const table = this.closest('table');
                    const column = parseInt(this.getAttribute('data-column'));
                    const type = this.getAttribute('data-type');

                    // Toggle sort direction
                    let direction = 'asc';
                    if (this.classList.contains('asc')) {
                        direction = 'desc';
                    }

                    // Remove sort classes from all headers in this table
                    table.querySelectorAll('.sortable').forEach(h => {
                        h.classList.remove('asc', 'desc');
                    });

                    // Add sort class to clicked header
                    this.classList.add(direction);

                    // Sort the table
                    sortTable(table, column, type, direction);
                });
            });
        });
    </script>
</body>
</html>
