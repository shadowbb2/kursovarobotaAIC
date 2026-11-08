<?php
/*
settings for mysql connection
 */
const DB_HOST = 'localhost';
const DB_NAME = 'crm_agro';
const DB_USER = 'root';
const DB_PASS = '';

$dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

$pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
