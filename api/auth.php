<?php
// ── KitsByElbaa Auth API ──────────────────────────────────────
// Actions: register, login, logout, me, update_profile,
//          change_password, delete_account
// ────────────────────────────────────────────────────────────

// Hide PHP errors from the browser + buffer output so nothing corrupts the JSON.
require_once __DIR__ . '/../includes/json_guard.php';
kits_json_guard();

require_once __DIR__ . '/../includes/session.php';
kits_session_start('Lax', 60 * 120);

header('Content-Type: application/json');
$cfg = require __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/cors.php';
require_once __DIR__ . '/../includes/app_log.php';
require_once __DIR__ . '/../includes/auth_rules.php';
kits_emit_cors_headers($cfg, true);
header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Max-Age: 86400');
    while (ob_get_level() > 0) ob_end_clean();
    http_response_code(204);
    exit;
}

function jsonError(string $msg, int $code = 400): void {
    while (ob_get_level() > 0) ob_end_clean();
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}
function jsonOk(array $data = []): void {
    while (ob_get_level() > 0) ob_end_clean();
    echo json_encode(array_merge(['ok' => true], $data));
    exit;
}

// ── DB ──────────────────────────────────────────────────────
try {
    $pdo = kits_pdo($cfg);
} catch (PDOException $e) {
    kits_log('error', 'auth_db_connect_failed', ['error' => $e->getMessage()]);
    jsonError('Geen verbinding met de database. Probeer het later opnieuw.', 500);
}

$input  = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $input['action'] ?? ($_GET['action'] ?? '');

if (in_array($action, ['login', 'register'], true)) {
    require_once __DIR__ . '/../includes/rate_limit.php';
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    if (!kits_rate_limit_allow('auth_login_' . $ip, 80, 3600)) {
        jsonError('Te veel verzoeken. Probeer het later opnieuw.', 429);
    }
}

$mutatingActions = ['register','login','logout','update_profile','change_password','delete_account'];
if (in_array($action, $mutatingActions, true)) {
    $csrfHeader = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    $csrfSession = $_SESSION['csrf_token'] ?? '';
    if (!$csrfSession || !is_string($csrfHeader) || !hash_equals($csrfSession, $csrfHeader)) {
        jsonError('Ongeldige beveiligingstoken. Vernieuw de pagina en probeer opnieuw.', 403);
    }
}

