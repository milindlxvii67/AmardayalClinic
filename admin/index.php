<?php

declare(strict_types=1);

/*
 * Do not expose PHP errors to website visitors in production.
 * Set APP_DEBUG=1 in the environment when local debugging is required.
 */
$debug = getenv('APP_DEBUG') === '1';
ini_set('display_errors', $debug ? '1' : '0');
ini_set('display_startup_errors', $debug ? '1' : '0');
error_reporting(E_ALL);

require_once __DIR__ . '/../config.php';
require_staff(['Admin','Doctor','Reception']);

$role = $_SESSION['staff_role'] ?? 'Staff';
$staffName = $_SESSION['staff_name'] ?? $_SESSION['staff_username'] ?? 'Staff';

$today = date('Y-m-d');
$tomorrow = date('Y-m-d', strtotime('+1 day'));

function dcount($conn, $sql) {
    $r = $conn->query($sql);
    if (!$r) return 0;
    $row = $r->fetch_assoc();
    return (int)($row['total'] ?? 0);
}

function dmoney($conn, $sql) {
    $r = $conn->query($sql);
    if (!$r) return 0;
    $row = $r->fetch_assoc();
    return (float)($row['total'] ?? 0);
}

function ddate($date) {
    return date('d M Y', strtotime($date));
}

function dtime($time) {
    if (!$time) return '—';
    $t = strtotime($time);
    return $t ? date('h:i A', $t) : $time;
}

function dstatus($status) {
    $s = strtolower(trim($status));
    if (in_array($s, ['completed','complete'], true)) return 'done';
    if (in_array($s, ['cancelled','rejected'], true)) return 'bad';
    if (in_array($s, ['approved','waiting','checked in'], true)) return 'active';
    return 'pending';
}

/* TODAY */
$todayAppointments = dcount($conn, "SELECT COUNT(*) total FROM appointments WHERE date='$today'");
$todayCompleted = dcount($conn, "SELECT COUNT(*) total FROM appointments WHERE date='$today' AND status='Completed'");
$todayWaiting = dcount($conn, "SELECT COUNT(*) total FROM appointments WHERE date='$today' AND status IN ('Pending','Approved','Waiting','Checked In')");
$todayPatients = dcount($conn, "SELECT COUNT(DISTINCT patient_id) total FROM appointments WHERE date='$today'");
$todayRecords = dcount($conn, "SELECT COUNT(*) total FROM visits WHERE visit_date='$today'");
$todayRevenue = dmoney($conn, "SELECT COALESCE(SUM(net_amount),0) total FROM bills WHERE bill_date='$today'");

/* TOMORROW */
$tomorrowAppointments = dcount($conn, "SELECT COUNT(*) total FROM appointments WHERE date='$tomorrow'");

