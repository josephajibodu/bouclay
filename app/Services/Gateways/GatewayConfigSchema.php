<?php

namespace App\Services\Gateways;

/**
 * A driver's credential manifest (IMPLEMENTATION_V2 §V2-4): the fields a team
 * must supply to connect this gateway, per mode. The connect form renders from
 * this and saves validate against it, so a new gateway lights its own UI up
 * with zero migrations and zero hard-coded fields.
 */
readonly class GatewayConfigSchema
{
    /**
     * @param  string  $label  the gateway's display name
     * @param  list<GatewayConfigField>  $fields
     * @param  string|null  $docsUrl  where a team finds these credentials
     */
    public function __construct(
        public string $label,
        public array $fields,
        public ?string $docsUrl = null,
    ) {}

    public function field(string $key): ?GatewayConfigField
    {
        foreach ($this->fields as $field) {
            if ($field->key === $key) {
                return $field;
            }
        }

        return null;
    }

    /**
     * Validation rules for a connect submission, keyed by field.
     *
     * @return array<string, list<string>>
     */
    public function validationRules(): array
    {
        $rules = [];

        foreach ($this->fields as $field) {
            $rules[$field->key] = $field->validationRules();
        }

        return $rules;
    }

    /**
     * Reduce a submission to the manifest's own keys, dropping blanks — the
     * credential blob written to the connection. Anything the manifest doesn't
     * declare never reaches storage.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, string>
     */
    public function credentialsFrom(array $input): array
    {
        $credentials = [];

        foreach ($this->fields as $field) {
            $value = $input[$field->key] ?? null;

            if (is_string($value) && trim($value) !== '') {
                $credentials[$field->key] = trim($value);
            }
        }

        return $credentials;
    }

    /**
     * @return array{label: string, docsUrl: string|null, fields: list<array{key: string, label: string, secret: bool, required: bool, help: string|null, placeholder: string|null}>}
     */
    public function toArray(): array
    {
        return [
            'label' => $this->label,
            'docsUrl' => $this->docsUrl,
            'fields' => array_map(
                fn (GatewayConfigField $field): array => $field->toArray(),
                $this->fields,
            ),
        ];
    }
}
