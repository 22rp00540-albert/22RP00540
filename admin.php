<?php
require 'db.php';
session_start();
if (!isset($_SESSION['admin'])) {
    header("Location: login.php");
    exit;
}

// Setup
$perPage = 8;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$startAt = ($page - 1) * $perPage;

$statusFilter = $_GET['status'] ?? '';
$searchRegno = $_GET['search'] ?? '';

$params = [];
$where = "";

if ($statusFilter && in_array($statusFilter, ['pending', 'under_review', 'resolved'])) {
    $where .= " AND a.status = ?";
    $params[] = $statusFilter;
}

if ($searchRegno) {
    $where .= " AND s.regno LIKE ?";
    $params[] = "%$searchRegno%";
}

$sql = "SELECT a.id, s.name AS student_name, s.regno, m.module_name, a.reason, a.status, mk.mark,
               DATE_FORMAT(a.created_at, '%e %b %Y') as formatted_date
        FROM appeals a 
        JOIN students s ON a.student_regno = s.regno 
        JOIN modules m ON a.module_id = m.id 
        LEFT JOIN marks mk ON mk.student_regno = s.regno AND mk.module_id = m.id
        WHERE 1 $where
        ORDER BY a.created_at DESC
        LIMIT $startAt, $perPage";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$appeals = $stmt->fetchAll();