/* TODAY APPOINTMENTS */
$todayRows = [];
$s = $conn->prepare("SELECT id,patient_id,name,phone,clinic,service,problem,date,time,status
                     FROM appointments WHERE date=?
                     ORDER BY time ASC,id ASC LIMIT 100");
if ($s) {
    $s->bind_param('s', $today);
    $s->execute();
    $r = $s->get_result();
    while ($row = $r->fetch_assoc()) $todayRows[] = $row;
    $s->close();
}

/* TOMORROW APPOINTMENTS */
$tomorrowRows = [];
$s = $conn->prepare("SELECT id,patient_id,name,phone,clinic,service,problem,date,time,status
                     FROM appointments WHERE date=?
                     ORDER BY time ASC,id ASC LIMIT 100");
if ($s) {
    $s->bind_param('s', $tomorrow);
    $s->execute();
    $r = $s->get_result();
    while ($row = $r->fetch_assoc()) $tomorrowRows[] = $row;
    $s->close();
}

/* RECENT PERMANENT RECORDS */
$records = [];
$r = $conn->query("SELECT v.id,v.visit_date,v.opd_serial,v.status,v.diagnosis,
                          p.id patient_id,p.code,p.name
                   FROM visits v
                   JOIN patients p ON p.id=v.patient_id
                   WHERE v.status IN ('Completed','Complete')
                   ORDER BY v.visit_date DESC,v.id DESC LIMIT 8");
if ($r) while ($row=$r->fetch_assoc()) $records[]=$row;
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Staff Dashboard | AmarDayal Clinic</title>
<link rel="stylesheet" href="<?= e(rtrim(BASE_URL, '/') . '/Styles/style.css') ?>">
<style>
:root{--bg:#f5f8fc;--card:#fff;--text:#10233f;--muted:#718096;--line:#e5ebf2;--blue:#2563eb;--blueSoft:#edf4ff;--green:#059669;--greenSoft:#eafaf4;--orange:#d97706;--orangeSoft:#fff7e8;--red:#dc2626;--redSoft:#fff0f0}
*{box-sizing:border-box}
body{margin:0;padding:0 !important;background:var(--bg);overflow-x:hidden}
.layout{min-height:100vh;width:100%;display:block}
.content{margin-left:270px;width:calc(100% - 270px);min-height:100vh;padding:0;max-width:none}
@media(max-width:900px){
    .content{margin-left:78px;width:calc(100% - 78px)}
}
@media(max-width:520px){
    .content{margin-left:66px;width:calc(100% - 66px)}
}
.dashboard{padding:28px 34px 50px;max-width:1600px;margin:auto}
.head{display:flex;justify-content:space-between;align-items:center;gap:20px;margin-bottom:25px}
.kicker{font-size:11px;font-weight:800;letter-spacing:1.6px;color:var(--blue);text-transform:uppercase}
.head h1{margin:6px 0;font-size:32px;color:var(--text)}
.head p{margin:0;color:var(--muted);font-size:14px}
.actions{display:flex;gap:10px;flex-wrap:wrap}
.btn{padding:12px 16px;border-radius:11px;text-decoration:none;font-weight:750;font-size:13px;border:1px solid var(--line);background:#fff;color:var(--text)}
.btn.primary{background:var(--blue);border-color:var(--blue);color:#fff}
.kpis{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:20px}
.kpi,.panel{background:#fff;border:1px solid var(--line);border-radius:18px;box-shadow:0 8px 28px rgba(15,23,42,.05)}
.kpi{padding:20px;min-height:140px}
.kpi-icon{width:40px;height:40px;border-radius:11px;background:var(--blueSoft);display:grid;place-items:center}
.label{margin-top:14px;font-size:11px;font-weight:800;color:var(--muted);letter-spacing:.5px}
.value{font-size:29px;font-weight:850;color:var(--text);margin-top:2px}
.meta{font-size:11px;color:var(--muted);margin-top:3px}
.grid{display:grid;grid-template-columns:1.55fr .85fr;gap:20px;margin-bottom:20px}
.panel-head{padding:20px 21px 14px;display:flex;justify-content:space-between;gap:15px;align-items:center}
.title{font-size:17px;font-weight:850;color:var(--text)}
.sub{font-size:11px;color:var(--muted);margin-top:3px}
.link{font-size:12px;color:var(--blue);font-weight:800;text-decoration:none}
.body{padding:0 21px 20px}
.row{display:grid;grid-template-columns:75px 1fr 125px 100px;gap:12px;align-items:center;padding:14px 0;border-top:1px solid #eef2f6}
.row:first-child{border-top:0}
.time{font-size:12px;font-weight:850;color:var(--text)}
.name{font-size:13px;font-weight:800;color:var(--text)}
.detail{font-size:10px;color:var(--muted);margin-top:3px}
.clinic{font-size:11px;color:#52647d}
.badge{display:inline-flex;padding:6px 9px;border-radius:999px;font-size:10px;font-weight:850}
.done{background:var(--greenSoft);color:var(--green)}
.active{background:var(--blueSoft);color:var(--blue)}
.pending{background:var(--orangeSoft);color:var(--orange)}
.bad{background:var(--redSoft);color:var(--red)}
.tomorrow{display:flex;align-items:center;gap:12px;padding:13px 0;border-top:1px solid #eef2f6}
.tomorrow:first-child{border-top:0}
.tom-time{min-width:72px;font-size:11px;font-weight:850;color:var(--text)}
.tom-name{font-size:12px;font-weight:800;color:var(--text)}
.tom-detail{font-size:10px;color:var(--muted);margin-top:2px}
.quick{display:grid;grid-template-columns:repeat(2,1fr);gap:10px}
.quick a{display:flex;align-items:center;gap:10px;padding:12px;border:1px solid var(--line);border-radius:13px;text-decoration:none;color:var(--text)}
.qicon{width:34px;height:34px;border-radius:9px;background:var(--blueSoft);display:grid;place-items:center}
.qtext strong{display:block;font-size:12px}.qtext span{display:block;color:var(--muted);font-size:10px;margin-top:2px}
.record{display:grid;grid-template-columns:80px 1fr 100px 1fr;gap:12px;align-items:center;padding:13px 0;border-top:1px solid #eef2f6}
.record:first-child{border-top:0}
.empty{text-align:center;padding:28px;color:var(--muted);font-size:12px}
@media(max-width:1100px){.kpis{grid-template-columns:repeat(2,1fr)}.grid{grid-template-columns:1fr}}
@media(max-width:700px){.dashboard{padding:20px 14px}.head{align-items:flex-start;flex-direction:column}.row{grid-template-columns:65px 1fr}.clinic,.row .badge{grid-column:2}.record{grid-template-columns:1fr 1fr}.quick{grid-template-columns:1fr}}
@media(max-width:550px){.kpis{grid-template-columns:1fr}.head h1{font-size:27px}}
</style>
</head>
<body>
<div class="layout">
<?php include __DIR__ . '/sidebar.php'; ?>

<main class="content">
<div class="dashboard">

<header class="head">
<div>
<div class="kicker">AmarDayal Clinic • Staff Portal</div>
<h1>Hello! <?=e($staffName)?> 👋</h1>
<p><?=date('l, d F Y')?> • Today's clinic operations at a glance.</p>
</div>
<div class="actions">
<?php if(in_array($role,['Admin','Reception'],true)): ?><a class="btn primary" href="<?= e(rtrim(BASE_URL, '/') . '/appointment.php') ?>">＋ New Appointment</a><?php endif; ?>
<a class="btn" href="appointments.php">View Appointments →</a>
</div>
</header>

<section class="kpis">
<div class="kpi"><div class="kpi-icon">👥</div><div class="label">PATIENTS TODAY</div><div class="value"><?=$todayPatients?></div><div class="meta">Unique booked patients</div></div>
<div class="kpi"><div class="kpi-icon">📅</div><div class="label">APPOINTMENTS TODAY</div><div class="value"><?=$todayAppointments?></div><div class="meta"><?=$todayCompleted?> completed • <?=$todayWaiting?> active</div></div>
<div class="kpi"><div class="kpi-icon">📋</div><div class="label">TODAY'S RECORDS</div><div class="value"><?=$todayRecords?></div><div class="meta">Completed visits saved</div></div>
<div class="kpi"><div class="kpi-icon">₹</div><div class="label">TODAY'S REVENUE</div><div class="value">₹<?=number_format($todayRevenue,0)?></div><div class="meta"><?=$tomorrowAppointments?> appointments tomorrow</div></div>
</section>

<section class="grid">
<article class="panel">
<div class="panel-head"><div><div class="title">Today's Clinic</div><div class="sub"><?=ddate($today)?> • <?=$todayAppointments?> appointments</div></div><a class="link" href="appointments.php">View all →</a></div>
<div class="body">
<?php if(!$todayRows): ?><div class="empty">📅<br><br><strong>No appointments today</strong><br>New appointments will appear here automatically.</div>
<?php else: foreach($todayRows as $a): ?>
<div class="row">
<div class="time"><?=dtime($a['time']??'')?></div>
<div><div class="name"><?=e($a['name']?:'Patient')?></div><div class="detail"><?=e($a['phone']??'')?><?php if(!empty($a['service'])): ?> • <?=e($a['service'])?><?php endif; ?></div></div>
<div class="clinic"><?=e($a['clinic']??'Clinic')?></div>
<div><span class="badge <?=dstatus($a['status']??'Pending')?>"><?=e($a['status']??'Pending')?></span></div>
</div>
<?php endforeach; endif; ?>
</div>
</article>

<article class="panel">
<div class="panel-head"><div><div class="title">Tomorrow</div><div class="sub"><?=$tomorrowAppointments?> upcoming appointments</div></div><span class="badge active"><?=ddate($tomorrow)?></span></div>
<div class="body">
<?php if(!$tomorrowRows): ?><div class="empty">🌤️<br><br><strong>No appointments tomorrow</strong><br>The next day's schedule is clear.</div>
<?php else: foreach($tomorrowRows as $a): ?>
<div class="tomorrow"><div class="tom-time"><?=dtime($a['time']??'')?></div><div><div class="tom-name"><?=e($a['name']?:'Patient')?></div><div class="tom-detail"><?=e($a['clinic']??'Clinic')?><?php if(!empty($a['service'])): ?> • <?=e($a['service'])?><?php endif; ?></div></div></div>
<?php endforeach; endif; ?>
</div>
</article>
</section>

<section class="grid">
<article class="panel">
<div class="panel-head"><div><div class="title">Quick Actions</div><div class="sub">Common staff tasks</div></div></div>
<div class="body"><div class="quick">
<?php if(in_array($role,['Admin','Reception'],true)): ?>
<a href="reception.php"><div class="qicon">＋</div><div class="qtext"><strong>New Patient</strong><span>Register patient</span></div></a>
<?php endif; ?>
<?php if(in_array($role,['Admin','Doctor'],true)): ?>
<a href="doctor.php"><div class="qicon">🩺</div><div class="qtext"><strong>Consultation</strong><span>Open doctor workspace</span></div></a>
<?php endif; ?>
<a href="patients.php"><div class="qicon">👥</div><div class="qtext"><strong>Patient Records</strong><span>Search history</span></div></a>
<?php if(in_array($role,['Admin','Reception'],true)): ?><a href="billing.php"><div class="qicon">₹</div><div class="qtext"><strong>Billing</strong><span>Manage payments</span></div></a><?php endif; ?>
<?php if($role==='Admin'): ?><a href="reports.php"><div class="qicon">📊</div><div class="qtext"><strong>Reports</strong><span>Clinic analytics</span></div></a><?php endif; ?>
</div></div>
</article>

<article class="panel">
<div class="panel-head"><div><div class="title">Permanent Records</div><div class="sub">Recently completed patient visits</div></div><a class="link" href="patients.php">Patient directory →</a></div>
<div class="body">
<?php if(!$records): ?><div class="empty">📋<br><br><strong>No completed records yet</strong><br>Completed consultations will appear here.</div>
<?php else: foreach($records as $r): ?>
<div class="record">
<div class="detail"><?=ddate($r['visit_date']??$today)?></div>
<div><div class="name"><?=e($r['name'])?></div><div class="detail">Patient ID: <?=e($r['code']??$r['patient_id'])?></div></div>
<div><span class="badge done">Completed</span></div>
<div class="detail"><?=e($r['diagnosis']?:'Consultation completed')?></div>
</div>
<?php endforeach; endif; ?>
</div>
</article>
</section>

</div>
</main>
</div>
</body>
</html>
