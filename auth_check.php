<?php
session_start();
if (!isset($_SESSION['my_user_logged'])) {
    header("Location: login.php");
    exit;
}
