<?php
session_start();
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header('Location: login.php');
    exit;
}
require_once 'config.php';

$success_message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'create_opening':
                $stmt = $conn->prepare("INSERT INTO job_openings (job_role_id, department_id, title, description, requirements, responsibilities, location, employment_type, salary_range_min, salary_range_max, vacancy_count, posting_date, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURDATE(), ?)");
                $stmt->execute([$_POST['job_role_id'], $_POST['department_id'], $_POST['title'], $_POST['description'], $_POST['requirements'], $_POST['responsibilities'], $_POST['location'], $_POST['employment_type'], $_POST['salary_min'], $_POST['salary_max'], $_POST['vacancy_count'], $_POST['status']]);
                $success_message = "✨ Job opening '" . htmlspecialchars($_POST['title']) . "' created successfully!";
                break;
            case 'update_status':
                if ($_POST['new_status'] == 'Closed') {
                    // Check for pending applications
                    $check_stmt = $conn->prepare("SELECT COUNT(*) as count FROM job_applications WHERE job_opening_id = ? AND status IN ('Applied', 'Approved', 'Interview')");
                    $check_stmt->execute([$_POST['job_opening_id']]);
                    $pending_count = $check_stmt->fetch(PDO::FETCH_ASSOC)['count'];
                    
                    if ($pending_count > 0) {
                        $success_message = "⚠️ Cannot close job! There are " . $pending_count . " pending applications that need to be processed first.";
                        break;
                    }
                    
                    // Set closing date when job is closed
                    $stmt = $conn->prepare("UPDATE job_openings SET status = ?, closing_date = CURDATE() WHERE job_opening_id = ?");
                    $stmt->execute([$_POST['new_status'], $_POST['job_opening_id']]);
                } else {
                    // Clear closing date when reopening
                    $stmt = $conn->prepare("UPDATE job_openings SET status = ?, closing_date = NULL WHERE job_opening_id = ?");
                    $stmt->execute([$_POST['new_status'], $_POST['job_opening_id']]);
                }
                
                $emoji = $_POST['new_status'] == 'Open' ? '🚀' : '🚫';
                $success_message = $emoji . " Job status updated to " . $_POST['new_status'] . " successfully!";
                break;
        }
    }
}

// Check and auto-close filled positions
$conn->exec("UPDATE job_openings jo SET status = 'Closed', closing_date = CURDATE() WHERE jo.status = 'Open' AND (SELECT COUNT(*) FROM job_applications ja WHERE ja.job_opening_id = jo.job_opening_id AND ja.status = 'Hired') >= jo.vacancy_count");

