<?php
/**
 * TRIVIAX v4.0 — Cierre de sesión
 */
require_once __DIR__ . '/../php/auth.php';
triviax_logout();
header('Location: /triviax/auth/login.php');
exit;
