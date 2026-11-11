<?php
// 1. بدء الجلسة لتتبع حالة تسجيل دخول المستخدم
session_start();

// 2. استدعاء ملف الاتصال بقاعدة البيانات (PDO)
require_once 'includes/db.php';

// 3. الحماية: إذا لم يكن المستخدم مسجلاً لدخوله، نرجعه فوراً لصفحة تسجيل الدخول
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

// تخزين بيانات المستخدم الحالي من الجلسة
$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'];
$error = '';
$success = '';

// ==========================================
// 4. معالجة إضافة مهمة جديدة (CREATE)
// ==========================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_task'])) {
    $title = trim($_POST['title']);
    $description = trim($_POST['description']);
    $priority = $_POST['priority'];
    $due_date = $_POST['due_date'];

    if (empty($title)) {
        $error = 'عنوان المهمة مطلوب.';
    } else {
        $stmt = $pdo->prepare("INSERT INTO tasks (user_id, title, description, priority, due_date) VALUES (?, ?, ?, ?, ?)");
        if ($stmt->execute([$user_id, $title, $description, $priority, $due_date])) {
            $success = 'تمت إضافة المهمة بنجاح!';
        } else {
            $error = 'حدث خطأ أثناء إضافة المهمة.';
        }
    }
}

// ==========================================
// 5. معالجة حذف المهمة (DELETE)
// ==========================================
if (isset($_GET['delete'])) {
    $task_id = $_GET['delete'];
    $stmt = $pdo->prepare("DELETE FROM tasks WHERE id = ? AND user_id = ?");
    $stmt->execute([$task_id, $user_id]);
    header("Location: index.php");
    exit;
}

// ==========================================
// 6. معالجة تغيير حالة المهمة السريع (UPDATE STATUS)
// ==========================================
if (isset($_GET['toggle']) && isset($_GET['status'])) {
    $task_id = $_GET['toggle'];
    $new_status = $_GET['status'];
    $stmt = $pdo->prepare("UPDATE tasks SET status = ? WHERE id = ? AND user_id = ?");
    $stmt->execute([$new_status, $task_id, $user_id]);
    header("Location: index.php");
    exit;
}

// ==========================================
// 7. منطق البحث والتصفية (SEARCH, STATUS & PRIORITY FILTER)
// ==========================================
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$status_filter = isset($_GET['status_filter']) ? trim($_GET['status_filter']) : 'all';
$priority_filter = isset($_GET['priority_filter']) ? trim($_GET['priority_filter']) : 'all';

$query = "SELECT * FROM tasks WHERE user_id = ?";
$params = [$user_id];

if (!empty($search)) {
    $query .= " AND (title LIKE ? OR description LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if ($status_filter != 'all') {
    $query .= " AND status = ?";
    $params[] = $status_filter;
}

if ($priority_filter != 'all') {
    $query .= " AND priority = ?";
    $params[] = $priority_filter;
}

$query .= " ORDER BY created_at DESC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$tasks = $stmt->fetchAll();

