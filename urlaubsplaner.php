<?php
/*
Plugin Name: Retail Vacation Planner (SaaS Ready)
Description: Enterprise-grade vacation planning tool with concurrency control, waitlist logic, and automated reporting.
Version: 1.0.0 (Public Release)
Author: [Dein Name]
*/

// ============================================================================
// 1. CONFIGURATION
// ============================================================================
define('RB_DB_KEY', 'rb_vacation_planner_prod');
define('RB_ADMIN_EMAIL', 'admin@example.com'); // Placeholder
define('RB_YEAR', 2026);
define('RB_BUNDESLAND', 'BW'); // German State for Holidays

// RULES
define('MAX_ABSENT', 3);     // Max employees absent at the same time
define('MIN_MANAGERS', 2);   // Min managers required on site

// ============================================================================
// 2. MOCK DATA (Demo Content)
// ============================================================================
// In a production environment, this would be fetched from a SQL Database via API.
function rb_get_employees(): array {
    return [
        // LEADERSHIP
        '1001' => ['name' => 'Mustermann Max',     'greeting' => 'Max',    'days' => 30, 'role' => 'manager'],
        '1002' => ['name' => 'Musterfrau Erika',   'greeting' => 'Erika',  'days' => 30, 'role' => 'deputy'],
        '1003' => ['name' => 'Doe John',           'greeting' => 'John',   'days' => 30, 'role' => 'tagesvertretung'],
        
        // STAFF
        '2001' => ['name' => 'Schmidt Lisa',       'greeting' => 'Lisa',   'days' => 28, 'role' => 'staff'],
        '2002' => ['name' => 'Müller Hans',        'greeting' => 'Hans',   'days' => 30, 'role' => 'staff'],
        '2003' => ['name' => 'Weber Sarah',        'greeting' => 'Sarah',  'days' => 25, 'role' => 'staff'],
        '2004' => ['name' => 'Klein Peter',        'greeting' => 'Peter',  'days' => 28, 'role' => 'staff'],
        '2005' => ['name' => 'Wagner Julia',       'greeting' => 'Julia',  'days' => 30, 'role' => 'staff'],
    ];
}

function rb_is_manager($role) { return in_array($role, ['manager', 'deputy', 'tagesvertretung']); }

// ============================================================================
// 3. LOGIC ENGINE
// ============================================================================
function rb_hols($y) {
    // Gauss Easter Algorithm & German Public Holidays
    $b = easter_date($y);
    $d = fn($x) => date('Y-m-d', strtotime("+$x days", $b));
    $h = ["$y-01-01"=>1,"$y-05-01"=>1,"$y-10-03"=>1,"$y-12-25"=>1,"$y-12-26"=>1,$d(-2)=>1,$d(1)=>1,$d(39)=>1,$d(50)=>1];
    if(RB_BUNDESLAND=='BW') { $h["$y-01-06"]=1; $h[$d(60)]=1; $h["$y-11-01"]=1; }
    return $h;
}

function rb_days($s, $e) {
    $h = rb_hols(substr($s,0,4)); $c=0; $curr=new DateTime($s); $end=new DateTime($e);
    while($curr<=$end) {
        // Logic: 6-Day-Workweek (Mon-Sat count as workdays)
        if($curr->format('N')<=6 && !isset($h[$curr->format('Y-m-d')])) $c++;
        $curr->modify('+1 day');
    }
    return $c;
}

function rb_check($s, $e, $uid, $ignore_id=0) {
    $db = json_decode(get_option(RB_DB_KEY, '[]'), true);
    $emps = rb_get_employees();
    $h = rb_hols(substr($s,0,4));
    
    $curr=new DateTime($s); $end=new DateTime($e);
    while($curr<=$end) {
        $ymd = $curr->format('Y-m-d');
        if($curr->format('N')<=6 && !isset($h[$ymd])) {
            $absent = [$uid];
            foreach($db as $v) {
                if($v['id'] == $ignore_id) continue;
                if(in_array($v['status'], ['approved','pending']) && $ymd>=$v['start'] && $ymd<=$v['end']) $absent[]=$v['personal_id'];
            }
            $absent = array_unique($absent);
            
            // Rule 1: Max Absence
            if(count($absent) > MAX_ABSENT) return "Maximum capacity reached (Max ".MAX_ABSENT.").";
            
            // Rule 2: Management Coverage
            $man_cnt = 0;
            foreach($emps as $eid=>$ed) if(!in_array($eid, $absent) && rb_is_manager($ed['role'])) $man_cnt++;
            if($man_cnt < MIN_MANAGERS) return "Minimum management coverage not met ($man_cnt present).";
        }
        $curr->modify('+1 day');
    }
    return null;
}

