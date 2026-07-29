<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Small rule-based validator.
 *
 *   $v = new Validator($request->all());
 *   $v->required('full_name')->maxLen('full_name', 150)
 *     ->mobile('mobile')->numeric('amount')->min('amount', 1);
 *   if ($v->fails()) { ... $v->errors() ... }
 */
final class Validator
{
    /** @var array<string,string> */
    private array $errors = [];

    /** @param array<string,mixed> $data */
    public function __construct(private array $data)
    {
    }

    private function val(string $field): string
    {
        $v = $this->data[$field] ?? '';
        return is_scalar($v) ? trim((string) $v) : '';
    }

    private function err(string $field, string $message): void
    {
        // First error per field wins - clearer for the user.
        if (!isset($this->errors[$field])) {
            $this->errors[$field] = $message;
        }
    }

    private function label(string $field): string
    {
        return ucwords(str_replace('_', ' ', $field));
    }

    public function required(string $field, ?string $message = null): self
    {
        if ($this->val($field) === '') {
            $this->err($field, $message ?? $this->label($field) . ' is required.');
        }
        return $this;
    }

    public function requiredIf(string $field, bool $condition, ?string $message = null): self
    {
        return $condition ? $this->required($field, $message) : $this;
    }

    public function minLen(string $field, int $len, ?string $message = null): self
    {
        $v = $this->val($field);
        if ($v !== '' && mb_strlen($v) < $len) {
            $this->err($field, $message ?? $this->label($field) . " must be at least {$len} characters.");
        }
        return $this;
    }

    public function maxLen(string $field, int $len, ?string $message = null): self
    {
        $v = $this->val($field);
        if ($v !== '' && mb_strlen($v) > $len) {
            $this->err($field, $message ?? $this->label($field) . " must not exceed {$len} characters.");
        }
        return $this;
    }

    public function email(string $field, ?string $message = null): self
    {
        $v = $this->val($field);
        if ($v !== '' && filter_var($v, FILTER_VALIDATE_EMAIL) === false) {
            $this->err($field, $message ?? 'Enter a valid email address.');
        }
        return $this;
    }

    /** Indian mobile: exactly 10 digits starting 6-9 (after stripping +91). */
    public function mobile(string $field, ?string $message = null): self
    {
        $v = $this->val($field);
        if ($v === '') {
            return $this;
        }
        $digits = preg_replace('/\D+/', '', $v) ?? '';
        if (strlen($digits) > 10) {
            $digits = substr($digits, -10);
        }
        if (preg_match('/^[6-9]\d{9}$/', $digits) !== 1) {
            $this->err($field, $message ?? 'Enter a valid 10-digit mobile number.');
        }
        return $this;
    }

    public function numeric(string $field, ?string $message = null): self
    {
        $v = $this->val($field);
        if ($v !== '' && !is_numeric($v)) {
            $this->err($field, $message ?? $this->label($field) . ' must be a number.');
        }
        return $this;
    }

    public function integer(string $field, ?string $message = null): self
    {
        $v = $this->val($field);
        if ($v !== '' && preg_match('/^-?\d+$/', $v) !== 1) {
            $this->err($field, $message ?? $this->label($field) . ' must be a whole number.');
        }
        return $this;
    }

    public function min(string $field, float $min, ?string $message = null): self
    {
        $v = $this->val($field);
        if ($v !== '' && is_numeric($v) && (float) $v < $min) {
            $this->err($field, $message ?? $this->label($field) . ' must be at least ' . $min . '.');
        }
        return $this;
    }

    public function max(string $field, float $max, ?string $message = null): self
    {
        $v = $this->val($field);
        if ($v !== '' && is_numeric($v) && (float) $v > $max) {
            $this->err($field, $message ?? $this->label($field) . ' must not exceed ' . $max . '.');
        }
        return $this;
    }

    /** @param list<string> $allowed */
    public function inList(string $field, array $allowed, ?string $message = null): self
    {
        $v = $this->val($field);
        if ($v !== '' && !in_array($v, $allowed, true)) {
            $this->err($field, $message ?? $this->label($field) . ' has an invalid value.');
        }
        return $this;
    }

    public function date(string $field, ?string $message = null): self
    {
        $v = $this->val($field);
        if ($v !== '' && strtotime($v) === false) {
            $this->err($field, $message ?? $this->label($field) . ' must be a valid date.');
        }
        return $this;
    }

    public function latitude(string $field): self
    {
        $v = $this->val($field);
        if ($v === '' || !is_numeric($v) || (float) $v < -90 || (float) $v > 90) {
            $this->err($field, 'A valid GPS latitude is required.');
        }
        return $this;
    }

    public function longitude(string $field): self
    {
        $v = $this->val($field);
        if ($v === '' || !is_numeric($v) || (float) $v < -180 || (float) $v > 180) {
            $this->err($field, 'A valid GPS longitude is required.');
        }
        return $this;
    }

    /** Reject 0,0 - the classic "GPS not actually acquired" value. */
    public function realCoordinates(string $latField, string $lngField): self
    {
        $lat = (float) $this->val($latField);
        $lng = (float) $this->val($lngField);
        if (abs($lat) < 0.0001 && abs($lng) < 0.0001) {
            $this->err($latField, 'GPS location was not acquired. Turn on GPS and try again.');
        }
        return $this;
    }

    public function strongPassword(string $field, ?string $message = null): self
    {
        $v = $this->val($field);
        if ($v === '') {
            return $this;
        }
        $ok = strlen($v) >= 8
            && preg_match('/[A-Z]/', $v) === 1
            && preg_match('/[a-z]/', $v) === 1
            && preg_match('/\d/', $v) === 1;
        if (!$ok) {
            $this->err($field, $message
                ?? 'Password must be at least 8 characters and include an uppercase letter, a lowercase letter and a digit.');
        }
        return $this;
    }

    public function matches(string $field, string $otherField, ?string $message = null): self
    {
        if ($this->val($field) !== $this->val($otherField)) {
            $this->err($field, $message ?? $this->label($field) . ' does not match.');
        }
        return $this;
    }

    public function custom(string $field, bool $passes, string $message): self
    {
        if (!$passes) {
            $this->err($field, $message);
        }
        return $this;
    }

    public function fails(): bool
    {
        return $this->errors !== [];
    }

    public function passes(): bool
    {
        return $this->errors === [];
    }

    /** @return array<string,string> */
    public function errors(): array
    {
        return $this->errors;
    }

    public function firstError(): string
    {
        foreach ($this->errors as $message) {
            return $message;
        }
        return '';
    }

    /** All messages joined - handy for a single flash message. */
    public function summary(): string
    {
        return implode(' ', $this->errors);
    }
}
