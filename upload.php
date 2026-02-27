<?php
/**
 * RAZR Barbershop — Upload API
 * Plaatsen: zelfde map als index.html
 * Vereist: PHP 7.4+, map "uploads/" met schrijfrechten (chmod 755)
 */

// ─── CONFIGURATIE ───────────────────────────────────────────────
// Zelfde wachtwoord hash als in admin.html (SHA-256 van je wachtwoord)
// Standaard: razr2025
define('ADMIN_HASH', '69730d7be5bdac8c7794dfa8a8a905aa449ab7769462ebab72ab746e1f6dfeef');

define('UPLOAD_DIR',  __DIR__ . '/uploads/');
define('DATA_FILE',   __DIR__ . '/uploads/photos.json');
define('MAX_SIZE',    5 * 1024 * 1024); // 5MB
define('ALLOWED',     ['image/jpeg', 'image/png', 'image/webp', 'image/gif']);
// ────────────────────────────────────────────────────────────────

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Auth-Token');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200); exit;
}

// ─── AUTH CHECK ─────────────────────────────────────────────────
function checkAuth() {
    $token = $_SERVER['HTTP_X_AUTH_TOKEN'] ?? '';
    if (hash('sha256', $token) !== ADMIN_HASH) {
        // Token is zelf al een hash (vanuit admin.html)
        if ($token !== ADMIN_HASH) {
            http_response_code(401);
            echo json_encode(['error' => 'Niet geautoriseerd']);
            exit;
        }
    }
}

// ─── UPLOAD DIR AANMAKEN ────────────────────────────────────────
if (!is_dir(UPLOAD_DIR)) {
    mkdir(UPLOAD_DIR, 0755, true);
}
if (!file_exists(UPLOAD_DIR . '.htaccess')) {
    // Voorkom directe PHP uitvoering in uploads map
    file_put_contents(UPLOAD_DIR . '.htaccess', "php_flag engine off\n");
}

// ─── PHOTOS.JSON LEZEN/SCHRIJVEN ────────────────────────────────
function readPhotos() {
    if (!file_exists(DATA_FILE)) return [];
    $data = json_decode(file_get_contents(DATA_FILE), true);
    return is_array($data) ? $data : [];
}

function writePhotos(array $photos) {
    file_put_contents(DATA_FILE, json_encode(array_values($photos), JSON_PRETTY_PRINT));
}

// ─── ROUTES ─────────────────────────────────────────────────────
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// GET /upload.php?action=list — publiek, geen auth nodig
if ($method === 'GET' && $action === 'list') {
    echo json_encode(['photos' => readPhotos()]);
    exit;
}

// Alle andere acties vereisen auth
checkAuth();

// POST /upload.php?action=upload — foto uploaden
if ($method === 'POST' && $action === 'upload') {
    if (empty($_FILES['photo'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Geen bestand ontvangen']);
        exit;
    }

    $file  = $_FILES['photo'];
    $title = htmlspecialchars(trim($_POST['title'] ?? ''), ENT_QUOTES);
    $tag   = htmlspecialchars(trim($_POST['tag']   ?? 'Classic Cut'), ENT_QUOTES);

    // Validatie
    if ($file['error'] !== UPLOAD_ERR_OK) {
        http_response_code(400);
        echo json_encode(['error' => 'Upload fout: ' . $file['error']]);
        exit;
    }
    if ($file['size'] > MAX_SIZE) {
        http_response_code(400);
        echo json_encode(['error' => 'Bestand te groot (max 5MB)']);
        exit;
    }

    // MIME type checken via fileinfo (veiliger dan extensie)
    $finfo    = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!in_array($mimeType, ALLOWED)) {
        http_response_code(400);
        echo json_encode(['error' => 'Bestandstype niet toegestaan']);
        exit;
    }

    // Unieke bestandsnaam genereren
    $ext      = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'][$mimeType];
    $filename = uniqid('razr_', true) . '.' . $ext;
    $dest     = UPLOAD_DIR . $filename;

    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        http_response_code(500);
        echo json_encode(['error' => 'Opslaan mislukt, controleer maprechten']);
        exit;
    }

    // Toevoegen aan photos.json
    $photos = readPhotos();
    $entry  = [
        'id'    => uniqid('', true),
        'src'   => 'uploads/' . $filename,
        'title' => $title ?: pathinfo($file['name'], PATHINFO_FILENAME),
        'tag'   => $tag,
        'date'  => date('d/m/Y'),
    ];
    $photos[] = $entry;
    writePhotos($photos);

    echo json_encode(['success' => true, 'photo' => $entry]);
    exit;
}

// DELETE /upload.php?action=delete&id=xxx — foto verwijderen
if ($method === 'DELETE' && $action === 'delete') {
    $body = json_decode(file_get_contents('php://input'), true);
    $id   = $body['id'] ?? '';

    if (!$id) {
        http_response_code(400);
        echo json_encode(['error' => 'Geen ID opgegeven']);
        exit;
    }

    $photos  = readPhotos();
    $updated = [];
    $deleted = false;

    foreach ($photos as $photo) {
        if ($photo['id'] === $id) {
            // Bestand van server verwijderen
            $filepath = __DIR__ . '/' . $photo['src'];
            if (file_exists($filepath)) unlink($filepath);
            $deleted = true;
        } else {
            $updated[] = $photo;
        }
    }

    if (!$deleted) {
        http_response_code(404);
        echo json_encode(['error' => 'Foto niet gevonden']);
        exit;
    }

    writePhotos($updated);
    echo json_encode(['success' => true]);
    exit;
}

// POST /upload.php?action=reorder — volgorde aanpassen
if ($method === 'POST' && $action === 'reorder') {
    $body = json_decode(file_get_contents('php://input'), true);
    $ids  = $body['ids'] ?? [];

    if (!is_array($ids)) {
        http_response_code(400);
        echo json_encode(['error' => 'Ongeldige volgorde data']);
        exit;
    }

    $photos  = readPhotos();
    $indexed = [];
    foreach ($photos as $p) $indexed[$p['id']] = $p;

    $reordered = [];
    foreach ($ids as $id) {
        if (isset($indexed[$id])) $reordered[] = $indexed[$id];
    }
    // Voeg eventuele niet-meegestuurde foto's toe aan het einde
    foreach ($photos as $p) {
        if (!in_array($p['id'], $ids)) $reordered[] = $p;
    }

    writePhotos($reordered);
    echo json_encode(['success' => true]);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Onbekende actie']);