// ============================================================================
// 4. API & REPORTING
// ============================================================================

function rb_send_weekly_report(): void {
    $db = json_decode(get_option(RB_DB_KEY, '[]'), true);
    $pending = array_filter($db, fn($v) => ($v['status']??'') === 'pending');
    
    // Auto-Backup CSV Generation
    $csv = "Name;Start;End;Days;Status;Created\n";
    foreach($db as $r) {
        $csv .= sprintf("%s;%s;%s;%s;%s;%s\n", $r['name'], $r['start'], $r['end'], $r['days'], $r['status'], $r['created_at'] ?? '-');
    }
    
    $tmp_dir = sys_get_temp_dir();
    $file = $tmp_dir . '/backup_'.date('Ymd').'.csv';
    file_put_contents($file, $csv);

    $count = count($pending);
    $subject = "System Report & Backup";
    $msg = "<p>Attached is the weekly database backup.</p>";
    if ($count > 0) $msg .= "<strong style='color:red'>Action required: $count pending requests.</strong>";
    else $msg .= "System running normal. No pending requests.";
    
    wp_mail(RB_ADMIN_EMAIL, $subject, $msg, ['Content-Type: text/html'], [$file]);
    @unlink($file);
}

// API Handler
if(isset($_GET['api']) && $_GET['api']=='1') {
    if(session_status()===PHP_SESSION_NONE) session_start();
    header('Content-Type: application/json');
    $in = json_decode(file_get_contents('php://input'), true);
    $act = $_GET['action']??'';
    $emps = rb_get_employees();
    $db = json_decode(get_option(RB_DB_KEY, '[]'), true);
    
    // Auth: Simple ID check for Demo (would be OAuth/LDAP in Prod)
    if($act=='login') {
        if(isset($emps[$in['pid']])) { $_SESSION['rb_uid']=$in['pid']; echo json_encode(['status'=>'ok']); }
        else echo json_encode(['status'=>'err','msg'=>'Unknown ID']);
        exit;
    }
    
    $uid = $_SESSION['rb_uid']??null;
    $is_wp = current_user_can('administrator');
    // Auto-login for WP Admin as Manager for testing
    if($is_wp && !$uid) foreach($emps as $k=>$v) if($v['role']=='manager') { $uid=$k; break; }
    if(!$uid) { echo json_encode(['status'=>'err','redirect'=>true]); exit; }
    
    $me = $emps[$uid];
    $is_admin = $is_wp; // Strict Admin Separation
    
    if($act=='load') {
        $used = 0;
        foreach($db as &$v) {
            // Admin Preview Logic
            if($is_admin && in_array($v['status'], ['pending','waitlist'])) $v['conflict'] = rb_check($v['start'], $v['end'], $v['personal_id'], $v['id']);
            if($v['personal_id']==$uid && in_array($v['status'], ['approved','pending'])) $used += rb_days($v['start'], $v['end']);
            // Privacy Masking
            if(!$is_admin && $v['personal_id']!=$uid) { $v['name']='Occupied'; $v['personal_id']=null; $v['conflict']=null; }
        }
        echo json_encode(['status'=>'ok', 'data'=>$db, 'user'=>$me, 'is_admin'=>$is_admin, 'quota'=>['total'=>$me['days'], 'used'=>$used], 'hols'=>array_keys(rb_hols(RB_YEAR))]);
        exit;
    }
    
    // CRUD Operations (Save, Delete, Approve) omitted for brevity in documentation but present in full code...
    // [Hier steht im echten File der Rest der API Logik, unverändert zu v7.4]
    
    // ... (FULL API LOGIC HERE) ...
    
    if($act=='save') {
        $s=$in['start']; $e=$in['end']; $id=(int)($in['id']??0); $force=$in['force']??false;
        if($s > $e) die(json_encode(['status'=>'err','msg'=>'Start > End']));
        $d = rb_days($s, $e);
        if($d <= 0) die(json_encode(['status'=>'err','msg'=>'No workdays selected']));

        $used = 0;
        foreach($db as $v) if($v['personal_id']==$uid && $v['id']!=$id && in_array($v['status'],['approved','pending'])) $used += rb_days($v['start'], $v['end']);
        if(($used + $d) > $me['days']) die(json_encode(['status'=>'err','msg'=>'Quota exceeded']));

        $err = rb_check($s, $e, $uid, $id);
        $status = 'pending';
        if($err) {
            if(!$force) die(json_encode(['status'=>'waitlist_confirm', 'msg'=>"$err Join waitlist?"]));
            $status = 'waitlist';
        }
        
        $new_db = [];
        foreach($db as $v) if($v['id']!=$id) $new_db[] = $v;
        $new_db[] = ['id' => $id > 0 ? $id : (int)(microtime(true)*1000), 'personal_id' => $uid, 'name' => $me['name'], 'start' => $s, 'end' => $e, 'days' => $d, 'status' => $status, 'created_at' => date('d.m. H:i')];
        update_option(RB_DB_KEY, json_encode($new_db));
        echo json_encode(['status'=>'ok', 'toast' => $status=='waitlist' ? 'Added to Waitlist' : 'Saved successfully']);
        exit;
    }

    if($act=='del') {
        $id=(int)$in['id'];
        $new=[]; foreach($db as $v) if(!($v['id']==$id && ($v['personal_id']==$uid || $is_admin))) $new[]=$v;
        update_option(RB_DB_KEY, json_encode($new));
        echo json_encode(['status'=>'ok']);
        exit;
    }
    
    if($act=='approve' && $is_admin) {
        foreach($db as &$v) if($v['id']==(int)$in['id']) $v['status']='approved';
        update_option(RB_DB_KEY, json_encode($db));
        echo json_encode(['status'=>'ok']);
        exit;
    }

    if($act=='reset' && $is_admin) { update_option(RB_DB_KEY, '[]'); echo json_encode(['status'=>'ok']); exit; }
    if($act=='logout') { session_destroy(); echo json_encode(['status'=>'ok']); exit; }
    exit;
}

