<?php
declare(strict_types=1);
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/commuter-auth.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// Recheck access on every account request so admin suspension revokes existing sessions.
$signedIn = commuter_account();
if ($signedIn !== null) {
    $access = db()->prepare('SELECT account_status FROM commuter_accounts WHERE id = ?');
    $access->execute([(int) $signedIn['id']]);
    if ($access->fetchColumn() !== 'active') logout_commuter_account();
}

function commuter_auth_response(int $status, array $body): never
{
    http_response_code($status);
    echo json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'session') {
    $account = commuter_account();
    if ($account === null) commuter_auth_response(401, ['error' => 'Not signed in.']);
    commuter_auth_response(200, ['account' => $account, 'csrf_token' => csrf_token()]);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: GET, POST');
    commuter_auth_response(405, ['error' => 'Unsupported request.']);
}
$input = json_decode((string) file_get_contents('php://input'), true);
$action = $_GET['action'] ?? ($input['action'] ?? '');
try {
    if ($action === 'change-password') {
        if (commuter_account() === null) commuter_auth_response(401, ['error' => 'Please sign in again.']);
        if (!csrf_value_is_valid($input['csrf_token'] ?? null)) {
            commuter_auth_response(403, ['error' => 'Your session expired. Reload the page and try again.']);
        }
        [$status, $message] = change_commuter_password(is_array($input) ? $input : []);
        commuter_auth_response($status, [$status === 200 ? 'message' : 'error' => $message]);
    }
    if ($action === 'presence') {
        if (commuter_account() === null) commuter_auth_response(401, ['error' => 'Please sign in again.']);
        if (!csrf_value_is_valid($input['csrf_token'] ?? null)) {
            commuter_auth_response(403, ['error' => 'Your session expired. Reload the page and try again.']);
        }
        if (!record_commuter_activity()) commuter_auth_response(401, ['error' => 'Please sign in again.']);
        commuter_auth_response(200, ['status' => 'active']);
    }
    if ($action === 'register') {
        [$status, $message] = register_commuter_account($input ?? []);
        commuter_auth_response($status, ['message' => $message]);
    }
    if ($action === 'login') {
        $contentType = strtolower(trim(explode(';', (string) ($_SERVER['CONTENT_TYPE'] ?? ''))[0]));
        if ($contentType !== 'application/json') commuter_auth_response(415, ['error' => 'Send sign-in details as JSON.']);
        $login = login_commuter_account(is_array($input) ? $input : []);
        [$status, $message] = $login;
        commuter_auth_response($status, [
            $status === 200 ? 'message' : 'error' => $message,
            'account' => $status === 200 && ($login[2] ?? '') === 'commuter' ? commuter_account() : null,
            'portal' => $status === 200 ? ($login[2] ?? 'commuter') : null,
        ]);
    }
    if ($action === 'logout') {
        logout_commuter_account();
        commuter_auth_response(200, ['message' => 'Signed out.']);
    }
    if ($action === 'update-name') {
        [$status, $message, $account] = update_commuter_account_name($input['full_name'] ?? null);
        commuter_auth_response($status, ['message' => $message, 'account' => $account ?? null]);
    }
    commuter_auth_response(400, ['error' => 'Unsupported authentication action.']);
} catch (InvalidArgumentException $error) {
    commuter_auth_response(422, ['error' => $error->getMessage()]);
} catch (Throwable $error) {
    error_log('Commuter authentication: ' . $error->getMessage());
    commuter_auth_response(503, ['error' => 'Account service is temporarily unavailable. Please try again.']);
}
