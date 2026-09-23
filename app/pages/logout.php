<?php
/** خروج امن از سامانه */

if (is_post()) {
    csrf_verify();
    auth_logout();
}
redirect(url('login'));
