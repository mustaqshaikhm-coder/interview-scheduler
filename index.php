<?php
session_start();

// ═══════════════════════════════════════════════════════════
//  ★  ADMIN CONFIG — add any email here to grant admin access
//     These users get admin role automatically on login.
// ═══════════════════════════════════════════════════════════
$ADMIN_EMAILS = [
    'admin@yourdomain.com',   // ← replace with your own email(s)
];

// ─── DATABASE ────────────────────────────────────────────────────────────────
$dataDir = (is_dir('/var/data') && is_writable('/var/data')) ? '/var/data' : __DIR__;
$db = new SQLite3($dataDir . '/scheduler.db');
$db->exec("PRAGMA journal_mode=WAL");

$db->exec("CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL, email TEXT UNIQUE NOT NULL,
    password TEXT NOT NULL, role TEXT NOT NULL DEFAULT 'client',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");

$db->exec("CREATE TABLE IF NOT EXISTS bookings (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL, slot_date TEXT NOT NULL,
    slot_start TEXT NOT NULL, slot_end TEXT NOT NULL,
    status TEXT DEFAULT 'confirmed', notes TEXT,
    job_id INTEGER, cv_file TEXT, cv_original TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(user_id) REFERENCES users(id)
)");

$db->exec("CREATE TABLE IF NOT EXISTS messages (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    from_id INTEGER NOT NULL, to_id INTEGER NOT NULL,
    booking_id INTEGER, subject TEXT, body TEXT NOT NULL,
    read_at DATETIME, created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");

// Jobs table with migration
$jobsExists = $db->querySingle("SELECT name FROM sqlite_master WHERE type='table' AND name='jobs'");
if ($jobsExists) {
    $jobCols = [];
    $jcRes = $db->query("PRAGMA table_info(jobs)");
    while ($r = $jcRes->fetchArray(SQLITE3_ASSOC)) $jobCols[] = $r['name'];
    if (!in_array('company',$jobCols)||!in_array('requirements',$jobCols)||!in_array('active',$jobCols)) {
        $db->exec("DROP TABLE jobs"); $jobsExists = false;
    }
}
if (!$jobsExists) {
    $db->exec("CREATE TABLE jobs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        title TEXT NOT NULL, company TEXT NOT NULL,
        location TEXT, type TEXT DEFAULT 'Full-time',
        description TEXT, requirements TEXT,
        active INTEGER DEFAULT 1, created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
}

// Migrate bookings columns
$bCols = [];
$bcRes = $db->query("PRAGMA table_info(bookings)");
while ($r = $bcRes->fetchArray(SQLITE3_ASSOC)) $bCols[] = $r['name'];
if (!in_array('job_id',      $bCols)) $db->exec("ALTER TABLE bookings ADD COLUMN job_id INTEGER");
if (!in_array('cv_file',     $bCols)) $db->exec("ALTER TABLE bookings ADD COLUMN cv_file TEXT");
if (!in_array('cv_original', $bCols)) $db->exec("ALTER TABLE bookings ADD COLUMN cv_original TEXT");

// Auto-promote emails in $ADMIN_EMAILS
foreach ($ADMIN_EMAILS as $ae) {
    $ae = trim($ae);
    if (!$ae) continue;
    $db->exec("UPDATE users SET role='admin' WHERE email='".SQLite3::escapeString($ae)."' AND role!='admin'");
}

// Seed sample jobs once
if ($db->querySingle("SELECT COUNT(*) FROM jobs") == 0) {
    $seeds = [
        ['Senior PHP Developer','TechCorp','Remote','Full-time','Build and maintain high-traffic web applications using modern PHP practices, Laravel and RESTful APIs.','PHP 8+, Laravel, MySQL, REST APIs, 3+ years experience'],
        ['React Frontend Engineer','DesignHub','New York, NY','Full-time','Create polished, performant UI experiences for our SaaS platform used by 200k+ users.','React, TypeScript, Tailwind CSS, Figma, 2+ years'],
        ['Product Manager','StartupX','San Francisco, CA','Full-time','Define the product roadmap, run discovery sprints, and collaborate closely with engineering and design.','3+ yrs PM, B2B SaaS experience, strong analytical skills'],
        ['Data Analyst','DataFlow Inc','Remote','Contract','Analyse business KPIs, build dashboards, and deliver insights to stakeholders across the company.','SQL, Python, Tableau or Looker, statistics fundamentals'],
        ['DevOps / Cloud Engineer','CloudSys','Austin, TX','Full-time','Own our CI/CD pipelines, manage AWS infrastructure, and drive reliability improvements.','AWS, Docker, Kubernetes, Terraform, Linux'],
        ['UX / UI Designer','CreativeAgency','London, UK','Full-time','Conduct user research, create wireframes, and deliver pixel-perfect designs for mobile and web.','Figma, user research, mobile-first design, portfolio required'],
    ];
    foreach ($seeds as $j) {
        $s = $db->prepare("INSERT INTO jobs (title,company,location,type,description,requirements) VALUES (?,?,?,?,?,?)");
        foreach ($j as $i => $v) $s->bindValue($i+1,$v);
        $s->execute();
    }
}

$uploadDir = ((is_dir('/var/data') && is_writable('/var/data')) ? '/var/data' : __DIR__) . '/uploads/cv/';
if (!is_dir($uploadDir)) @mkdir($uploadDir, 0755, true);

// ─── HELPERS ─────────────────────────────────────────────────────────────────
function redirect($url)  { header("Location: $url"); exit; }
function isLoggedIn()    { return isset($_SESSION['user_id']); }
function isAdmin()       { return isset($_SESSION['role']) && $_SESSION['role'] === 'admin'; }
function h($s)           { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }

function getWeekDays() {
    $days=[]; $now=new DateTime(); $dow=(int)$now->format('N');
    $mon=clone $now; $mon->modify('-'.($dow-1).' days');
    for($i=0;$i<6;$i++){$d=clone $mon;$d->modify("+$i days");$days[]=$d->format('Y-m-d');}
    return $days;
}
function getSlots() {
    $slots=[];$s=strtotime('10:00');$e=strtotime('17:00');$dur=45*60;
    while($s+$dur<=$e){$slots[]=['start'=>date('H:i',$s),'end'=>date('H:i',$s+$dur)];$s+=$dur;}
    return $slots;
}
function getBooking($db,$date,$start) {
    $s=$db->prepare("SELECT b.*,u.name,u.email FROM bookings b JOIN users u ON b.user_id=u.id WHERE b.slot_date=:d AND b.slot_start=:s LIMIT 1");
    if(!$s)return false; $s->bindValue(':d',$date);$s->bindValue(':s',$start);
    return $s->execute()->fetchArray(SQLITE3_ASSOC);
}
function getAllUserBookings($db,$uid) {
    $s=$db->prepare("SELECT b.*,j.title AS job_title,j.company FROM bookings b LEFT JOIN jobs j ON b.job_id=j.id WHERE b.user_id=:u ORDER BY b.slot_date DESC,b.slot_start DESC");
    if(!$s)return[]; $s->bindValue(':u',$uid);
    $r=$s->execute();$rows=[];while($row=$r->fetchArray(SQLITE3_ASSOC))$rows[]=$row;return $rows;
}
function getAllBookings($db) {
    $res=$db->query("SELECT b.*,u.name,u.email,j.title AS job_title,j.company FROM bookings b JOIN users u ON b.user_id=u.id LEFT JOIN jobs j ON b.job_id=j.id ORDER BY b.slot_date,b.slot_start");
    if(!$res)return[];$rows=[];while($row=$res->fetchArray(SQLITE3_ASSOC))$rows[]=$row;return $rows;
}
function getAllClients($db) {
    $res=$db->query("SELECT * FROM users WHERE role='client' ORDER BY created_at DESC");
    $rows=[];while($row=$res->fetchArray(SQLITE3_ASSOC))$rows[]=$row;return $rows;
}
function getAllUsers($db) {
    $res=$db->query("SELECT id,name,email,role,created_at FROM users ORDER BY role DESC,created_at DESC");
    $rows=[];while($row=$res->fetchArray(SQLITE3_ASSOC))$rows[]=$row;return $rows;
}
function getAllJobs($db,$activeOnly=true) {
    $w=$activeOnly?"WHERE active=1":"";
    $res=$db->query("SELECT * FROM jobs $w ORDER BY created_at DESC");
    $rows=[];while($row=$res->fetchArray(SQLITE3_ASSOC))$rows[]=$row;return $rows;
}
function getJob($db,$id) {
    $s=$db->prepare("SELECT * FROM jobs WHERE id=:id");
    if(!$s)return false; $s->bindValue(':id',$id);
    return $s->execute()->fetchArray(SQLITE3_ASSOC);
}
function hasUserBookedJob($db,$uid,$job_id) {
    if(!$job_id)return false;
    $s=$db->prepare("SELECT id FROM bookings WHERE user_id=:u AND job_id=:j LIMIT 1");
    if(!$s)return false; $s->bindValue(':u',$uid);$s->bindValue(':j',$job_id);
    return (bool)$s->execute()->fetchArray(SQLITE3_ASSOC);
}
function getMessages($db,$uid,$role) {
    if($role==='admin'){
        $res=$db->query("SELECT m.*,u.name AS from_name FROM messages m JOIN users u ON m.from_id=u.id ORDER BY m.created_at DESC");
    } else {
        $s=$db->prepare("SELECT m.*,u.name AS from_name FROM messages m JOIN users u ON m.from_id=u.id WHERE m.to_id=:u ORDER BY m.created_at DESC");
        if(!$s)return[]; $s->bindValue(':u',$uid); $res=$s->execute();
    }
    $rows=[];while($row=$res->fetchArray(SQLITE3_ASSOC))$rows[]=$row;return $rows;
}
function countUnread($db,$uid) {
    $s=$db->prepare("SELECT COUNT(*) FROM messages WHERE to_id=:u AND read_at IS NULL");
    if(!$s)return 0; $s->bindValue(':u',$uid);
    return (int)$s->execute()->fetchArray()[0];
}
function statusSteps() {
    return [
        'confirmed'   =>['label'=>'Booked',      'icon'=>'📅','step'=>1],
        'cv_uploaded' =>['label'=>'CV Submitted', 'icon'=>'📄','step'=>2],
        'reviewed'    =>['label'=>'Under Review', 'icon'=>'🔍','step'=>3],
        'interviewed' =>['label'=>'Interviewed',  'icon'=>'🎤','step'=>4],
        'offered'     =>['label'=>'Offer Made',   'icon'=>'🎉','step'=>5],
        'rejected'    =>['label'=>'Not Selected', 'icon'=>'❌','step'=>5],
    ];
}

// ─── ACTIONS ─────────────────────────────────────────────────────────────────
$action=''; $message=''; $msgType='';
$action=$_GET['action']??'login';

// LOGIN
if($_SERVER['REQUEST_METHOD']==='POST'&&$action==='login'){
    $email=trim($_POST['email']??'');$pass=$_POST['password']??'';
    $stmt=$db->prepare("SELECT * FROM users WHERE email=:e");$stmt->bindValue(':e',$email);
    $res=$stmt->execute()->fetchArray(SQLITE3_ASSOC);
    if($res&&password_verify($pass,$res['password'])){
        global $ADMIN_EMAILS;
        if(in_array(strtolower($res['email']),array_map('strtolower',$ADMIN_EMAILS))&&$res['role']!=='admin'){
            $db->exec("UPDATE users SET role='admin' WHERE id=".(int)$res['id']);$res['role']='admin';
        }
        $_SESSION['user_id']=$res['id'];$_SESSION['name']=$res['name'];
        $_SESSION['email']=$res['email'];$_SESSION['role']=$res['role'];
        redirect('?action='.($res['role']==='admin'?'admin':'dashboard'));
    }else{$message='Invalid email or password.';$msgType='error';}
}

// REGISTER
if($_SERVER['REQUEST_METHOD']==='POST'&&$action==='register'){
    $name=trim($_POST['name']??'');$email=trim($_POST['email']??'');
    $pass=$_POST['password']??'';$pass2=$_POST['password2']??'';
    if(!$name||!$email||!$pass){$message='All fields required.';$msgType='error';}
    elseif($pass!==$pass2){$message='Passwords do not match.';$msgType='error';}
    elseif(strlen($pass)<6){$message='Password must be 6+ characters.';$msgType='error';}
    else{
        $chk=$db->prepare("SELECT id FROM users WHERE email=:e");$chk->bindValue(':e',$email);
        if($chk->execute()->fetchArray()){$message='Email already registered.';$msgType='error';}
        else{
            global $ADMIN_EMAILS;
            $role=in_array(strtolower($email),array_map('strtolower',$ADMIN_EMAILS))?'admin':'client';
            $hash=password_hash($pass,PASSWORD_DEFAULT);
            $s=$db->prepare("INSERT INTO users (name,email,password,role) VALUES (:n,:e,:p,:r)");
            $s->bindValue(':n',$name);$s->bindValue(':e',$email);$s->bindValue(':p',$hash);$s->bindValue(':r',$role);
            $s->execute();$id=$db->lastInsertRowID();
            $_SESSION['user_id']=$id;$_SESSION['name']=$name;$_SESSION['email']=$email;$_SESSION['role']=$role;
            redirect('?action='.($role==='admin'?'admin':'dashboard'));
        }
    }
}

// GRANT/REVOKE ADMIN
if($action==='grant_admin'&&isAdmin()){
    $uid=(int)($_GET['uid']??0);$op=$_GET['op']??'grant';
    if($uid&&$uid!==$_SESSION['user_id']){
        $db->exec("UPDATE users SET role='".($op==='grant'?'admin':'client')."' WHERE id=$uid");
    }
    redirect('?action=admin&view=access&msg=access_updated');
}

// BOOK SLOT
if($_SERVER['REQUEST_METHOD']==='POST'&&$action==='book'&&isLoggedIn()&&!isAdmin()){
    $date=trim($_POST['date']??'');$start=trim($_POST['start']??'');$end=trim($_POST['end']??'');
    $notes=trim($_POST['notes']??'');$job_id=(int)($_POST['job_id']??0);
    $vs=getSlots();$vd=getWeekDays();$sv=false;
    foreach($vs as $sl){if($sl['start']===$start&&$sl['end']===$end){$sv=true;break;}}
    if(!in_array($date,$vd)||!$sv){$message='Invalid slot.';$msgType='error';}
    else{
        $taken=getBooking($db,$date,$start);
        $dup=$job_id?hasUserBookedJob($db,$_SESSION['user_id'],$job_id):false;
        if($taken){$message='Slot already booked. Choose another.';$msgType='error';}
        elseif($dup){$message='You already applied for this position.';$msgType='error';}
        else{
            $s=$db->prepare("INSERT INTO bookings (user_id,slot_date,slot_start,slot_end,status,notes,job_id) VALUES (:u,:d,:s,:e,'confirmed',:n,:j)");
            $s->bindValue(':u',$_SESSION['user_id']);$s->bindValue(':d',$date);$s->bindValue(':s',$start);
            $s->bindValue(':e',$end);$s->bindValue(':n',$notes);
            if($job_id)$s->bindValue(':j',$job_id);else $s->bindValue(':j',null,SQLITE3_NULL);
            $s->execute();redirect('?action=upload_cv&bid='.$db->lastInsertRowID().'&new=1');
        }
    }
    $action='dashboard';
}

// UPLOAD CV
if($_SERVER['REQUEST_METHOD']==='POST'&&$action==='upload_cv'&&isLoggedIn()&&!isAdmin()){
    $bid=(int)($_POST['booking_id']??0);
    $s=$db->prepare("SELECT * FROM bookings WHERE id=:id AND user_id=:u");
    $s->bindValue(':id',$bid);$s->bindValue(':u',$_SESSION['user_id']);
    $bk=$s->execute()->fetchArray(SQLITE3_ASSOC);
    if(!$bk){$message='Booking not found.';$msgType='error';}
    elseif(!isset($_FILES['cv'])||$_FILES['cv']['error']!==UPLOAD_ERR_OK){$message='Please select a file.';$msgType='error';}
    else{
        $file=$_FILES['cv'];$ext=strtolower(pathinfo($file['name'],PATHINFO_EXTENSION));
        if(!in_array($ext,['pdf','doc','docx'])){$message='Only PDF, DOC, DOCX allowed.';$msgType='error';}
        elseif($file['size']>5*1024*1024){$message='File must be under 5MB.';$msgType='error';}
        else{
            $fn='cv_'.$_SESSION['user_id'].'_'.$bid.'_'.time().'.'.$ext;
            if(move_uploaded_file($file['tmp_name'],$uploadDir.$fn)){
                $s2=$db->prepare("UPDATE bookings SET cv_file=:f,cv_original=:o,status='cv_uploaded' WHERE id=:id");
                $s2->bindValue(':f',$fn);$s2->bindValue(':o',h($file['name']));$s2->bindValue(':id',$bid);$s2->execute();
                redirect('?action=jobs&msg=cv_uploaded');
            }else{$message='Upload failed.';$msgType='error';}
        }
    }
}

// SEND MESSAGE (admin)
if($_SERVER['REQUEST_METHOD']==='POST'&&$action==='send_message'&&isAdmin()){
    $to=(int)($_POST['to_id']??0);$sub=trim($_POST['subject']??'');
    $body=trim($_POST['body']??'');$bid=(int)($_POST['booking_id']??0);
    if($to&&$body){
        $s=$db->prepare("INSERT INTO messages (from_id,to_id,booking_id,subject,body) VALUES (:f,:t,:b,:s,:body)");
        $s->bindValue(':f',$_SESSION['user_id']);$s->bindValue(':t',$to);
        $s->bindValue(':b',$bid?$bid:null,SQLITE3_NULL);
        $s->bindValue(':s',$sub?$sub:'Message from Interviewer');$s->bindValue(':body',$body);$s->execute();
    }
    redirect('?action=admin&view=bookings&msg=msg_sent');
}

// READ MESSAGES (client)
if($action==='read_messages'&&isLoggedIn()&&!isAdmin()){
    $db->exec("UPDATE messages SET read_at=CURRENT_TIMESTAMP WHERE to_id=".(int)$_SESSION['user_id']." AND read_at IS NULL");
    $action='messages';
}

// CANCEL BOOKING (client)
if($action==='cancel'&&isLoggedIn()&&!isAdmin()){
    $bid=(int)($_GET['id']??0);
    $s=$db->prepare("DELETE FROM bookings WHERE id=:id AND user_id=:u");
    $s->bindValue(':id',$bid);$s->bindValue(':u',$_SESSION['user_id']);$s->execute();
    redirect('?action=my_bookings&msg=cancelled');
}

// ADMIN CANCEL
if($action==='admin_cancel'&&isAdmin()){
    $bid=(int)($_GET['id']??0);$view=$_GET['view']??'bookings';$day=$_GET['day']??'';
    $db->exec("DELETE FROM bookings WHERE id=$bid");
    redirect("?action=admin&view=$view".($day?"&day=$day":"")."&msg=cancelled");
}

// ADMIN STATUS UPDATE
if($action==='admin_status'&&isAdmin()&&$_SERVER['REQUEST_METHOD']==='POST'){
    $bid=(int)($_POST['bid']??0);$status=$_POST['status']??'';$allowed=array_keys(statusSteps());
    if($bid&&in_array($status,$allowed)){
        $s=$db->prepare("UPDATE bookings SET status=:s WHERE id=:id");
        $s->bindValue(':s',$status);$s->bindValue(':id',$bid);$s->execute();
    }
    redirect('?action=admin&view=bookings&msg=updated');
}

// POST JOB
if($action==='post_job'&&isAdmin()&&$_SERVER['REQUEST_METHOD']==='POST'){
    $t=trim($_POST['title']??'');$c=trim($_POST['company']??'');$l=trim($_POST['location']??'');
    $ty=$_POST['type']??'Full-time';$d=trim($_POST['description']??'');$r=trim($_POST['requirements']??'');
    if(!$t||!$c){$message='Title and company required.';$msgType='error';$action='admin';$_GET['view']='jobs';}
    else{
        $s=$db->prepare("INSERT INTO jobs (title,company,location,type,description,requirements) VALUES (:t,:c,:l,:ty,:d,:r)");
        $s->bindValue(':t',$t);$s->bindValue(':c',$c);$s->bindValue(':l',$l);
        $s->bindValue(':ty',$ty);$s->bindValue(':d',$d);$s->bindValue(':r',$r);$s->execute();
        redirect('?action=admin&view=jobs&msg=job_posted');
    }
}

// ARCHIVE JOB
if($action==='delete_job'&&isAdmin()){
    $jid=(int)($_GET['id']??0);if($jid)$db->exec("UPDATE jobs SET active=0 WHERE id=$jid");
    redirect('?action=admin&view=jobs&msg=job_deleted');
}

// LOGOUT
if($action==='logout'){session_destroy();redirect('?action=login');}

// Auth guards
if(!in_array($action,['login','register'])&&!isLoggedIn()) redirect('?action=login');
if(in_array($action,['login','register'])&&isLoggedIn()) redirect('?action='.(isAdmin()?'admin':'dashboard'));

$weekDays=getWeekDays();$slots=getSlots();$urlMsg=$_GET['msg']??'';
$unread=isLoggedIn()&&!isAdmin()?countUnread($db,$_SESSION['user_id']):0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0,maximum-scale=1.0">
<title>Interview Scheduler</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:ital,opsz,wght@0,14..32,400;0,14..32,500;0,14..32,600;0,14..32,700;0,14..32,800;1,14..32,400&family=Syne:wght@700;800&display=swap" rel="stylesheet">
<style>
:root{
  --bg:#f0f4f8;--surface:#fff;--card:#fff;--border:#dde3ed;--border2:#c8d0de;
  --accent:#2563eb;--accentH:#1d4ed8;--accent2:#059669;
  --danger:#dc2626;--warn:#d97706;--purple:#7c3aed;
  --text:#0f172a;--text2:#334155;--muted:#64748b;--muted2:#94a3b8;
  --r:12px;
  --ss:0 1px 3px rgba(0,0,0,.08),0 1px 2px rgba(0,0,0,.05);
  --sm:0 4px 16px rgba(0,0,0,.07),0 2px 6px rgba(0,0,0,.04);
  --lg:0 12px 40px rgba(0,0,0,.11);
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
html{font-size:16px;-webkit-font-smoothing:antialiased;-moz-osx-font-smoothing:grayscale;}
body{background:var(--bg);color:var(--text);font-family:'Inter',sans-serif;min-height:100vh;}
h1,h2,h3{font-family:'Syne',sans-serif;}
a{color:var(--accent);text-decoration:none;}

/* NAV */
.nav{position:sticky;top:0;z-index:200;display:flex;align-items:center;justify-content:space-between;
  padding:0 24px;height:58px;background:#fff;border-bottom:1.5px solid var(--border);box-shadow:var(--ss);}
.nav-brand{font-family:'Syne',sans-serif;font-size:.85rem;font-weight:800;color:var(--accent);letter-spacing:-.3px;}
.nav-brand span{color:var(--accent2);}
.nav-right{display:flex;align-items:center;gap:6px;}
.nav-links{display:flex;gap:2px;}
.nav-link{padding:6px 11px;border-radius:7px;font-size:.81rem;font-weight:600;color:var(--muted);transition:all .15s;text-decoration:none;display:inline-flex;align-items:center;gap:4px;}
.nav-link:hover{background:#f1f5f9;color:var(--text);}
.nav-link.active{background:#eff6ff;color:var(--accent);}
.nav-sep{width:1px;height:22px;background:var(--border);margin:0 4px;}
.nav-user{font-size:.79rem;color:var(--muted);text-align:right;line-height:1.3;}
.nav-user strong{color:var(--text);font-size:.81rem;display:block;font-weight:700;}
.badge-red{display:inline-flex;align-items:center;justify-content:center;min-width:17px;height:17px;
  background:var(--danger);color:#fff;font-size:.62rem;font-weight:800;border-radius:20px;padding:0 4px;margin-left:3px;}
.nav-back-btn{width:32px;height:32px;border-radius:8px;border:1.5px solid var(--border);background:#f1f5f9;
  color:var(--text2);font-size:1rem;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;
  transition:all .15s;flex-shrink:0;line-height:1;}
.nav-back-btn:hover{background:#e2e8f0;border-color:var(--border2);color:var(--accent);}

/* ── BOTTOM NAV BAR ── */
.bnav{position:fixed;bottom:0;left:0;right:0;z-index:999;
  display:flex;align-items:center;height:64px;
  background:#fff;border-top:2px solid var(--border);
  box-shadow:0 -2px 16px rgba(0,0,0,.08);}
.bnav a,.bnav button{
  flex:1;height:100%;display:flex;flex-direction:column;
  align-items:center;justify-content:center;gap:3px;
  font-size:.62rem;font-weight:700;letter-spacing:.3px;text-transform:uppercase;
  color:#94a3b8;text-decoration:none;background:none;border:none;
  font-family:'Inter',sans-serif;cursor:pointer;
  -webkit-tap-highlight-color:transparent;transition:color .15s;}
.bnav a:active,.bnav button:active{opacity:.7;}
.bnav a.on,.bnav button.on{color:var(--accent);}
.bnav a.out{color:#94a3b8;}
.bnav a.out:hover{color:var(--danger);}
.bnav-icon{font-size:1.4rem;line-height:1;position:relative;}
.bnav-icon .bdot{position:absolute;top:-3px;right:-8px;
  min-width:17px;height:17px;background:var(--danger);color:#fff;
  font-size:.58rem;font-weight:800;border-radius:20px;
  display:flex;align-items:center;justify-content:center;padding:0 3px;}

/* ── SLIDE UP SHEET ── */
.sov{display:none;position:fixed;inset:0;background:rgba(15,23,42,.4);z-index:1000;}
.sov.on{display:block;}
.sht{position:fixed;bottom:64px;left:0;right:0;z-index:1001;
  background:#fff;border-radius:22px 22px 0 0;
  box-shadow:0 -6px 30px rgba(0,0,0,.13);
  transform:translateY(110%);
  transition:transform .26s cubic-bezier(.32,1,.32,1);}
.sht.on{transform:translateY(0);}
.sht-bar{width:38px;height:4px;background:#cbd5e1;border-radius:2px;margin:14px auto 8px;}
.sht-ttl{font-size:.68rem;font-weight:800;color:#94a3b8;text-transform:uppercase;
  letter-spacing:.7px;padding:0 20px 10px;}
.sht a{display:flex;align-items:center;gap:14px;padding:16px 22px;
  font-size:.97rem;font-weight:600;color:var(--text2);text-decoration:none;
  border-top:1.5px solid var(--border);-webkit-tap-highlight-color:transparent;}
.sht a:active{background:#f8fafc;}
.sht a.on{color:var(--accent);background:#eff6ff;}
.sht-ico{font-size:1.25rem;width:30px;text-align:center;}
.sht-lbl{flex:1;}
.sht-arr{color:#94a3b8;font-size:.9rem;}
.sht-pad{height:16px;}

/* BUTTONS */
.btn-sm{padding:6px 13px;border-radius:7px;font-size:.79rem;font-weight:600;cursor:pointer;border:none;
  text-decoration:none;display:inline-flex;align-items:center;gap:5px;transition:all .15s;white-space:nowrap;line-height:1;}
.btn-sm.ghost{background:#f1f5f9;color:var(--text2);border:1.5px solid var(--border);}
.btn-sm.ghost:hover{background:#e2e8f0;}
.btn-sm.primary{background:var(--accent);color:#fff;}
.btn-sm.primary:hover{background:var(--accentH);}
.btn-sm.success{background:#ecfdf5;color:var(--accent2);border:1.5px solid #bbf7d0;}
.btn-sm.success:hover{background:#d1fae5;}
.btn-sm.danger{background:#fef2f2;color:var(--danger);border:1.5px solid #fecaca;}
.btn-sm.danger:hover{background:#fee2e2;}
.btn-sm.purple{background:#f5f3ff;color:var(--purple);border:1.5px solid #ddd6fe;}
.btn-sm.purple:hover{background:#ede9fe;}
.btn{width:100%;padding:12px;background:var(--accent);color:#fff;border:none;border-radius:var(--r);
  font-family:'Inter',sans-serif;font-size:.93rem;font-weight:700;cursor:pointer;
  transition:all .15s;text-decoration:none;display:block;text-align:center;}
.btn:hover{background:var(--accentH);box-shadow:0 4px 14px rgba(37,99,235,.28);transform:translateY(-1px);}
.btn.secondary{background:#fff;border:1.5px solid var(--accent);color:var(--accent);}
.btn.secondary:hover{background:#eff6ff;}
.btn.green{background:var(--accent2);color:#fff;}
.btn.green:hover{background:#047857;box-shadow:0 4px 14px rgba(5,150,105,.28);}
.btn.sm{padding:9px 14px;font-size:.84rem;}
.btn.inline{width:auto;display:inline-block;}

/* LAYOUT */
.container{max-width:500px;margin:0 auto;padding:28px 16px 90px;}
.container.wide{max-width:740px;padding-bottom:90px;}
.container.full{max-width:1000px;padding-bottom:90px;}

/* CARDS */
.card{background:#fff;border:1.5px solid var(--border);border-radius:var(--r);padding:22px;box-shadow:var(--ss);}
.card+.card{margin-top:13px;}

/* ALERTS */
.alert{padding:11px 15px;border-radius:9px;font-size:.87rem;margin-bottom:16px;font-weight:500;
  display:flex;align-items:center;gap:9px;border:1.5px solid;}
.alert.success{background:#f0fdf4;color:#15803d;border-color:#bbf7d0;}
.alert.error  {background:#fef2f2;color:var(--danger);border-color:#fecaca;}
.alert.info   {background:#eff6ff;color:var(--accent);border-color:#bfdbfe;}

/* FORMS */
.form-group{margin-bottom:14px;}
label{display:block;font-size:.76rem;color:var(--muted);margin-bottom:5px;font-weight:700;letter-spacing:.35px;text-transform:uppercase;}
input[type=text],input[type=email],input[type=password],textarea,select{
  width:100%;padding:10px 12px;background:#fff;border:1.5px solid var(--border);
  border-radius:9px;color:var(--text);font-family:'Inter',sans-serif;font-size:.92rem;
  outline:none;transition:border-color .15s,box-shadow .15s;font-weight:400;}
input:focus,textarea:focus,select:focus{border-color:var(--accent);box-shadow:0 0 0 3px rgba(37,99,235,.1);}
textarea{resize:vertical;min-height:78px;}
.row-2{display:grid;grid-template-columns:1fr 1fr;gap:12px;}

/* LOGIN */
.login-wrap{min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px;
  background:linear-gradient(135deg,#eff6ff 0%,#f0fdf4 55%,#fefce8 100%);}
.login-box{width:100%;max-width:420px;}
.login-logo{text-align:center;margin-bottom:30px;}
.login-logo h1{font-size:2.1rem;font-weight:800;color:var(--accent);}
.login-logo h1 span{color:var(--accent2);}
.login-logo p{color:var(--muted);font-size:.9rem;margin-top:5px;font-weight:500;}
.tab-bar{display:flex;background:#f1f5f9;border-radius:9px;padding:4px;margin-bottom:20px;}
.tab{flex:1;text-align:center;padding:8px;border-radius:7px;font-size:.87rem;font-weight:600;
  color:var(--muted);transition:all .15s;text-decoration:none;}
.tab.active{background:#fff;color:var(--accent);box-shadow:var(--ss);}

/* SECTION TITLES */
.section-title{font-family:'Syne',sans-serif;font-size:1.08rem;font-weight:800;margin-bottom:13px;
  display:flex;align-items:center;gap:7px;color:var(--text);}
.section-title .dot{width:7px;height:7px;border-radius:50%;background:var(--accent2);flex-shrink:0;}

/* STATS */
.stats-row{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:20px;}
.stat-card{background:#fff;border:1.5px solid var(--border);border-radius:var(--r);padding:15px;text-align:center;box-shadow:var(--ss);}
.stat-num{font-family:'Syne',sans-serif;font-size:1.9rem;font-weight:800;color:var(--accent);line-height:1;}
.stat-label{font-size:.68rem;color:var(--muted);margin-top:4px;text-transform:uppercase;letter-spacing:.5px;font-weight:700;}

/* ADMIN TABS */
.admin-tabs{display:flex;border-bottom:2px solid var(--border);margin-bottom:22px;overflow-x:auto;gap:0;}
.admin-tab{padding:10px 16px;font-size:.84rem;font-weight:600;color:var(--muted);text-decoration:none;
  border-bottom:2px solid transparent;margin-bottom:-2px;white-space:nowrap;transition:all .15s;}
.admin-tab:hover{color:var(--text2);}
.admin-tab.active{color:var(--accent);border-bottom-color:var(--accent);}

/* WEEK NAV */
.week-nav{display:flex;gap:6px;overflow-x:auto;padding-bottom:6px;margin-bottom:13px;scrollbar-width:none;}
.week-nav::-webkit-scrollbar{display:none;}
.day-tab{flex-shrink:0;padding:9px 14px;border-radius:10px;background:#fff;border:1.5px solid var(--border);
  cursor:pointer;text-align:center;text-decoration:none;color:var(--text2);transition:all .15s;min-width:62px;box-shadow:var(--ss);}
.day-tab:hover,.day-tab.active{background:var(--accent);border-color:var(--accent);color:#fff;box-shadow:0 4px 12px rgba(37,99,235,.22);}
.day-tab .day-name{font-size:.66rem;color:var(--muted);font-weight:700;letter-spacing:.5px;text-transform:uppercase;}
.day-tab.active .day-name,.day-tab:hover .day-name{color:rgba(255,255,255,.72);}
.day-tab .day-num{font-family:'Syne',sans-serif;font-size:1.08rem;font-weight:800;margin-top:2px;}
.day-tab.today{border-color:var(--accent2);}
.day-tab.today .day-num{color:var(--accent2);}

/* SLOTS */
.slot-grid{display:flex;flex-direction:column;gap:7px;}
.slot-item{display:flex;align-items:center;justify-content:space-between;padding:12px 15px;
  background:#fff;border:1.5px solid var(--border);border-radius:10px;transition:all .15s;box-shadow:var(--ss);}
.slot-item.available{cursor:pointer;}
.slot-item.available:hover{border-color:var(--accent);box-shadow:0 4px 14px rgba(37,99,235,.12);transform:translateX(2px);}
.slot-item.booked{border-color:#fecaca;background:#fff9f9;}
.slot-time{font-family:'Syne',sans-serif;font-size:.92rem;font-weight:800;color:var(--text);}
.slot-label{font-size:.72rem;padding:3px 10px;border-radius:20px;font-weight:700;letter-spacing:.3px;}
.slot-label.free{background:#eff6ff;color:var(--accent);}
.slot-label.taken{background:#fef2f2;color:var(--danger);}
.slot-label.past{background:#f8fafc;color:var(--muted2);}

/* STATUS BADGES */
.status{display:inline-block;padding:3px 10px;border-radius:20px;font-size:.71rem;font-weight:700;letter-spacing:.3px;text-transform:uppercase;}
.status.confirmed  {background:#eff6ff;color:var(--accent);}
.status.cv_uploaded{background:#f0fdf4;color:var(--accent2);}
.status.reviewed   {background:#fffbeb;color:var(--warn);}
.status.interviewed{background:#f5f3ff;color:var(--purple);}
.status.offered    {background:#ecfdf5;color:#065f46;font-weight:800;}
.status.rejected   {background:#fef2f2;color:var(--danger);}
.status.pending    {background:#fffbeb;color:var(--warn);}

/* CHIPS */
.chip{display:inline-block;padding:3px 9px;border-radius:20px;font-size:.71rem;font-weight:700;letter-spacing:.3px;}
.chip.blue  {background:#eff6ff;color:var(--accent);}
.chip.green {background:#f0fdf4;color:var(--accent2);}
.chip.red   {background:#fef2f2;color:var(--danger);}
.chip.purple{background:#f5f3ff;color:var(--purple);}
.chip.gray  {background:#f1f5f9;color:var(--muted);}

/* MODAL */
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(15,23,42,.4);backdrop-filter:blur(4px);
  z-index:300;align-items:flex-end;justify-content:center;}
.modal-overlay.open{display:flex;}
.modal{background:#fff;border:1.5px solid var(--border);border-radius:20px 20px 0 0;
  padding:22px 22px 36px;width:100%;max-width:500px;animation:slideUp .26s ease;box-shadow:var(--lg);}
@keyframes slideUp{from{transform:translateY(100%);}to{transform:translateY(0);}}
.modal-handle{width:36px;height:4px;background:var(--border2);border-radius:2px;margin:0 auto 18px;}
.modal-title{font-family:'Syne',sans-serif;font-size:1.12rem;font-weight:800;margin-bottom:4px;}
.modal-subtitle{color:var(--muted);font-size:.84rem;margin-bottom:16px;}

/* BOOKING CARD */
.booking-card{background:linear-gradient(135deg,#eff6ff,#f0fdf4);border:1.5px solid #bfdbfe;border-radius:var(--r);padding:18px;margin-bottom:16px;}
.booking-card .date-big{font-family:'Syne',sans-serif;font-size:1.35rem;font-weight:800;color:var(--accent);}
.booking-card .time{font-size:.9rem;color:var(--muted);margin:3px 0 11px;font-weight:500;}

/* TRACKER */
.tracker-steps{display:flex;align-items:flex-start;margin:14px 0;}
.tracker-step{flex:1;display:flex;flex-direction:column;align-items:center;position:relative;}
.tracker-step:not(:last-child)::after{content:'';position:absolute;top:12px;left:50%;width:100%;height:2px;background:var(--border);z-index:0;}
.tracker-step.done:not(:last-child)::after{background:var(--accent);}
.tracker-dot{width:25px;height:25px;border-radius:50%;border:2px solid var(--border);background:#fff;
  display:flex;align-items:center;justify-content:center;font-size:.7rem;z-index:1;position:relative;transition:all .25s;}
.tracker-step.done .tracker-dot{border-color:var(--accent);background:var(--accent);color:#fff;font-size:.74rem;}
.tracker-step.current .tracker-dot{border-color:var(--accent2);background:var(--accent2);color:#fff;box-shadow:0 0 0 4px rgba(5,150,105,.14);}
.tracker-step.rejected-step .tracker-dot{border-color:var(--danger);background:var(--danger);color:#fff;}
.tracker-label{font-size:.61rem;color:var(--muted2);margin-top:5px;text-align:center;font-weight:600;letter-spacing:.15px;}
.tracker-step.current .tracker-label{color:var(--accent2);font-weight:800;}
.tracker-step.done .tracker-label{color:var(--accent);}

/* JOB CARDS */
.job-grid{display:grid;grid-template-columns:1fr;gap:11px;}
@media(min-width:620px){.job-grid{grid-template-columns:repeat(2,1fr);}}
.job-card{background:#fff;border:1.5px solid var(--border);border-radius:var(--r);padding:18px;
  display:flex;flex-direction:column;gap:9px;transition:all .18s;box-shadow:var(--ss);}
.job-card:hover{border-color:var(--accent);box-shadow:0 6px 22px rgba(37,99,235,.1);transform:translateY(-2px);}
.job-title{font-family:'Syne',sans-serif;font-size:.96rem;font-weight:800;color:var(--text);}
.job-company{font-size:.84rem;color:var(--accent2);font-weight:700;}
.job-meta{display:flex;flex-wrap:wrap;gap:5px;margin-top:3px;}
.job-tag{display:inline-block;padding:2px 8px;border-radius:20px;font-size:.7rem;font-weight:700;}
.job-tag.loc{background:#f1f5f9;color:var(--muted);}
.job-tag.type{background:#eff6ff;color:var(--accent);}
.job-desc{font-size:.83rem;color:var(--text2);line-height:1.55;flex:1;}
.job-actions{display:flex;gap:7px;margin-top:3px;}

/* CV / UPLOAD */
.upload-zone{border:2px dashed var(--border2);border-radius:11px;padding:30px 18px;text-align:center;
  cursor:pointer;transition:all .18s;background:#fafbff;}
.upload-zone:hover,.upload-zone.drag{border-color:var(--accent);background:#eff6ff;}
.upload-zone .uz-icon{font-size:2.4rem;margin-bottom:10px;}
.upload-zone p{color:var(--muted);font-size:.87rem;}
.upload-zone span{color:var(--accent);font-weight:700;}
.file-chosen{display:none;align-items:center;gap:9px;background:#f0fdf4;border:1.5px solid #bbf7d0;border-radius:9px;padding:10px 13px;margin-top:9px;}
.file-chosen .fn{font-size:.87rem;font-weight:700;color:var(--accent2);}
input[type=file]{display:none;}
.cv-badge{display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:20px;font-size:.71rem;font-weight:700;background:#f0fdf4;color:var(--accent2);border:1.5px solid #bbf7d0;}

/* BOOKING HISTORY */
.booking-hist{background:#fff;border:1.5px solid var(--border);border-radius:var(--r);padding:17px;margin-bottom:10px;box-shadow:var(--ss);}
.booking-hist .bh-title{font-family:'Syne',sans-serif;font-weight:800;font-size:.94rem;color:var(--text);}
.booking-hist .bh-sub{font-size:.79rem;color:var(--muted);margin-top:2px;}

/* CANDIDATE CARD */
.c-card{background:#fff;border:1.5px solid var(--border);border-radius:var(--r);padding:17px;margin-bottom:10px;box-shadow:var(--ss);}

/* ACCESS ROW */
.access-row{display:flex;align-items:center;justify-content:space-between;padding:12px 16px;
  background:#fff;border:1.5px solid var(--border);border-radius:10px;margin-bottom:7px;box-shadow:var(--ss);}

/* MESSAGE CARD */
.msg-card{background:#fff;border:1.5px solid var(--border);border-radius:var(--r);padding:15px;margin-bottom:9px;box-shadow:var(--ss);}
.msg-card.unread{border-left:3px solid var(--accent);background:#fafbff;}

/* POST JOB FORM */
.post-job-form{background:#fff;border:1.5px solid var(--border);border-radius:var(--r);padding:20px;margin-bottom:20px;box-shadow:var(--ss);}

/* CV BLOCK */
.cv-block{background:#f8fbff;border:1.5px solid #bfdbfe;border-radius:9px;padding:11px 13px;}

/* EMPTY */
.empty{text-align:center;padding:44px 20px;color:var(--muted);}
.empty .icon{font-size:2.6rem;margin-bottom:11px;opacity:.38;}
.empty p{font-size:.89rem;line-height:1.6;}

/* UTILS */
.mt-2{margin-top:8px;}.mt-3{margin-top:12px;}.mt-4{margin-top:16px;}.mt-6{margin-top:24px;}
.text-muted{color:var(--muted);}.text-sm{font-size:.84rem;}.text-xs{font-size:.75rem;}
.text-center{text-align:center;}.fw-700{font-weight:700;}.fw-800{font-weight:800;}
.text-accent{color:var(--accent);}.text-green{color:var(--accent2);}.text-danger{color:var(--danger);}
.flex{display:flex;}.items-center{align-items:center;}.justify-between{justify-content:space-between;}
::-webkit-scrollbar{width:4px;height:4px;}::-webkit-scrollbar-track{background:transparent;}::-webkit-scrollbar-thumb{background:var(--border2);border-radius:2px;}

/* ── CV PREVIEW MODAL ── */
.cv-modal-overlay{display:none;position:fixed;inset:0;background:rgba(15,23,42,.6);backdrop-filter:blur(6px);z-index:400;align-items:center;justify-content:center;padding:20px;}
.cv-modal-overlay.open{display:flex;}
.cv-modal{background:#fff;border-radius:16px;box-shadow:0 24px 80px rgba(0,0,0,.2);width:100%;max-width:860px;max-height:90vh;display:flex;flex-direction:column;overflow:hidden;}
.cv-modal-header{display:flex;align-items:center;justify-content:space-between;padding:14px 18px;border-bottom:1.5px solid var(--border);flex-shrink:0;}
.cv-modal-title{font-family:'Syne',sans-serif;font-weight:800;font-size:.97rem;color:var(--text);display:flex;align-items:center;gap:8px;min-width:0;}
.cv-modal-title span{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.cv-modal-actions{display:flex;gap:7px;flex-shrink:0;}
.cv-modal-body{flex:1;overflow:auto;background:#f8fafc;min-height:0;}
.cv-modal-body iframe{width:100%;height:100%;border:none;min-height:600px;display:block;}
.cv-modal-body .cv-no-preview{display:flex;flex-direction:column;align-items:center;justify-content:center;padding:60px 32px;text-align:center;gap:16px;min-height:320px;}
.cv-modal-body .cv-no-preview .np-icon{font-size:3.2rem;opacity:.4;}
.cv-modal-body .cv-no-preview p{color:var(--muted);font-size:.9rem;line-height:1.6;}
@keyframes fadeIn{from{opacity:0;transform:translateY(6px);}to{opacity:1;transform:none;}}
.fade-in{animation:fadeIn .3s ease both;}
</style>
</head>
<body>

<?php if(isLoggedIn()): ?>
<nav class="nav">
  <div class="nav-brand">Interview <span>Scheduler</span></div>
  <div class="nav-right">
    <?php if(!isAdmin()): ?>
    <div class="nav-links" style="gap:6px;">
      <button class="nav-back-btn" onclick="history.back()" title="Go back">&#8592;</button>
      <span class="text-xs text-muted" style="font-weight:600;">
        <?php if(in_array($action,['dashboard','book'])): ?>📅 Schedule
        <?php elseif($action==='jobs'): ?>💼 Jobs
        <?php elseif($action==='my_bookings'): ?>📋 Applications
        <?php elseif(in_array($action,['messages','read_messages'])): ?>✉ Messages
        <?php else: ?>Interview Scheduler<?php endif; ?>
      </span>
    </div>
    <?php else: ?>
    <div class="nav-links"><a href="?action=admin" class="nav-link active">🛡 Admin Panel</a></div>
    <div class="nav-sep"></div>
    <div class="nav-user"><strong><?= h($_SESSION['name']) ?></strong>🛡 Admin</div>
    <a href="?action=logout" class="btn-sm ghost">Sign Out</a>
    <?php endif; ?>
  </div>
</nav>
<?php endif; ?>

<?php
// ─── LOGIN / REGISTER ────────────────────────────────────────────────────────
if($action==='login'||$action==='register'):
?>
<div class="login-wrap fade-in">
<div class="login-box">
  <div class="login-logo">
    <h1>Interview <span>Scheduler</span></h1>
    <p>Professional Interview Scheduling Portal</p>
  </div>
  <?php if($message): ?><div class="alert <?= $msgType ?>"><?= $msgType==='error'?'⚠':'✓' ?> <?= h($message) ?></div><?php endif; ?>
  <div class="card">
    <div class="tab-bar">
      <a href="?action=login"    class="tab <?= $action==='login'   ?'active':'' ?>">Sign In</a>
      <a href="?action=register" class="tab <?= $action==='register'?'active':'' ?>">Create Account</a>
    </div>
    <?php if($action==='login'): ?>
    <form method="POST" action="?action=login">
      <div class="form-group"><label>Email Address</label><input type="email" name="email" placeholder="you@example.com" required autofocus></div>
      <div class="form-group"><label>Password</label><input type="password" name="password" placeholder="••••••••" required></div>
      <button type="submit" class="btn">Sign In →</button>
    </form>
    <?php else: ?>
    <form method="POST" action="?action=register">
      <div class="form-group"><label>Full Name</label><input type="text" name="name" placeholder="John Smith" required autofocus></div>
      <div class="form-group"><label>Email Address</label><input type="email" name="email" placeholder="you@example.com" required></div>
      <div class="form-group"><label>Password</label><input type="password" name="password" placeholder="Min. 6 characters" required></div>
      <div class="form-group"><label>Confirm Password</label><input type="password" name="password2" placeholder="Repeat password" required></div>
      <button type="submit" class="btn">Create Account →</button>
    </form>
    <?php endif; ?>
  </div>
</div>
</div>

<?php
// ─── CLIENT DASHBOARD ────────────────────────────────────────────────────────
elseif($action==='dashboard'||$action==='book'):
  $activeDay=$_GET['day']??date('Y-m-d');
  if(!in_array($activeDay,$weekDays))$activeDay=$weekDays[0];
  $today=date('Y-m-d');$now=date('H:i');
  $jobId=(int)($_GET['job_id']??0);$jobCtx=$jobId?getJob($db,$jobId):null;
  $allMyDash=getAllUserBookings($db,$_SESSION['user_id']);
?>
<div class="container fade-in">
  <?php if($message): ?><div class="alert <?= $msgType ?> mt-4"><?= $msgType==='error'?'⚠':'✓' ?> <?= h($message) ?></div><?php endif; ?>
  <?php if($urlMsg==='cancelled'): ?><div class="alert info mt-4">ℹ Booking cancelled.</div><?php endif; ?>

  <?php if($jobCtx): ?>
  <div class="card mt-4" style="padding:12px 15px;border-color:#bfdbfe;background:#eff6ff;">
    <div class="text-xs text-accent fw-700" style="text-transform:uppercase;letter-spacing:.5px;margin-bottom:2px;">Booking interview for</div>
    <div class="fw-800" style="font-family:'Syne',sans-serif;"><?= h($jobCtx['title']) ?></div>
    <div class="text-sm text-muted"><?= h($jobCtx['company']) ?> · <?= h($jobCtx['location']) ?></div>
  </div>
  <?php endif; ?>

  <?php if(!empty($allMyDash)): ?>
  <div class="card mt-4" style="padding:13px 16px;border-color:#bbf7d0;background:#f0fdf4;">
    <div class="flex items-center justify-between" style="margin-bottom:9px;">
      <div class="text-xs fw-700 text-green" style="text-transform:uppercase;letter-spacing:.5px;">Your Applications (<?= count($allMyDash) ?>)</div>
      <a href="?action=my_bookings" class="btn-sm success">Track Status →</a>
    </div>
    <?php foreach($allMyDash as $mb): ?>
    <div style="padding:7px 0;border-top:1.5px solid #dcfce7;display:flex;justify-content:space-between;align-items:center;gap:8px;">
      <div>
        <div class="text-sm fw-700"><?= $mb['job_title']?h($mb['job_title']):'🎯 General Interview' ?></div>
        <div class="text-xs text-muted">📅 <?= date('D M j',strtotime($mb['slot_date'])) ?> · <?= h($mb['slot_start']) ?></div>
      </div>
      <span class="status <?= h($mb['status']) ?>"><?= ucfirst(str_replace('_',' ',$mb['status'])) ?></span>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <div class="section-title mt-6"><span class="dot"></span> Available Slots – This Week</div>
  <div class="week-nav">
    <?php foreach($weekDays as $d):
      $cls=($d===$activeDay?'active ':''). ($d===$today?'today':'');
    ?>
    <a href="?action=dashboard&day=<?= $d ?><?= $jobId?"&job_id=$jobId":'' ?>" class="day-tab <?= $cls ?>">
      <div class="day-name"><?= date('D',strtotime($d)) ?></div>
      <div class="day-num"><?= date('j',strtotime($d)) ?></div>
    </a>
    <?php endforeach; ?>
  </div>
  <div class="slot-grid">
    <?php foreach($slots as $sl):
      $booking=getBooking($db,$activeDay,$sl['start']);
      $isPast=($activeDay<$today)||($activeDay===$today&&$sl['end']<=$now);
      if($booking): ?>
      <div class="slot-item booked">
        <div>
          <div class="slot-time">🕙 <?= $sl['start'] ?> – <?= $sl['end'] ?></div>
          <div class="text-xs" style="margin-top:3px;color:var(--danger);font-weight:600;">🚫 This slot is booked by other candidate</div>
        </div>
        <span class="slot-label taken">Booked</span>
      </div>
      <?php elseif($isPast): ?>
      <div class="slot-item" style="opacity:.38;">
        <div class="slot-time" style="color:var(--muted2);">🕙 <?= $sl['start'] ?> – <?= $sl['end'] ?></div>
        <span class="slot-label past">Past</span>
      </div>
      <?php else: ?>
      <div class="slot-item available" onclick="openModal('<?= $activeDay ?>','<?= $sl['start'] ?>','<?= $sl['end'] ?>',<?= $jobId ?>)">
        <div>
          <div class="slot-time">🕙 <?= $sl['start'] ?> – <?= $sl['end'] ?></div>
          <div class="text-xs text-muted" style="margin-top:2px;">45 min · Available</div>
        </div>
        <span class="slot-label free">Book →</span>
      </div>
      <?php endif; ?>
    <?php endforeach; ?>
  </div>
</div>
<div class="modal-overlay" id="modal" onclick="if(event.target===this)closeModal()">
  <div class="modal">
    <div class="modal-handle"></div>
    <div class="modal-title">Confirm Your Slot</div>
    <div class="modal-subtitle" id="modal-info">–</div>
    <form method="POST" action="?action=book">
      <input type="hidden" name="date"   id="m-date">
      <input type="hidden" name="start"  id="m-start">
      <input type="hidden" name="end"    id="m-end">
      <input type="hidden" name="job_id" id="m-job">
      <div class="form-group"><label>Notes (Optional)</label><textarea name="notes" placeholder="Any notes for the interviewer…"></textarea></div>
      <button type="submit" class="btn">✓ Confirm Booking</button>
      <button type="button" class="btn secondary mt-2" onclick="closeModal()">Cancel</button>
    </form>
  </div>
</div>
<script>
function openModal(date,start,end,jobId){
  document.getElementById('m-date').value=date;document.getElementById('m-start').value=start;
  document.getElementById('m-end').value=end;document.getElementById('m-job').value=jobId||0;
  const d=new Date(date+'T00:00:00'),days=['Sun','Mon','Tue','Wed','Thu','Fri','Sat'],
    months=['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
  document.getElementById('modal-info').textContent=days[d.getDay()]+', '+months[d.getMonth()]+' '+d.getDate()+'  ·  '+start+' – '+end;
  document.getElementById('modal').classList.add('open');
}
function closeModal(){document.getElementById('modal').classList.remove('open');}
</script>

<?php
// ─── CV UPLOAD ───────────────────────────────────────────────────────────────
elseif($action==='upload_cv'&&isLoggedIn()&&!isAdmin()):
  $bid=(int)($_GET['bid']??0);$isNew=isset($_GET['new']);
  $stmt=$db->prepare("SELECT b.*,j.title AS job_title,j.company FROM bookings b LEFT JOIN jobs j ON b.job_id=j.id WHERE b.id=:id AND b.user_id=:u");
  $stmt->bindValue(':id',$bid);$stmt->bindValue(':u',$_SESSION['user_id']);
  $cvB=$stmt->execute()->fetchArray(SQLITE3_ASSOC);
  if(!$cvB)redirect('?action=dashboard');
?>
<div class="container fade-in" style="padding-top:28px;">
  <?php if($isNew): ?><div class="alert success">✓ Your interview slot is confirmed!</div><?php endif; ?>
  <?php if($message): ?><div class="alert <?= $msgType ?>"><?= $msgType==='error'?'⚠':'✓' ?> <?= h($message) ?></div><?php endif; ?>
  <div class="section-title"><span class="dot"></span> <?= $cvB['cv_file']?'Update':'Upload' ?> Your CV</div>
  <div class="card" style="padding:14px 16px;margin-bottom:16px;border-color:#bfdbfe;background:#f8fbff;">
    <div class="text-xs text-accent fw-700" style="text-transform:uppercase;letter-spacing:.5px;margin-bottom:5px;">Interview Details</div>
    <div class="fw-800" style="font-family:'Syne',sans-serif;"><?= date('l, M j',strtotime($cvB['slot_date'])) ?></div>
    <div class="text-sm text-muted">🕙 <?= h($cvB['slot_start']) ?> – <?= h($cvB['slot_end']) ?></div>
    <?php if($cvB['job_title']): ?><div class="text-sm mt-2 text-accent">💼 <?= h($cvB['job_title']) ?> @ <?= h($cvB['company']) ?></div><?php endif; ?>
  </div>
  <?php if($cvB['cv_file']): ?><div class="alert success" style="margin-bottom:16px;">📄 On file: <strong><?= h($cvB['cv_original']?:$cvB['cv_file']) ?></strong> — replace below.</div><?php endif; ?>
  <div class="card">
    <form method="POST" action="?action=upload_cv" enctype="multipart/form-data">
      <input type="hidden" name="booking_id" value="<?= $cvB['id'] ?>">
      <div class="form-group">
        <label>CV / Résumé</label>
        <div class="upload-zone" id="dropZone" onclick="document.getElementById('cvInput').click()" ondragover="ev(event)" ondrop="drop(event)">
          <div class="uz-icon">📄</div>
          <p>Drag & drop, or <span>click to browse</span></p>
          <p style="font-size:.74rem;margin-top:4px;color:var(--muted2);">PDF, DOC or DOCX · Max 5 MB</p>
        </div>
        <div class="file-chosen" id="fileChosen">
          <span style="font-size:1.2rem;">📎</span>
          <span class="fn" id="fileName"></span>
          <span style="margin-left:auto;cursor:pointer;color:var(--muted2);" onclick="clearFile()">✕</span>
        </div>
        <input type="file" name="cv" id="cvInput" accept=".pdf,.doc,.docx" onchange="fileSelected(this)">
      </div>
      <button type="submit" class="btn" id="submitBtn" disabled style="opacity:.5;">📤 Upload CV</button>
    </form>
  </div>
  <div style="text-align:center;margin-top:16px;"><a href="?action=jobs" class="text-sm text-accent">Skip → Browse more jobs</a></div>
</div>
<script>
function fileSelected(i){const f=i.files[0];if(!f)return;document.getElementById('fileName').textContent=f.name;document.getElementById('fileChosen').style.display='flex';document.getElementById('submitBtn').disabled=false;document.getElementById('submitBtn').style.opacity='1';}
function clearFile(){document.getElementById('cvInput').value='';document.getElementById('fileChosen').style.display='none';document.getElementById('submitBtn').disabled=true;document.getElementById('submitBtn').style.opacity='.5';}
function ev(e){e.preventDefault();document.getElementById('dropZone').classList.add('drag');}
function drop(e){e.preventDefault();document.getElementById('dropZone').classList.remove('drag');const f=e.dataTransfer.files[0];if(!f)return;const dt=new DataTransfer();dt.items.add(f);const inp=document.getElementById('cvInput');inp.files=dt.files;fileSelected(inp);}
</script>

<?php
// ─── JOBS ───────────────────────────────────────────────────────────────────
elseif($action==='jobs'&&isLoggedIn()&&!isAdmin()):
  $allJobs=getAllJobs($db,true);
  $appliedRows=getAllUserBookings($db,$_SESSION['user_id']);
  $myApplied=[];foreach($appliedRows as $ar){if($ar['job_id'])$myApplied[$ar['job_id']]=true;}
?>
<div class="container wide fade-in">
  <?php if($urlMsg==='cv_uploaded'): ?><div class="alert success mt-4">🎉 CV uploaded! Browse more positions below.</div><?php endif; ?>
  <div class="section-title mt-4"><span class="dot"></span> Open Positions</div>
  <p class="text-sm text-muted" style="margin-bottom:16px;">Select a role to book an interview slot.</p>
  <?php if(!empty($myApplied)): ?>
  <div class="card" style="padding:11px 15px;border-color:#bbf7d0;background:#f0fdf4;margin-bottom:16px;display:flex;align-items:center;justify-content:space-between;">
    <div class="text-sm">✅ <strong><?= count($myApplied) ?></strong> active application<?= count($myApplied)>1?'s':'' ?>.</div>
    <a href="?action=my_bookings" class="btn-sm success">Track Status →</a>
  </div>
  <?php endif; ?>
  <?php if(empty($allJobs)): ?>
  <div class="empty"><div class="icon">💼</div><p>No open positions right now.</p></div>
  <?php else: ?>
  <div class="job-grid">
    <?php foreach($allJobs as $j): ?>
    <div class="job-card">
      <div>
        <div class="job-title"><?= h($j['title']) ?></div>
        <div class="job-company"><?= h($j['company']) ?></div>
        <div class="job-meta">
          <?php if($j['location']): ?><span class="job-tag loc">📍 <?= h($j['location']) ?></span><?php endif; ?>
          <span class="job-tag type"><?= h($j['type']) ?></span>
        </div>
      </div>
      <?php if($j['description']): ?><div class="job-desc"><?= h(substr($j['description'],0,115)) ?><?= strlen($j['description'])>115?'…':'' ?></div><?php endif; ?>
      <?php if($j['requirements']): ?><div class="text-xs text-muted">🔧 <?= h(substr($j['requirements'],0,85)) ?><?= strlen($j['requirements'])>85?'…':'' ?></div><?php endif; ?>
      <div class="job-actions">
        <?php if(!empty($myApplied[$j['id']])): ?>
        <span class="btn secondary sm inline" style="flex:1;opacity:.55;cursor:not-allowed;">✓ Applied</span>
        <?php else: ?>
        <a href="?action=dashboard&job_id=<?= $j['id'] ?>" class="btn green sm inline" style="flex:1;">📅 Book Interview</a>
        <?php endif; ?>
        <button class="btn-sm ghost" onclick="toggleDesc(this)" data-full="<?= h($j['description']) ?>">Details</button>
      </div>
      <div class="job-full-desc" style="display:none;font-size:.83rem;color:var(--text2);line-height:1.6;border-top:1.5px solid var(--border);padding-top:11px;margin-top:3px;"></div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>
<script>function toggleDesc(b){const d=b.closest('.job-card').querySelector('.job-full-desc');if(d.style.display==='none'){d.textContent=b.dataset.full;d.style.display='block';b.textContent='Less';}else{d.style.display='none';b.textContent='Details';}}</script>

<?php
// ─── MY BOOKINGS ─────────────────────────────────────────────────────────────
elseif($action==='my_bookings'&&isLoggedIn()&&!isAdmin()):
  $allMy=getAllUserBookings($db,$_SESSION['user_id']);$steps=statusSteps();
?>
<div class="container wide fade-in">
  <?php if($urlMsg==='cancelled'): ?><div class="alert info mt-4">ℹ Booking cancelled.</div><?php endif; ?>
  <div class="section-title mt-4"><span class="dot"></span> My Applications & Status</div>
  <?php if(empty($allMy)): ?>
  <div class="empty"><div class="icon">📋</div><p>No applications yet.</p>
    <a href="?action=jobs" class="btn green inline mt-4" style="width:auto;padding:10px 22px;">💼 Browse Jobs</a></div>
  <?php else: ?>
  <?php foreach($allMy as $b):
    $st=$b['status'];$stInfo=$steps[$st]??$steps['confirmed'];$cur=$stInfo['step'];
    $isRej=($st==='rejected');$isFut=$b['slot_date']>=date('Y-m-d');
    $ds=[['key'=>'confirmed','label'=>'Booked','icon'=>'📅'],['key'=>'cv_uploaded','label'=>'CV Sent','icon'=>'📄'],['key'=>'reviewed','label'=>'Reviewed','icon'=>'🔍'],['key'=>'interviewed','label'=>'Interviewed','icon'=>'🎤'],['key'=>'decision','label'=>'Decision','icon'=>'🏁']];
  ?>
  <div class="booking-hist">
    <div class="flex items-center justify-between" style="margin-bottom:10px;">
      <div>
        <div class="bh-title"><?= $b['job_title']?h($b['job_title']):'🎯 General Interview' ?></div>
        <div class="bh-sub"><?= $b['job_title']?h($b['company']).' · ':'' ?><?= date('D, M j, Y',strtotime($b['slot_date'])) ?> · 🕙 <?= h($b['slot_start']) ?></div>
      </div>
      <span class="status <?= h($st) ?>"><?= ucfirst(str_replace('_',' ',$st)) ?></span>
    </div>
    <div class="tracker-steps">
      <?php foreach($ds as $i=>$d):
        $sn=$i+1;
        if($isRej&&$sn===5){$cl='rejected-step';$d['icon']='❌';$d['label']='Rejected';}
        elseif($isRej&&$d['key']==='offered'){continue;}
        elseif($st==='offered'&&$sn===5){$d['icon']='🎉';$d['label']='Offered';$cl='current';}
        elseif($sn<$cur){$cl='done';}
        elseif($sn===$cur&&!$isRej){$cl='current';}
        else{$cl='';}
      ?>
      <div class="tracker-step <?= $cl ?>">
        <div class="tracker-dot"><?= $cl==='done'?'✓':$d['icon'] ?></div>
        <div class="tracker-label"><?= $d['label'] ?></div>
      </div>
      <?php endforeach; ?>
    </div>
    <div class="flex items-center justify-between mt-3" style="flex-wrap:wrap;gap:7px;">
      <div>
        <?php if($b['cv_file']): ?><span class="cv-badge">📎 <?= h($b['cv_original']?:'CV Uploaded') ?></span>
        <?php else: ?><span class="text-xs text-muted">No CV uploaded yet</span><?php endif; ?>
        <?php if($b['notes']): ?><div class="text-sm text-muted mt-2">📝 <?= h($b['notes']) ?></div><?php endif; ?>
      </div>
      <div style="display:flex;gap:7px;flex-wrap:wrap;">
        <?php if(!$b['cv_file']): ?><a href="?action=upload_cv&bid=<?= $b['id'] ?>" class="btn-sm success">📄 Upload CV</a>
        <?php else: ?><a href="?action=upload_cv&bid=<?= $b['id'] ?>" class="btn-sm ghost">🔄 Replace CV</a><?php endif; ?>
        <?php if($isFut): ?><a href="?action=cancel&id=<?= $b['id'] ?>" class="btn-sm danger" onclick="return confirm('Cancel this booking?')">✕ Cancel</a><?php endif; ?>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
  <div style="text-align:center;margin-top:20px;"><a href="?action=jobs" class="btn green inline" style="width:auto;padding:10px 24px;">💼 Browse More Positions</a></div>
  <?php endif; ?>
</div>

<?php
// ─── CLIENT MESSAGES ─────────────────────────────────────────────────────────
elseif(in_array($action,['messages','read_messages'])&&isLoggedIn()&&!isAdmin()):
  $myMsgs=getMessages($db,$_SESSION['user_id'],'client');
?>
<div class="container wide fade-in">
  <div class="section-title mt-4"><span class="dot"></span> Messages from Interviewer</div>
  <?php if(empty($myMsgs)): ?>
  <div class="empty"><div class="icon">✉</div><p>No messages yet.<br>You'll receive updates here from the interview team.</p></div>
  <?php else: ?>
  <?php foreach($myMsgs as $msg): ?>
  <div class="msg-card <?= !$msg['read_at']?'unread':'' ?>">
    <div class="flex items-center justify-between" style="margin-bottom:7px;">
      <div class="fw-700 text-sm"><?= h($msg['subject']) ?></div>
      <div class="text-xs text-muted"><?= date('M j, g:i A',strtotime($msg['created_at'])) ?></div>
    </div>
    <?php if(!$msg['read_at']): ?><span class="chip blue" style="font-size:.63rem;margin-bottom:7px;display:inline-block;">New</span><?php endif; ?>
    <div class="text-sm" style="color:var(--text2);line-height:1.65;white-space:pre-wrap;"><?= h($msg['body']) ?></div>
    <div class="text-xs text-muted mt-2">From: <?= h($msg['from_name']) ?></div>
  </div>
  <?php endforeach; ?>
  <?php endif; ?>
</div>

<?php
// ─── ADMIN PANEL ─────────────────────────────────────────────────────────────
elseif($action==='admin'&&isAdmin()):
  $allBookings=getAllBookings($db);$allClients=getAllClients($db);
  $allUsers=getAllUsers($db);$allAdminJobs=getAllJobs($db,false);
  $totalSlots=count($weekDays)*count($slots);$booked=count($allBookings);$free=$totalSlots-$booked;
  $view=$_GET['view']??'schedule';
  $activeDay=$_GET['day']??date('Y-m-d');if(!in_array($activeDay,$weekDays))$activeDay=$weekDays[0];
  $stList=statusSteps();
?>
<div class="container full fade-in">
  <?php if($urlMsg==='cancelled'):   ?><div class="alert info mt-4">ℹ Booking removed.</div><?php endif; ?>
  <?php if($urlMsg==='updated'):     ?><div class="alert success mt-4">✓ Status updated.</div><?php endif; ?>
  <?php if($urlMsg==='job_posted'):  ?><div class="alert success mt-4">✓ Job posted.</div><?php endif; ?>
  <?php if($urlMsg==='job_deleted'): ?><div class="alert info mt-4">ℹ Job archived.</div><?php endif; ?>
  <?php if($urlMsg==='msg_sent'):    ?><div class="alert success mt-4">✓ Message sent to candidate.</div><?php endif; ?>
  <?php if($urlMsg==='access_updated'): ?><div class="alert success mt-4">✓ Access permissions updated.</div><?php endif; ?>
  <?php if($message): ?><div class="alert <?= $msgType ?> mt-4"><?= $msgType==='error'?'⚠':'✓' ?> <?= h($message) ?></div><?php endif; ?>

  <div class="section-title mt-4"><span class="dot"></span> Overview</div>
  <div class="stats-row">
    <div class="stat-card"><div class="stat-num"><?= $totalSlots ?></div><div class="stat-label">Total Slots</div></div>
    <div class="stat-card"><div class="stat-num" style="color:var(--danger);"><?= $booked ?></div><div class="stat-label">Booked</div></div>
    <div class="stat-card"><div class="stat-num" style="color:var(--accent2);"><?= $free ?></div><div class="stat-label">Available</div></div>
    <div class="stat-card"><div class="stat-num" style="color:var(--warn);"><?= count($allAdminJobs) ?></div><div class="stat-label">Job Posts</div></div>
  </div>

  <div class="admin-tabs">
    <a href="?action=admin&view=schedule" class="admin-tab <?= $view==='schedule'?'active':'' ?>">📅 Schedule</a>
    <a href="?action=admin&view=bookings" class="admin-tab <?= $view==='bookings'?'active':'' ?>">📋 Bookings</a>
    <a href="?action=admin&view=clients"  class="admin-tab <?= $view==='clients' ?'active':'' ?>">👥 Candidates</a>
    <a href="?action=admin&view=jobs"     class="admin-tab <?= $view==='jobs'    ?'active':'' ?>">💼 Jobs</a>
    <a href="?action=admin&view=access"   class="admin-tab <?= $view==='access'  ?'active':'' ?>">🔑 Admin Access</a>
  </div>

  <?php if($view==='schedule'): ?>
  <div class="week-nav">
    <?php foreach($weekDays as $d):$cls=($d===$activeDay?'active ':''). ($d===date('Y-m-d')?'today':''); ?>
    <a href="?action=admin&view=schedule&day=<?= $d ?>" class="day-tab <?= $cls ?>">
      <div class="day-name"><?= date('D',strtotime($d)) ?></div>
      <div class="day-num"><?= date('j',strtotime($d)) ?></div>
    </a>
    <?php endforeach; ?>
  </div>
  <div class="slot-grid">
    <?php foreach($slots as $sl):$booking=getBooking($db,$activeDay,$sl['start']); ?>
    <div class="slot-item <?= $booking?'booked':'' ?>" style="<?= !$booking?'cursor:default;':'' ?>">
      <div style="flex:1;">
        <div class="slot-time">🕙 <?= $sl['start'] ?> – <?= $sl['end'] ?></div>
        <?php if($booking): ?>
        <div class="text-sm fw-700" style="margin-top:3px;">👤 <?= h($booking['name']) ?></div>
        <div class="text-xs text-muted"><?= h($booking['email']) ?></div>
        <?php if($booking['job_title']??''): ?><div class="text-xs text-accent">💼 <?= h($booking['job_title']) ?></div><?php endif; ?>
        <?php if($booking['cv_file']): ?><span class="cv-badge" style="margin-top:4px;">📎 CV on file</span><?php endif; ?>
        <?php endif; ?>
      </div>
      <div style="display:flex;flex-direction:column;align-items:flex-end;gap:5px;">
        <?php if($booking): ?>
        <span class="status <?= h($booking['status']) ?>"><?= ucfirst(str_replace('_',' ',$booking['status'])) ?></span>
        <a href="?action=admin_cancel&id=<?= $booking['id'] ?>&view=schedule&day=<?= $activeDay ?>" class="btn-sm danger" onclick="return confirm('Remove?')">✕</a>
        <?php else: ?><span class="slot-label free">Free</span><?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <?php elseif($view==='bookings'): ?>
  <?php if(empty($allBookings)): ?>
  <div class="empty"><div class="icon">📭</div><p>No bookings yet.</p></div>
  <?php else: ?>
  <?php foreach($allBookings as $b): ?>
  <div class="c-card">
    <div class="flex items-center justify-between" style="margin-bottom:9px;">
      <div>
        <div class="fw-800" style="font-family:'Syne',sans-serif;"><?= h($b['name']) ?></div>
        <div class="text-xs text-muted"><?= h($b['email']) ?></div>
      </div>
      <span class="status <?= h($b['status']) ?>"><?= ucfirst(str_replace('_',' ',$b['status'])) ?></span>
    </div>
    <div class="text-sm" style="color:var(--text2);">📅 <?= date('D, M j',strtotime($b['slot_date'])) ?> &nbsp;·&nbsp; 🕙 <?= h($b['slot_start']) ?> – <?= h($b['slot_end']) ?></div>
    <?php if($b['job_title']): ?><div class="text-sm mt-2 text-accent">💼 <?= h($b['job_title']) ?> @ <?= h($b['company']) ?></div><?php endif; ?>
    <?php if($b['notes']): ?><div class="text-sm text-muted mt-1">📝 <?= h($b['notes']) ?></div><?php endif; ?>

    <?php if($b['cv_file']): ?>
    <div class="cv-block mt-3">
      <div class="flex items-center justify-between">
        <div>
          <div class="text-xs fw-700 text-accent" style="text-transform:uppercase;letter-spacing:.4px;margin-bottom:2px;">Submitted CV</div>
          <div class="text-sm fw-700">📎 <?= h($b['cv_original']?:$b['cv_file']) ?></div>
        </div>
        <div style="display:flex;gap:6px;">
          <?php $cvExt=strtolower(pathinfo($b['cv_file'],PATHINFO_EXTENSION)); ?>
          <button class="btn-sm purple" onclick="previewCV('uploads/cv/<?= h($b['cv_file']) ?>','<?= h($b['cv_original']?:$b['cv_file']) ?>','<?= $cvExt ?>')">👁 Preview</button>
          <a href="uploads/cv/<?= h($b['cv_file']) ?>" download="<?= h($b['cv_original']?:$b['cv_file']) ?>" class="btn-sm primary">⬇ Download</a>
        </div>
      </div>
    </div>
    <?php else: ?>
    <div class="mt-3 text-xs text-muted" style="background:#f8fafc;border:1.5px solid var(--border);border-radius:8px;padding:9px 12px;">📄 No CV uploaded yet</div>
    <?php endif; ?>

    <div class="flex mt-3" style="gap:7px;align-items:center;flex-wrap:wrap;">
      <form method="POST" action="?action=admin_status" style="display:flex;gap:6px;flex:1;min-width:190px;">
        <input type="hidden" name="bid" value="<?= $b['id'] ?>">
        <select name="status" style="flex:1;padding:7px 10px;font-size:.81rem;border-radius:8px;border:1.5px solid var(--border);background:#fff;color:var(--text);font-family:'Inter',sans-serif;font-weight:500;">
          <?php foreach($stList as $sk=>$sv): ?>
          <option value="<?= $sk ?>" <?= $b['status']===$sk?'selected':'' ?>><?= $sv['icon'] ?> <?= $sv['label'] ?></option>
          <?php endforeach; ?>
        </select>
        <button type="submit" class="btn-sm success">Update</button>
      </form>
      <button class="btn-sm purple" onclick="openMsgModal(<?= $b['user_id'] ?>,<?= $b['id'] ?>,'<?= h($b['name']) ?>')">✉ Message</button>
      <a href="?action=admin_cancel&id=<?= $b['id'] ?>&view=bookings" class="btn-sm danger" onclick="return confirm('Remove this booking?')">✕</a>
    </div>
  </div>
  <?php endforeach; endif; ?>

  <?php elseif($view==='clients'): ?>
  <?php if(empty($allClients)): ?>
  <div class="empty"><div class="icon">👥</div><p>No candidates registered yet.</p></div>
  <?php else: ?>
  <?php foreach($allClients as $c):$cB=getAllUserBookings($db,$c['id']); ?>
  <div class="c-card">
    <div class="flex items-center justify-between" style="margin-bottom:8px;">
      <div>
        <div class="fw-800" style="font-family:'Syne',sans-serif;"><?= h($c['name']) ?></div>
        <div class="text-xs text-muted"><?= h($c['email']) ?> · Joined <?= date('M j, Y',strtotime($c['created_at'])) ?></div>
      </div>
      <div style="display:flex;gap:6px;align-items:center;">
        <?php if(!empty($cB)): ?><span class="chip green"><?= count($cB) ?> Application<?= count($cB)>1?'s':'' ?></span><?php else: ?><span class="chip gray">No Bookings</span><?php endif; ?>
        <button class="btn-sm purple" onclick="openMsgModal(<?= $c['id'] ?>,0,'<?= h($c['name']) ?>')">✉ Message</button>
      </div>
    </div>
    <?php foreach($cB as $cb): ?>
    <div style="background:#f8fbff;border:1.5px solid var(--border);border-radius:9px;padding:10px 12px;margin-top:7px;">
      <div class="flex items-center justify-between">
        <div>
          <div class="text-sm fw-700"><?= $cb['job_title']?h($cb['job_title']):'General Interview' ?></div>
          <div class="text-xs text-muted">📅 <?= date('D, M j',strtotime($cb['slot_date'])) ?> · <?= h($cb['slot_start']) ?></div>
        </div>
        <div style="display:flex;gap:6px;align-items:center;">
          <span class="status <?= h($cb['status']) ?>"><?= ucfirst(str_replace('_',' ',$cb['status'])) ?></span>
          <?php if($cb['cv_file']): $cbExt=strtolower(pathinfo($cb['cv_file'],PATHINFO_EXTENSION)); ?><button class="btn-sm purple" style="font-size:.71rem;padding:3px 9px;" onclick="previewCV('uploads/cv/<?= h($cb['cv_file']) ?>','<?= h($cb['cv_original']?:$cb['cv_file']) ?>','<?= $cbExt ?>')">👁 CV</button><a href="uploads/cv/<?= h($cb['cv_file']) ?>" download="<?= h($cb['cv_original']?:$cb['cv_file']) ?>" class="btn-sm primary" style="font-size:.71rem;padding:3px 9px;">⬇</a><?php endif; ?>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endforeach; endif; ?>

  <?php elseif($view==='jobs'): ?>
  <div class="post-job-form">
    <div class="fw-800" style="font-family:'Syne',sans-serif;margin-bottom:15px;">➕ Post New Job</div>
    <form method="POST" action="?action=post_job">
      <div class="row-2">
        <div class="form-group" style="margin-bottom:11px;"><label>Job Title *</label><input type="text" name="title" placeholder="e.g. Senior Developer" required></div>
        <div class="form-group" style="margin-bottom:11px;"><label>Company *</label><input type="text" name="company" placeholder="e.g. TechCorp" required></div>
      </div>
      <div class="row-2">
        <div class="form-group" style="margin-bottom:11px;"><label>Location</label><input type="text" name="location" placeholder="Remote, New York…"></div>
        <div class="form-group" style="margin-bottom:11px;"><label>Type</label>
          <select name="type"><option>Full-time</option><option>Part-time</option><option>Contract</option><option>Internship</option><option>Freelance</option></select>
        </div>
      </div>
      <div class="form-group" style="margin-bottom:11px;"><label>Description</label><textarea name="description" placeholder="Role overview, responsibilities…" style="min-height:68px;"></textarea></div>
      <div class="form-group" style="margin-bottom:14px;"><label>Requirements / Skills</label><textarea name="requirements" placeholder="e.g. PHP 8+, MySQL, 3+ years" style="min-height:52px;"></textarea></div>
      <button type="submit" class="btn inline sm" style="width:auto;padding:9px 24px;">📢 Post Job</button>
    </form>
  </div>
  <div class="section-title"><span class="dot"></span> All Postings (<?= count($allAdminJobs) ?>)</div>
  <?php if(empty($allAdminJobs)): ?>
  <div class="empty"><div class="icon">💼</div><p>No jobs posted yet.</p></div>
  <?php else: ?>
  <?php foreach($allAdminJobs as $j): ?>
  <div class="c-card" style="<?= !$j['active']?'opacity:.5;':'' ?>">
    <div class="flex items-center justify-between" style="margin-bottom:7px;">
      <div><div class="fw-800" style="font-family:'Syne',sans-serif;"><?= h($j['title']) ?></div><div class="text-sm text-green"><?= h($j['company']) ?></div></div>
      <?php if($j['active']): ?><span class="chip green">Active</span><?php else: ?><span class="chip red">Archived</span><?php endif; ?>
    </div>
    <div class="job-meta" style="margin-bottom:7px;">
      <?php if($j['location']): ?><span class="job-tag loc">📍 <?= h($j['location']) ?></span><?php endif; ?>
      <span class="job-tag type"><?= h($j['type']) ?></span>
    </div>
    <?php if($j['description']): ?><div class="text-sm text-muted" style="margin-bottom:7px;"><?= h(substr($j['description'],0,105)) ?>…</div><?php endif; ?>
    <div class="text-xs text-muted">Posted: <?= date('M j, Y',strtotime($j['created_at'])) ?></div>
    <div class="mt-2"><?php if($j['active']): ?><a href="?action=delete_job&id=<?= $j['id'] ?>" class="btn-sm danger" onclick="return confirm('Archive this job?')">🗄 Archive</a><?php endif; ?></div>
  </div>
  <?php endforeach; endif; ?>

  <?php elseif($view==='access'): ?>
  <div class="card" style="padding:15px 17px;margin-bottom:18px;border-color:#bfdbfe;background:#f8fbff;">
    <div class="fw-800" style="font-family:'Syne',sans-serif;margin-bottom:5px;">🔑 Admin Access Control</div>
    <div class="text-sm text-muted">Grant or revoke admin access for any registered user. Admins can manage bookings, jobs, candidates and send messages.</div>
    <div class="mt-3 text-xs" style="background:#fffbeb;border:1.5px solid #fde68a;border-radius:8px;padding:9px 12px;color:var(--warn);line-height:1.55;">
      ⚠ To give admin access to a user who hasn't registered yet, add their email to the <code style="background:#fff3cd;padding:1px 4px;border-radius:4px;">$ADMIN_EMAILS</code> array at the top of <code style="background:#fff3cd;padding:1px 4px;border-radius:4px;">index.php</code>. They will be auto-promoted on login.
    </div>
  </div>
  <?php foreach($allUsers as $u): ?>
  <div class="access-row">
    <div>
      <div class="text-sm fw-700"><?= h($u['name']) ?></div>
      <div class="text-xs text-muted"><?= h($u['email']) ?> · Joined <?= date('M j, Y',strtotime($u['created_at'])) ?></div>
    </div>
    <div style="display:flex;align-items:center;gap:9px;">
      <?php if($u['role']==='admin'): ?>
      <span class="chip purple">🛡 Admin</span>
      <?php if($u['id']!==$_SESSION['user_id']): ?>
      <a href="?action=grant_admin&uid=<?= $u['id'] ?>&op=revoke" class="btn-sm danger" onclick="return confirm('Revoke admin from <?= h($u['name']) ?>?')">Revoke</a>
      <?php else: ?><span class="text-xs text-muted">(You)</span><?php endif; ?>
      <?php else: ?>
      <span class="chip gray">👤 Client</span>
      <a href="?action=grant_admin&uid=<?= $u['id'] ?>&op=grant" class="btn-sm success" onclick="return confirm('Grant admin to <?= h($u['name']) ?>?')">Grant Admin</a>
      <?php endif; ?>
    </div>
  </div>
  <?php endforeach; ?>

  <?php endif; ?>
</div>

<!-- Admin Message Modal -->
<div class="modal-overlay" id="msgModal" onclick="if(event.target===this)closeMsgModal()">
  <div class="modal">
    <div class="modal-handle"></div>
    <div class="modal-title">Send Message</div>
    <div class="modal-subtitle" id="msg-to-label">To: –</div>
    <form method="POST" action="?action=send_message">
      <input type="hidden" name="to_id"      id="msg-to-id">
      <input type="hidden" name="booking_id" id="msg-bid">
      <div class="form-group"><label>Subject</label><input type="text" name="subject" value="Update on your application"></div>
      <div class="form-group"><label>Message *</label><textarea name="body" placeholder="Write your message to the candidate…" style="min-height:110px;" required></textarea></div>
      <button type="submit" class="btn">✉ Send Message</button>
      <button type="button" class="btn secondary mt-2" onclick="closeMsgModal()">Cancel</button>
    </form>
  </div>
</div>
<script>
function openMsgModal(toId,bid,name){
  document.getElementById('msg-to-id').value=toId;
  document.getElementById('msg-bid').value=bid||0;
  document.getElementById('msg-to-label').textContent='To: '+name;
  document.getElementById('msgModal').classList.add('open');
}
function closeMsgModal(){document.getElementById('msgModal').classList.remove('open');}
</script>

<!-- CV Preview Modal -->
<div class="cv-modal-overlay" id="cvPreviewModal" onclick="if(event.target===this)closePreview()">
  <div class="cv-modal">
    <div class="cv-modal-header">
      <div class="cv-modal-title">📎 <span id="cvPreviewName">Document</span></div>
      <div class="cv-modal-actions">
        <a id="cvDownloadBtn" href="#" download class="btn-sm success">⬇ Download</a>
        <button class="btn-sm ghost" onclick="closePreview()">✕ Close</button>
      </div>
    </div>
    <div class="cv-modal-body" id="cvPreviewBody"></div>
  </div>
</div>
<script>
function previewCV(url, name, ext) {
  document.getElementById('cvPreviewName').textContent = name;
  document.getElementById('cvDownloadBtn').href = url;
  document.getElementById('cvDownloadBtn').download = name;
  const body = document.getElementById('cvPreviewBody');
  if (ext === 'pdf') {
    body.innerHTML = '<iframe src="' + url + '#toolbar=1&navpanes=0" allowfullscreen></iframe>';
  } else {
    // For doc/docx use Google Docs Viewer
    const encoded = encodeURIComponent(window.location.origin + '/' + url);
    body.innerHTML = '<iframe src="https://docs.google.com/gview?url=' + encoded + '&embedded=true" allowfullscreen></iframe>';
  }
  document.getElementById('cvPreviewModal').classList.add('open');
  document.body.style.overflow = 'hidden';
}
function closePreview() {
  document.getElementById('cvPreviewModal').classList.remove('open');
  document.getElementById('cvPreviewBody').innerHTML = '';
  document.body.style.overflow = '';
}
document.addEventListener('keydown', function(e){ if(e.key==='Escape') closePreview(); });
</script>


<?php endif; ?>

<?php if(isLoggedIn() && !isAdmin()): ?>
<!-- overlay -->
<div class="sov" id="sov" onclick="cls()"></div>
<!-- sheet -->
<div class="sht" id="sht">
  <div class="sht-bar"></div>
  <div class="sht-ttl">Go to</div>
  <a href="?action=dashboard"   class="<?= in_array($action,['dashboard','book'])?'on':'' ?>"><span class="sht-ico">📅</span><span class="sht-lbl">Schedule</span><span class="sht-arr">›</span></a>
  <a href="?action=jobs"        class="<?= $action==='jobs'?'on':'' ?>"><span class="sht-ico">💼</span><span class="sht-lbl">Jobs</span><span class="sht-arr">›</span></a>
  <a href="?action=my_bookings" class="<?= $action==='my_bookings'?'on':'' ?>"><span class="sht-ico">📋</span><span class="sht-lbl">Applications</span><span class="sht-arr">›</span></a>
  <div class="sht-pad"></div>
</div>
<!-- bottom bar -->
<nav class="bnav">
  <button class="<?= in_array($action,['dashboard','book','jobs','my_bookings'])?'on':'' ?>" onclick="opn()">
    <span class="bnav-icon">☰</span><span>Menu</span>
  </button>
  <a href="?action=read_messages" class="<?= in_array($action,['messages','read_messages'])?'on':'' ?>">
    <span class="bnav-icon"><?php if($unread): ?><span class="bdot"><?= $unread ?></span><?php endif; ?>✉</span>
    <span>Messages</span>
  </a>
  <a href="?action=logout" class="out" onclick="return confirm('Sign out?')">
    <span class="bnav-icon">⏻</span><span>Sign Out</span>
  </a>
</nav>
<script>
function opn(){document.getElementById('sov').classList.add('on');document.getElementById('sht').classList.add('on');document.body.style.overflow='hidden';}
function cls(){document.getElementById('sov').classList.remove('on');document.getElementById('sht').classList.remove('on');document.body.style.overflow='';}
document.addEventListener('keydown',function(e){if(e.key==='Escape')cls();});
</script>
<?php endif; ?>
</body>
</html>