// ==========================================
// 8. حساب الإحصائيات ونسبة الإنجاز (AGGREGATION)
// ==========================================
$stats_stmt = $pdo->prepare("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
        SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as in_progress,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending
    FROM tasks WHERE user_id = ?
");
$stats_stmt->execute([$user_id]);
$stats = $stats_stmt->fetch();

$total_tasks = $stats['total'] ?? 0;
$completed_tasks = $stats['completed'] ?? 0;
$progress_percentage = ($total_tasks > 0) ? round(($completed_tasks / $total_tasks) * 100) : 0;
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>لوحة التحكم - TaskFlow</title>
    <!-- Bootstrap 5 & Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
</head>
<body class="bg-body text-body">

    <!-- شريط التنقل العلوي -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark shadow-sm">
        <div class="container d-flex justify-content-between align-items-center">
            <a class="navbar-brand fw-bold" href="index.php"><i class="bi bi-kanban-fill text-primary"></i> TaskFlow</a>
            
            <!-- أزرار شريط التنقل مع مسافات منتظمة باستخدام gap-3 -->
            <div class="d-flex align-items-center gap-3">
                <span class="text-light">أهلاً بك، <strong><?= htmlspecialchars($username) ?></strong></span>
                
                <button class="btn btn-outline-light btn-sm rounded-pill px-3" onclick="toggleDarkMode()">
                    <i class="bi bi-moon-fill" id="darkModeIcon"></i> الوضع المظلم
                </button>
                
                <a href="logout.php" class="btn btn-outline-light btn-sm rounded-pill px-3">تسجيل الخروج</a>
            </div>
        </div>
    </nav>

    <div class="container py-4">
        
        <!-- التنبيهات -->
        <?php if (!empty($error)): ?>
            <div class="alert alert-danger shadow-sm rounded-3"><?= $error ?></div>
        <?php endif; ?>
        <?php if (!empty($success)): ?>
            <div class="alert alert-success shadow-sm rounded-3"><?= $success ?></div>
        <?php endif; ?>

        <!-- 📊 المربعات الإحصائية -->
        <div class="row g-3 mb-4">
            <div class="col-md-3 col-6">
                <div class="card border-0 shadow-sm rounded-4 p-3 border-start border-primary border-4">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <span class="text-muted small d-block">إجمالي المهام</span>
                            <h3 class="fw-bold mb-0 text-body"><?= $stats['total'] ?? 0 ?></h3>
                        </div>
                        <div class="fs-2 text-primary opacity-50"><i class="bi bi-list-check"></i></div>
                    </div>
                </div>
            </div>
            <div class="col-md-3 col-6">
                <div class="card border-0 shadow-sm rounded-4 p-3 border-start border-success border-4">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <span class="text-muted small d-block">المهام المنجزة</span>
                            <h3 class="fw-bold mb-0 text-success"><?= $stats['completed'] ?? 0 ?></h3>
                        </div>
                        <div class="fs-2 text-success opacity-50"><i class="bi bi-check-circle"></i></div>
                    </div>
                </div>
            </div>
            <div class="col-md-3 col-6">
                <div class="card border-0 shadow-sm rounded-4 p-3 border-start border-info border-4">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <span class="text-muted small d-block">قيد التنفيذ</span>
                            <h3 class="fw-bold mb-0 text-info"><?= $stats['in_progress'] ?? 0 ?></h3>
                        </div>
                        <div class="fs-2 text-info opacity-50"><i class="bi bi-hourglass-split"></i></div>
                    </div>
                </div>
            </div>
            <div class="col-md-3 col-6">
                <div class="card border-0 shadow-sm rounded-4 p-3 border-start border-secondary border-4">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <span class="text-muted small d-block">قيد الانتظار</span>
                            <h3 class="fw-bold mb-0 text-secondary"><?= $stats['pending'] ?? 0 ?></h3>
                        </div>
                        <div class="fs-2 text-secondary opacity-50"><i class="bi bi-clock"></i></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- 📈 شريط نسبة الإنجاز المتحرك -->
        <div class="card border-0 shadow-sm rounded-4 p-4 mb-4">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <span class="fw-bold text-body"><i class="bi bi-graph-up-arrow text-primary"></i> نسبة إنجاز المهام الكلية</span>
                <span class="fw-bold text-primary"><?= $progress_percentage ?>%</span>
            </div>
            <div class="progress" style="height: 10px; border-radius: 10px;">
                <div class="progress-bar bg-success progress-bar-striped progress-bar-animated" role="progressbar" style="width: <?= $progress_percentage ?>%;" aria-valuenow="<?= $progress_percentage ?>" aria-valuemin="0" aria-valuimax="100"></div>
            </div>
        </div>

        <div class="row g-4">
            <!-- نموذج إضافة مهمة -->
            <div class="col-lg-4">
                <div class="card shadow-sm border-0 rounded-4 p-4">
                    <h4 class="fw-bold mb-3 text-primary"><i class="bi bi-plus-circle"></i> إضافة مهمة جديدة</h4>
                    <form action="index.php" method="POST">
                        <div class="mb-3">
                            <label class="form-label">عنوان المهمة</label>
                            <input type="text" name="title" class="form-control" placeholder="مثال: تصميم واجهة المستخدم" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">الوصف (اختياري)</label>
                            <textarea name="description" class="form-control" rows="3" placeholder="تفاصيل إضافية..."></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">الأولوية</label>
                            <select name="priority" class="form-select">
                                <option value="low">منخفضة</option>
                                <option value="medium" selected>متوسطة</option>
                                <option value="high">عالية</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">تاريخ الاستحقاق</label>
                            <input type="date" name="due_date" class="form-control">
                        </div>
                        <button type="submit" name="add_task" class="btn btn-primary w-100 rounded-3 py-2 fw-bold">حفظ المهمة</button>
                    </form>
                </div>
            </div>

            <!-- جدول عرض المهام والبحث والتصفية -->
            <div class="col-lg-8">
                <div class="card shadow-sm border-0 rounded-4 p-4">
                    
                    <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center mb-4 gap-3">
                        <h4 class="fw-bold text-body mb-0"><i class="bi bi-list-task"></i> مهامي الحالية</h4>
                        
                        <!-- نموذج البحث والتصفية المتقدم -->
                        <form action="index.php" method="GET" class="d-flex flex-wrap gap-2">
                            <input type="text" name="search" class="form-control form-control-sm w-auto" placeholder="بحث..." value="<?= htmlspecialchars($search) ?>">
                            
                            <!-- تصفية الحالة -->
                            <select name="status_filter" class="form-select form-select-sm w-auto" onchange="this.form.submit()">
                                <option value="all" <?= $status_filter == 'all' ? 'selected' : '' ?>>كل الحالات</option>
                                <option value="pending" <?= $status_filter == 'pending' ? 'selected' : '' ?>>قيد الانتظار</option>
                                <option value="in_progress" <?= $status_filter == 'in_progress' ? 'selected' : '' ?>>قيد التنفيذ</option>
                                <option value="completed" <?= $status_filter == 'completed' ? 'selected' : '' ?>>المنجزة</option>
                            </select>

                            <!-- تصفية الأولوية -->
                            <select name="priority_filter" class="form-select form-select-sm w-auto" onchange="this.form.submit()">
                                <option value="all" <?= $priority_filter == 'all' ? 'selected' : '' ?>>كل الأولويات</option>
                                <option value="high" <?= $priority_filter == 'high' ? 'selected' : '' ?>>عالية</option>
                                <option value="medium" <?= $priority_filter == 'medium' ? 'selected' : '' ?>>متوسطة</option>
                                <option value="low" <?= $priority_filter == 'low' ? 'selected' : '' ?>>منخفضة</option>
                            </select>

                            <?php if (!empty($search) || $status_filter != 'all' || $priority_filter != 'all'): ?>
                                <a href="index.php" class="btn btn-outline-secondary btn-sm" title="إعادة تعيين الفلاتر"><i class="bi bi-arrow-counterclockwise"></i></a>
                            <?php endif; ?>
                        </form>
                    </div>

                    <?php if (count($tasks) > 0): ?>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle">
                                <thead class="table-light">
                                    <tr>
                                        <th>المهمة</th>
                                        <th>الأولوية</th>
                                        <th>الحالة</th>
                                        <th>التاريخ</th>
                                        <th>الإجراءات</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($tasks as $task): ?>
                                        <?php 
                                            $is_overdue = (!empty($task['due_date']) && $task['due_date'] < date('Y-m-d') && $task['status'] != 'completed');
                                        ?>
                                        <tr class="<?= $is_overdue ? 'table-danger' : '' ?>">
                                            <td>
                                                <span class="fw-bold d-block"><?= htmlspecialchars($task['title']) ?></span>
                                                <small class="text-muted"><?= htmlspecialchars($task['description']) ?></small>
                                            </td>
                                            <td>
                                                <?php if ($task['priority'] == 'high'): ?>
                                                    <span class="badge bg-danger">عالية</span>
                                                <?php elseif ($task['priority'] == 'medium'): ?>
                                                    <span class="badge bg-warning text-dark">متوسطة</span>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary">منخفضة</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($task['status'] == 'completed'): ?>
                                                    <span class="badge bg-success">منجزة</span>
                                                <?php elseif ($task['status'] == 'in_progress'): ?>
                                                    <span class="badge bg-info text-dark">قيد التنفيذ</span>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary">قيد الانتظار</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <small class="<?= $is_overdue ? 'text-danger fw-bold' : 'text-muted' ?>">
                                                    <?= $task['due_date'] ?>
                                                    <?php if ($is_overdue): ?>
                                                        <span class="badge bg-danger ms-1">متأخرة!</span>
                                                    <?php endif; ?>
                                                </small>
                                            </td>
                                            <td>
                                                <div class="dropdown">
                                                    <button class="btn btn-sm btn-light border rounded-pill dropdown-toggle" type="button" data-bs-toggle="dropdown">
                                                        خيارات
                                                    </button>
                                                    <ul class="dropdown-menu">
                                                        <li><a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#editModal<?= $task['id'] ?>"><i class="bi bi-pencil text-primary"></i> تعديل</a></li>
                                                        <li><hr class="dropdown-divider"></li>
                                                        <li><a class="dropdown-item" href="index.php?toggle=<?= $task['id'] ?>&status=completed">تحديد كمنجزة</a></li>
                                                        <li><a class="dropdown-item" href="index.php?toggle=<?= $task['id'] ?>&status=in_progress">قيد التنفيذ</a></li>
                                                        <li><a class="dropdown-item" href="index.php?toggle=<?= $task['id'] ?>&status=pending">قيد الانتظار</a></li>
                                                        <li><hr class="dropdown-divider"></li>
                                                        <li><a class="dropdown-item text-danger" href="index.php?delete=<?= $task['id'] ?>" onclick="return confirm('هل أنت متأكد من الحذف؟')"><i class="bi bi-trash"></i> حذف</a></li>
                                                    </ul>
                                                </div>

                                                <!-- نافذة التعديل Modal -->
                                                <div class="modal fade" id="editModal<?= $task['id'] ?>" tabindex="-1">
                                                    <div class="modal-dialog">
                                                        <div class="modal-content rounded-4">
                                                            <div class="modal-header">
                                                                <h5 class="modal-title fw-bold">تعديل المهمة</h5>
                                                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                            </div>
                                                            <form action="index.php" method="POST">
                                                                <div class="modal-body text-start" dir="rtl">
                                                                    <input type="hidden" name="task_id" value="<?= $task['id'] ?>">
                                                                    <div class="mb-3">
                                                                        <label class="form-label">عنوان المهمة</label>
                                                                        <input type="text" name="title" class="form-control" value="<?= htmlspecialchars($task['title']) ?>" required>
                                                                    </div>
                                                                    <div class="mb-3">
                                                                        <label class="form-label">الوصف</label>
                                                                        <textarea name="description" class="form-control" rows="3"><?= htmlspecialchars($task['description']) ?></textarea>
                                                                    </div>
                                                                    <div class="mb-3">
                                                                        <label class="form-label">الأولوية</label>
                                                                        <select name="priority" class="form-select">
                                                                            <option value="low" <?= $task['priority'] == 'low' ? 'selected' : '' ?>>منخفضة</option>
                                                                            <option value="medium" <?= $task['priority'] == 'medium' ? 'selected' : '' ?>>متوسطة</option>
                                                                            <option value="high" <?= $task['priority'] == 'high' ? 'selected' : '' ?>>عالية</option>
                                                                        </select>
                                                                    </div>
                                                                    <div class="mb-3">
                                                                        <label class="form-label">تاريخ الاستحقاق</label>
                                                                        <input type="date" name="due_date" class="form-control" value="<?= $task['due_date'] ?>">
                                                                    </div>
                                                                </div>
                                                                <div class="modal-footer">
                                                                    <button type="button" class="btn btn-secondary rounded-pill" data-bs-dismiss="modal">إلغاء</button>
                                                                    <button type="submit" name="update_task" class="btn btn-primary rounded-pill px-4">حفظ التعديلات</button>
                                                                </div>
                                                            </form>
                                                        </div>
                                                    </div>
                                                </div>

                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-5 text-muted">
                            <i class="bi bi-inbox fs-1 d-block mb-2"></i>
                            <p>لا توجد مهام مطابقة للشروط أو الفلاتر الحالية.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

<?php
// ==========================================
// 9. معالجة حفظ تعديلات المهمة (UPDATE TASK)
// ==========================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_task'])) {
    $task_id = $_POST['task_id'];
    $title = trim($_POST['title']);
    $description = trim($_POST['description']);
    $priority = $_POST['priority'];
    $due_date = $_POST['due_date'];

    if (!empty($title)) {
        $update_stmt = $pdo->prepare("UPDATE tasks SET title = ?, description = ?, priority = ?, due_date = ? WHERE id = ? AND user_id = ?");
        $update_stmt->execute([$title, $description, $priority, $due_date, $task_id, $user_id]);
        echo "<script>window.location.href='index.php';</script>";
        exit;
    }
}
?>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <!-- Script الوضع المظلم وتخزين الحالة -->
    <script>
        function toggleDarkMode() {
            const htmlElement = document.documentElement;
            const icon = document.getElementById('darkModeIcon');
            
            if (htmlElement.getAttribute('data-bs-theme') === 'dark') {
                htmlElement.setAttribute('data-bs-theme', 'light');
                icon.classList.replace('bi-sun-fill', 'bi-moon-fill');
                localStorage.setItem('theme', 'light');
            } else {
                htmlElement.setAttribute('data-bs-theme', 'dark');
                icon.classList.replace('bi-moon-fill', 'bi-sun-fill');
                localStorage.setItem('theme', 'dark');
            }
        }

        window.addEventListener('DOMContentLoaded', () => {
            const savedTheme = localStorage.getItem('theme') || 'light';
            document.documentElement.setAttribute('data-bs-theme', savedTheme);
            const icon = document.getElementById('darkModeIcon');
            if (savedTheme === 'dark') {
                icon.classList.replace('bi-moon-fill', 'bi-sun-fill');
            }
        });
    </script>
</body>
</html>