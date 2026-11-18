<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Validasi input di BELAKANG (backend) — sumber kebenaran utama.
 * Validasi frontend hanya pelengkap.
 *
 * Aturan per field dipisah dengan "|", contoh: "required|min:8|confirmed".
 * Dukungan aturan:
 *   required | string | email | numeric | integer | min:n | max:n
 *   in:a,b,c | confirmed | regex:pola
 *
 * Catatan: `min`/`max` berarti PERBANDINGAN ANGKA bila field juga diberi
 * aturan `numeric`/`integer`; selain itu diartikan sebagai panjang karakter.
 */
final class Validator
{
    /** @var string[] */
    private array $errors = [];

    /** @var array<string,mixed> */
    private array $validated = [];

    private string $currentField = '';

    /**
     * @param array<string,mixed> $data
     * @param array<string,string> $rules
     * @param array<string,string> $labels Label Bahasa Indonesia per field.
     */
    private function __construct(
        private array $data,
        private array $rules,
        private array $labels = []
    ) {
    }

    /** @param array<string,mixed> $data @param array<string,string> $rules @param array<string,string> $labels */
    public static function make(array $data, array $rules, array $labels = []): self
    {
        $validator = new self($data, $rules, $labels);
        $validator->run();
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

    /** @return string[] */
    public function errors(): array
    {
        return $this->errors;
    }

    public function firstError(): ?string
    {
        return $this->errors[0] ?? null;
    }

    /** @return array<string,mixed> */
    public function validated(): array
    {
        return $this->validated;
    }

    private function label(string $field): string
    {
        return $this->labels[$field] ?? $field;
    }

    private function run(): void
    {
        foreach ($this->rules as $field => $ruleString) {
            $this->currentField = (string) $field;
            $label = $this->label($this->currentField);
            $rules = explode('|', (string) $ruleString);
            $value = $this->data[$field] ?? null;
            $isEmpty = $value === null || $value === '';
            $errorCountBefore = count($this->errors);
            $isNumericContext = in_array('numeric', $rules, true) || in_array('integer', $rules, true);

            if (in_array('required', $rules, true)) {
                if ($isEmpty) {
                    $this->errors[] = "{$label} wajib diisi.";
                    continue;
                }
            } elseif ($isEmpty) {
                // Tidak wajib dan kosong → lolos.
                $this->validated[$field] = is_string($value) ? trim($value) : $value;
                continue;
            }

            foreach ($rules as $rule) {
                [$name, $arg] = array_pad(explode(':', $rule, 2), 2, null);
                if ($name === 'required') {
                    continue;
                }
                if (!$this->check($name, $arg, $value, $isNumericContext)) {
                    $this->errors[] = $this->message($name, $label, $arg, $isNumericContext);
                }
            }

            if (count($this->errors) === $errorCountBefore) {
                $this->validated[$field] = is_string($value) ? trim($value) : $value;
            }
        }
        $this->currentField = '';
    }

    private function check(string $rule, ?string $arg, mixed $value, bool $isNumericContext): bool
    {
        switch ($rule) {
            case 'string':
                return is_scalar($value);

            case 'email':
                return filter_var($value, FILTER_VALIDATE_EMAIL) !== false;

            case 'numeric':
                return is_numeric($value);

            case 'integer':
                return filter_var($value, FILTER_VALIDATE_INT) !== false;

            case 'min':
                if ($isNumericContext) {
                    return is_numeric($value) && (float) $value >= (float) $arg;
                }
                return mb_strlen((string) $value) >= (int) $arg;

            case 'max':
                if ($isNumericContext) {
                    return is_numeric($value) && (float) $value <= (float) $arg;
                }
                return mb_strlen((string) $value) <= (int) $arg;

            case 'in':
                return in_array((string) $value, explode(',', (string) $arg), true);

            case 'confirmed':
                return ($this->data[$this->currentField . '_confirmation'] ?? null) === $value;

            case 'regex':
                return preg_match('/' . str_replace('/', '\/', (string) $arg) . '/', (string) $value) === 1;

            default:
                // Aturan tidak dikenal TIDAK dianggap gagal (agar tak memblokir), tetapi dicatat.
                error_log("[Validator] aturan tidak dikenal: {$rule} pada field {$this->currentField}");
                return true;
        }
    }

    private function message(string $rule, string $label, ?string $arg, bool $isNumericContext): string
    {
        $isNumericBound = preg_match('/^\d+(\.\d+)?$/', (string) $arg) === 1;

        return match ($rule) {
            'email'     => "{$label} harus berupa alamat email yang valid.",
            'numeric'   => "{$label} harus berupa angka.",
            'integer'   => "{$label} harus berupa bilangan bulat.",
            'min'       => ($isNumericContext && $isNumericBound)
                            ? "{$label} minimal {$arg}."
                            : "{$label} minimal {$arg} karakter.",
            'max'       => (!$isNumericContext && $isNumericBound)
                            ? "{$label} maksimal {$arg} karakter."
                            : "{$label} maksimal {$arg}.",
            'in'        => "{$label} berisi nilai yang tidak diizinkan.",
            'confirmed' => "Konfirmasi {$label} tidak cocok.",
            'regex'     => "{$label} tidak memenuhi format yang diizinkan.",
            default     => "{$label} tidak valid.",
        };
    }
}
