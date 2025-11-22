<?php
/**
 * Admin Dashboard - PHP Backend Integration
 */

// Include authentication
require_once __DIR__ . '/../backend/auth/auth.php';

// Require admin authentication
requireAuth('admin');

// Get current user
$currentUser = getCurrentUser();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - AssessPro</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Toast Notification */
        .toast {
            position: fixed;
            left: 50%;
            bottom: 40px;
            transform: translateX(-50%);
            min-width: 220px;
            max-width: 90vw;
            background: #222;
            color: #fff;
            padding: 1rem 2rem;
            border-radius: 6px;
            font-size: 1rem;
            box-shadow: 0 4px 16px rgba(0,0,0,0.18);
            z-index: 9999;
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.3s, bottom 0.3s;
        }
        .toast.show {
            opacity: 1;
            pointer-events: auto;
            bottom: 60px;
        }
        .toast.success { background: #2d8659; }
        .toast.error { background: #c0392b; }
        .toast.info { background: #2980b9; }
        .toast.warning { background: #f39c12; color: #222; }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            --primary-green: #2d8659;
            --accent-green: #4ade80;
            --light-green: #f0f9f4;
            --dark-green: #1e5a3a;
            --text-dark: #1f2937;
            --text-light: #6b7280;
            --white: #ffffff;
            --border-light: #e5e7eb;
            --sidebar-width: 280px;
            --header-height: 70px;
            --bg-light: #f8fafc;
            --shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
        }

        body {
            font-family: 'Poppins', sans-serif;
            background: var(--bg-light);
            color: var(--text-dark);
            line-height: 1.6;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
        }

        /* Layout */
        .admin-layout {
            display: flex;
            min-height: 100vh;
        }

        /* Sidebar */
        .sidebar {
            width: var(--sidebar-width);
            background: var(--white);
            box-shadow: var(--shadow);
            position: fixed;
            height: 100vh;
            left: 0;
            top: 0;
            z-index: 1000;
            transition: transform 0.3s ease;
            overflow-y: auto;
        }

        .sidebar.collapsed {
            transform: translateX(-100%);
        }

        .sidebar-header {
            padding: 1.5rem;
            border-bottom: 1px solid var(--border-light);
            background: var(--primary-green);
            color: var(--white);
        }

        .sidebar-logo {
            display: flex;
            align-items: center;
            font-size: 1.25rem;
            font-weight: 700;
        }

        .sidebar-logo i {
            margin-right: 0.75rem;
            font-size: 1.5rem;
        }

        .sidebar-nav {
            padding: 1rem 0;
        }

        .nav-section {
            margin-bottom: 2rem;
        }

        .nav-section-title {
            padding: 0 1.5rem 0.5rem;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--text-light);
        }

        .nav-item {
            margin-bottom: 0.25rem;
        }

        .nav-link {
            display: flex;
            align-items: center;
            padding: 0.875rem 1.5rem;
            color: var(--text-dark);
            text-decoration: none;
            transition: all 0.2s ease;
            position: relative;
            min-height: 48px;
            cursor: pointer;
        }

        .nav-link:hover {
            background: var(--light-green);
            color: var(--primary-green);
        }

        .nav-link.active {
            background: var(--light-green);
            color: var(--primary-green);
            border-right: 3px solid var(--primary-green);
        }

        .nav-link i {
            width: 20px;
            margin-right: 0.875rem;
            font-size: 1.125rem;
        }

        .nav-link .badge {
            margin-left: auto;
            background: var(--accent-green);
            color: var(--white);
            font-size: 0.75rem;
            padding: 0.25rem 0.5rem;
            border-radius: 12px;
            font-weight: 500;
        }

        /* Main Content */
        .main-content {
            flex: 1;
            margin-left: var(--sidebar-width);
            min-height: 100vh;
            transition: margin-left 0.3s ease;
        }

        .main-content.expanded {
            margin-left: 0;
        }

        /* Header */
        .header {
            background: var(--white);
            height: var(--header-height);
            box-shadow: var(--shadow);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 2rem;
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .header-left {
            display: flex;
            align-items: center;
        }

        .mobile-menu-btn {
            display: none;
            background: none;
            border: none;
            font-size: 1.5rem;
            color: var(--text-dark);
            cursor: pointer;
            margin-right: 1rem;
        }

        .header-title {
            font-size: 1.5rem;
            font-weight: 600;
            color: var(--text-dark);
        }

        .header-right {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .user-menu {
            position: relative;
        }

        .user-btn {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            background: none;
            border: none;
            cursor: pointer;
            padding: 0.5rem;
            border-radius: 8px;
            transition: background 0.2s;
        }

        .user-btn:hover {
            background: var(--light-green);
        }

        .user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--primary-green);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--white);
            font-weight: 600;
        }

        .user-dropdown {
            position: absolute;
            top: 100%;
            right: 0;
            background: var(--white);
            border-radius: 8px;
            box-shadow: var(--shadow-lg);
            min-width: 200px;
            padding: 0.5rem 0;
            display: none;
            z-index: 1000;
        }

        .user-dropdown.show {
            display: block;
        }

        .dropdown-item {
            display: flex;
            align-items: center;
            padding: 0.75rem 1rem;
            color: var(--text-dark);
            text-decoration: none;
            transition: background 0.2s;
        }

        .dropdown-item:hover {
            background: var(--light-green);
        }

        .dropdown-item i {
            margin-right: 0.75rem;
            width: 16px;
        }

        /* Content Area */
        .content {
            padding: 2rem;
        }

        .content-section {
            display: none;
        }

        .content-section.active {
            display: block;
        }

        /* Dashboard Stats */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: var(--white);
            padding: 1.5rem;
            border-radius: 12px;
            box-shadow: var(--shadow);
            transition: transform 0.2s;
        }

        .stat-card:hover {
            transform: translateY(-2px);
        }

        .stat-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 1rem;
        }

        .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            color: var(--white);
        }

        .stat-icon.primary { background: var(--primary-green); }
        .stat-icon.accent { background: var(--accent-green); }
        .stat-icon.warning { background: #f59e0b; }
        .stat-icon.info { background: #3b82f6; }

        .stat-number {
            font-size: 2rem;
            font-weight: 700;
            color: var(--text-dark);
            margin-bottom: 0.25rem;
        }

        .stat-label {
            color: var(--text-light);
            font-size: 0.875rem;
        }

        /* Tables */
        .table-container {
            background: var(--white);
            border-radius: 12px;
            box-shadow: var(--shadow);
            overflow: hidden;
        }

        .table-header {
            padding: 1.5rem;
            border-bottom: 1px solid var(--border-light);
            display: flex;
            align-items: center;
            justify-content: between;
        }

        .table-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--text-dark);
        }

        .table-wrapper {
            overflow-x: auto;
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
        }

        .data-table th,
        .data-table td {
            padding: 1rem;
            text-align: left;
            border-bottom: 1px solid var(--border-light);
        }

        .data-table th {
            background: var(--bg-light);
            font-weight: 600;
            color: var(--text-dark);
        }

        .data-table tbody tr:hover {
            background: var(--light-green);
        }

        /* Status badges */
        .status-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .status-badge.pending {
            background: #fef3c7;
            color: #92400e;
        }

        .status-badge.approved {
            background: #d1fae5;
            color: #065f46;
        }

        .status-badge.completed {
            background: #dbeafe;
            color: #1e40af;
        }

        .status-badge.cancelled {
            background: #fee2e2;
            color: #991b1b;
        }

        /* Action buttons */
        .btn {
            padding: 0.5rem 1rem;
            border: none;
            border-radius: 6px;
            font-size: 0.875rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .btn-primary {
            background: var(--primary-green);
            color: var(--white);
        }

        .btn-primary:hover {
            background: var(--dark-green);
        }

        .btn-secondary {
            background: var(--white);
            color: var(--text-dark);
            border: 1px solid var(--border-light);
        }

        .btn-secondary:hover {
            background: var(--light-green);
        }

        .btn-sm {
            padding: 0.375rem 0.75rem;
            font-size: 0.75rem;
        }

        /* Loading spinner */
        .spinner {
            width: 20px;
            height: 20px;
            border: 2px solid #f3f3f3;
            border-top: 2px solid var(--primary-green);
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .mobile-menu-btn {
                display: block;
            }

            .sidebar {
                transform: translateX(-100%);
            }

            .sidebar.show {
                transform: translateX(0);
            }

            .main-content {
                margin-left: 0;
            }

            .content {
                padding: 1rem;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .header {
                padding: 0 1rem;
            }

            .user-dropdown {
                right: -1rem;
            }
        }
    </style>
</head>
<body>
    <div class="admin-layout">
        <!-- Sidebar -->
        <aside class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <div class="sidebar-logo">
                    <i class="fas fa-chart-line"></i>
                    AssessPro Admin
                </div>
            </div>
            <nav class="sidebar-nav">
                <div class="nav-section">
                    <div class="nav-section-title">Main</div>
                    <div class="nav-item">
                        <a href="#" class="nav-link active" data-section="dashboard">
                            <i class="fas fa-tachometer-alt"></i>
                            Dashboard
                        </a>
                    </div>
                </div>
                <div class="nav-section">
                    <div class="nav-section-title">Management</div>
                    <div class="nav-item">
                        <a href="#" class="nav-link" data-section="requests">
                            <i class="fas fa-file-alt"></i>
                            Assessment Requests
                            <span class="badge" id="pending-requests-badge">0</span>
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="#" class="nav-link" data-section="appointments">
                            <i class="fas fa-calendar-check"></i>
                            Appointments
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="#" class="nav-link" data-section="users">
                            <i class="fas fa-users"></i>
                            User Management
                        </a>
                    </div>
                </div>
                <div class="nav-section">
                    <div class="nav-section-title">System</div>
                    <div class="nav-item">
                        <a href="#" class="nav-link" data-section="reports">
                            <i class="fas fa-chart-bar"></i>
                            Reports
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="#" class="nav-link" data-section="settings">
                            <i class="fas fa-cog"></i>
                            Settings
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="#" class="nav-link" data-section="activity">
                            <i class="fas fa-history"></i>
                            Activity Log
                        </a>
                    </div>
                </div>
            </nav>
        </aside>

        <!-- Main Content -->
        <main class="main-content" id="mainContent">
            <!-- Header -->
            <header class="header">
                <div class="header-left">
                    <button class="mobile-menu-btn" id="mobileMenuBtn">
                        <i class="fas fa-bars"></i>
                    </button>
                    <h1 class="header-title" id="pageTitle">Dashboard</h1>
                </div>
                <div class="header-right">
                    <div class="user-menu">
                        <button class="user-btn" id="userMenuBtn">
                            <div class="user-avatar">
                                <?php echo strtoupper(substr($currentUser['full_name'], 0, 1)); ?>
                            </div>
                            <span><?php echo htmlspecialchars($currentUser['full_name']); ?></span>
                            <i class="fas fa-chevron-down"></i>
                        </button>
                        <div class="user-dropdown" id="userDropdown">
                            <a href="#" class="dropdown-item">
                                <i class="fas fa-user"></i>
                                Profile
                            </a>
                            <a href="#" class="dropdown-item">
                                <i class="fas fa-cog"></i>
                                Settings
                            </a>
                            <a href="#" class="dropdown-item" id="logoutBtn">
                                <i class="fas fa-sign-out-alt"></i>
                                Logout
                            </a>
                        </div>
                    </div>
                </div>
            </header>

            <!-- Content -->
            <div class="content">
                <!-- Dashboard Section -->
                <div class="content-section active" id="dashboard-section">
                    <div class="stats-grid">
                        <div class="stat-card">
                            <div class="stat-header">
                                <div class="stat-icon primary">
                                    <i class="fas fa-file-alt"></i>
                                </div>
                            </div>
                            <div class="stat-number" id="total-requests">0</div>
                            <div class="stat-label">Total Requests</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-header">
                                <div class="stat-icon warning">
                                    <i class="fas fa-clock"></i>
                                </div>
                            </div>
                            <div class="stat-number" id="pending-requests">0</div>
                            <div class="stat-label">Pending Requests</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-header">
                                <div class="stat-icon accent">
                                    <i class="fas fa-users"></i>
                                </div>
                            </div>
                            <div class="stat-number" id="active-staff">0</div>
                            <div class="stat-label">Active Staff</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-header">
                                <div class="stat-icon info">
                                    <i class="fas fa-calendar-check"></i>
                                </div>
                            </div>
                            <div class="stat-number" id="todays-appointments">0</div>
                            <div class="stat-label">Today's Appointments</div>
                        </div>
                    </div>

                    <!-- Recent Activities -->
                    <div class="table-container">
                        <div class="table-header">
                            <h3 class="table-title">Recent Activities</h3>
                        </div>
                        <div class="table-wrapper">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>User</th>
                                        <th>Action</th>
                                        <th>Details</th>
                                        <th>Time</th>
                                    </tr>
                                </thead>
                                <tbody id="recent-activities">
                                    <!-- Will be populated by JavaScript -->
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Assessment Requests Section -->
                <div class="content-section" id="requests-section">
                    <div class="table-container">
                        <div class="table-header">
                            <h3 class="table-title">Assessment Requests</h3>
                            <button class="btn btn-primary" onclick="refreshRequests()">
                                <i class="fas fa-sync-alt"></i>
                                Refresh
                            </button>
                        </div>
                        <div class="table-wrapper">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Client</th>
                                        <th>Property Type</th>
                                        <th>Location</th>
                                        <th>Status</th>
                                        <th>Assigned Staff</th>
                                        <th>Created</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody id="assessment-requests">
                                    <!-- Will be populated by JavaScript -->
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Other sections will be similar... -->
                <div class="content-section" id="appointments-section">
                    <h2>Appointments Management</h2>
                    <p>Appointments management features will be implemented here.</p>
                </div>

                <div class="content-section" id="users-section">
                    <h2>User Management</h2>
                    <p>User management features will be implemented here.</p>
                </div>

                <div class="content-section" id="reports-section">
                    <h2>Reports</h2>
                    <p>Reporting features will be implemented here.</p>
                </div>

                <div class="content-section" id="settings-section">
                    <h2>System Settings</h2>
                    <p>System settings will be implemented here.</p>
                </div>

                <div class="content-section" id="activity-section">
                    <h2>Activity Log</h2>
                    <p>Activity log will be implemented here.</p>
                </div>
            </div>
        </main>
    </div>

    <!-- Toast notification container -->
    <div id="toast" class="toast"></div>

    <script>
        // Global variables
        const API_BASE = '../backend/api/';
        let currentUser = <?php echo json_encode($currentUser); ?>;

        // Initialize dashboard
        document.addEventListener('DOMContentLoaded', function() {
            initializeDashboard();
            loadDashboardStats();
            loadRecentActivities();
            setupEventListeners();
        });

        // Setup event listeners
        function setupEventListeners() {
            // Sidebar navigation
            document.querySelectorAll('.nav-link').forEach(link => {
                link.addEventListener('click', handleNavigation);
            });

            // Mobile menu toggle
            document.getElementById('mobileMenuBtn').addEventListener('click', toggleSidebar);

            // User menu toggle
            document.getElementById('userMenuBtn').addEventListener('click', toggleUserMenu);

            // Logout
            document.getElementById('logoutBtn').addEventListener('click', handleLogout);

            // Close dropdowns when clicking outside
            document.addEventListener('click', function(e) {
                if (!e.target.closest('.user-menu')) {
                    document.getElementById('userDropdown').classList.remove('show');
                }
            });
        }

        // Navigation handling
        function handleNavigation(e) {
            e.preventDefault();
            
            const section = e.target.closest('.nav-link').dataset.section;
            
            // Update active nav link
            document.querySelectorAll('.nav-link').forEach(link => {
                link.classList.remove('active');
            });
            e.target.closest('.nav-link').classList.add('active');
            
            // Show corresponding content section
            document.querySelectorAll('.content-section').forEach(section => {
                section.classList.remove('active');
            });
            document.getElementById(section + '-section').classList.add('active');
            
            // Update page title
            const titles = {
                'dashboard': 'Dashboard',
                'requests': 'Assessment Requests',
                'appointments': 'Appointments',
                'users': 'User Management',
                'reports': 'Reports',
                'settings': 'Settings',
                'activity': 'Activity Log'
            };
            document.getElementById('pageTitle').textContent = titles[section] || 'Dashboard';
            
            // Load section-specific data
            switch(section) {
                case 'dashboard':
                    loadDashboardStats();
                    loadRecentActivities();
                    break;
                case 'requests':
                    loadAssessmentRequests();
                    break;
                case 'appointments':
                    loadAppointments();
                    break;
                case 'users':
                    loadUsers();
                    break;
                case 'activity':
                    loadActivityLog();
                    break;
            }
        }

        // Load dashboard statistics
        async function loadDashboardStats() {
            try {
                const response = await fetch(`${API_BASE}admin.php?action=dashboard_stats`, { credentials: 'same-origin' });
                const result = await response.json();
                
                if (result.success) {
                    const stats = result.data;
                    document.getElementById('total-requests').textContent = stats.total_requests;
                    document.getElementById('pending-requests').textContent = stats.pending_requests;
                    document.getElementById('active-staff').textContent = stats.active_staff;
                    document.getElementById('todays-appointments').textContent = stats.todays_appointments;
                    
                    // Update badge
                    document.getElementById('pending-requests-badge').textContent = stats.pending_requests;
                }
            } catch (error) {
                console.error('Error loading dashboard stats:', error);
                showToast('Error loading dashboard statistics', 'error');
            }
        }

        // Load recent activities
        async function loadRecentActivities() {
            try {
                const response = await fetch(`${API_BASE}admin.php?action=recent_activities`, { credentials: 'same-origin' });
                const result = await response.json();
                
                if (result.success) {
                    const tbody = document.getElementById('recent-activities');
                    tbody.innerHTML = '';
                    
                    result.data.forEach(activity => {
                        const row = document.createElement('tr');
                        row.innerHTML = `
                            <td>${activity.first_name} ${activity.last_name}</td>
                            <td>${formatAction(activity.action)}</td>
                            <td>${activity.table_name || '-'}</td>
                            <td>${formatDateTime(activity.created_at)}</td>
                        `;
                        tbody.appendChild(row);
                    });
                }
            } catch (error) {
                console.error('Error loading recent activities:', error);
            }
        }

        // Load assessment requests
        async function loadAssessmentRequests() {
            try {
                const response = await fetch(`${API_BASE}admin.php?action=assessment_requests`, { credentials: 'same-origin' });
                const result = await response.json();
                
                if (result.success) {
                    const tbody = document.getElementById('assessment-requests');
                    tbody.innerHTML = '';
                    
                    result.data.forEach(request => {
                        const row = document.createElement('tr');
                        row.innerHTML = `
                            <td>#${request.id}</td>
                            <td>${request.client_name}</td>
                            <td>${request.property_classification}</td>
                            <td>${request.location}</td>
                            <td><span class="status-badge ${request.status}">${request.status}</span></td>
                            <td>${request.assigned_staff_name || '-'}</td>
                            <td>${formatDate(request.created_at)}</td>
                            <td>
                                <button class="btn btn-sm btn-primary" onclick="viewRequest(${request.id})">
                                    <i class="fas fa-eye"></i>
                                </button>
                                <button class="btn btn-sm btn-secondary" onclick="editRequest(${request.id})">
                                    <i class="fas fa-edit"></i>
                                </button>
                            </td>
                        `;
                        tbody.appendChild(row);
                    });
                }
            } catch (error) {
                console.error('Error loading assessment requests:', error);
                showToast('Error loading assessment requests', 'error');
            }
        }

        // Utility functions
        function toggleSidebar() {
            document.getElementById('sidebar').classList.toggle('show');
        }

        function toggleUserMenu() {
            document.getElementById('userDropdown').classList.toggle('show');
        }

        async function handleLogout() {
            try {
                const response = await fetch(`${API_BASE}logout.php`, {
                    method: 'POST'
                });
                
                if (response.ok) {
                    window.location.href = '../index.html';
                }
            } catch (error) {
                console.error('Logout error:', error);
                showToast('Logout failed', 'error');
            }
        }

        function showToast(message, type = 'info') {
            const toast = document.getElementById('toast');
            toast.textContent = message;
            toast.className = `toast ${type} show`;
            
            setTimeout(() => {
                toast.classList.remove('show');
            }, 3000);
        }

        function formatDateTime(dateString) {
            return new Date(dateString).toLocaleString();
        }

        function formatDate(dateString) {
            return new Date(dateString).toLocaleDateString();
        }

        function formatAction(action) {
            return action.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
        }

        function refreshRequests() {
            loadAssessmentRequests();
            showToast('Requests refreshed', 'success');
        }

        // Placeholder functions for future implementation
        function loadAppointments() {
            console.log('Loading appointments...');
        }

        function loadUsers() {
            console.log('Loading users...');
        }

        function loadActivityLog() {
            console.log('Loading activity log...');
        }

        function viewRequest(id) {
            console.log('Viewing request:', id);
        }

        function editRequest(id) {
            console.log('Editing request:', id);
        }

        function initializeDashboard() {
            console.log('Dashboard initialized for user:', currentUser.full_name);
        }
    </script>
</body>
</html>