<?php
function positiveInteger($value, string $label, int $max = PHP_INT_MAX): int {
    $number = (is_int($value) || is_string($value)) ? filter_var($value, FILTER_VALIDATE_INT) : false;
    if ($number === false || $number < 1 || $number > $max) throw new InvalidArgumentException($label . ' must be a whole number between 1 and ' . $max . '.');
    return $number;
}
function nonNegativeAmount($value, string $label): float {
    if (!is_scalar($value) || !is_numeric($value) || !is_finite((float)$value) || (float)$value < 0 || (float)$value > 99999999) throw new InvalidArgumentException($label . ' must be a valid non-negative amount.');
    return round((float)$value, 2);
}
function validCalendarDate($value): string {
    if (!is_string($value)) throw new InvalidArgumentException('Enter a valid production date.');
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    if (!$date || $date->format('Y-m-d') !== $value) throw new InvalidArgumentException('Enter a valid production date.');
    return $value;
}
function validateAccountFields($username, $password, $fullName): array {
    if (!is_string($username) || !preg_match('/^[A-Za-z0-9_.-]{3,50}$/', trim($username))) throw new InvalidArgumentException('Username must be 3–50 letters, numbers, dots, underscores or hyphens.');
    if (!is_string($password) || strlen($password) < 6 || strlen($password) > 72) throw new InvalidArgumentException('Password must be 6–72 characters.');
    if (!is_string($fullName) || strlen(trim($fullName)) < 2 || strlen(trim($fullName)) > 100) throw new InvalidArgumentException('Full name must be 2–100 characters.');
    return [trim($username), $password, trim($fullName)];
}
