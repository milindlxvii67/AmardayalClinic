<?php
require_once __DIR__ . '/../config.php';
if (session_status()===PHP_SESSION_NONE) session_start();
require_staff();
if (($_SESSION['staff_role']??'')!=='Admin'){http_response_code(403);exit('Access denied.');}
function re($v){return function_exists('e') ? e($v) : htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function scalar(mysqli $c,$sql,$types='',$p=[]){$s=$c->prepare($sql);if(!$s)return 0;if($types)$s->bind_param($types,...$p);if(!$s->execute()){$s->close();return 0;}$r=$s->get_result();$x=$r?$r->fetch_row():null;$s->close();return $x[0]??0;}
function rows(mysqli $c,$sql,$types='',$p=[]){$out=[];$s=$c->prepare($sql);if(!$s)return $out;if($types)$s->bind_param($types,...$p);if(!$s->execute()){$s->close();return $out;}$r=$s->get_result();if($r)while($x=$r->fetch_assoc())$out[]=$x;$s->close();return $out;}
$today=date('Y-m-d');$from=$_GET['from']??date('Y-m-01');$to=$_GET['to']??$today;
$fromDate=DateTime::createFromFormat('!Y-m-d',$from);
$toDate=DateTime::createFromFormat('!Y-m-d',$to);
if(!$fromDate || $fromDate->format('Y-m-d')!==$from)$from=date('Y-m-01');
if(!$toDate || $toDate->format('Y-m-d')!==$to)$to=$today;
if($from>$to)[$from,$to]=[$to,$from];
$patients=(int)scalar($conn,'SELECT COUNT(*) FROM patients');
$apps=(int)scalar($conn,'SELECT COUNT(*) FROM appointments WHERE date BETWEEN ? AND ?','ss',[$from,$to]);
$completed=(int)scalar($conn,"SELECT COUNT(*) FROM appointments WHERE date BETWEEN ? AND ? AND status='Completed'",'ss',[$from,$to]);
$visits=(int)scalar($conn,'SELECT COUNT(*) FROM visits WHERE visit_date BETWEEN ? AND ?','ss',[$from,$to]);
$completedVisits=(int)scalar($conn,"SELECT COUNT(*) FROM visits WHERE visit_date BETWEEN ? AND ? AND status='Completed'",'ss',[$from,$to]);
$revenue=(float)scalar($conn,'SELECT COALESCE(SUM(net_amount),0) FROM bills WHERE bill_date BETWEEN ? AND ?','ss',[$from,$to]);
$bills=(int)scalar($conn,'SELECT COUNT(*) FROM bills WHERE bill_date BETWEEN ? AND ?','ss',[$from,$to]);
$avg=$bills?$revenue/$bills:0;$rate=$apps?$completed/$apps*100:0;
$status=rows($conn,'SELECT status,COUNT(*) total FROM appointments WHERE date BETWEEN ? AND ? GROUP BY status ORDER BY total DESC','ss',[$from,$to]);
$daily=rows($conn,"SELECT date report_date,COUNT(*) appointments,SUM(CASE WHEN status='Completed' THEN 1 ELSE 0 END) completed FROM appointments WHERE date BETWEEN ? AND ? GROUP BY date ORDER BY date",'ss',[$from,$to]);
$rev=rows($conn,'SELECT bill_date report_date,COALESCE(SUM(net_amount),0) revenue FROM bills WHERE bill_date BETWEEN ? AND ? GROUP BY bill_date ORDER BY bill_date','ss',[$from,$to]);
$conditions=rows($conn,"SELECT COALESCE(NULLIF(TRIM(main_conditions),''),'General Consultation') condition_name,COUNT(*) total FROM visits WHERE visit_date BETWEEN ? AND ? GROUP BY condition_name ORDER BY total DESC LIMIT 10",'ss',[$from,$to]);
$clinics=rows($conn,"SELECT COALESCE(NULLIF(TRIM(clinic),''),'Not Specified') clinic_name,COUNT(*) total FROM appointments WHERE date BETWEEN ? AND ? GROUP BY clinic_name ORDER BY total DESC",'ss',[$from,$to]);
$payments=rows($conn,"SELECT COALESCE(NULLIF(TRIM(payment_mode),''),'Not Specified') payment_mode,COUNT(*) bills,COALESCE(SUM(net_amount),0) revenue FROM bills WHERE bill_date BETWEEN ? AND ? GROUP BY payment_mode ORDER BY revenue DESC",'ss',[$from,$to]);
$recent=rows($conn,'SELECT v.opd_serial,v.visit_date,v.diagnosis,v.status,p.code,p.name FROM visits v INNER JOIN patients p ON p.id=v.patient_id WHERE v.visit_date BETWEEN ? AND ? ORDER BY v.id DESC LIMIT 15','ss',[$from,$to]);
$appointmentDetails=rows($conn,"
    SELECT
        a.id,
        a.date,
        a.time,
        a.service,
        a.status,
        a.clinic,
        a.disease,
        a.problem,
        a.problem_duration,
        a.problem_duration_unit,
        a.patient_type,
        a.address AS appointment_address,
        a.district AS appointment_district,
        p.code AS patient_code,
        p.name AS patient_name,
        p.age,
        p.gender,
        p.phone AS patient_phone,
        p.address AS patient_address,
        p.district AS patient_district
    FROM appointments a
    INNER JOIN patients p ON p.id=a.patient_id
    WHERE a.date BETWEEN ? AND ?
    ORDER BY a.date ASC, a.time ASC, a.id ASC
",'ss',[$from,$to]);

$maxA=1;$maxR=1;$maxC=1;foreach($daily as $x)$maxA=max($maxA,(int)$x['appointments']);foreach($rev as $x)$maxR=max($maxR,(float)$x['revenue']);foreach($conditions as $x)$maxC=max($maxC,(int)$x['total']);
$seven=date('Y-m-d',strtotime('-6 days'));$month=date('Y-m-01');$monthEnd=date('Y-m-t');$lm=date('Y-m-01',strtotime('first day of last month'));$lmEnd=date('Y-m-t',strtotime('last day of last month'));
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Reports & Analytics | AmarDayal Clinic</title><link rel="stylesheet" href="<?= re(BASE_URL) ?>Styles/style.css">
<style>
:root{--bg:#f5f7fb;--card:#fff;--text:#172033;--muted:#64748b;--line:#e5e7eb;--blue:#2563eb;--green:#16a34a;--purple:#7c3aed;--shadow:0 14px 38px rgba(15,23,42,.07)}*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text);font-family:Inter,Segoe UI,Arial,sans-serif}.layout{min-height:100vh;display:flex}.content{
    position:relative;
    flex:1;
    min-width:0;
    padding:30px;
    margin-left:290px;
    width:calc(100% - 290px);
    max-width:none;
}.head{display:flex;justify-content:space-between;gap:20px;align-items:flex-start;margin-bottom:20px}.head h1{margin:0;font-size:30px}.head p{margin:7px 0;color:var(--muted)}.filter,.card,.kpi{background:#fff;border:1px solid var(--line);border-radius:18px;box-shadow:var(--shadow)}.filter{padding:17px;margin-bottom:20px}.form{display:flex;gap:12px;align-items:end;flex-wrap:wrap}.field{display:flex;flex-direction:column;gap:6px}.field label{font-size:10px;font-weight:900;color:var(--muted);text-transform:uppercase}.field input{padding:10px;border:1px solid #dbe1ea;border-radius:10px}.btn{display:inline-flex;border:0;border-radius:10px;padding:10px 13px;text-decoration:none;font-size:11px;font-weight:900;cursor:pointer}.primary{background:var(--blue);color:#fff}.light{background:#f1f5f9;color:#334155}.presets{display:flex;gap:7px;flex-wrap:wrap;margin-top:12px}.presets a{padding:7px 10px;border:1px solid var(--line);border-radius:9px;color:#475569;text-decoration:none;font-size:10px;font-weight:800}.kpis{display:grid;grid-template-columns:repeat(5,1fr);gap:14px;margin-bottom:20px}.kpi{padding:18px}.icon{width:38px;height:38px;border-radius:11px;display:grid;place-items:center;background:#eff6ff;color:var(--blue);font-weight:900;margin-bottom:12px}.kpi:nth-child(2) .icon{background:#ecfdf5;color:var(--green)}.kpi:nth-child(3) .icon{background:#fff7ed;color:#c2410c}.kpi:nth-child(4) .icon{background:#f5f3ff;color:var(--purple)}.kpi .label{font-size:10px;font-weight:900;color:var(--muted);text-transform:uppercase}.kpi .value{font-size:25px;font-weight:900;margin-top:6px}.kpi .meta{font-size:10px;color:#94a3b8;margin-top:4px}.grid{display:grid;grid-template-columns:1.45fr 1fr;gap:20px;margin-bottom:20px}.card{overflow:hidden}.card h2{font-size:16px;margin:0}.ch{padding:18px 20px;border-bottom:1px solid var(--line);display:flex;justify-content:space-between}.ch span{font-size:11px;color:var(--muted);font-weight:800}.body{padding:19px}.trend{margin-bottom:15px}.top{display:flex;justify-content:space-between;font-size:11px;margin-bottom:6px}.barbg,.mini{height:9px;background:#eef2f7;border-radius:999px;overflow:hidden}.bar{height:100%;background:var(--blue);border-radius:999px}.purple{background:var(--purple)}.status{margin-bottom:14px}.status .top{font-weight:800}.cond{display:grid;grid-template-columns:28px 1fr 40px;gap:9px;align-items:center;margin-bottom:12px}.rank{background:#eff6ff;color:var(--blue);border-radius:8px;width:27px;height:27px;display:grid;place-items:center;font-size:10px;font-weight:900}.condname{font-size:11px;font-weight:800;margin-bottom:5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.mini div{height:100%;background:var(--blue);border-radius:999px}.count{text-align:right;font-size:11px;font-weight:900}.branches{display:grid;grid-template-columns:1fr 1fr;gap:12px}.branch{border:1px solid var(--line);border-radius:13px;padding:14px}.branch b{font-size:12px}.branch strong{display:block;font-size:23px;margin-top:6px}.branch small{color:var(--muted)}table{width:100%;border-collapse:collapse}th,td{padding:11px 10px;border-bottom:1px solid #edf0f4;text-align:left;font-size:11px}th{font-size:9px;color:var(--muted);text-transform:uppercase}td:last-child,th:last-child{text-align:right}.wrap{overflow:auto}.badge{padding:6px 9px;border-radius:999px;background:#eff6ff;color:#1d4ed8;font-size:9px;font-weight:900}.empty{text-align:center;color:var(--muted);padding:28px;font-size:12px}@media(max-width:1250px){.kpis{grid-template-columns:repeat(3,1fr)}.grid{grid-template-columns:1fr}}
/* Shared admin sidebar spacing */
@media (max-width:1200px) and (min-width:701px){
    .content{
        margin-left:260px;
        width:calc(100% - 260px);
    }
}

@media(max-width:700px){.content{margin-left:0;width:100%;padding:17px}.head{flex-direction:column}.kpis{grid-template-columns:1fr 1fr}.branches{grid-template-columns:1fr}}@media(max-width:500px){.kpis{grid-template-columns:1fr}}

.appointment-register{margin-top:20px}
.appointment-register .register-toolbar{
    display:flex;justify-content:space-between;align-items:center;
    gap:14px;padding:14px 18px;border-bottom:1px solid var(--line)
}
.register-summary{font-size:11px;color:var(--muted)}
.register-table-wrap{overflow:auto}
.register-table{width:100%;border-collapse:collapse;min-width:1180px}
.register-table th,.register-table td{
    padding:10px 9px;border-bottom:1px solid #edf0f4;
    text-align:left;vertical-align:top
}
.register-table th{
    background:#f8fafc;color:var(--muted);font-size:9px;
    text-transform:uppercase;white-space:nowrap
}
.register-table td{font-size:10px}
.patient-cell strong{display:block;font-size:11px;color:#172033}
.patient-cell small{display:block;color:#64748b;margin-top:3px;font-size:9px}
.status-pill{
    display:inline-flex;padding:5px 8px;border-radius:999px;
    background:#eff6ff;color:#1d4ed8;font-size:9px;font-weight:900
}
.status-pill.completed{background:#ecfdf5;color:#047857}
.status-pill.cancelled{background:#fef2f2;color:#b91c1c}
.register-empty{text-align:center;color:var(--muted);padding:30px;font-size:12px}

@media print{
    /* Never print the shared admin navigation/sidebar */
    .admin-sidebar,
    .sidebar,
    aside.sidebar,
    nav.sidebar,
    .no-print,
    .filter{
        display:none !important;
        visibility:hidden !important;
    }

    html,body{
        margin:0 !important;
        padding:0 !important;
        width:100% !important;
        min-width:0 !important;
        background:#fff !important;
    }

    .layout{
        display:block !important;
        width:100% !important;
        min-height:0 !important;
        margin:0 !important;
        padding:0 !important;
    }

    .content{
        display:block !important;
        position:static !important;
        margin:0 !important;
        padding:0 !important;
        width:100% !important;
        max-width:none !important;
        min-width:0 !important;
    }

    .head{
        margin:0 0 12px !important;
    }

    .head h1{
        font-size:21px !important;
    }

    .head p{
        font-size:9px !important;
    }

    /* Dashboard cards/charts are not part of the appointment printout */
    .kpis,
    .grid{
        display:none !important;
    }

    .appointment-register{
        display:block !important;
        width:100% !important;
        margin:0 !important;
        border:1px solid #cbd5e1 !important;
        box-shadow:none !important;
    }

    .appointment-register .ch{
        padding:10px 12px !important;
    }

    .appointment-register .ch h2{
        font-size:15px !important;
    }

    .register-toolbar{
        display:none !important;
    }

    .register-table-wrap{
        overflow:visible !important;
    }

    .register-table{
        width:100% !important;
        min-width:0 !important;
        font-size:8px !important;
    }

    .register-table th,
    .register-table td{
        padding:5px !important;
        font-size:7.5px !important;
    }

    .register-table th{
        background:#f1f5f9 !important;
    }

    .status-pill,
    .register-status{
        background:none !important;
        padding:0 !important;
        color:#111827 !important;
    }

    .register-table tr{
        page-break-inside:avoid;
    }

    @page{
        size:A4 landscape;
        margin:8mm;
    }
}
</style></head><body><div class="layout"><?php include __DIR__.'/sidebar.php'; ?><main class="content">
<header class="head"><div><h1>Reports & Analytics</h1><p>Clinic performance, appointments, consultations and billing for the selected period.</p></div><button class="btn light no-print" onclick="window.print()">🖨 Print Report</button></header>
<section class="filter no-print"><form class="form" method="get"><div class="field"><label>From Date</label><input type="date" name="from" value="<?=re($from)?>"></div><div class="field"><label>To Date</label><input type="date" name="to" value="<?=re($to)?>"></div><button class="btn primary">Apply Report</button></form><div class="presets">
<a href="<?=re(BASE_URL)?>admin/reports.php?from=<?=re($today)?>&to=<?=re($today)?>">Today</a>
<a href="<?=re(BASE_URL)?>admin/reports.php?from=<?=re($seven)?>&to=<?=re($today)?>">Last 7 Days</a>
<a href="<?=re(BASE_URL)?>admin/reports.php?from=<?=re($month)?>&to=<?=re($monthEnd)?>">This Month</a>
<a href="<?=re(BASE_URL)?>admin/reports.php?from=<?=re($lm)?>&to=<?=re($lmEnd)?>">Last Month</a>
<a href="<?=re(BASE_URL)?>admin/reports.php?from=<?=re(date('Y-01-01'))?>&to=<?=re($today)?>">This Year</a>
</div></section>
<section class="kpis"><div class="kpi"><div class="icon">👥</div><div class="label">Total Patients</div><div class="value"><?=$patients?></div><div class="meta">Registered records</div></div><div class="kpi"><div class="icon">📅</div><div class="label">Appointments</div><div class="value"><?=$apps?></div><div class="meta">Selected period</div></div><div class="kpi"><div class="icon">✓</div><div class="label">Completed Visits</div><div class="value"><?=$completedVisits?></div><div class="meta"><?=number_format($rate,1)?>% appointment completion</div></div><div class="kpi"><div class="icon">₹</div><div class="label">Revenue</div><div class="value">₹<?=number_format($revenue,0)?></div><div class="meta"><?=$bills?> invoices</div></div><div class="kpi"><div class="icon">▣</div><div class="label">Average Bill</div><div class="value">₹<?=number_format($avg,0)?></div><div class="meta">Per invoice</div></div></section>
<section class="grid"><article class="card"><div class="ch"><h2>Appointment Trend</h2><span><?=re($from)?> — <?=re($to)?></span></div><div class="body"><?php if(!$daily):?><div class="empty">No appointment data for this period.</div><?php else:foreach($daily as $x):$w=(int)$x['appointments']/$maxA*100;?><div class="trend"><div class="top"><span><?=date('d M Y',strtotime($x['report_date']))?></span><b><?=$x['appointments']?> appointments • <?=$x['completed']?> completed</b></div><div class="barbg"><div class="bar" style="width:<?=re(number_format((float)$w,2,'.',''))?>%"></div></div></div><?php endforeach;endif;?></div></article>
<article class="card"><div class="ch"><h2>Appointment Status</h2><span><?=$apps?> total</span></div><div class="body"><?php if(!$status):?><div class="empty">No status data.</div><?php else:foreach($status as $x):$w=(int)$x['total']/max(1,$apps)*100;?><div class="status"><div class="top"><span><?=re($x['status']?:'Unknown')?></span><b><?=$x['total']?> (<?=number_format($w,1)?>%)</b></div><div class="barbg"><div class="bar" style="width:<?=re(number_format((float)$w,2,'.',''))?>%"></div></div></div><?php endforeach;endif;?></div></article></section>
<section class="grid"><article class="card"><div class="ch"><h2>Top Patient Conditions</h2><span>Top 10</span></div><div class="body"><?php if(!$conditions):?><div class="empty">No consultation condition data.</div><?php else:foreach($conditions as $i=>$x):$w=(int)$x['total']/$maxC*100;?><div class="cond"><div class="rank"><?=$i+1?></div><div><div class="condname"><?=re($x['condition_name'])?></div><div class="mini"><div style="width:<?=re(number_format((float)$w,2,'.',''))?>%"></div></div></div><div class="count"><?=$x['total']?></div></div><?php endforeach;endif;?></div></article>
<article class="card"><div class="ch"><h2>Clinic Branches</h2><span>Appointments</span></div><div class="body"><?php if(!$clinics):?><div class="empty">No branch data.</div><?php else:?><div class="branches"><?php foreach($clinics as $x):?><div class="branch"><b><?=re($x['clinic_name'])?></b><strong><?=$x['total']?></strong><small>appointments</small></div><?php endforeach;?></div><?php endif;?></div></article></section>
<section class="grid"><article class="card"><div class="ch"><h2>Revenue by Day</h2><span>Net invoice amount</span></div><div class="body"><?php if(!$rev):?><div class="empty">No billing data.</div><?php else:foreach($rev as $x):$w=(float)$x['revenue']/$maxR*100;?><div class="trend"><div class="top"><span><?=date('d M Y',strtotime($x['report_date']))?></span><b>₹<?=number_format($x['revenue'],0)?></b></div><div class="barbg"><div class="bar purple" style="width:<?=re(number_format((float)$w,2,'.',''))?>%"></div></div></div><?php endforeach;endif;?></div></article>
<article class="card"><div class="ch"><h2>Payment Modes</h2><span><?=$bills?> invoices</span></div><div class="body wrap"><?php if(!$payments):?><div class="empty">No payment data.</div><?php else:?><table><tr><th>Mode</th><th>Invoices</th><th>Revenue</th></tr><?php foreach($payments as $x):?><tr><td><span class="badge"><?=re($x['payment_mode'])?></span></td><td><?=$x['bills']?></td><td>₹<?=number_format($x['revenue'],0)?></td></tr><?php endforeach;?></table><?php endif;?></div></article></section>

<section class="card appointment-register">
<div class="ch">
    <h2>Appointment Register</h2>
    <span><?=re($from)?> — <?=re($to)?> • <?=count($appointmentDetails)?> appointments</span>
</div>

<div class="register-toolbar no-print">
    <div class="register-summary">
        All appointments with patient details for the selected date range.
    </div>
    <button type="button" class="btn primary" onclick="printAppointmentRegister()">
        🖨 Print Appointments
    </button>
</div>

<div class="body">
<?php if(!$appointmentDetails): ?>
    <div class="register-empty">No appointments found for the selected date range.</div>
<?php else: ?>
<div class="register-table-wrap">
<table class="register-table" id="appointmentRegister">
<thead>
<tr>
    <th>#</th>
    <th>Date</th>
    <th>Time</th>
    <th>Patient</th>
    <th>Patient ID</th>
    <th>Age / Gender</th>
    <th>Phone</th>
    <th>District</th>
    <th>Problem / Disease</th>
    <th>Duration</th>
    <th>Service</th>
    <th>Clinic</th>
    <th>Status</th>
</tr>
</thead>
<tbody>
<?php foreach($appointmentDetails as $i=>$a): ?>
<?php
    $st=trim((string)($a['status']??'Unknown'));
    $stClass=strtolower($st);
    if($stClass==='completed') $stClass='completed';
    elseif($stClass==='cancelled'||$stClass==='canceled') $stClass='cancelled';
    else $stClass='';
    $district=$a['patient_district'] ?: $a['appointment_district'];
    $address=$a['patient_address'] ?: $a['appointment_address'];
    $problem=$a['disease'] ?: $a['problem'];
    $duration=trim((string)($a['problem_duration']??''));
    $unit=trim((string)($a['problem_duration_unit']??''));
    $durationText=trim($duration.' '.$unit);
?>
<tr>
    <td><?=($i+1)?></td>
    <td><?=date('d M Y',strtotime($a['date']))?></td>
    <td><?=re($a['time']?:'—')?></td>
    <td class="patient-cell">
        <strong><?=re($a['patient_name'])?></strong>
        <?php if($address): ?><small><?=re($address)?></small><?php endif; ?>
    </td>
    <td>AD-<?=re($a['patient_code'])?></td>
    <td><?=re($a['age']?:'—')?> / <?=re($a['gender']?:'—')?></td>
    <td><?=re($a['patient_phone']?:'—')?></td>
    <td><?=re($district?:'—')?></td>
    <td><?=re($problem?:'General Consultation')?></td>
    <td><?=re($durationText?:'—')?></td>
    <td><?=re($a['service']?:'General Consultation')?></td>
    <td><?=re($a['clinic']?:'—')?></td>
    <td><span class="status-pill <?=$stClass?>"><?=re($st)?></span></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php endif; ?>
</div>
</section>


</main></div><script>
function printAppointmentRegister(){
    const table=document.getElementById('appointmentRegister');
    if(!table){
        alert('No appointments found for the selected date range.');
        return;
    }
    window.print();
}
</script>
</body></html>
