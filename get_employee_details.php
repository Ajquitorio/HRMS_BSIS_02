<?php
session_start();

// Check if the user is logged in, if not then redirect to login page
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// Include database connection
require_once 'config.php';

// Fetch employee details along with job role
$stmt = $conn->prepare("
    SELECT ep.employee_id,
           CONCAT(pi.first_name, ' ', pi.last_name) AS name,
           jr.title AS job_role,
           ep.job_role_id
    FROM employee_profiles ep
    JOIN personal_information pi 
        ON ep.personal_info_id = pi.personal_info_id
    LEFT JOIN job_roles jr 
        ON ep.job_role_id = jr.job_role_id
    WHERE ep.employee_id = ?
");
>>>>>>> 643a8d7 (updated competencies and performance review files)

    try {
        $sql = "SELECT 
            ep.employee_number, 
            ep.employment_status,
            ep.current_salary, -- ✅ get from employee_profiles
            pi.first_name, 
            pi.last_name,
            jr.title AS job_title,
            d.department_name
        FROM employee_profiles ep
        JOIN personal_information pi ON ep.personal_info_id = pi.personal_info_id
        LEFT JOIN job_roles jr ON ep.job_role_id = jr.job_role_id
        LEFT JOIN departments d ON jr.department = d.department_name
        WHERE ep.employee_id = ?";

        $stmt = $conn->prepare($sql);
        $stmt->execute([$employee_id]);
        $employee = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($employee) {
            header('Content-Type: application/json');
            echo json_encode($employee);
        } else {
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Employee not found']);
        }
    } catch (PDOException $e) {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
    }
} else {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Invalid request']);
}
?>
