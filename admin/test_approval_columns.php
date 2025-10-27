<?php
// Test if approval columns exist in database
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once '../config/database.php';

echo "<h2>Testing Approval System Database Columns</h2>";
echo "<hr>";

try {
    // Test 1: Check if applications table has approval columns
    echo "<h3>Test 1: Checking approval columns in applications table</h3>";
    
    $stmt = $pdo->query("DESCRIBE applications");
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    $required_columns = [
        'evaluator_submitted_at',
        'director_eteeap_status',
        'director_eteeap_approved_by',
        'director_eteeap_approved_at',
        'director_eteeap_remarks',
        'ced_status',
        'ced_approved_by',
        'ced_approved_at',
        'ced_remarks',
        'vpaa_status',
        'vpaa_approved_by',
        'vpaa_approved_at',
        'vpaa_remarks',
        'final_approval_status'
    ];
    
    $missing = [];
    $found = [];
    
    foreach ($required_columns as $col) {
        if (in_array($col, $columns)) {
            $found[] = $col;
            echo "✅ <span style='color: green;'>$col</span> - EXISTS<br>";
        } else {
            $missing[] = $col;
            echo "❌ <span style='color: red;'>$col</span> - MISSING<br>";
        }
    }
    
    echo "<hr>";
    
    if (count($missing) > 0) {
        echo "<div style='background: #ffebee; padding: 15px; border-left: 4px solid #f44336;'>";
        echo "<strong>⚠️ DATABASE NOT READY!</strong><br>";
        echo "You are missing " . count($missing) . " columns.<br>";
        echo "Please run the SQL migration file: <strong>approval_workflow_migration.sql</strong>";
        echo "</div>";
    } else {
        echo "<div style='background: #e8f5e9; padding: 15px; border-left: 4px solid #4caf50;'>";
        echo "<strong>✅ DATABASE READY!</strong><br>";
        echo "All " . count($found) . " approval columns exist.";
        echo "</div>";
    }
    
    echo "<hr>";
    
    // Test 2: Check if approval_logs table exists
    echo "<h3>Test 2: Checking approval_logs table</h3>";
    
    try {
        $stmt = $pdo->query("SELECT COUNT(*) FROM approval_logs");
        echo "✅ <span style='color: green;'>approval_logs table EXISTS</span><br>";
        $count = $stmt->fetchColumn();
        echo "Current records: $count<br>";
    } catch (PDOException $e) {
        echo "❌ <span style='color: red;'>approval_logs table MISSING</span><br>";
        echo "<div style='background: #fff3cd; padding: 15px; border-left: 4px solid #ffc107; margin-top: 10px;'>";
        echo "The approval_logs table doesn't exist yet.<br>";
        echo "This is optional but recommended for audit trail.<br>";
        echo "Run the SQL migration file to create it.";
        echo "</div>";
    }
    
    echo "<hr>";
    
    // Test 3: Sample query to check if a pending application can be fetched
    echo "<h3>Test 3: Checking for pending applications</h3>";
    
    if (count($missing) === 0) {
        try {
            $stmt = $pdo->query("
                SELECT COUNT(*) as total
                FROM applications 
                WHERE director_eteeap_status = 'pending'
                AND application_status = 'qualified'
            ");
            $result = $stmt->fetch();
            echo "✅ Query executed successfully<br>";
            echo "Pending qualified applications: <strong>" . $result['total'] . "</strong><br>";
            
            if ($result['total'] > 0) {
                echo "<div style='background: #e3f2fd; padding: 15px; border-left: 4px solid #2196f3; margin-top: 10px;'>";
                echo "There are applications waiting for approval!";
                echo "</div>";
            }
        } catch (PDOException $e) {
            echo "❌ Query failed: " . $e->getMessage() . "<br>";
        }
    } else {
        echo "<span style='color: orange;'>⚠️ Skipped - columns missing</span><br>";
    }
    
    echo "<hr>";
    echo "<h3>Summary</h3>";
    
    if (count($missing) > 0) {
        echo "<div style='background: #ffebee; padding: 20px; border-radius: 5px;'>";
        echo "<h4 style='color: #c62828;'>⚠️ ACTION REQUIRED</h4>";
        echo "<ol style='line-height: 2;'>";
        echo "<li>Download the <strong>approval_workflow_migration.sql</strong> file</li>";
        echo "<li>Open phpMyAdmin or your MySQL client</li>";
        echo "<li>Select the <strong>eteeap_db</strong> database</li>";
        echo "<li>Run the SQL migration file</li>";
        echo "<li>Refresh this page to verify</li>";
        echo "</ol>";
        echo "</div>";
    } else {
        echo "<div style='background: #e8f5e9; padding: 20px; border-radius: 5px;'>";
        echo "<h4 style='color: #2e7d32;'>✅ SYSTEM READY</h4>";
        echo "<p>All database columns are in place. The approval system should work correctly.</p>";
        echo "<p>If you still get errors, check:</p>";
        echo "<ul>";
        echo "<li>Browser console for JavaScript errors</li>";
        echo "<li>PHP error logs in your server</li>";
        echo "<li>Network tab in browser dev tools</li>";
        echo "</ul>";
        echo "</div>";
    }
    
} catch (PDOException $e) {
    echo "<div style='background: #ffebee; padding: 20px; border-radius: 5px;'>";
    echo "<h4 style='color: #c62828;'>❌ DATABASE CONNECTION ERROR</h4>";
    echo "<p>" . $e->getMessage() . "</p>";
    echo "</div>";
}

echo "<hr>";
echo "<p><a href='director_eteeap.php'>← Back to Director ETEEAP Dashboard</a></p>";
?>