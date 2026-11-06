<?php
/**
 * app/views/layout/head.php
 *
 * The <head> of EVERY screen page: the sign-in card pages (auth-top.php) and the
 * app-shell pages (header.php) both include this one file, so the title, favicon,
 * viewport and stylesheet are written once.
 *
 * Needs $pageTitle (set by the page before it includes a layout file).
 * The print page (Step 8) has its own head on purpose and does not use this file.
 */

$pageTitle = $pageTitle ?? 'ZCMC Evaluation Form System';
?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="#168049">
    <title><?= e($pageTitle) ?> - ZCMC Evaluation Form System</title>
    <link rel="icon" type="image/png" href="<?= e(asset('img/zcmc.png')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
