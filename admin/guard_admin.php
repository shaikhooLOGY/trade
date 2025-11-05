<?php
// guard_admin.php — only logged-in admins may enter /admin/*
if (session_status() === PHP_SESSION_NONE) session_start();

if (empty($_SESSION['user_id']) || (int)($_SESSION['is_admin'] ?? 0) !== 1) {
  header('Location: /login.php');
  exit;
}