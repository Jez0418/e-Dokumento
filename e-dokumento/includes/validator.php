<?php
declare(strict_types=1);

/**
 * Server-side validation. Each method returns the cleaned value (or null) and
 * records a human-readable error under the field name.
 */
final class Validator
{
    private array $errors = [];

    public function __construct(private array $data)
    {
    }

    private function raw(string $field): string
    {
        $v = $this->data[$field] ?? '';
        return is_string($v) ? trim(str_replace("\0", '', $v)) : '';
    }

    public function error(string $field, string $message): void
    {
        $this->errors[$field] ??= $message;
    }

    public function fails(): bool
    {
        return $this->errors !== [];
    }

    public function errors(): array
    {
        return $this->errors;
    }

    public function text(string $field, string $label, bool $required = true, int $min = 0, int $max = 255): ?string
    {
        $v = $this->raw($field);
        if ($v === '') {
            if ($required) {
                $this->error($field, "{$label} is required.");
            }
            return null;
        }
        $len = mb_strlen($v);
        if ($len < $min) {
            $this->error($field, "{$label} must be at least {$min} characters.");
        } elseif ($len > $max) {
            $this->error($field, "{$label} must be {$max} characters or fewer.");
        }
        if (preg_match('/[<>]/', $v)) {
            $this->error($field, "{$label} cannot contain < or >.");
        }
        return $v;
    }

    public function name(string $field, string $label, bool $required = true, int $max = 60): ?string
    {
        $v = $this->text($field, $label, $required, 1, $max);
        if ($v !== null && !preg_match("/^[\p{L}][\p{L}\s.'\-]*$/u", $v)) {
            $this->error($field, "{$label} can only contain letters, spaces, periods, apostrophes and hyphens.");
        }
        return $v;
    }

    public function email(string $field, string $label = 'Email', bool $required = true): ?string
    {
        $v = $this->raw($field);
        if ($v === '') {
            if ($required) {
                $this->error($field, "{$label} is required.");
            }
            return null;
        }
        if (mb_strlen($v) > 254 || filter_var($v, FILTER_VALIDATE_EMAIL) === false) {
            $this->error($field, "Enter a valid email address.");
        }
        return strtolower($v);
    }

    public function phone(string $field, string $label = 'Mobile number', bool $required = false): ?string
    {
        $v = preg_replace('/[\s\-]/', '', $this->raw($field)) ?? '';
        if ($v === '') {
            if ($required) {
                $this->error($field, "{$label} is required.");
            }
            return null;
        }
        if (!preg_match('/^(09|\+639)\d{9}$/', $v)) {
            $this->error($field, "{$label} must look like 09171234567 or +639171234567.");
        }
        return $v;
    }

    public function integer(string $field, string $label, bool $required = true, int $min = PHP_INT_MIN, int $max = PHP_INT_MAX): ?int
    {
        $v = $this->raw($field);
        if ($v === '') {
            if ($required) {
                $this->error($field, "{$label} is required.");
            }
            return null;
        }
        if (!preg_match('/^-?\d{1,9}$/', $v)) {
            $this->error($field, "{$label} must be a whole number.");
            return null;
        }
        $n = (int) $v;
        if ($n < $min || $n > $max) {
            $this->error($field, "{$label} must be between {$min} and {$max}.");
        }
        return $n;
    }

    public function decimal(string $field, string $label, bool $required = true, float $min = 0, float $max = 9999999): ?string
    {
        $v = str_replace(',', '', $this->raw($field));
        if ($v === '') {
            if ($required) {
                $this->error($field, "{$label} is required.");
            }
            return null;
        }
        if (!preg_match('/^\d{1,8}(\.\d{1,2})?$/', $v)) {
            $this->error($field, "{$label} must be an amount like 50 or 50.00.");
            return null;
        }
        if ((float) $v < $min || (float) $v > $max) {
            $this->error($field, "{$label} must be between {$min} and {$max}.");
        }
        return number_format((float) $v, 2, '.', '');
    }

    public function date(string $field, string $label, bool $required = true, ?string $notAfter = null, ?string $notBefore = null): ?string
    {
        $v = $this->raw($field);
        if ($v === '') {
            if ($required) {
                $this->error($field, "{$label} is required.");
            }
            return null;
        }
        if (!is_date($v)) {
            $this->error($field, "{$label} must be a valid date.");
            return null;
        }
        if ($notAfter !== null && $v > $notAfter) {
            $this->error($field, "{$label} cannot be after " . fmt_date($notAfter) . '.');
        }
        if ($notBefore !== null && $v < $notBefore) {
            $this->error($field, "{$label} cannot be before " . fmt_date($notBefore) . '.');
        }
        return $v;
    }

    public function in(string $field, string $label, array $allowed, bool $required = true): ?string
    {
        $v = $this->raw($field);
        if ($v === '') {
            if ($required) {
                $this->error($field, "Choose {$label}.");
            }
            return null;
        }
        if (!in_array($v, array_map('strval', $allowed), true)) {
            $this->error($field, "Choose a valid {$label}.");
            return null;
        }
        return $v;
    }

    public function bool(string $field): bool
    {
        return in_array($this->raw($field), ['1', 'on', 'true', 'yes'], true);
    }

    public function uuid(string $field, string $label, bool $required = true): ?string
    {
        $v = $this->raw($field);
        if ($v === '') {
            if ($required) {
                $this->error($field, "{$label} is required.");
            }
            return null;
        }
        if (!is_uuid($v)) {
            $this->error($field, "{$label} is invalid.");
            return null;
        }
        return strtolower($v);
    }

    public function password(string $field, string $confirmField, string $label = 'Password'): ?string
    {
        $v = (string) ($this->data[$field] ?? '');
        $confirm = (string) ($this->data[$confirmField] ?? '');
        if ($v === '') {
            $this->error($field, "{$label} is required.");
            return null;
        }
        if (strlen($v) < 8 || strlen($v) > 72) {
            $this->error($field, "{$label} must be 8 to 72 characters.");
        } elseif (!preg_match('/[a-z]/', $v) || !preg_match('/[A-Z]/', $v) || !preg_match('/\d/', $v)) {
            $this->error($field, "{$label} needs an uppercase letter, a lowercase letter and a number.");
        }
        if ($v !== $confirm) {
            $this->error($confirmField, 'The passwords do not match.');
        }
        return $v;
    }
}
