<?php

require __DIR__ . '/../../../app/includes/bootstrap.php';
require_once __DIR__ . '/../../../app/functions/devices.php';

header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_error('METHOD_NOT_ALLOWED', 'Gunakan method GET', 405);
}

if (array_key_exists('duration_hours', $_GET)) {
    json_error('VALIDATION_ERROR', 'Gunakan duration_days untuk durasi sewa harisn', 422);
}

$rawStartTime = $_GET['start_time'] ?? null;

if (!is_string($rawStartTime)) {
    json_error('VALIDATION_ERROR', 'start_time wajib diisi berupa teks', 422);
}

$timeZone = new DateTimeZone('Asia/Jakarta');

$startTime = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s', $rawStartTime, $timeZone);

if ($startTime === false || $startTime->format('Y-m-d\TH:i:s') !== $rawStartTime) {
    json_error('VALIDATION_ERROR', 'Format start_time harus YYYY-MM-DDTHH:MM:SS dalam WIB', 422);
}

//Menyamakan toleransi waktu dengan endpoint create.php
$earlierStart = (new DateTimeImmutable('now', $timeZone))->modify('-5 minutes');

if ($startTime < $earlierStart) {
    json_error('VALIDATION_ERROR', 'Waktu mulai tidak boleh lebih dari 5 menit di masa lalu', 422);
}

$rawDuration = $_GET['duration_days'] ?? null;

$durationDays = is_string($rawDuration) ? filter_var($rawDuration, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 7]]) : false;

if ($durationDays === false) {
    json_error('VALIDATION_ERROR', 'duration_days harus berupa bilangan bulat antara 1 sampai 7', 422);
}

$category = $_GET['category'] ?? null;

if ($category !== null && (!is_string($category) || !in_array($category, ['PS4', 'PS5'], true))) {
    json_error('VALIDATION_ERROR', 'category harus PS4 atau PS5', 422);
}

$durationHours = $durationDays * 24;
$endTime = $startTime->modify("+{$durationHours} hours");

try {
    $devices = get_available_devices($pdo, $startTime, $endTime, $category);

    json_response([
        'success' => true,
        'data' => $devices,
        'meta' => [
            'start_time' => $startTime->format('Y-m-d\TH:i:sP'),
            'end_time' => $endTime->format('Y-m-d\TH:i:sP'),
            'duration_days' => $durationDays,
            'duration_hours' => $durationHours,
            'total' => count($devices),
        ],
    ]);
} catch (PDOException $e) {
    error_log('Device Availability API Error: ' . $e->getMessage());
    json_error('SERVER_ERROR', 'Ketersediaan device gagal diambil', 500);
}