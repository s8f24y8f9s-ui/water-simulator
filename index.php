<?php
session_start();
date_default_timezone_set('UTC');

if (!is_dir(__DIR__ . '/data')) {
    mkdir(__DIR__ . '/data', 0777, true);
}

$db = new PDO('sqlite:' . __DIR__ . '/data/app.db');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

initializeDatabase($db);

function initializeDatabase(PDO $db): void
{
    $db->exec('CREATE TABLE IF NOT EXISTS merchants (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        business_name TEXT NOT NULL,
        owner_name TEXT NOT NULL,
        slug TEXT NOT NULL UNIQUE,
        email TEXT NOT NULL UNIQUE,
        password_hash TEXT NOT NULL,
        timezone TEXT NOT NULL DEFAULT "America/New_York",
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )');

    $db->exec('CREATE TABLE IF NOT EXISTS services (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        merchant_id INTEGER NOT NULL,
        name TEXT NOT NULL,
        duration_minutes INTEGER NOT NULL,
        price REAL NOT NULL,
        active INTEGER NOT NULL DEFAULT 1,
        FOREIGN KEY (merchant_id) REFERENCES merchants(id)
    )');

    $db->exec('CREATE TABLE IF NOT EXISTS weekly_availability (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        merchant_id INTEGER NOT NULL,
        day_of_week INTEGER NOT NULL,
        start_time TEXT,
        end_time TEXT,
        is_closed INTEGER NOT NULL DEFAULT 0,
        UNIQUE (merchant_id, day_of_week),
        FOREIGN KEY (merchant_id) REFERENCES merchants(id)
    )');

    $db->exec('CREATE TABLE IF NOT EXISTS availability_exceptions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        merchant_id INTEGER NOT NULL,
        date TEXT NOT NULL,
        start_time TEXT,
        end_time TEXT,
        note TEXT,
        FOREIGN KEY (merchant_id) REFERENCES merchants(id)
    )');

    $db->exec('CREATE TABLE IF NOT EXISTS appointments (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        merchant_id INTEGER NOT NULL,
        service_id INTEGER NOT NULL,
        start_datetime TEXT NOT NULL,
        end_datetime TEXT NOT NULL,
        patron_name TEXT NOT NULL,
        patron_contact TEXT NOT NULL,
        status TEXT NOT NULL DEFAULT "booked",
        cancel_token TEXT NOT NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (merchant_id) REFERENCES merchants(id),
        FOREIGN KEY (service_id) REFERENCES services(id)
    )');
}

function h(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function slugify(PDO $db, string $name): string
{
    $slug = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $name), '-'));
    if ($slug === '') {
        $slug = 'merchant';
    }
    $base = $slug;
    $i = 1;
    $stmt = $db->prepare('SELECT COUNT(*) FROM merchants WHERE slug = ?');
    while (true) {
        $stmt->execute([$slug]);
        if ($stmt->fetchColumn() == 0) {
            return $slug;
        }
        $slug = $base . '-' . $i;
        $i++;
    }
}

function currentMerchant(PDO $db): ?array
{
    if (empty($_SESSION['merchant_id'])) {
        return null;
    }
    $stmt = $db->prepare('SELECT * FROM merchants WHERE id = ?');
    $stmt->execute([$_SESSION['merchant_id']]);
    $merchant = $stmt->fetch(PDO::FETCH_ASSOC);
    return $merchant ?: null;
}

function redirect(string $path): void
{
    header('Location: ' . $path);
    exit;
}

function ensureAuthenticated(PDO $db): array
{
    $merchant = currentMerchant($db);
    if (!$merchant) {
        redirect('index.php?page=login');
    }
    return $merchant;
}

