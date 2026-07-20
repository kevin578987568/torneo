<?php
// index.php - Single-file implementation for torneo features
// - Teams table with explanatory text
// - Profile panel: Agregar equipo / Crear equipo for judge/participant/admin
// - Admin panel: Desbaneo button in PANEL DE ADMINISTRACIÓN
// Uses SQLite (data.db) in same directory. Simple auth via header 'X-User-Id' or query param 'x-user-id'.

header('Content-Type: text/html; charset=utf-8');

// Simple router: if ?action=... then serve API JSON responses
$action = isset($_GET['action']) ? $_GET['action'] : null;

// Database connection
function get_db() {
    $db = new PDO('sqlite:' . __DIR__ . '/data.db');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    return $db;
}

function init_db_if_needed() {
    $db = get_db();
    // Create tables if not exist
    $db->exec("PRAGMA foreign_keys = ON;
    CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        role TEXT NOT NULL,
        banned INTEGER DEFAULT 0
    );
    CREATE TABLE IF NOT EXISTS teams (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        country TEXT,
        created_at TEXT DEFAULT (datetime('now'))
    );
    CREATE TABLE IF NOT EXISTS team_members (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        team_id INTEGER NOT NULL,
        user_id INTEGER NOT NULL,
        FOREIGN KEY(team_id) REFERENCES teams(id) ON DELETE CASCADE,
        FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
    );");

    // Seed sample data if empty
    $row = $db->query("SELECT COUNT(*) as c FROM users")->fetch(PDO::FETCH_ASSOC);
    if ($row['c'] == 0) {
        $insertUser = $db->prepare('INSERT INTO users (name, role, banned) VALUES (?, ?, ?);');
        $insertUser->execute(['Admin Usuario', 'administrador', 0]);
        $insertUser->execute(['Juez Usuario', 'juez', 0]);
        $insertUser->execute(['Participante 1', 'participante', 0]);
        $insertUser->execute(['Baneado User', 'participante', 1]);

        $insertTeam = $db->prepare('INSERT INTO teams (name, country) VALUES (?, ?)');
        $insertTeam->execute(['Equipo Rayo', 'España']);
        $insertTeam->execute(['Los Titanes', 'México']);

        $insertMember = $db->prepare('INSERT INTO team_members (team_id, user_id) VALUES (?, ?)');
        $insertMember->execute([1, 3]);
    }
}

