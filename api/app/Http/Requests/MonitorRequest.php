<?php

namespace App\Http\Requests;

use Cron\CronExpression;
use Illuminate\Validation\Rule;

class MonitorRequest extends ApiFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $req = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            // Immutable after creation: accepted (and ignored) on PATCH so clients can send the full object back.
            'tracked_product_id' => [$this->isMethod('POST') ? 'required' : 'sometimes', 'string', 'ulid'],
            'schedule' => [$req, 'string', 'max:64', function ($a, $v, $fail) {
                if (! is_string($v) || ! CronExpression::isValidExpression($v)) {
                    $fail('The schedule must be a valid cron expression.');
                } else {
                    $cron = new CronExpression($v);
                    $first = $cron->getNextRunDate();
                    if ($cron->getNextRunDate($first)->getTimestamp() - $first->getTimestamp() < 15 * 60) {
                        $fail('Monitors may run at most every 15 minutes.');
                    }
                }
            }],
            'timezone' => ['sometimes', 'timezone:all'],
            'source_ids' => ['nullable', 'array', 'max:100'],
            'source_ids.*' => ['string', 'ulid'],
            'options' => ['nullable', 'array'],
            'options.condition' => ['nullable', Rule::in(['new', 'refurbished', 'used', 'any'])],
            'options.max_results' => ['nullable', 'integer', 'min:1', 'max:500'],
            'options.sources' => ['nullable', 'array'],
            'alert_rules' => ['nullable', 'array'],
            'alert_rules.price_drop_percent' => ['nullable', 'numeric', 'min:0.1', 'max:100'],
            'alert_rules.price_increase_percent' => ['nullable', 'numeric', 'min:0.1', 'max:1000'],
            'alert_rules.target_price' => ['nullable', 'numeric', 'gt:0'],
            'alert_rules.map_enforcement' => ['nullable', 'boolean'],
            'alert_rules.availability_changes' => ['nullable', 'boolean'],
            'alert_rules.new_sellers' => ['nullable', 'boolean'],
            'alert_rules.cooldown_minutes' => ['nullable', 'integer', 'min:0', 'max:43200'],
            'status' => ['sometimes', Rule::in(['active', 'paused'])],
        ];
    }
}