function weeklyAvailability(PDO $db, int $merchantId): array
{
    $stmt = $db->prepare('SELECT * FROM weekly_availability WHERE merchant_id = ?');
    $stmt->execute([$merchantId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $byDay = [];
    foreach ($rows as $row) {
        $byDay[(int)$row['day_of_week']] = $row;
    }
    return $byDay;
}

function appointmentOverlaps(PDO $db, int $merchantId, string $start, string $end): bool
{
    $stmt = $db->prepare('SELECT COUNT(*) FROM appointments WHERE merchant_id = ? AND status = "booked" AND start_datetime < ? AND end_datetime > ?');
    $stmt->execute([$merchantId, $end, $start]);
    return $stmt->fetchColumn() > 0;
}

function slotIsBlocked(array $exception, DateTimeImmutable $slotStart, DateTimeImmutable $slotEnd): bool
{
    if (!$exception) {
        return false;
    }
    if (empty($exception['start_time']) && empty($exception['end_time'])) {
        return true; // full-day block
    }
    $tz = $slotStart->getTimezone();
    $blockStart = new DateTimeImmutable($exception['date'] . ' ' . ($exception['start_time'] ?? '00:00'), $tz);
    $blockEnd = new DateTimeImmutable($exception['date'] . ' ' . ($exception['end_time'] ?? '23:59'), $tz);
    return $slotStart < $blockEnd && $slotEnd > $blockStart;
}

function availableSlots(PDO $db, array $merchant, int $serviceId, string $date): array
{
    $serviceStmt = $db->prepare('SELECT * FROM services WHERE id = ? AND merchant_id = ? AND active = 1');
    $serviceStmt->execute([$serviceId, $merchant['id']]);
    $service = $serviceStmt->fetch(PDO::FETCH_ASSOC);
    if (!$service) {
        return [];
    }

    $tz = new DateTimeZone($merchant['timezone'] ?: 'America/New_York');
    $availability = weeklyAvailability($db, (int)$merchant['id']);
    $dayOfWeek = (int)(new DateTimeImmutable($date, $tz))->format('w');
    $day = $availability[$dayOfWeek] ?? ['is_closed' => 1];
    if (!empty($day['is_closed'])) {
        return [];
    }

    $exceptionsStmt = $db->prepare('SELECT * FROM availability_exceptions WHERE merchant_id = ? AND date = ?');
    $exceptionsStmt->execute([$merchant['id'], $date]);
    $exceptions = $exceptionsStmt->fetchAll(PDO::FETCH_ASSOC);

    $startWindow = new DateTimeImmutable($date . ' ' . ($day['start_time'] ?? '09:00'), $tz);
    $endWindow = new DateTimeImmutable($date . ' ' . ($day['end_time'] ?? '17:00'), $tz);

    $duration = (int)$service['duration_minutes'];
    $slots = [];

    for ($cursor = $startWindow; $cursor->add(new DateInterval('PT' . $duration . 'M')) <= $endWindow; $cursor = $cursor->add(new DateInterval('PT15M'))) {
        $slotEnd = $cursor->add(new DateInterval('PT' . $duration . 'M'));
        $blocked = false;
        foreach ($exceptions as $ex) {
            if (slotIsBlocked($ex, $cursor, $slotEnd)) {
                $blocked = true;
                break;
            }
        }
        if ($blocked) {
            continue;
        }
        $overlaps = appointmentOverlaps($db, (int)$merchant['id'], $cursor->format(DateTime::ATOM), $slotEnd->format(DateTime::ATOM));
        if ($overlaps) {
            continue;
        }
        $slots[] = $cursor->format('H:i');
    }

    return $slots;
}

function flash(?string $message = null): ?string
{
    if ($message !== null) {
        $_SESSION['flash'] = $message;
        return null;
    }
    if (!empty($_SESSION['flash'])) {
        $msg = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $msg;
    }
    return null;
}

$page = $_GET['page'] ?? 'login';
$merchant = currentMerchant($db);

if ($page === 'logout') {
    session_destroy();
    redirect('index.php?page=login');
}

if ($page === 'signup' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $business = trim($_POST['business_name'] ?? '');
    $owner = trim($_POST['owner_name'] ?? '');
    $email = strtolower(trim($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';
    $timezone = $_POST['timezone'] ?? 'America/New_York';

    if ($business && $owner && $email && $password) {
        $slug = slugify($db, $business);
        $stmt = $db->prepare('INSERT INTO merchants (business_name, owner_name, email, password_hash, slug, timezone) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->execute([$business, $owner, $email, password_hash($password, PASSWORD_DEFAULT), $slug, $timezone]);
        $_SESSION['merchant_id'] = (int)$db->lastInsertId();
        flash('Welcome! Start by adding your services.');
        redirect('index.php?page=services');
    } else {
        flash('All fields are required to sign up.');
    }
}

if ($page === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = strtolower(trim($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';
    $stmt = $db->prepare('SELECT * FROM merchants WHERE email = ?');
    $stmt->execute([$email]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row && password_verify($password, $row['password_hash'])) {
        $_SESSION['merchant_id'] = (int)$row['id'];
        flash('Signed in.');
        redirect('index.php?page=dashboard');
    } else {
        flash('Invalid credentials.');
    }
}

if ($page === 'services' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $merchant = ensureAuthenticated($db);
    if (isset($_POST['create_service'])) {
        $name = trim($_POST['name'] ?? '');
        $duration = (int)($_POST['duration_minutes'] ?? 0);
        $price = (float)($_POST['price'] ?? 0);
        if ($name && $duration > 0) {
            $stmt = $db->prepare('INSERT INTO services (merchant_id, name, duration_minutes, price, active) VALUES (?, ?, ?, ?, 1)');
            $stmt->execute([$merchant['id'], $name, $duration, $price]);
            flash('Service added.');
        } else {
            flash('Name and duration are required.');
        }
    }
    if (isset($_POST['update_service'])) {
        $id = (int)$_POST['service_id'];
        $name = trim($_POST['name'] ?? '');
        $duration = (int)($_POST['duration_minutes'] ?? 0);
        $price = (float)($_POST['price'] ?? 0);
        $active = isset($_POST['active']) ? 1 : 0;
        $stmt = $db->prepare('UPDATE services SET name = ?, duration_minutes = ?, price = ?, active = ? WHERE id = ? AND merchant_id = ?');
        $stmt->execute([$name, $duration, $price, $active, $id, $merchant['id']]);
        flash('Service updated.');
    }
    if (isset($_POST['delete_service'])) {
        $id = (int)$_POST['service_id'];
        $stmt = $db->prepare('DELETE FROM services WHERE id = ? AND merchant_id = ?');
        $stmt->execute([$id, $merchant['id']]);
        flash('Service removed.');
    }
    redirect('index.php?page=services');
}

if ($page === 'availability' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $merchant = ensureAuthenticated($db);
    $days = range(0, 6);
    foreach ($days as $day) {
        $isClosed = isset($_POST['closed'][$day]) ? 1 : 0;
        $start = $_POST['start'][$day] ?? null;
        $end = $_POST['end'][$day] ?? null;
        $existingStmt = $db->prepare('SELECT id FROM weekly_availability WHERE merchant_id = ? AND day_of_week = ?');
        $existingStmt->execute([$merchant['id'], $day]);
        $existingId = $existingStmt->fetchColumn();
        if ($existingId) {
            $stmt = $db->prepare('UPDATE weekly_availability SET start_time = ?, end_time = ?, is_closed = ? WHERE id = ?');
            $stmt->execute([$start, $end, $isClosed, $existingId]);
        } else {
            $stmt = $db->prepare('INSERT INTO weekly_availability (merchant_id, day_of_week, start_time, end_time, is_closed) VALUES (?, ?, ?, ?, ?)');
            $stmt->execute([$merchant['id'], $day, $start, $end, $isClosed]);
        }
    }
    flash('Weekly availability saved.');
    redirect('index.php?page=availability');
}

if ($page === 'exceptions' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $merchant = ensureAuthenticated($db);
    if (isset($_POST['create_exception'])) {
        $date = $_POST['date'] ?? '';
        $start = $_POST['start_time'] ?? null;
        $end = $_POST['end_time'] ?? null;
        $note = trim($_POST['note'] ?? '');
        if ($date) {
            $stmt = $db->prepare('INSERT INTO availability_exceptions (merchant_id, date, start_time, end_time, note) VALUES (?, ?, ?, ?, ?)');
            $stmt->execute([$merchant['id'], $date, $start, $end, $note]);
            flash('Exception added.');
        } else {
            flash('Date is required.');
        }
    }
    if (isset($_POST['delete_exception'])) {
        $id = (int)$_POST['exception_id'];
        $stmt = $db->prepare('DELETE FROM availability_exceptions WHERE id = ? AND merchant_id = ?');
        $stmt->execute([$id, $merchant['id']]);
        flash('Exception removed.');
    }
    redirect('index.php?page=exceptions');
}

if ($page === 'book_submit' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $slug = $_POST['merchant_slug'] ?? '';
    $serviceId = (int)($_POST['service_id'] ?? 0);
    $date = $_POST['date'] ?? '';
    $time = $_POST['time'] ?? '';
    $name = trim($_POST['patron_name'] ?? '');
    $contact = trim($_POST['patron_contact'] ?? '');

    $merchantStmt = $db->prepare('SELECT * FROM merchants WHERE slug = ?');
    $merchantStmt->execute([$slug]);
    $merchantRow = $merchantStmt->fetch(PDO::FETCH_ASSOC);
    if (!$merchantRow) {
        flash('Merchant not found.');
        redirect('index.php?page=book&merchant=' . urlencode($slug));
    }
    if (!$name || !$contact || !$date || !$time || !$serviceId) {
        flash('Please fill out all required fields.');
        redirect('index.php?page=book&merchant=' . urlencode($slug));
    }

    $serviceStmt = $db->prepare('SELECT * FROM services WHERE id = ? AND merchant_id = ? AND active = 1');
    $serviceStmt->execute([$serviceId, $merchantRow['id']]);
    $service = $serviceStmt->fetch(PDO::FETCH_ASSOC);
    if (!$service) {
        flash('Service unavailable.');
        redirect('index.php?page=book&merchant=' . urlencode($slug));
    }

    $tz = new DateTimeZone($merchantRow['timezone'] ?: 'America/New_York');
    $start = new DateTimeImmutable($date . ' ' . $time, $tz);
    $end = $start->add(new DateInterval('PT' . $service['duration_minutes'] . 'M'));

    $available = availableSlots($db, $merchantRow, $serviceId, $date);
    if (!in_array($start->format('H:i'), $available, true)) {
        flash('That time was just booked—please pick another.');
        redirect('index.php?page=book&merchant=' . urlencode($slug));
    }

    $db->beginTransaction();
    $conflict = appointmentOverlaps($db, (int)$merchantRow['id'], $start->format(DateTime::ATOM), $end->format(DateTime::ATOM));
    if ($conflict) {
        $db->rollBack();
        flash('That time was just booked—please pick another.');
        redirect('index.php?page=book&merchant=' . urlencode($slug));
    }
    $token = bin2hex(random_bytes(16));
    $insert = $db->prepare('INSERT INTO appointments (merchant_id, service_id, start_datetime, end_datetime, patron_name, patron_contact, status, cancel_token) VALUES (?, ?, ?, ?, ?, ?, "booked", ?)');
    $insert->execute([$merchantRow['id'], $serviceId, $start->format(DateTime::ATOM), $end->format(DateTime::ATOM), $name, $contact, $token]);
    $appointmentId = (int)$db->lastInsertId();
    $db->commit();

    redirect('index.php?page=confirm&id=' . $appointmentId . '&token=' . urlencode($token));
}

if ($page === 'cancel') {
    $token = $_GET['token'] ?? '';
    $stmt = $db->prepare('UPDATE appointments SET status = "cancelled" WHERE cancel_token = ?');
    $stmt->execute([$token]);
    flash('Appointment cancelled.');
    redirect('index.php?page=login');
}

function renderHead(string $title = 'Merchant Booking Portal'): void
{
    ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?php echo h($title); ?></title>
<style>
:root { --bg:#0b172a; --card:#0f203d; --accent:#4cc9f0; --text:#eaeaea; --muted:#9bb0c8; }
*{box-sizing:border-box}body{margin:0;font-family:system-ui,Arial,sans-serif;background:var(--bg);color:var(--text);min-height:100vh}
a{color:var(--accent);text-decoration:none}a:hover{text-decoration:underline}
header{padding:16px 24px;background:#0d1b31;border-bottom:1px solid rgba(255,255,255,0.05);display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px}
nav a{margin-right:12px;font-weight:600}
.container{max-width:1100px;margin:24px auto;padding:0 16px}
.card{background:var(--card);padding:16px 18px;border-radius:12px;border:1px solid rgba(255,255,255,0.07);margin-bottom:16px;box-shadow:0 6px 24px rgba(0,0,0,0.3)}
.card h2{margin-top:0;margin-bottom:12px}
label{display:block;margin:8px 0 4px;font-weight:600}
input,select,button,textarea{width:100%;padding:10px;border-radius:8px;border:1px solid rgba(255,255,255,0.15);background:#0b1c34;color:var(--text);font-size:15px}
button{background:var(--accent);color:#041120;border:none;font-weight:700;cursor:pointer;transition:.15s ease all}button:hover{filter:brightness(1.05)}
.flex{display:flex;gap:12px;flex-wrap:wrap}
.flex .half{flex:1;min-width:220px}
.table{width:100%;border-collapse:collapse;font-size:14px}
.table th,.table td{padding:10px;border-bottom:1px solid rgba(255,255,255,0.06);text-align:left}
.badge{display:inline-block;padding:4px 8px;border-radius:8px;font-size:12px;background:rgba(76,201,240,0.12);color:var(--accent)}
.flash{background:#163156;border:1px solid rgba(76,201,240,0.4);padding:12px;border-radius:10px;margin-bottom:12px}
.small{font-size:13px;color:var(--muted)}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:12px}
.tag{display:inline-block;padding:4px 8px;border-radius:8px;background:rgba(255,255,255,0.08);font-size:13px;color:var(--muted)}
</style>
</head>
<body>
<?php
}

function renderHeader(?array $merchant): void
{
    ?>
<header>
  <div><strong>Booking Portal</strong><?php if ($merchant): ?> · <span class="small">Logged in as <?php echo h($merchant['business_name']); ?></span><?php endif; ?></div>
  <nav>
    <?php if ($merchant): ?>
      <a href="index.php?page=dashboard">Dashboard</a>
      <a href="index.php?page=services">Services</a>
      <a href="index.php?page=availability">Availability</a>
      <a href="index.php?page=exceptions">Time Off</a>
      <a href="index.php?page=bookings">Bookings</a>
      <a href="index.php?page=qr">QR Code</a>
      <a href="index.php?page=logout">Logout</a>
    <?php else: ?>
      <a href="index.php?page=login">Login</a>
      <a href="index.php?page=signup">Sign Up</a>
    <?php endif; ?>
  </nav>
</header>
<?php
}

function renderFooter(): void
{
    echo "</body></html>";
}

$dayNames = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];

if ($page === 'available_times') {
    $slug = $_GET['merchant'] ?? '';
    $serviceId = (int)($_GET['service_id'] ?? 0);
    $date = $_GET['date'] ?? '';
    $stmt = $db->prepare('SELECT * FROM merchants WHERE slug = ?');
    $stmt->execute([$slug]);
    $merchantRow = $stmt->fetch(PDO::FETCH_ASSOC);
    header('Content-Type: application/json');
    if (!$merchantRow || !$serviceId || !$date) {
        echo json_encode(['times' => []]);
        exit;
    }
    echo json_encode(['times' => availableSlots($db, $merchantRow, $serviceId, $date)]);
    exit;
}

if ($page === 'confirm') {
    $id = (int)($_GET['id'] ?? 0);
    $token = $_GET['token'] ?? '';
    $stmt = $db->prepare('SELECT a.*, m.business_name, m.slug, m.timezone, s.name AS service_name, s.duration_minutes, s.price FROM appointments a JOIN merchants m ON a.merchant_id = m.id JOIN services s ON a.service_id = s.id WHERE a.id = ? AND a.cancel_token = ?');
    $stmt->execute([$id, $token]);
    $appt = $stmt->fetch(PDO::FETCH_ASSOC);
    renderHead('Booking Confirmation');
    ?>
    <div class="container">
      <div class="card">
        <?php if ($appt): ?>
          <h2>Booking Confirmed</h2>
          <p><strong><?php echo h($appt['business_name']); ?></strong></p>
          <p><?php echo h($appt['service_name']); ?> — <?php echo h($appt['duration_minutes']); ?> mins — $<?php echo number_format($appt['price'],2); ?></p>
          <p>
            <?php
              $tz = new DateTimeZone($appt['timezone']);
              $start = new DateTimeImmutable($appt['start_datetime']);
              echo $start->setTimezone($tz)->format('l, F j \a\t g:i A');
            ?>
          </p>
          <p>Name: <?php echo h($appt['patron_name']); ?><br>Contact: <?php echo h($appt['patron_contact']); ?></p>
          <p><a href="index.php?page=cancel&token=<?php echo h($appt['cancel_token']); ?>" class="badge">Cancel this appointment</a></p>
          <p class="small">Bookmark this page to manage your booking.</p>
        <?php else: ?>
          <h2>Booking not found</h2>
          <p>This confirmation link is invalid.</p>
        <?php endif; ?>
      </div>
    </div>
    <?php
    renderFooter();
    exit;
}

if ($page === 'book') {
    $slug = $_GET['merchant'] ?? '';
    $stmt = $db->prepare('SELECT * FROM merchants WHERE slug = ?');
    $stmt->execute([$slug]);
    $merchantRow = $stmt->fetch(PDO::FETCH_ASSOC);
    renderHead('Book an Appointment');
    ?>
    <div class="container">
      <div class="card">
        <?php if (!$merchantRow): ?>
          <h2>Merchant not found</h2>
          <p>Check the QR link or URL and try again.</p>
        <?php else: ?>
          <h2>Book with <?php echo h($merchantRow['business_name']); ?></h2>
          <p class="small">Choose a service, date, and time to book.</p>
          <?php if ($msg = flash()): ?><div class="flash"><?php echo h($msg); ?></div><?php endif; ?>
          <?php
            $services = $db->prepare('SELECT * FROM services WHERE merchant_id = ? AND active = 1');
            $services->execute([$merchantRow['id']]);
            $serviceRows = $services->fetchAll(PDO::FETCH_ASSOC);
          ?>
          <?php if (!$serviceRows): ?>
            <p>This merchant is not accepting bookings yet.</p>
          <?php else: ?>
          <form method="post" action="index.php?page=book_submit" id="booking-form">
            <input type="hidden" name="merchant_slug" value="<?php echo h($slug); ?>">
            <label>Service</label>
            <select name="service_id" id="service_id" required>
              <option value="">Select a service</option>
              <?php foreach ($serviceRows as $service): ?>
                <option value="<?php echo h($service['id']); ?>" data-duration="<?php echo h($service['duration_minutes']); ?>"><?php echo h($service['name']); ?> — <?php echo h($service['duration_minutes']); ?> mins — $<?php echo number_format($service['price'],2); ?></option>
              <?php endforeach; ?>
            </select>
            <label>Date</label>
            <input type="date" name="date" id="date" required>
            <label>Time</label>
            <select name="time" id="time" required></select>
            <div class="flex">
              <div class="half">
                <label>Full Name</label>
                <input type="text" name="patron_name" required>
              </div>
              <div class="half">
                <label>Mobile or Email</label>
                <input type="text" name="patron_contact" required>
              </div>
            </div>
            <button type="submit">Confirm Booking</button>
          </form>
          <script>
            const service = document.getElementById('service_id');
            const dateInput = document.getElementById('date');
            const timeSelect = document.getElementById('time');
            const fetchTimes = () => {
              timeSelect.innerHTML = '';
              if (!service.value || !dateInput.value) return;
              fetch(`index.php?page=available_times&merchant=<?php echo h(urlencode($slug)); ?>&service_id=${service.value}&date=${dateInput.value}`)
                .then(r => r.json())
                .then(data => {
                  if (!data.times.length) {
                    timeSelect.innerHTML = '<option value="">No times available</option>';
                    return;
                  }
                  timeSelect.innerHTML = '<option value="">Select a time</option>' + data.times.map(t => `<option value="${t}">${t}</option>`).join('');
                });
            };
            service.addEventListener('change', fetchTimes);
            dateInput.addEventListener('change', fetchTimes);
            const today = new Date().toISOString().split('T')[0];
            dateInput.min = today;
          </script>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>
    <?php
    renderFooter();
    exit;
}

if ($page === 'qr') {
    $merchant = ensureAuthenticated($db);
    $bookingUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['REQUEST_URI']) . '/index.php?page=book&merchant=' . urlencode($merchant['slug']);
    renderHead('QR Code');
    renderHeader($merchant);
    ?>
    <div class="container">
      <div class="card">
        <h2>Share your booking page</h2>
        <p>Booking URL:</p>
        <input type="text" readonly value="<?php echo h($bookingUrl); ?>">
        <p class="small">QR code updates automatically from the URL below.</p>
        <img src="https://api.qrserver.com/v1/create-qr-code/?size=240x240&data=<?php echo urlencode($bookingUrl); ?>" alt="QR code linking to booking page">
      </div>
    </div>
    <?php
    renderFooter();
    exit;
}

if ($page === 'bookings') {
    $merchant = ensureAuthenticated($db);
    renderHead('Bookings');
    renderHeader($merchant);
    ?>
    <div class="container">
      <div class="card">
        <h2>Bookings</h2>
        <?php if ($msg = flash()): ?><div class="flash"><?php echo h($msg); ?></div><?php endif; ?>
        <table class="table">
          <tr><th>Service</th><th>When</th><th>Patron</th><th>Status</th></tr>
          <?php
            $stmt = $db->prepare('SELECT a.*, s.name AS service_name, m.timezone FROM appointments a JOIN services s ON a.service_id = s.id JOIN merchants m ON a.merchant_id = m.id WHERE a.merchant_id = ? ORDER BY a.start_datetime DESC');
            $stmt->execute([$merchant['id']]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (!$rows) {
              echo '<tr><td colspan="4">No bookings yet.</td></tr>';
            }
            foreach ($rows as $row):
              $tz = new DateTimeZone($row['timezone'] ?? 'America/New_York');
              $start = new DateTimeImmutable($row['start_datetime']);
          ?>
          <tr>
            <td><?php echo h($row['service_name']); ?></td>
            <td><?php echo h($start->setTimezone($tz)->format('M j, g:i A')); ?></td>
            <td><?php echo h($row['patron_name']); ?> (<?php echo h($row['patron_contact']); ?>)</td>
            <td><span class="badge"><?php echo h($row['status']); ?></span></td>
          </tr>
          <?php endforeach; ?>
        </table>
      </div>
    </div>
    <?php
    renderFooter();
    exit;
}

if ($page === 'exceptions') {
    $merchant = ensureAuthenticated($db);
    renderHead('Time Off / Exceptions');
    renderHeader($merchant);
    ?>
    <div class="container">
      <div class="card">
        <h2>Blocked Time</h2>
        <?php if ($msg = flash()): ?><div class="flash"><?php echo h($msg); ?></div><?php endif; ?>
        <form method="post" action="index.php?page=exceptions" class="flex">
          <div class="half">
            <label>Date</label>
            <input type="date" name="date" required>
          </div>
          <div class="half">
            <label>Start time (optional)</label>
            <input type="time" name="start_time">
          </div>
          <div class="half">
            <label>End time (optional)</label>
            <input type="time" name="end_time">
          </div>
          <div class="half">
            <label>Note</label>
            <input type="text" name="note" placeholder="Vacation, lunch, etc.">
          </div>
          <button type="submit" name="create_exception">Add Exception</button>
        </form>
        <h3>Existing blocks</h3>
        <table class="table">
          <tr><th>Date</th><th>Time</th><th>Note</th><th></th></tr>
          <?php
            $stmt = $db->prepare('SELECT * FROM availability_exceptions WHERE merchant_id = ? ORDER BY date DESC');
            $stmt->execute([$merchant['id']]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (!$rows) echo '<tr><td colspan="4">No exceptions yet.</td></tr>';
            foreach ($rows as $row):
          ?>
          <tr>
            <td><?php echo h($row['date']); ?></td>
            <td><?php echo ($row['start_time'] || $row['end_time']) ? h(($row['start_time'] ?? '—') . '–' . ($row['end_time'] ?? '—')) : 'All day'; ?></td>
            <td><?php echo h($row['note']); ?></td>
            <td>
              <form method="post" action="index.php?page=exceptions" style="margin:0">
                <input type="hidden" name="exception_id" value="<?php echo h($row['id']); ?>">
                <button type="submit" name="delete_exception">Delete</button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
        </table>
      </div>
    </div>
    <?php
    renderFooter();
    exit;
}

if ($page === 'availability') {
    $merchant = ensureAuthenticated($db);
    $availability = weeklyAvailability($db, (int)$merchant['id']);
    renderHead('Weekly Availability');
    renderHeader($merchant);
    ?>
    <div class="container">
      <div class="card">
        <h2>Weekly Availability</h2>
        <?php if ($msg = flash()): ?><div class="flash"><?php echo h($msg); ?></div><?php endif; ?>
        <form method="post" action="index.php?page=availability">
          <div class="grid">
            <?php foreach ($dayNames as $idx => $name): $row = $availability[$idx] ?? ['start_time' => '09:00','end_time' => '17:00','is_closed' => 1]; ?>
              <div class="card" style="padding:12px;">
                <strong><?php echo h($name); ?></strong>
                <label style="margin-top:8px;"><input type="checkbox" name="closed[<?php echo $idx; ?>]" <?php echo !empty($row['is_closed']) ? 'checked' : ''; ?>> Closed</label>
                <label>Start</label>
                <input type="time" name="start[<?php echo $idx; ?>]" value="<?php echo h($row['start_time']); ?>">
                <label>End</label>
                <input type="time" name="end[<?php echo $idx; ?>]" value="<?php echo h($row['end_time']); ?>">
              </div>
            <?php endforeach; ?>
          </div>
          <button type="submit" style="margin-top:12px;">Save Availability</button>
        </form>
      </div>
    </div>
    <?php
    renderFooter();
    exit;
}

if ($page === 'services') {
    $merchant = ensureAuthenticated($db);
    $services = $db->prepare('SELECT * FROM services WHERE merchant_id = ?');
    $services->execute([$merchant['id']]);
    $serviceRows = $services->fetchAll(PDO::FETCH_ASSOC);
    renderHead('Services');
    renderHeader($merchant);
    ?>
    <div class="container">
      <div class="card">
        <h2>Services</h2>
        <?php if ($msg = flash()): ?><div class="flash"><?php echo h($msg); ?></div><?php endif; ?>
        <div class="grid">
          <?php foreach ($serviceRows as $service): ?>
            <div class="card" style="padding:12px;">
              <form method="post" action="index.php?page=services">
                <input type="hidden" name="service_id" value="<?php echo h($service['id']); ?>">
                <label>Name</label>
                <input type="text" name="name" value="<?php echo h($service['name']); ?>" required>
                <label>Duration (minutes)</label>
                <input type="number" name="duration_minutes" value="<?php echo h($service['duration_minutes']); ?>" required>
                <label>Price</label>
                <input type="number" step="0.01" name="price" value="<?php echo h($service['price']); ?>" required>
                <label><input type="checkbox" name="active" <?php echo !empty($service['active']) ? 'checked' : ''; ?>> Active</label>
                <div class="flex">
                  <button type="submit" name="update_service" class="half">Save</button>
                  <button type="submit" name="delete_service" class="half" style="background:#ff6b6b;">Delete</button>
                </div>
              </form>
            </div>
          <?php endforeach; ?>
          <div class="card" style="padding:12px;">
            <form method="post" action="index.php?page=services">
              <h3>Add service</h3>
              <label>Name</label>
              <input type="text" name="name" required>
              <label>Duration (minutes)</label>
              <input type="number" name="duration_minutes" required>
              <label>Price</label>
              <input type="number" step="0.01" name="price" required>
              <button type="submit" name="create_service">Add</button>
            </form>
          </div>
        </div>
      </div>
    </div>
    <?php
    renderFooter();
    exit;
}

if ($page === 'dashboard') {
    $merchant = ensureAuthenticated($db);
    $serviceCount = $db->prepare('SELECT COUNT(*) FROM services WHERE merchant_id = ?');
    $serviceCount->execute([$merchant['id']]);
    $serviceTotal = (int)$serviceCount->fetchColumn();

    $upcoming = $db->prepare('SELECT a.start_datetime, s.name AS service_name, m.timezone FROM appointments a JOIN services s ON a.service_id = s.id JOIN merchants m ON a.merchant_id = m.id WHERE a.merchant_id = ? AND a.status = "booked" ORDER BY a.start_datetime ASC LIMIT 3');
    $upcoming->execute([$merchant['id']]);
    $upcomingRows = $upcoming->fetchAll(PDO::FETCH_ASSOC);

    $availability = weeklyAvailability($db, (int)$merchant['id']);
    $openDays = array_filter($availability, fn($row) => empty($row['is_closed']));

    renderHead('Dashboard');
    renderHeader($merchant);
    ?>
    <div class="container">
      <div class="card">
        <h2>Welcome, <?php echo h($merchant['owner_name']); ?></h2>
        <p class="small">Your booking link: <a href="index.php?page=book&merchant=<?php echo h(urlencode($merchant['slug'])); ?>">index.php?page=book&merchant=<?php echo h($merchant['slug']); ?></a></p>
        <div class="grid">
          <div class="card"><strong>Services</strong><p><?php echo $serviceTotal; ?> configured</p></div>
          <div class="card"><strong>Open days</strong><p><?php echo count($openDays); ?> days set to open</p></div>
          <div class="card"><strong>Timezone</strong><p><?php echo h($merchant['timezone']); ?></p></div>
        </div>
      </div>
      <div class="card">
        <h3>Next bookings</h3>
        <?php if (!$upcomingRows): ?>
          <p>No upcoming bookings yet.</p>
        <?php else: ?>
          <ul>
            <?php foreach ($upcomingRows as $row): $tz = new DateTimeZone($row['timezone']); $start = new DateTimeImmutable($row['start_datetime']); ?>
              <li><?php echo h($row['service_name']); ?> — <?php echo h($start->setTimezone($tz)->format('D, M j g:i A')); ?></li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>
    </div>
    <?php
    renderFooter();
    exit;
}

if ($page === 'signup' || $page === 'login') {
    $isSignup = $page === 'signup';
    renderHead($isSignup ? 'Sign Up' : 'Log In');
    renderHeader($merchant);
    ?>
    <div class="container">
      <div class="card" style="max-width:520px;margin:32px auto;">
        <h2><?php echo $isSignup ? 'Create a merchant account' : 'Merchant login'; ?></h2>
        <?php if ($msg = flash()): ?><div class="flash"><?php echo h($msg); ?></div><?php endif; ?>
        <form method="post" action="index.php?page=<?php echo $isSignup ? 'signup' : 'login'; ?>">
          <?php if ($isSignup): ?>
            <label>Business name</label>
            <input type="text" name="business_name" required>
            <label>Owner name</label>
            <input type="text" name="owner_name" required>
            <label>Timezone</label>
            <select name="timezone">
              <?php
                $timezones = ['America/New_York','America/Chicago','America/Denver','America/Los_Angeles','UTC','Europe/London','Europe/Berlin'];
                foreach ($timezones as $tz):
              ?>
                <option value="<?php echo h($tz); ?>" <?php echo $tz === 'America/New_York' ? 'selected' : ''; ?>><?php echo h($tz); ?></option>
              <?php endforeach; ?>
            </select>
          <?php endif; ?>
          <label>Email</label>
          <input type="email" name="email" required>
          <label>Password</label>
          <input type="password" name="password" required>
          <button type="submit"><?php echo $isSignup ? 'Create account' : 'Log in'; ?></button>
        </form>
        <p class="small" style="margin-top:12px;">
          <?php if ($isSignup): ?>Already have an account? <a href="index.php?page=login">Log in</a>.
          <?php else: ?>Need an account? <a href="index.php?page=signup">Sign up</a>.
          <?php endif; ?>
        </p>
      </div>
    </div>
    <?php
    renderFooter();
    exit;
}

renderHead('Page not found');
renderHeader($merchant);
?>
<div class="container"><div class="card"><h2>Page not found</h2><p>The page you requested does not exist.</p></div></div>
<?php renderFooter();
