<?php
// guard_admin.php — only logged-in admins may enter /admin/*
require_once __DIR__ . '/../includes/bootstrap.php';

if (empty($_SESSION['user_id']) || (int)($_SESSION['is_admin'] ?? 0) !== 1) {
  header('Location: /login.php');
  exit;
}