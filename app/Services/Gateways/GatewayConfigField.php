<?php

namespace App\Services\Gateways;

/**
 * One credential field in a driver's {@see GatewayConfigSchema} manifest —
 * enough for the connect form to render it, for validation to accept it, and
 * for the UI to know whether it may ever be echoed back.
 */
readonly class GatewayConfigField
{
    /**
     * @param  string  $key  the credential blob key it stores under
     * @param  bool  $secret  a value never sent back to the browser: the form
     *                        shows a masked preview instead
     * @param  GatewayConfigFieldRole  $role  what the field is for, so callers
     *                                        can find it without knowing its key
     * @param  list<string>  $rules  Laravel validation rules, minus required/nullable
     */
    public function __construct(
        public string $key,
        public string $label,
        public bool $secret = false,
        public bool $required = true,
        public GatewayConfigFieldRole $role = GatewayConfigFieldRole::Credential,
        public ?string $help = null,
        public ?string $placeholder = null,
        public array $rules = ['string', 'max:255'],
    ) {}

    /**
     * The validation rules for this field, required-ness included.
     *
     * @return list<string>
     */
    public function validationRules(): array
    {
        return [$this->required ? 'required' : 'nullable', ...$this->rules];
    }

    /**
     * The manifest shape the connect form renders from. Never carries a value
     * — secrets stay server-side; the form sends new ones, it never reads old.
     *
     * @return array{key: string, label: string, secret: bool, required: bool, role: string, help: string|null, placeholder: string|null}
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'secret' => $this->secret,
            'required' => $this->required,
            'role' => $this->role->value,
            'help' => $this->help,
            'placeholder' => $this->placeholder,
        ];
    }
}
