<?php

declare(strict_types=1);

namespace Fium;

use Fium\Runtime\Request;

final class Validator
{
    /** @var array<string, list<string>> */
    private array $errors = [];

    /** @var array<string, mixed> */
    private array $validated = [];

    /** @var array<string, mixed> */
    private array $data;

    /** @param array<string, mixed> $data */
    private function __construct(array $data)
    {
        $this->data = $data;
    }

    /**
     * Validate request input against rules.
     *
     * @param array<string, string> $rules  e.g. ['email' => 'required|email', 'name' => 'required|min:2|max:100']
     */
    public static function make(Request $request, array $rules): self
    {
        $data = $request->json() ?? [];
        $validator = new self($data);
        $validator->applyRules($rules);

        return $validator;
    }

    /**
     * Validate an arbitrary array against rules.
     *
     * @param array<string, mixed> $data
     * @param array<string, string> $rules
     */
    public static function validate(array $data, array $rules): self
    {
        $validator = new self($data);
        $validator->applyRules($rules);

        return $validator;
    }

    public function fails(): bool
    {
        return $this->errors !== [];
    }

    public function passes(): bool
    {
        return $this->errors === [];
    }

    /** @return array<string, list<string>> */
    public function errors(): array
    {
        return $this->errors;
    }

    /** @return array<string, mixed> Only fields that passed validation */
    public function validated(): array
    {
        return $this->validated;
    }

    /** @param array<string, string> $rules */
    private function applyRules(array $rules): void
    {
        foreach ($rules as $field => $ruleString) {
            $fieldRules = explode('|', $ruleString);
            $value = $this->data[$field] ?? null;
            $fieldErrors = [];

            foreach ($fieldRules as $rule) {
                $error = $this->checkRule($field, $value, $rule);

                if ($error !== null) {
                    $fieldErrors[] = $error;
                }
            }

            if ($fieldErrors === []) {
                $this->validated[$field] = $value;
            } else {
                $this->errors[$field] = $fieldErrors;
            }
        }
    }

    private function checkRule(string $field, mixed $value, string $rule): ?string
    {
        [$ruleName, $param] = array_pad(explode(':', $rule, 2), 2, null);

        return match ($ruleName) {
            'required' => $this->ruleRequired($field, $value),
            'string' => $this->ruleString($field, $value),
            'integer', 'int' => $this->ruleInteger($field, $value),
            'numeric' => $this->ruleNumeric($field, $value),
            'email' => $this->ruleEmail($field, $value),
            'min' => $this->ruleMin($field, $value, (int) ($param ?? 0)),
            'max' => $this->ruleMax($field, $value, (int) ($param ?? PHP_INT_MAX)),
            'in' => $this->ruleIn($field, $value, $param ?? ''),
            'boolean', 'bool' => $this->ruleBoolean($field, $value),
            'array' => $this->ruleArray($field, $value),
            'nullable' => null, // Allows null — no validation
            default => null,
        };
    }

    private function ruleRequired(string $field, mixed $value): ?string
    {
        if ($value === null || $value === '' || $value === []) {
            return "The {$field} field is required.";
        }

        return null;
    }

    private function ruleString(string $field, mixed $value): ?string
    {
        if ($value !== null && !is_string($value)) {
            return "The {$field} field must be a string.";
        }

        return null;
    }

    private function ruleInteger(string $field, mixed $value): ?string
    {
        if ($value !== null && !is_int($value) && !ctype_digit((string) $value)) {
            return "The {$field} field must be an integer.";
        }

        return null;
    }

    private function ruleNumeric(string $field, mixed $value): ?string
    {
        if ($value !== null && !is_numeric($value)) {
            return "The {$field} field must be numeric.";
        }

        return null;
    }

    private function ruleEmail(string $field, mixed $value): ?string
    {
        if ($value !== null && (!is_string($value) || filter_var($value, FILTER_VALIDATE_EMAIL) === false)) {
            return "The {$field} field must be a valid email address.";
        }

        return null;
    }

    private function ruleMin(string $field, mixed $value, int $min): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value) && mb_strlen($value) < $min) {
            return "The {$field} field must be at least {$min} characters.";
        }

        if (is_int($value) && $value < $min) {
            return "The {$field} field must be at least {$min}.";
        }

        if (is_array($value) && count($value) < $min) {
            return "The {$field} field must have at least {$min} items.";
        }

        return null;
    }

    private function ruleMax(string $field, mixed $value, int $max): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value) && mb_strlen($value) > $max) {
            return "The {$field} field must not exceed {$max} characters.";
        }

        if (is_int($value) && $value > $max) {
            return "The {$field} field must not exceed {$max}.";
        }

        if (is_array($value) && count($value) > $max) {
            return "The {$field} field must not have more than {$max} items.";
        }

        return null;
    }

    private function ruleIn(string $field, mixed $value, string $options): ?string
    {
        if ($value === null) {
            return null;
        }

        $allowed = explode(',', $options);

        if (!in_array((string) $value, $allowed, true)) {
            return "The {$field} field must be one of: {$options}.";
        }

        return null;
    }

    private function ruleBoolean(string $field, mixed $value): ?string
    {
        if ($value !== null && !is_bool($value) && !in_array($value, [0, 1, '0', '1'], true)) {
            return "The {$field} field must be a boolean.";
        }

        return null;
    }

    private function ruleArray(string $field, mixed $value): ?string
    {
        if ($value !== null && !is_array($value)) {
            return "The {$field} field must be an array.";
        }

        return null;
    }
}