$countStmt = $pdo->prepare("SELECT COUNT(*) 
                            FROM appeals a 
                            JOIN students s ON a.student_regno = s.regno 
                            WHERE 1 $where");
$countStmt->execute($params);
$totalAppeals = $countStmt->fetchColumn();
$totalPages = ceil($totalAppeals / $perPage);

// Get stats for cards
$statsStmt = $pdo->query("SELECT 
    COUNT(*) as total,
    SUM(status = 'pending') as pending,
    SUM(status = 'under_review') as under_review,
    SUM(status = 'resolved') as resolved
    FROM appeals");
$stats = $statsStmt->fetch();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - Appeals Management</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        :root {
            --primary: #4f46e5;
            --primary-light: #6366f1;
            --primary-dark: #4338ca;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --info: #3b82f6;
            --light: #f9fafb;
            --dark: #111827;
            --gray: #6b7280;
            --gray-light: #e5e7eb;
            --border-radius: 0.5rem;
            --shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            --transition: all 0.3s ease;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', 'Segoe UI', sans-serif;
            background-color: #f3f4f6;
            color: var(--dark);
            line-height: 1.6;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 1.5rem;
        }

        /* Header */
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid var(--gray-light);
        }

        .header h1 {
            font-size: 1.75rem;
            color: var(--primary);
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .header-actions {
            display: flex;
            gap: 1rem;
            align-items: center;
        }

        .logout-btn {
            background-color: var(--danger);
            color: white;
            padding: 0.5rem 1rem;
            border-radius: var(--border-radius);
            text-decoration: none;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            transition: var(--transition);
        }

        .logout-btn:hover {
            background-color: #dc2626;
            transform: translateY(-1px);
        }

        /* Stats Cards */
        .stats-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2.5rem;
        }

        .stat-card {
            background: white;
            border-radius: var(--border-radius);
            padding: 1.5rem;
            box-shadow: var(--shadow);
            position: relative;
            overflow: hidden;
        }

        .stat-card::after {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 4px;
            height: 100%;
        }

        .stat-card.total::after { background-color: var(--primary); }
        .stat-card.pending::after { background-color: var(--warning); }
        .stat-card.under_review::after { background-color: var(--info); }
        .stat-card.resolved::after { background-color: var(--success); }

        .stat-card h3 {
            font-size: 0.875rem;
            color: var(--gray);
            margin-bottom: 0.5rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .stat-card .value {
            font-size: 1.75rem;
            font-weight: 700;
            margin-bottom: 0.25rem;
        }

        .stat-card .change {
            font-size: 0.875rem;
            color: var(--gray);
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }

        /* Filter Section */
        .filter-section {
            background: white;
            border-radius: var(--border-radius);
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: var(--shadow);
        }

        .filter-form {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            align-items: center;
        }

        .filter-group {
            flex: 1;
            min-width: 200px;
        }

        .filter-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-size: 0.875rem;
            font-weight: 500;
            color: var(--gray);
        }

        .filter-group input,
        .filter-group select {
            width: 100%;
            padding: 0.625rem 1rem;
            border: 1px solid var(--gray-light);
            border-radius: var(--border-radius);
            font-size: 0.9375rem;
            transition: var(--transition);
        }

        .filter-group input:focus,
        .filter-group select:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
        }

        .filter-btn {
            background-color: var(--primary);
            color: white;
            padding: 0.625rem 1.5rem;
            border: none;
            border-radius: var(--border-radius);
            font-weight: 500;
            cursor: pointer;
            transition: var(--transition);
            align-self: flex-end;
        }

        .filter-btn:hover {
            background-color: var(--primary-dark);
            transform: translateY(-1px);
        }

        /* Table */
        .table-container {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            overflow: hidden;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th, td {
            padding: 1rem 1.25rem;
            text-align: left;
        }

        th {
            background-color: var(--primary);
            color: white;
            font-weight: 500;
            text-transform: uppercase;
            font-size: 0.75rem;
            letter-spacing: 0.05em;
        }

        tr:nth-child(even) {
            background-color: #f9fafb;
        }

        tr:hover {
            background-color: #f3f4f6;
        }

        td {
            font-size: 0.9375rem;
            border-bottom: 1px solid var(--gray-light);
        }

        .status-badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 500;
        }

        .status-pending {
            background-color: #fef3c7;
            color: #92400e;
        }

        .status-under_review {
            background-color: #dbeafe;
            color: #1e40af;
        }

        .status-resolved {
            background-color: #d1fae5;
            color: #065f46;
        }

        .action-form {
            display: flex;
            gap: 0.5rem;
        }

        .action-form select {
            padding: 0.375rem 0.75rem;
            border: 1px solid var(--gray-light);
            border-radius: var(--border-radius);
            font-size: 0.875rem;
            background-color: white;
        }

        .action-form button {
            background-color: var(--primary);
            color: white;
            border: none;
            border-radius: var(--border-radius);
            padding: 0.375rem 0.75rem;
            font-size: 0.875rem;
            cursor: pointer;
            transition: var(--transition);
        }

        .action-form button:hover {
            background-color: var(--primary-dark);
        }

        /* Pagination */
        .pagination {
            display: flex;
            justify-content: center;
            padding: 1.5rem;
            gap: 0.5rem;
        }

        .pagination a, 
        .pagination span {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 2.5rem;
            height: 2.5rem;
            border-radius: var(--border-radius);
            text-decoration: none;
            font-weight: 500;
            transition: var(--transition);
        }

        .pagination a {
            color: var(--gray);
            background-color: white;
            border: 1px solid var(--gray-light);
        }

        .pagination a:hover {
            background-color: var(--primary);
            color: white;
            border-color: var(--primary);
        }

        .pagination .current {
            background-color: var(--primary);
            color: white;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 3rem;
            color: var(--gray);
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            color: var(--gray-light);
        }

        /* Responsive */
        @media (max-width: 768px) {
            .header {
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
            }
            
            .filter-form {
                flex-direction: column;
                align-items: stretch;
            }
            
            .filter-group {
                width: 100%;
            }
            
            table {
                display: block;
                overflow-x: auto;
            }
        }

        /* Animations */
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .stat-card {
            animation: fadeIn 0.5s ease-out forwards;
        }

        .stat-card:nth-child(1) { animation-delay: 0.1s; }
        .stat-card:nth-child(2) { animation-delay: 0.2s; }
        .stat-card:nth-child(3) { animation-delay: 0.3s; }
        .stat-card:nth-child(4) { animation-delay: 0.4s; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>
                <i class="fas fa-gavel"></i>
                Appeals Management Dashboard
            </h1>
            <div class="header-actions">
                <span class="welcome-msg">Welcome, <?= htmlspecialchars($_SESSION['admin']) ?></span>
                <a href="logout.php" class="logout-btn">
                    <i class="fas fa-sign-out-alt"></i>
                    Logout
                </a>
            </div>
        </div>

        <!-- Stats Cards -->
        <div class="stats-container">
            <div class="stat-card total">
                <h3>Total Appeals</h3>
                <div class="value"><?= $stats['total'] ?></div>
                <div class="change">
                    <i class="fas fa-chart-line"></i>
                    <span>All time records</span>
                </div>
            </div>
            
            <div class="stat-card pending">
                <h3>Pending</h3>
                <div class="value"><?= $stats['pending'] ?></div>
                <div class="change">
                    <i class="fas fa-clock"></i>
                    <span>Awaiting review</span>
                </div>
            </div>
            
            <div class="stat-card under_review">
                <h3>Under Review</h3>
                <div class="value"><?= $stats['under_review'] ?></div>
                <div class="change">
                    <i class="fas fa-search"></i>
                    <span>In progress</span>
                </div>
            </div>
            
            <div class="stat-card resolved">
                <h3>Resolved</h3>
                <div class="value"><?= $stats['resolved'] ?></div>
                <div class="change">
                    <i class="fas fa-check-circle"></i>
                    <span>Completed</span>
                </div>
            </div>
        </div>

        <!-- Filter Section -->
        <div class="filter-section">
            <form method="get" class="filter-form">
                <div class="filter-group">
                    <label for="search">Search by RegNo</label>
                    <input type="text" id="search" name="search" placeholder="Enter student registration number" 
                           value="<?= htmlspecialchars($searchRegno) ?>">
                </div>
                
                <div class="filter-group">
                    <label for="status">Filter by Status</label>
                    <select id="status" name="status">
                        <option value="">All Statuses</option>
                        <option value="pending" <?= $statusFilter == 'pending' ? 'selected' : '' ?>>Pending</option>
                        <option value="under_review" <?= $statusFilter == 'under_review' ? 'selected' : '' ?>>Under Review</option>
                        <option value="resolved" <?= $statusFilter == 'resolved' ? 'selected' : '' ?>>Resolved</option>
                    </select>
                </div>
                
                <button type="submit" class="filter-btn">
                    <i class="fas fa-filter"></i>
                    Apply Filters
                </button>
            </form>
        </div>

        <!-- Appeals Table -->
        <div class="table-container">
            <?php if (count($appeals) > 0): ?>
                <table>
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Student</th>
                            <th>Module</th>
                            <th>Mark</th>
                            <th>Reason</th>
                            <th>Date</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($appeals as $i => $a): ?>
                            <tr>
                                <td><?= $i + 1 + $startAt ?></td>
                                <td>
                                    <strong><?= htmlspecialchars($a['student_name']) ?></strong>
                                    <div class="text-muted"><?= $a['regno'] ?></div>
                                </td>
                                <td><?= htmlspecialchars($a['module_name']) ?></td>
                                <td><?= is_numeric($a['mark']) ? $a['mark'] : 'N/A' ?></td>
                                <td><?= htmlspecialchars($a['reason']) ?></td>
                                <td><?= $a['formatted_date'] ?></td>
                                <td>
                                    <span class="status-badge status-<?= $a['status'] ?>">
                                        <?= ucfirst(str_replace('_', ' ', $a['status'])) ?>
                                    </span>
                                </td>
                                <td>
                                    <form method="post" action="update_status.php" class="action-form">
                                        <input type="hidden" name="id" value="<?= $a['id'] ?>">
                                        <select name="status">
                                            <option value="pending" <?= $a['status'] == 'pending' ? 'selected' : '' ?>>Pending</option>
                                            <option value="under_review" <?= $a['status'] == 'under_review' ? 'selected' : '' ?>>Under Review</option>
                                            <option value="resolved" <?= $a['status'] == 'resolved' ? 'selected' : '' ?>>Resolved</option>
                                        </select>
                                        <button type="submit" title="Update Status">
                                            <i class="fas fa-save"></i>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-inbox"></i>
                    <h3>No appeals found</h3>
                    <p>There are currently no appeals matching your criteria</p>
                </div>
            <?php endif; ?>
        </div>

        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
            <div class="pagination">
                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                    <?php if ($i == $page): ?>
                        <span class="current"><?= $i ?></span>
                    <?php else: ?>
                        <a href="?page=<?= $i ?>&status=<?= urlencode($statusFilter) ?>&search=<?= urlencode($searchRegno) ?>"><?= $i ?></a>
                    <?php endif; ?>
                <?php endfor; ?>
            </div>
        <?php endif; ?>
    </div>

    <script>
        // Add hover effects and animations
        document.addEventListener('DOMContentLoaded', function() {
            // Add hover effect to table rows
            const rows = document.querySelectorAll('tbody tr');
            rows.forEach(row => {
                row.addEventListener('mouseenter', function() {
                    this.style.transform = 'translateX(4px)';
                    this.style.transition = 'transform 0.2s ease';
                });
                row.addEventListener('mouseleave', function() {
                    this.style.transform = 'translateX(0)';
                });
            });

            // Add focus styles to form elements
            const inputs = document.querySelectorAll('input, select');
            inputs.forEach(input => {
                input.addEventListener('focus', function() {
                    this.parentElement.style.transform = 'scale(1.02)';
                });
                input.addEventListener('blur', function() {
                    this.parentElement.style.transform = 'scale(1)';
                });
            });
        });
    </script>
</body>
</html>