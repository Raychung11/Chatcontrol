<?php
require_once __DIR__ . '/inc/auth.php';
logout_user();
redirect('/login.php');
