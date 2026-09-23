<?php
/** خروج امن از سامانه */

if (is_post()) {
    csrf_verify();
    $u = auth_user();
    activity_log('logout', 'auth', $u['id'] ?? null, $u ? 'username=' . $u['username'] : null, $u['id'] ?? null);
    auth_logout();
}
redirect(url('login'));
