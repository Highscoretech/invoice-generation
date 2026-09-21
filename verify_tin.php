<?php
/**
 * Verify TIN — small JSON endpoint that proxies a TIN to the FIRS/NRS
 * verify-tin utility and returns a normalised result for the UI. Logged-in
 * operators only; the FIRS credentials never leave the server.
 */
require_once 'includes/auth.php';
require_once 'includes/FirsClient.php';

header('Content-Type: application/json');

$auth = new Auth();
if (!$auth->isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Not authenticated']);
    exit;
}

$tin = trim($_GET['tin'] ?? $_POST['tin'] ?? '');
if ($tin === '') {
    echo json_encode(['ok' => false, 'valid' => false, 'message' => 'Enter a TIN to verify.']);
    exit;
}

$res  = (new FirsClient())->verifyTin($tin);
$data = $res['body']['data'] ?? null;
$out  = ['ok' => $res['ok'], 'http' => $res['http'], 'tin' => $tin, 'valid' => false];

if ($res['ok']) {
    $name = $data['name'] ?? $data['taxpayer_name'] ?? $data['tax_payer_name']
          ?? $data['business_name'] ?? $data['entity_name'] ?? $data['party_name'] ?? null;
    $out['valid']   = true;
    $out['name']    = $name;
    $out['data']    = $data;
    $out['message'] = $name ? ('Valid — ' . $name) : 'TIN is valid.';
} elseif ((int) $res['http'] === 403) {
    // Same FIRS entity-authorisation gate as transmit.
    $out['message'] = 'FIRS has not authorised TIN verification for this entity yet (403). '
                    . 'Once FIRS grants access, this returns the taxpayer details.';
} elseif ((int) $res['http'] === 404 || (int) $res['http'] === 400) {
    $out['message'] = 'TIN not found or invalid.';
} else {
    $out['message'] = $res['error'] ?: ('Verification failed (HTTP ' . $res['http'] . ').');
}

echo json_encode($out);