// ── ACTIONS ──────────────────────────────────────────────────
switch ($action) {

    // ── WHO AM I ──────────────────────────────────────────────
    case 'me':
        if (empty($_SESSION['user_id'])) {
            echo json_encode(['ok' => true, 'user' => null]);
            exit;
        }
        $stmt = $pdo->prepare(
            "SELECT id, name, email, phone, street, zip, city, created_at FROM users WHERE id=? LIMIT 1"
        );
        $stmt->execute([(int)$_SESSION['user_id']]);
        $user = $stmt->fetch();
        echo json_encode(['ok' => true, 'user' => $user ?: null]);
        break;

    // ── REGISTER ──────────────────────────────────────────────
    case 'register':
        // Rate-limit: 5 failed attempts → 15 min lockout
        if (time() < (int)($_SESSION['auth_locked_until'] ?? 0)) {
            jsonError('Te veel pogingen. Wacht 15 minuten en probeer opnieuw.', 429);
        }

        $name  = trim($input['name'] ?? '');
        $email = kits_normalize_email((string)($input['email'] ?? ''));
        $pass  = $input['password'] ?? '';

        if (!$name || !$email || !$pass)
            jsonError('Naam, e-mail en wachtwoord zijn verplicht');
        if (mb_strlen($name) > 120)  jsonError('Naam is te lang');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) jsonError('Ongeldig e-mailadres');
        if (mb_strlen($email) > 254) jsonError('E-mailadres is te lang');
        if (($pwErr = kits_password_policy_error((string)$pass)) !== null) jsonError($pwErr);

        // Duplicate email check
        $ck = $pdo->prepare("SELECT id FROM users WHERE email=? LIMIT 1");
        $ck->execute([$email]);
        if ($ck->fetch()) jsonError('Er bestaat al een account met dit e-mailadres');

        $hash = password_hash($pass, PASSWORD_BCRYPT, ['cost' => 12]);
        $ins  = $pdo->prepare("INSERT INTO users (name, email, password_hash) VALUES (?, ?, ?)");
        $ins->execute([$name, $email, $hash]);
        $userId = (int)$pdo->lastInsertId();

        session_regenerate_id(true);
        $_SESSION['user_id']       = $userId;
        $_SESSION['auth_attempts'] = 0;

        $stmt = $pdo->prepare("SELECT id, name, email FROM users WHERE id=?");
        $stmt->execute([$userId]);
        jsonOk(['user' => $stmt->fetch()]);
        break;

    // ── LOGIN ─────────────────────────────────────────────────
    case 'login':
        if (time() < (int)($_SESSION['auth_locked_until'] ?? 0)) {
            jsonError('Te veel pogingen. Wacht 15 minuten en probeer opnieuw.', 429);
        }

        $email = kits_normalize_email((string)($input['email'] ?? ''));
        $pass  = $input['password'] ?? '';
        if (!$email || !$pass) jsonError('E-mail en wachtwoord zijn verplicht');

        $stmt = $pdo->prepare(
            "SELECT id, name, email, password_hash FROM users WHERE email=? LIMIT 1"
        );
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        // Always call password_verify to prevent timing attacks
        $dummy = '$2y$12$invalidhashfortimingprotectionXXXXXXXXXXXXXXXXXXXXXXXX';
        $valid = $user && password_verify($pass, $user['password_hash'] ?? $dummy);
        if (!$user) password_verify($pass, $dummy); // ensure constant time

        if (!$valid) {
            $_SESSION['auth_attempts'] = (int)($_SESSION['auth_attempts'] ?? 0) + 1;
            if ($_SESSION['auth_attempts'] >= 5) {
                $_SESSION['auth_locked_until'] = time() + 900;
            }
            jsonError('Onjuist e-mailadres of wachtwoord', 401);
        }

        session_regenerate_id(true);
        $_SESSION['user_id']       = (int)$user['id'];
        $_SESSION['auth_attempts'] = 0;
        unset($user['password_hash']);
        jsonOk(['user' => $user]);
        break;

    // ── LOGOUT ────────────────────────────────────────────────
    case 'logout':
        require_once __DIR__ . '/../includes/session.php';
        kits_destroy_session();
        jsonOk();
        break;

    // ── UPDATE PROFILE ────────────────────────────────────────
    case 'update_profile':
        if (empty($_SESSION['user_id'])) jsonError('Niet ingelogd', 401);

        $name   = trim($input['name']   ?? '');
        $phone  = trim($input['phone']  ?? '');
        $street = trim($input['street'] ?? '');
        $zip    = trim($input['zip']    ?? '');
        $city   = trim($input['city']   ?? '');

        if (!$name)              jsonError('Naam is verplicht');
        if (mb_strlen($name)   > 120) jsonError('Naam is te lang');
        if (mb_strlen($phone)  > 30)  jsonError('Telefoonnummer is te lang');
        if (mb_strlen($street) > 200) jsonError('Straat is te lang');
        if (mb_strlen($zip)    > 20)  jsonError('Postcode is te lang');
        if (mb_strlen($city)   > 100) jsonError('Plaats is te lang');

        $pdo->prepare(
            "UPDATE users SET name=?, phone=?, street=?, zip=?, city=?, updated_at=NOW() WHERE id=?"
        )->execute([$name, $phone ?: null, $street ?: null, $zip ?: null, $city ?: null, (int)$_SESSION['user_id']]);

        jsonOk();
        break;

    // ── CHANGE PASSWORD ───────────────────────────────────────
    case 'change_password':
        if (empty($_SESSION['user_id'])) jsonError('Niet ingelogd', 401);

        $current = $input['current_password'] ?? '';
        $new     = $input['new_password']     ?? '';
        if (!$current || !$new) jsonError('Beide wachtwoordvelden zijn verplicht');
        if (mb_strlen($new) < 8)   jsonError('Nieuw wachtwoord moet minimaal 8 tekens zijn');
        if (mb_strlen($new) > 128) jsonError('Nieuw wachtwoord is te lang');

        $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE id=? LIMIT 1");
        $stmt->execute([(int)$_SESSION['user_id']]);
        $u = $stmt->fetch();

        if (!$u || !password_verify($current, $u['password_hash'])) {
            jsonError('Huidig wachtwoord is onjuist', 401);
        }

        $pdo->prepare("UPDATE users SET password_hash=?, updated_at=NOW() WHERE id=?")->execute([
            password_hash($new, PASSWORD_BCRYPT, ['cost' => 12]),
            (int)$_SESSION['user_id'],
        ]);
        jsonOk();
        break;

    // ── DELETE ACCOUNT (GDPR right to erasure) ────────────────
    case 'delete_account':
        if (empty($_SESSION['user_id'])) jsonError('Niet ingelogd', 401);

        $pass = $input['password'] ?? '';
        if (!$pass) jsonError('Voer je wachtwoord in om je account te verwijderen');

        $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE id=? LIMIT 1");
        $stmt->execute([(int)$_SESSION['user_id']]);
        $u = $stmt->fetch();

        if (!$u || !password_verify($pass, $u['password_hash'])) {
            jsonError('Onjuist wachtwoord', 401);
        }

        $uid = (int)$_SESSION['user_id'];

        // GDPR: remove personal link from orders but keep order records
        // for 7-year Dutch tax-retention requirement
        $pdo->prepare("UPDATE orders SET user_id=NULL WHERE user_id=?")->execute([$uid]);

        // Delete the user record (removes name, email, phone, address)
        $pdo->prepare("DELETE FROM users WHERE id=?")->execute([$uid]);

        require_once __DIR__ . '/../includes/session.php';
        kits_destroy_session();
        jsonOk(['message' => 'Je account en alle persoonsgegevens zijn verwijderd.']);
        break;

    default:
        jsonError('Onbekende actie', 404);
}
