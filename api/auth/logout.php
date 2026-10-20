<?php
require_once __DIR__ . '/../../includes/auth.php';
startSession();
requirePostRequest();
if (!csrfRequestIsValid()) { http_response_code(403); exit('Your form session expired. Reload and try again.'); }
logoutUser();

header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
header('Location: ' . rtrim(appBaseUrl(), '/') . '/index.php?out=1');
exit;