// Get current user from header or query param
function get_current_user() {
    $headers = getallheaders();
    $uid = null;
    if (isset($headers['X-User-Id'])) $uid = $headers['X-User-Id'];
    if (isset($_GET['x-user-id'])) $uid = $_GET['x-user-id'];
    if (isset($_POST['x-user-id'])) $uid = $_POST['x-user-id'];
    if (!$uid) return null;
    $db = get_db();
    $stmt = $db->prepare('SELECT id, name, role, banned FROM users WHERE id = ?');
    $stmt->execute([$uid]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    return $user ? $user : null;
}

// Utility JSON responder
function json_response($data, $status = 200) {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

// Initialize DB
init_db_if_needed();

if ($action) {
    // API endpoints
    if ($action === 'api_me') {
        $user = get_current_user();
        json_response(['user' => $user]);
    }

    if ($action === 'api_teams') {
        $db = get_db();
        $stmt = $db->query("SELECT t.id, t.name, t.country, t.created_at,
            (SELECT COUNT(*) FROM team_members m WHERE m.team_id = t.id) as members
            FROM teams t ORDER BY t.id");
        $teams = $stmt->fetchAll(PDO::FETCH_ASSOC);
        json_response(['teams' => $teams]);
    }

    if ($action === 'create_team') {
        // POST
        $user = get_current_user();
        if (!$user) json_response(['error' => 'Autenticación requerida (X-User-Id).'], 401);
        $input = json_decode(file_get_contents('php://input'), true);
        $name = isset($input['name']) ? trim($input['name']) : null;
        $country = isset($input['country']) ? trim($input['country']) : null;
        if (!$name) json_response(['error' => 'name es requerido'], 400);
        $db = get_db();
        $stmt = $db->prepare('INSERT INTO teams (name, country) VALUES (?, ?)');
        $stmt->execute([$name, $country]);
        $teamId = $db->lastInsertId();
        // add creator as member
        $m = $db->prepare('INSERT INTO team_members (team_id, user_id) VALUES (?, ?)');
        $m->execute([$teamId, $user['id']]);
        json_response(['teamId' => (int)$teamId], 201);
    }

    if ($action === 'add_member') {
        // POST
        $user = get_current_user();
        if (!$user) json_response(['error' => 'Autenticación requerida (X-User-Id).'], 401);
        $input = json_decode(file_get_contents('php://input'), true);
        $teamId = isset($input['teamId']) ? $input['teamId'] : null;
        $userId = isset($input['userId']) ? $input['userId'] : null;
        if (!$teamId || !$userId) json_response(['error' => 'teamId y userId son requeridos'], 400);
        $db = get_db();
        // check exists
        $check = $db->prepare('SELECT 1 FROM team_members WHERE team_id = ? AND user_id = ?');
        $check->execute([$teamId, $userId]);
        if ($check->fetch()) json_response(['error' => 'Ya es miembro'], 400);
        $ins = $db->prepare('INSERT INTO team_members (team_id, user_id) VALUES (?, ?)');
        $ins->execute([$teamId, $userId]);
        json_response(['ok' => true]);
    }

    if ($action === 'admin_users') {
        $user = get_current_user();
        if (!$user) json_response(['error' => 'Autenticación requerida (X-User-Id).'], 401);
        if ($user['role'] !== 'administrador') json_response(['error' => 'Permiso denegado.'], 403);
        $db = get_db();
        $stmt = $db->query('SELECT id, name, role, banned FROM users ORDER BY id');
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
        json_response(['users' => $users]);
    }

    if ($action === 'admin_unban') {
        // POST
        $user = get_current_user();
        if (!$user) json_response(['error' => 'Autenticación requerida (X-User-Id).'], 401);
        if ($user['role'] !== 'administrador') json_response(['error' => 'Permiso denegado.'], 403);
        $input = json_decode(file_get_contents('php://input'), true);
        $userId = isset($input['userId']) ? $input['userId'] : null;
        if (!$userId) json_response(['error' => 'userId requerido'], 400);
        $db = get_db();
        $stmt = $db->prepare('UPDATE users SET banned = 0 WHERE id = ?');
        $stmt->execute([$userId]);
        json_response(['ok' => true]);
    }

    // Unknown action
    json_response(['error' => 'Acción desconocida'], 400);
}

// If no action specified, render the HTML UI
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width,initial-scale=1" />
<title>Torneo - Panel</title>
<style>
body{font-family:Arial,Helvetica,sans-serif;background:#f7f7f8;color:#222;padding:18px}
.container{max-width:980px;margin:0 auto}
.panels{display:flex;gap:20px;margin-bottom:20px}
.panel{background:#fff;padding:12px;border-radius:6px;box-shadow:0 1px 3px rgba(0,0,0,0.08);width:48%}
.table{width:100%;border-collapse:collapse;background:#fff;margin-top:16px}
.table th,.table td{padding:8px 10px;border-bottom:1px solid #eee;text-align:left}
.small-muted{color:#6c757d;font-size:0.9rem;margin-top:8px}
button{cursor:pointer;padding:6px 10px;border-radius:4px}
.btn-primary{background:#0d6efd;color:#fff;border:none}
.btn-outline{background:#fff;border:1px solid #0d6efd;color:#0d6efd}
</style>
</head>
<body>
<div class="container">
  <h1>Torneo — Panel</h1>
  <div style="margin-bottom:12px">Usuario actual (x-user-id):
    <select id="userSelect">
      <option value="1">1 - Admin Usuario</option>
      <option value="2">2 - Juez Usuario</option>
      <option value="3">3 - Participante 1</option>
      <option value="4">4 - Baneado User</option>
    </select>
  </div>

  <div class="panels">
    <div class="panel" id="profilePanel">
      <h3>Mi perfil</h3>
      <div id="profileContent">Cargando perfil...</div>
    </div>

    <div class="panel" id="adminPanel">
      <h3>PANEL DE ADMINISTRACIÓN</h3>
      <div id="adminContent">Cargando...</div>
    </div>
  </div>

  <div>
    <h2>Equipos</h2>
    <table class="table" id="teamsTable">
      <thead><tr><th>ID</th><th>Nombre</th><th>País</th><th>Miembros</th><th>Creado</th></tr></thead>
      <tbody id="teamsBody"></tbody>
    </table>

    <div class="small-muted" style="margin-top:8px">
      <strong>Significado de las columnas:</strong>
      <ul>
        <li><strong>ID</strong>: Identificador interno del equipo.</li>
        <li><strong>Nombre</strong>: Nombre del equipo.</li>
        <li><strong>País</strong>: País de origen del equipo (si se proporcionó).</li>
        <li><strong>Miembros</strong>: Número de participantes pertenecientes al equipo.</li>
        <li><strong>Creado</strong>: Fecha y hora en que se creó el registro del equipo.</li>
      </ul>
    </div>
  </div>
</div>

<script>
const apiBase = './index.php';
let currentUserId = document.getElementById('userSelect').value;
document.getElementById('userSelect').addEventListener('change', e => { currentUserId = e.target.value; loadAll(); });

async function api(action, method='GET', body=null) {
  const opts = { method, headers: { 'X-User-Id': currentUserId } };
  if (body) { opts.headers['Content-Type'] = 'application/json'; opts.body = JSON.stringify(body); }
  const res = await fetch(apiBase + '?action=' + encodeURIComponent(action), opts);
  const txt = await res.text();
  try { return { status: res.status, ok: res.ok, data: JSON.parse(txt) }; } catch(e) { return { status: res.status, ok: res.ok, data: txt }; }
}

async function loadProfile() {
  const r = await api('api_me');
  const el = document.getElementById('profileContent');
  if (!r.ok) {
    el.innerHTML = '<p>Error al obtener perfil</p>';
    return;
  }
  const user = r.data.user;
  if (!user) {
    el.innerHTML = '<p>No autenticado. Selecciona un usuario arriba (x-user-id).</p>';
    return;
  }
  let html = `<p><strong>${escapeHtml(user.name)}</strong> — Rol: <em>${escapeHtml(user.role)}</em> ${user.banned? '(baneado)' : ''}</p>`;
  const canManageTeams = ['juez','participante','administrador'].includes(user.role);
  if (canManageTeams) {
    html += `<div><button class="btn-outline" onclick="openAddTeamModal()">Agregar equipo</button> <button class="btn-primary" style="margin-left:8px" onclick="openCreateTeamModal()">Crear equipo</button></div>`;
  } else {
    html += '<p class="small-muted">No tienes permisos para gestionar equipos.</p>';
  }
  el.innerHTML = html;
}

async function loadAdmin() {
  const el = document.getElementById('adminContent');
  const me = await api('api_me');
  const role = me.data.user ? me.data.user.role : null;
  if (role !== 'administrador') {
    el.innerHTML = '<p class="small-muted">Sección visible solo para administradores.</p>';
    return;
  }
  const r = await api('admin_users');
  if (!r.ok) { el.innerHTML = '<p>Error cargando usuarios</p>'; return; }
  const users = r.data.users;
  let html = '<table class="table"><thead><tr><th>ID</th><th>Nombre</th><th>Rol</th><th>Baneado</th><th>Acciones</th></tr></thead><tbody>';
  for (const u of users) {
    html += `<tr><td>${u.id}</td><td>${escapeHtml(u.name)}</td><td>${escapeHtml(u.role)}</td><td>${u.banned? 'Sí':'No'}</td><td>`;
    if (u.banned) html += `<button onclick="handleUnban(${u.id})">Desbaneo</button>`;
    else html += '<span class="small-muted">—</span>';
    html += '</td></tr>';
  }
  html += '</tbody></table>';
  el.innerHTML = html;
}

async function loadTeams() {
  const r = await api('api_teams');
  const body = document.getElementById('teamsBody');
  body.innerHTML = '';
  if (!r.ok) { body.innerHTML = '<tr><td colspan="5">Error cargando equipos</td></tr>'; return; }
  for (const t of r.data.teams) {
    const d = new Date(t.created_at);
    body.innerHTML += `<tr><td>${t.id}</td><td>${escapeHtml(t.name)}</td><td>${escapeHtml(t.country || '-')}</td><td>${t.members}</td><td>${escapeHtml(d.toLocaleString())}</td></tr>`;
  }
}

function escapeHtml(s){ if(!s && s!==0) return ''; return String(s).replace(/[&<>\\"']/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','\"':'&quot;',"'":"&#39;"}[c]; }); }

async function loadAll(){ await loadProfile(); await loadAdmin(); await loadTeams(); }

async function openCreateTeamModal(){
  const name = prompt('Nombre del equipo:');
  if (!name) return;
  const country = prompt('País (opcional):','');
  const r = await api('create_team', 'POST', { name, country });
  if (r.ok) { alert('Equipo creado con id ' + r.data.teamId); loadTeams(); } else { alert('Error: ' + JSON.stringify(r.data)); }
}

async function openAddTeamModal(){
  const teamId = prompt('ID del equipo al que quieres unirte:');
  const uid = prompt('ID del usuario a agregar (tu id por defecto):', currentUserId);
  if (!teamId || !uid) return;
  const r = await api('add_member', 'POST', { teamId: Number(teamId), userId: Number(uid) });
  if (r.ok) { alert('Miembro agregado'); loadTeams(); } else { alert('Error: ' + JSON.stringify(r.data)); }
}

async function handleUnban(uId) {
  if (!confirm('¿Confirmas desbanear a este usuario?')) return;
  const r = await api('admin_unban', 'POST', { userId: uId });
  if (r.ok) { alert('Usuario desbaneado'); loadAdmin(); } else { alert('Error: ' + JSON.stringify(r.data)); }
}

// Initial load
loadAll();
</script>
</body>
</html>
