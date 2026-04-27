<?php
require_once __DIR__ . '/inc/auth.php';
if (current_user()) {
    redirect('/dashboard.php');
}
redirect('/login.php');