$stats = [];
$stmt = $conn->query("SELECT COUNT(*) as count FROM job_openings WHERE status = 'Draft'");
$stats['draft'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
$stmt = $conn->query("SELECT COUNT(*) as count FROM job_openings WHERE status = 'Open'");
$stats['open'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
$stmt = $conn->query("SELECT COUNT(*) as count FROM job_applications");
$stats['applications'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

$job_openings_query = "SELECT jo.*, 
                       COALESCE(d.department_name, 'Unknown Department') as department_name, 
                       COALESCE(jr.title, 'Unknown Role') as role_title,
                       COUNT(ja.application_id) as total_applications,
                       SUM(CASE WHEN ja.status = 'Applied' THEN 1 ELSE 0 END) as pending_applications,
                       SUM(CASE WHEN ja.status = 'Interview' THEN 1 ELSE 0 END) as interview_stage,
                       SUM(CASE WHEN ja.status = 'Hired' THEN 1 ELSE 0 END) as hired_count
                       FROM job_openings jo 
                       LEFT JOIN departments d ON jo.department_id = d.department_id 
                       LEFT JOIN job_roles jr ON jo.job_role_id = jr.job_role_id 
                       LEFT JOIN job_applications ja ON jo.job_opening_id = ja.job_opening_id
                       WHERE jo.status != 'Archived'
                       GROUP BY jo.job_opening_id
                       ORDER BY jo.posting_date DESC";
$job_openings_result = $conn->query($job_openings_query);

try {
    $departments = $conn->query("SELECT * FROM departments ORDER BY department_name")->fetchAll(PDO::FETCH_ASSOC);
    $job_roles = $conn->query("SELECT * FROM job_roles ORDER BY title")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $departments = [];
    $job_roles = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Job Openings - HR Management System</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.1/css/all.min.css">
    <link rel="stylesheet" href="styles.css?v=rose">
</head>
<body>
    <div class="container-fluid">
        <?php include 'navigation.php'; ?>
        <div class="row">
            <?php include 'sidebar.php'; ?>
            <div class="main-content">
                <h2>💼 Job Openings Management</h2>
                
                <?php if (!empty($success_message)): ?>
                    <div class="alert <?php echo strpos($success_message, 'Cannot') !== false ? 'alert-warning' : 'alert-success'; ?> alert-dismissible fade show" role="alert">
                        <?php echo $success_message; ?>
                        <button type="button" class="close" data-dismiss="alert">
                            <span>&times;</span>
                        </button>
                    </div>
                <?php endif; ?>
                
                <div class="row mb-4">
                    <div class="col-md-4">
                        <div class="stats-card card">
                            <div class="card-body text-center">
                                <div class="activity-icon bg-warning">
                                    <i class="fas fa-edit"></i>
                                </div>
                                <h3 class="stats-number"><?php echo $stats['draft']; ?></h3>
                                <p class="stats-label">Draft Openings</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="stats-card card">
                            <div class="card-body text-center">
                                <div class="activity-icon bg-success">
                                    <i class="fas fa-rocket"></i>
                                </div>
                                <h3 class="stats-number"><?php echo $stats['open']; ?></h3>
                                <p class="stats-label">Active Openings</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="stats-card card">
                            <div class="card-body text-center">
                                <div class="activity-icon bg-info">
                                    <i class="fas fa-users"></i>
                                </div>
                                <h3 class="stats-number"><?php echo $stats['applications']; ?></h3>
                                <p class="stats-label">Total Applications</p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">💼 Job Openings Management</h5>
                        <div class="d-flex">
                            <input type="text" id="searchJobs" class="form-control mr-2" placeholder="🔍 Search jobs..." style="width: 200px;">
                            <button class="btn btn-secondary btn-sm mr-2" id="toggleClosed">👁️ Show Closed</button>
                            <button class="btn btn-primary" data-toggle="modal" data-target="#addJobModal">✨ Create Job</button>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-striped">
                                <thead>
                                    <tr>
                                        <th>Title</th>
                                        <th>Department</th>
                                        <th>Role</th>
                                        <th>Vacancies</th>
                                        <th>Posted</th>
                                        <th>Closing</th>
                                        <th>Status</th>
                                        <th>Applications</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if ($job_openings_result && $job_openings_result->rowCount() > 0): ?>
                                        <?php while($row = $job_openings_result->fetch(PDO::FETCH_ASSOC)): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($row['title']); ?></td>
                                                <td><?php echo htmlspecialchars($row['department_name']); ?></td>
                                                <td><?php echo htmlspecialchars($row['role_title']); ?></td>
                                                <td><?php echo $row['vacancy_count']; ?></td>
                                                <td><?php echo date('M d, Y', strtotime($row['posting_date'])); ?></td>
                                                <td><?php echo $row['closing_date'] ? date('M d, Y', strtotime($row['closing_date'])) : 'No deadline'; ?></td>
                                                <td>
                                                    <span class="badge badge-<?php echo $row['status'] == 'Open' ? 'success' : ($row['status'] == 'Draft' ? 'warning' : 'danger'); ?>">
                                                        <?php 
                                                        $emoji = $row['status'] == 'Open' ? '🚀' : ($row['status'] == 'Draft' ? '📝' : '🚫');
                                                        echo $emoji . ' ' . $row['status']; 
                                                        ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <small>
                                                        📈 Total: <strong><?php echo $row['total_applications']; ?></strong><br>
                                                        ⏳ Pending: <?php echo $row['pending_applications']; ?><br>
                                                        💬 Interview: <?php echo $row['interview_stage']; ?><br>
                                                        ✅ Hired: <?php echo $row['hired_count']; ?>
                                                    </small>
                                                </td>
                                                <td>
                                                    <div class="d-flex flex-column" style="min-width: 120px;">
                                                        <a href="job_applications.php?job_id=<?php echo $row['job_opening_id']; ?>" class="btn btn-info btn-sm mb-1 text-left">👥 Applications</a>
                                                        <?php if ($row['status'] == 'Draft'): ?>
                                                            <button type="button" class="btn btn-success btn-sm w-100 text-left" onclick="showPublishModal('<?php echo $row['job_opening_id']; ?>', '<?php echo htmlspecialchars($row['title']); ?>')">🚀 Publish</button>
                                                        <?php endif; ?>
                                                        <?php if ($row['status'] == 'Open'): ?>
                                                            <button type="button" class="btn btn-danger btn-sm w-100 text-left" onclick="showCloseModal('<?php echo $row['job_opening_id']; ?>', '<?php echo htmlspecialchars($row['title']); ?>')">🚫 Close</button>
                                                        <?php endif; ?>
                                                        <?php if ($row['status'] == 'Closed'): ?>
                                                            <button type="button" class="btn btn-warning btn-sm w-100 text-left" onclick="showReopenModal('<?php echo $row['job_opening_id']; ?>', '<?php echo htmlspecialchars($row['title']); ?>')">🔄 Reopen</button>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endwhile; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="9" class="text-center">No job openings found.</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="addJobModal" tabindex="-1" role="dialog">
        <div class="modal-dialog modal-xl" role="document">
            <div class="modal-content border-0 shadow-lg">
                <div class="modal-header bg-primary text-white">
                    <h4 class="modal-title mb-0"><i class="fas fa-plus-circle mr-2"></i>Create New Job Opening</h4>
                    <button type="button" class="close text-white" data-dismiss="modal">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <form method="POST" id="jobForm">
                    <div class="modal-body p-4">
                        <input type="hidden" name="action" value="create_opening">
                        
                        <!-- Basic Information Section -->
                        <div class="mb-4">
                            <h5 class="text-primary mb-3"><i class="fas fa-info-circle mr-2"></i>Basic Information</h5>
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label class="font-weight-bold text-dark"><i class="fas fa-building mr-1"></i>Department <span class="text-danger">*</span></label>
                                        <select name="department_id" id="department_select" class="form-control form-control-lg border-primary" required>
                                            <option value="">🏢 Choose Department</option>
                                            <?php foreach($departments as $dept): ?>
                                                <option value="<?php echo $dept['department_id']; ?>"><?php echo htmlspecialchars($dept['department_name']); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label class="font-weight-bold text-dark"><i class="fas fa-user-tie mr-1"></i>Job Role <span class="text-danger">*</span></label>
                                        <select name="job_role_id" id="job_role_select" class="form-control form-control-lg border-primary" required>
                                            <option value="">👔 Select Job Role</option>
                                            <?php foreach($job_roles as $role): ?>
                                                <option value="<?php echo $role['job_role_id']; ?>" data-department="<?php echo $role['department']; ?>"><?php echo htmlspecialchars($role['title']); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label class="font-weight-bold text-dark"><i class="fas fa-briefcase mr-1"></i>Job Title <span class="text-danger">*</span></label>
                                <input type="text" name="title" class="form-control form-control-lg border-primary" placeholder="Enter a descriptive job title" required>
                                <small class="text-muted">Make it clear and specific (e.g., "Senior Software Developer - Frontend")</small>
                            </div>
                        </div>

                        <!-- Job Details Section -->
                        <div class="mb-4">
                            <h5 class="text-primary mb-3"><i class="fas fa-clipboard-list mr-2"></i>Job Details</h5>
                            <div class="form-group">
                                <label class="font-weight-bold text-dark"><i class="fas fa-align-left mr-1"></i>Job Description <span class="text-danger">*</span></label>
                                <textarea name="description" class="form-control border-primary" rows="4" placeholder="Provide a comprehensive overview of the position, including key objectives and what the role entails..." required></textarea>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label class="font-weight-bold text-dark"><i class="fas fa-check-circle mr-1"></i>Requirements <span class="text-danger">*</span></label>
                                        <textarea name="requirements" class="form-control border-primary" rows="4" placeholder="• Bachelor's degree in relevant field&#10;• 2+ years of experience&#10;• Proficiency in specific skills&#10;• Strong communication abilities" required></textarea>
                                        <small class="text-muted">List qualifications, skills, and experience needed</small>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label class="font-weight-bold text-dark"><i class="fas fa-tasks mr-1"></i>Key Responsibilities <span class="text-danger">*</span></label>
                                        <textarea name="responsibilities" class="form-control border-primary" rows="4" placeholder="• Manage daily operations&#10;• Collaborate with team members&#10;• Develop and implement strategies&#10;• Report to management" required></textarea>
                                        <small class="text-muted">Outline main duties and expectations</small>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Employment Details Section -->
                        <div class="mb-4">
                            <h5 class="text-primary mb-3"><i class="fas fa-cogs mr-2"></i>Employment Details</h5>
                            <div class="row">
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label class="font-weight-bold text-dark"><i class="fas fa-map-marker-alt mr-1"></i>Work Location <span class="text-danger">*</span></label>
                                        <input type="text" name="location" class="form-control form-control-lg border-primary" value="Municipal Office" required>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label class="font-weight-bold text-dark"><i class="fas fa-clock mr-1"></i>Employment Type <span class="text-danger">*</span></label>
                                        <select name="employment_type" class="form-control form-control-lg border-primary" required>
                                            <option value="">⏰ Select Type</option>
                                            <option value="Full-time">🕘 Full-time</option>
                                            <option value="Part-time">🕐 Part-time</option>
                                            <option value="Contract">📝 Contract</option>
                                            <option value="Temporary">⏳ Temporary</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label class="font-weight-bold text-dark"><i class="fas fa-users mr-1"></i>Number of Positions <span class="text-danger">*</span></label>
                                        <input type="number" name="vacancy_count" class="form-control form-control-lg border-primary" value="1" min="1" max="50" required>
                                        <small class="text-muted">How many people to hire</small>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Compensation & Timeline Section -->
                        <div class="mb-4">
                            <h5 class="text-primary mb-3"><i class="fas fa-money-bill-wave mr-2"></i>Compensation & Timeline</h5>
                            <div class="row">
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label class="font-weight-bold text-dark"><i class="fas fa-peso-sign mr-1"></i>Minimum Salary</label>
                                        <div class="input-group">
                                            <div class="input-group-prepend">
                                                <span class="input-group-text bg-light">₱</span>
                                            </div>
                                            <input type="number" name="salary_min" class="form-control border-primary" step="1" min="0" placeholder="25000">
                                        </div>
                                        <small class="text-muted">Optional - leave blank if not disclosed</small>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label class="font-weight-bold text-dark"><i class="fas fa-peso-sign mr-1"></i>Maximum Salary</label>
                                        <div class="input-group">
                                            <div class="input-group-prepend">
                                                <span class="input-group-text bg-light">₱</span>
                                            </div>
                                            <input type="number" name="salary_max" class="form-control border-primary" step="1" min="0" placeholder="35000">
                                        </div>
                                        <small class="text-muted">Optional - leave blank if not disclosed</small>
                                    </div>
                                </div>

                            </div>
                        </div>

                        <!-- Document Requirements -->
                        <div class="mb-4">
                            <h5 class="text-primary mb-3"><i class="fas fa-file-alt mr-2"></i>Required Documents for Application</h5>
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle mr-1"></i><strong>Note:</strong> These documents will be required for future payment disbursement processing.
                            </div>
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="custom-control custom-switch mb-3">
                                        <input type="checkbox" class="custom-control-input" id="require_resume" name="require_resume" checked>
                                        <label class="custom-control-label font-weight-bold" for="require_resume">
                                            <i class="fas fa-file-text text-primary mr-2"></i>Resume/CV
                                        </label>
                                        <small class="d-block text-muted ml-4">Professional background and work experience</small>
                                    </div>
                                    <div class="custom-control custom-switch mb-3">
                                        <input type="checkbox" class="custom-control-input" id="require_cover_letter" name="require_cover_letter">
                                        <label class="custom-control-label font-weight-bold" for="require_cover_letter">
                                            <i class="fas fa-envelope text-success mr-2"></i>Cover Letter
                                        </label>
                                        <small class="d-block text-muted ml-4">Letter of intent and motivation</small>
                                    </div>
                                    <div class="custom-control custom-switch mb-3">
                                        <input type="checkbox" class="custom-control-input" id="require_certifications" name="require_certifications">
                                        <label class="custom-control-label font-weight-bold" for="require_certifications">
                                            <i class="fas fa-certificate text-warning mr-2"></i>Professional Certifications
                                        </label>
                                        <small class="d-block text-muted ml-4">Licenses, certificates, and professional credentials</small>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="custom-control custom-switch mb-3">
                                        <input type="checkbox" class="custom-control-input" id="require_ids" name="require_ids">
                                        <label class="custom-control-label font-weight-bold" for="require_ids">
                                            <i class="fas fa-id-card text-info mr-2"></i>Valid Government IDs
                                        </label>
                                        <small class="d-block text-muted ml-4">Required for identity verification and payroll setup</small>
                                    </div>
                                    <div class="custom-control custom-switch mb-3">
                                        <input type="checkbox" class="custom-control-input" id="require_portfolio" name="require_portfolio">
                                        <label class="custom-control-label font-weight-bold" for="require_portfolio">
                                            <i class="fas fa-briefcase text-secondary mr-2"></i>Work Portfolio
                                        </label>
                                        <small class="d-block text-muted ml-4">Samples of previous work and projects</small>
                                    </div>
                                    <div class="custom-control custom-switch mb-3">
                                        <input type="checkbox" class="custom-control-input" id="require_references" name="require_references">
                                        <label class="custom-control-label font-weight-bold" for="require_references">
                                            <i class="fas fa-users text-dark mr-2"></i>Character References
                                        </label>
                                        <small class="d-block text-muted ml-4">Professional and personal references</small>
                                    </div>
                                </div>
                            </div>
                            <div class="bg-light p-3 rounded">
                                <small class="text-muted">
                                    <i class="fas fa-toggle-on mr-1"></i><strong>How to use:</strong> Toggle switches ON for documents that applicants must submit. 
                                    Documents marked as required will be validated during the application process and used for future payment disbursement.
                                </small>
                            </div>
                        </div>

                        <!-- Publication Status -->
                        <div class="mb-3">
                            <h5 class="text-primary mb-3"><i class="fas fa-eye mr-2"></i>Publication Status</h5>
                            <div class="form-group">
                                <label class="font-weight-bold text-dark"><i class="fas fa-toggle-on mr-1"></i>Job Status <span class="text-danger">*</span></label>
                                <select name="status" class="form-control form-control-lg border-primary" required>
                                    <option value="">📋 Choose Status</option>
                                    <option value="Draft">📝 Draft - Save for review later</option>
                                    <option value="Open">🚀 Open - Publish and accept applications immediately</option>
                                </select>
                                <div class="mt-2">
                                    <small class="text-info"><i class="fas fa-info-circle mr-1"></i><strong>Draft:</strong> Job will be saved but not visible to applicants</small><br>
                                    <small class="text-success"><i class="fas fa-check-circle mr-1"></i><strong>Open:</strong> Job will be published immediately and accept applications</small>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer bg-light p-3">
                        <button type="button" class="btn btn-outline-secondary btn-lg px-4" data-dismiss="modal">
                            <i class="fas fa-times mr-2"></i>Cancel
                        </button>
                        <button type="submit" class="btn btn-primary btn-lg px-4">
                            <i class="fas fa-plus-circle mr-2"></i>Create Job Opening
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Publish Confirmation Modal -->
    <div class="modal fade" id="publishModal" tabindex="-1" role="dialog">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title"><i class="fas fa-rocket mr-2"></i>Publish Job Opening</h5>
                    <button type="button" class="close text-white" data-dismiss="modal">
                        <span>&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <div class="text-center mb-3">
                        <i class="fas fa-rocket text-success" style="font-size: 48px;"></i>
                    </div>
                    <h6 class="text-center mb-3">Are you sure you want to publish this job opening?</h6>
                    <div class="alert alert-info">
                        <strong id="jobTitleToPublish"></strong>
                    </div>
                    <p class="text-muted">This will make the job visible to applicants and they can start applying immediately.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <form method="POST" style="display: inline;" id="publishForm">
                        <input type="hidden" name="action" value="update_status">
                        <input type="hidden" name="job_opening_id" id="jobIdToPublish">
                        <input type="hidden" name="new_status" value="Open">
                        <button type="submit" class="btn btn-success"><i class="fas fa-rocket mr-1"></i>Publish Job</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Close Confirmation Modal -->
    <div class="modal fade" id="closeModal" tabindex="-1" role="dialog">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title"><i class="fas fa-times-circle mr-2"></i>Close Job Opening</h5>
                    <button type="button" class="close text-white" data-dismiss="modal">
                        <span>&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <div class="text-center mb-3">
                        <i class="fas fa-times-circle text-danger" style="font-size: 48px;"></i>
                    </div>
                    <h6 class="text-center mb-3">Are you sure you want to close this job opening?</h6>
                    <div class="alert alert-warning">
                        <strong id="jobTitleToClose"></strong>
                    </div>
                    <p class="text-muted">This will stop accepting new applications and set the closing date to today.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <form method="POST" style="display: inline;" id="closeForm">
                        <input type="hidden" name="action" value="update_status">
                        <input type="hidden" name="job_opening_id" id="jobIdToClose">
                        <input type="hidden" name="new_status" value="Closed">
                        <button type="submit" class="btn btn-danger"><i class="fas fa-times-circle mr-1"></i>Close Job</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Reopen Confirmation Modal -->
    <div class="modal fade" id="reopenModal" tabindex="-1" role="dialog">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header bg-warning text-dark">
                    <h5 class="modal-title"><i class="fas fa-redo mr-2"></i>Reopen Job Opening</h5>
                    <button type="button" class="close" data-dismiss="modal">
                        <span>&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <div class="text-center mb-3">
                        <i class="fas fa-redo text-warning" style="font-size: 48px;"></i>
                    </div>
                    <h6 class="text-center mb-3">Are you sure you want to reopen this job opening?</h6>
                    <div class="alert alert-info">
                        <strong id="jobTitleToReopen"></strong>
                    </div>
                    <p class="text-muted">This will allow new applications and clear the closing date.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <form method="POST" style="display: inline;" id="reopenForm">
                        <input type="hidden" name="action" value="update_status">
                        <input type="hidden" name="job_opening_id" id="jobIdToReopen">
                        <input type="hidden" name="new_status" value="Open">
                        <button type="submit" class="btn btn-warning"><i class="fas fa-redo mr-1"></i>Reopen Job</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/popper.js@1.16.1/dist/umd/popper.min.js"></script>
    <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
    <script>
    function showPublishModal(jobId, jobTitle) {
        $('#jobIdToPublish').val(jobId);
        $('#jobTitleToPublish').text(jobTitle);
        $('#publishModal').modal('show');
    }
    
    function showCloseModal(jobId, jobTitle) {
        $('#jobIdToClose').val(jobId);
        $('#jobTitleToClose').text(jobTitle);
        $('#closeModal').modal('show');
    }
    
    function showReopenModal(jobId, jobTitle) {
        $('#jobIdToReopen').val(jobId);
        $('#jobTitleToReopen').text(jobTitle);
        $('#reopenModal').modal('show');
    }
    
    $(document).ready(function(){
        // Hide closed jobs by default
        $('tbody tr').each(function(){
            if($(this).find('.badge-danger').length > 0) {
                $(this).hide();
            }
        });
        
        $('#toggleClosed').on('click', function(){
            var closedRows = $('tbody tr').filter(function(){
                return $(this).find('.badge-danger').length > 0;
            });
            
            if(closedRows.is(':visible')) {
                closedRows.hide();
                $(this).text('👁️ Show Closed');
            } else {
                closedRows.show();
                $(this).text('🙈 Hide Closed');
            }
        });
        
        $('#searchJobs').on('keyup', function(){
            var value = $(this).val().toLowerCase();
            $('tbody tr').filter(function(){
                $(this).toggle($(this).text().toLowerCase().indexOf(value) > -1)
            });
        });
        
        $('#department_select').on('change', function(){
            var selectedDept = $(this).find('option:selected').text();
            var roleSelect = $('#job_role_select');
            
            roleSelect.find('option').each(function(){
                if($(this).val() === '') {
                    $(this).show();
                } else {
                    var roleDept = $(this).data('department');
                    if(selectedDept === '' || roleDept === selectedDept) {
                        $(this).show();
                    } else {
                        $(this).hide();
                    }
                }
            });
            
            roleSelect.val('');
        });
        
        $('#jobForm').on('submit', function(e){
            var salaryMin = parseFloat($('input[name="salary_min"]').val()) || 0;
            var salaryMax = parseFloat($('input[name="salary_max"]').val()) || 0;
            
            if(salaryMin > 0 && salaryMax > 0 && salaryMin >= salaryMax) {
                e.preventDefault();
                alert('Maximum salary must be greater than minimum salary.');
                return false;
            }
        });
    });
    </script>
</body>
</html>