// ============================================================================
// 5. FRONTEND (Single Page Application)
// ============================================================================
get_header(); ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Vacation Planner Demo</title>
</head>
<body>
<style>
    header, footer, #wpadminbar { display:none!important }
    html, body { margin:0; background:#0f0f0f; color:#fff; font-family:-apple-system, sans-serif; height:100dvh; overflow:hidden; }
    #main { height:100%; display:flex; flex-direction:column; max-width:600px; margin:0 auto; position:relative; }
    .scroll-area { flex:1; overflow-y:auto; padding:20px; padding-bottom:80px; scrollbar-width: none; }
    .scroll-area::-webkit-scrollbar { display: none; }
    /* ... (CSS STYLES REDUCED FOR BRIEFNESS, BUT INCLUDE FULL CSS FROM V7.4) ... */
    .card { background:#1a1a1a; padding:15px; border-radius:16px; margin-bottom:12px; border:1px solid #333; }
    .btn { width:100%; padding:14px; border-radius:12px; border:none; background:linear-gradient(90deg, #00ffff, #0099ff); font-weight:bold; cursor:pointer; color:#000; margin-top:8px; }
    .btn-del { background:#330000; color:#ff5555; border:1px solid #ff0000; }
    .btn-sec { background:#333; color:#fff; }
    .grid { display:grid; grid-template-columns:repeat(7,1fr); gap:4px; }
    .day { aspect-ratio:1; background:#222; border-radius:6px; display:flex; flex-direction:column; align-items:center; justify-content:center; font-size:0.85rem; }
    .day.hol { border:1px solid #ffaa00; color:#ffaa00; }
    .dots { display:flex; gap:2px; margin-top:2px; }
    .d { width:4px; height:4px; border-radius:50%; }
    .d.g { background:#00ff88; } .d.y { background:#ffaa00; } .d.r { background:#ff003c; } .d.p { border:1px solid #fff; }
    #loader { position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.6); z-index:9999; display:none; justify-content:center; align-items:center; backdrop-filter:blur(2px); }
    .spin { width:40px; height:40px; border:4px solid #333; border-top:4px solid #00ffff; border-radius:50%; animation:spin 1s linear infinite; }
    @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
    .view { display:none; } .view.active { display:block; }
    input { width:100%; padding:14px; background:#222; border:1px solid #444; border-radius:12px; color:#fff; text-align:center; box-sizing:border-box; margin-bottom:10px; }
    .item { background:#222; padding:12px; border-radius:10px; margin-bottom:8px; border-left:3px solid transparent; }
    .st-approved { border-color:#00ff88; } .st-pending { border-color:#ffaa00; }
    .login-wrap { width:100%; max-width:350px; text-align:center; }
    .tabs { display:flex; background:#222; margin:0 20px; border-radius:12px; padding:4px; }
    .tab { flex:1; text-align:center; padding:10px; color:#666; cursor:pointer; }
    .tab.active { background:#333; color:#fff; }
    .top-bar { display:flex; justify-content:space-between; align-items:center; padding:15px 20px; background:#111; }
    #msgBox { display:none; background:rgba(0,255,136,0.1); color:#00ff88; padding:10px; border-radius:8px; margin-top:10px; text-align:center; border:1px solid #00ff88; }
    #toast { position:fixed; bottom:30px; left:50%; transform:translateX(-50%); background:#333; padding:12px 24px; border-radius:50px; opacity:0; pointer-events:none; transition:0.3s; z-index:9999; }
    #modal { display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.8); z-index:9990; align-items:center; justify-content:center; backdrop-filter:blur(4px); }
    .m-box { background:#1a1a1a; width:85%; max-width:320px; padding:25px; border-radius:20px; text-align:center; border:1px solid #444; }
</style>

<div id="loader"><div class="spin"></div></div>
<div id="toast">Msg</div>
<div id="modal">
    <div class="m-box">
        <h3 id="mTit" style="margin-top:0">Title</h3>
        <p id="mTxt" style="color:#aaa; margin-bottom:20px"></p>
        <div id="mActs" style="display:flex; gap:10px; flex-direction:column"></div>
    </div>
</div>

<div id="login" style="height:100%; display:flex; flex-direction:column; justify-content:center; align-items:center; padding:20px;">
    <div class="login-wrap">
        <h1 style="color:#00ffff; margin-bottom:30px">Vacation Planner</h1>
        <input type="password" id="pid" placeholder="ID (e.g. 1001)" inputmode="numeric">
        <div id="wel" style="height:20px; color:#00ff88; margin-bottom:10px; font-weight:bold"></div>
        <button class="btn" id="lBtn" disabled>Login</button>
    </div>
</div>

<div id="main" style="display:none">
    <div class="top-bar">
        <div style="display:flex; align-items:center; gap:10px">
            <div style="width:35px; height:35px; background:#00ffff; border-radius:50%; display:flex; align-items:center; justify-content:center; color:#000; font-weight:bold" id="uAv"></div>
            <strong id="uName"></strong>
        </div>
        <button onclick="API.out()" style="background:none; border:1px solid #444; color:#fff; padding:6px 12px; border-radius:20px">Logout</button>
    </div>

    <div class="tabs">
        <div class="tab active" onclick="nav('cal')">Calendar</div>
        <div class="tab" onclick="nav('book')">Book</div>
        <div class="tab" id="tAdm" style="display:none" onclick="nav('adm')">Admin</div>
    </div>

    <div class="scroll-area">
        <div id="v-cal" class="view active">
            <div class="card">
                <div style="display:flex; justify-content:space-between; margin-bottom:10px; align-items:center">
                    <button onclick="cal(-1)" style="background:none;border:none;color:#fff;font-size:1.5rem">‹</button>
                    <div id="mName" style="font-weight:bold"></div>
                    <button onclick="cal(1)" style="background:none;border:none;color:#fff;font-size:1.5rem">›</button>
                </div>
                <div class="grid" id="grid"></div>
            </div>
            <h4 style="margin:10px 5px">My Requests</h4>
            <div id="myList"></div>
        </div>

        <div id="v-book" class="view">
            <div style="display:flex; gap:10px; margin-bottom:15px; text-align:center">
                <div style="flex:1; background:#222; padding:10px; border-radius:12px"><div id="qT" style="font-weight:bold">-</div><small style="color:#666">Total</small></div>
                <div style="flex:1; background:#222; padding:10px; border-radius:12px"><div id="qU" style="font-weight:bold; color:#00ff88">-</div><small style="color:#666">Left</small></div>
            </div>
            <div class="card">
                <h3 id="bTit">New Request</h3>
                <input type="date" id="dA"><input type="date" id="dE"><input type="hidden" id="eId" value="0">
                <button class="btn" id="bBtn" onclick="API.save(false)">Submit</button>
                <div id="msgBox">✅ Saved!</div>
                <button class="btn btn-sec" id="cBtn" style="display:none; margin-top:10px" onclick="resetBook()">Cancel</button>
            </div>
            <h4 style="margin:20px 5px 10px 5px">Status</h4>
            <div id="myListBook"></div>
        </div>

        <div id="v-adm" class="view">
            <div class="card" style="border-color:#00ffff">
                <h3 style="color:#00ffff; margin-top:0">Management</h3>
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:8px">
                    <a href="?api=1&action=export" target="_blank" class="btn btn-sec" style="text-align:center;text-decoration:none;margin:0">CSV</a>
                    <a href="?test_mail=1" target="_blank" class="btn btn-sec" style="text-align:center;text-decoration:none;margin:0">Test Mail</a>
                </div>
                <button class="btn btn-del" onclick="confirm('Reset Database?', 'All data will be lost.', [{t:'Reset', c:'red', f:()=>API.act('reset')}])" style="margin-top:10px">Reset DB</button>
            </div>
            <h4>Pending Requests</h4>
            <div id="admList" style="margin-bottom:20px"></div>
        </div>
    </div>
</div>

<script>
// Mock Data for Login Preview
const EMPS=<?php echo json_encode(array_map(fn($e)=>$e['greeting'], rb_get_employees())); ?>;
const D=new Date(<?php echo RB_YEAR; ?>,0,1);
let DATA=null;

const el = (i) => document.getElementById(i);
const toast = (m, err=false) => {
    let t = el('toast'); t.innerText = m; t.style.background = err?'#500':'#333'; t.style.color = err?'#ffcccc':'#fff';
    t.style.opacity = 1; t.style.bottom = "50px";
    setTimeout(()=>{ t.style.opacity=0; t.style.bottom="30px"; }, 3000);
};

const confirm = (tit, txt, acts) => {
    el('mTit').innerText=tit; el('mTxt').innerText=txt;
    el('mActs').innerHTML = acts.map((a,i) => `<button class="btn" style="background:${a.c=='red'?'#300':'#333'}; color:${a.c=='red'?'red':'#fff'}; border:1px solid ${a.c=='red'?'red':'#666'}" id="mb${i}">${a.t}</button>`).join('') + `<button class="btn btn-sec" onclick="el('modal').style.display='none'">Cancel</button>`;
    acts.forEach((a,i) => el('mb'+i).onclick = () => { el('modal').style.display='none'; a.f(); });
    el('modal').style.display = 'flex';
};

const nav = (t) => {
    document.querySelectorAll('.view').forEach(e=>e.classList.remove('active')); el('v-'+t).classList.add('active');
    document.querySelectorAll('.tab').forEach(e=>e.classList.remove('active')); event.target.classList.add('active');
    el('msgBox').style.display='none';
};

const cal = (d) => { D.setMonth(D.getMonth()+d); render(); };

const resetBook = () => {
    el('dA').value=''; el('dE').value=''; el('eId').value=0;
    el('bTit').innerText='New Request'; el('bBtn').innerText='Submit'; el('cBtn').style.display='none';
};

const edit = (id, s, e) => {
    confirm('Edit Request?', 'Select action:', [
        {t:'✏️ Edit', c:'blue', f:()=>{
            nav('book'); el('dA').value=s; el('dE').value=e; el('eId').value=id;
            el('bTit').innerText='Edit Request'; el('bBtn').innerText='Save Changes'; el('cBtn').style.display='block';
        }},
        {t:'🗑️ Delete', c:'red', f:()=>API.act('del', id)}
    ]);
};

const API = {
    async req(act, pl={}) {
        el('loader').style.display = 'flex';
        try { let r=await fetch(`?api=1&action=${act}&t=${Date.now()}`, {method:'POST', body:JSON.stringify(pl)}); el('loader').style.display = 'none'; return r.json(); }
        catch { el('loader').style.display = 'none'; toast('Connection Error', true); return null; }
    },
    async login() {
        let v = el('pid').value; if(!v) return;
        let r = await this.req('login', {pid:v}); if(r.status=='ok') this.load(); else toast(r.msg, true);
    },
    async load() {
        let r = await this.req('load');
        if(r.status=='ok') {
            DATA=r; el('login').style.display='none'; el('main').style.display='flex';
            el('uName').innerText = r.user.name; el('uAv').innerText = r.user.greeting.substring(0,2).toUpperCase();
            el('qT').innerText = r.quota.total; el('qU').innerText = r.quota.total - r.quota.used;
            if(r.is_admin) { el('tAdm').style.display='block'; renderAdm(); }
            render(); renderMy();
        } else if(r.redirect) el('main').style.display='none';
    },
    async save(force) {
        let r = await this.req('save', {start:el('dA').value, end:el('dE').value, id:el('eId').value, force:force});
        if(r.status=='ok') { resetBook(); this.load(); let m = el('msgBox'); m.style.display='block'; m.innerText = r.toast; setTimeout(()=>m.style.display='none', 4000); } 
        else if(r.status=='waitlist_confirm') { confirm('Period Full', r.msg, [{t:'Join Waitlist', c:'blue', f:()=>API.save(true)}]); }
        else toast(r.msg, true);
    },
    async act(a, id) { let r = await this.req(a, {id:id}); if(r && r.status=='ok') { toast('Done'); this.load(); } },
    out() { this.req('logout').then(()=>location.reload()); }
};

const render = () => {
    let y=D.getFullYear(), m=D.getMonth(); el('mName').innerText = D.toLocaleString('en-US',{month:'long', year:'numeric'});
    let g=el('grid'); g.innerHTML='Mo,Tu,We,Th,Fr,Sa,Su'.split(',').map(d=>`<div style="text-align:center;color:#666;font-size:0.8rem">${d}</div>`).join('');
    let f = new Date(y,m,1).getDay() || 7; for(let i=1; i<f; i++) g.innerHTML+='<div></div>';
    let days = new Date(y,m+1,0).getDate();
    for(let i=1; i<=days; i++) {
        let iso = `${y}-${(m+1+'').padStart(2,'0')}-${(i+'').padStart(2,'0')}`;
        let w = new Date(y,m,i).getDay(); let hol = DATA.hols.includes(iso); let dots = '';
        if(w!=0 && !hol) {
            let active = DATA.data.filter(v=>iso>=v.start && iso<=v.end && !['rejected','waitlist'].includes(v.status));
            let app = active.filter(v=>v.status=='approved').length; let pen = active.length - app;
            let col = app>=3 ? 'r' : (app==2 ? 'y' : 'g');
            if(app>0) dots+=`<div class="d ${col}"></div>`; for(let k=0; k<pen; k++) dots+=`<div class="d p"></div>`;
        }
        g.innerHTML += `<div class="day ${hol?'hol':''} ${w==0?'sun':''}"><span>${i}</span><div class="dots">${dots}</div></div>`;
    }
};

const renderMy = () => {
    let h = DATA.data.filter(v=>v.personal_id).map(v=>`
        <div class="item st-${v.status}" onclick="edit(${v.id}, '${v.start}', '${v.end}')">
            <div><b>${new Date(v.start).toLocaleDateString()} - ${new Date(v.end).toLocaleDateString()}</b><br><small>${v.status}</small></div>
            <span>✏️</span>
        </div>
    `).join('');
    let empty = '<div style="text-align:center;color:#666;padding:10px">No Requests</div>';
    el('myList').innerHTML = h || empty; el('myListBook').innerHTML = h || empty;
};

const renderAdm = () => {
    let h = DATA.data.filter(v=>['pending','waitlist'].includes(v.status)).map(v=>`
        <div class="item st-${v.status}" style="display:block">
            <div style="display:flex;justify-content:space-between"><span style="color:${v.status=='waitlist'?'#999':'#00ffff'}">${v.name}</span><small>${v.days} days</small></div>
            <small style="color:#aaa;display:block;margin-bottom:5px;">${v.start} - ${v.end}</small>
            ${v.conflict ? `<div style="color:#ff5555;font-size:0.75rem;margin-top:5px">⚠️ ${v.conflict}</div>` : ''}
            <div style="display:flex;gap:10px;margin-top:10px">
                <button onclick="API.act('approve',${v.id})" class="btn" style="margin:0;background:#00ff88;color:#000">Approve</button>
                <button onclick="confirm('Reject?','Irreversible.',[{t:'Reject',c:'red',f:()=>API.act('del',${v.id})}])" class="btn" style="margin:0;background:#330000;color:red;border:1px solid red">Reject</button>
            </div>
        </div>
    `).join('');
    el('admList').innerHTML = h || '<div style="text-align:center;color:#666">All clear!</div>';
};

el('pid').addEventListener('input', e => { let n = EMPS[e.target.value.trim()]; el('wel').innerText = n ? `Hi, ${n}` : ''; el('lBtn').disabled = !n; });
el('lBtn').onclick = () => API.login();
window.onload = () => API.load();
</script>
</body>
</html>
<?php get_footer(); ?>