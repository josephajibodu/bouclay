<?php

namespace App\Http\Requests;

use App\Enums\DunningTerminalAction;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDunningSettingsRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // The schedule is the source of truth for how many attempts there
            // are: one retry per interval. `max_attempts` is derived, never
            // submitted, so the two can't contradict each other.
            'retry_intervals_days' => ['required', 'array', 'min:1', 'max:6'],
            'retry_intervals_days.*' => ['required', 'integer', 'min:0', 'max:30'],
            'terminal_action' => ['required', Rule::enum(DunningTerminalAction::class)],
            'incomplete_grace_days' => ['required', 'integer', 'min:1', 'max:60'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'retry_intervals_days.min' => 'Add at least one retry, or the terminal action would run on the first failure.',
            'retry_intervals_days.max' => 'Six retries is the most a recovery window can usefully hold.',
            'retry_intervals_days.*.max' => 'A retry more than 30 days out is past the point of recovery.',
        ];
    }
}
