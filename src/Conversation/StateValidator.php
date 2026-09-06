<?php

namespace Jayanta\Jeeves\Conversation;

use Jayanta\Jeeves\Schema\SchemaRegistry;

/**
 * Check every slot against the schema before any SQL is built from it.
 *
 * The state is assembled from model output, so it can contain a metric that
 * does not exist or a breakdown that is not a dimension. Those must become a
 * question, never a query: a confidently wrong number is worse than "I am not
 * sure what you mean by that", and it is worse by a wide margin when the
 * number is going to be read as fact.
 *
 * Slots are resolved to their canonical schema names as a side effect, so
 * "sales" becomes `revenue` once and stays that way for the rest of the
 * conversation.
 */
class StateValidator
{
    protected SchemaRegistry $registry;

    public function __construct(SchemaRegistry $registry)
    {
        $this->registry = $registry;
    }

    /**
     * @return array{valid: bool, state?: QueryState, slot?: string, value?: string, options?: array}
     */
    public function validate(QueryState $state): array
    {
        $dataset = $state->get('dataset');

        if (!$dataset || !$this->registry->has($dataset)) {
            return ['valid' => false, 'slot' => 'dataset', 'value' => (string) $dataset,
                'options' => array_keys($this->registry->all())];
        }

        $slots = $state->toIntent();

        if ($metric = $state->get('metric')) {
            $resolved = $this->registry->resolveMetric($dataset, (string) $metric);

            if (!$resolved) {
                return ['valid' => false, 'slot' => 'metric', 'value' => (string) $metric,
                    'options' => array_column($this->registry->getDatasetMetrics($dataset), 'key')];
            }

            $slots['metric'] = $resolved;
        }

        foreach (['group_by', 'filter_column'] as $dimensionSlot) {
            if (!($value = $state->get($dimensionSlot))) {
                continue;
            }

            $resolved = $this->registry->resolveGroupColumn($dataset, (string) $value);

            if (!$resolved) {
                return ['valid' => false, 'slot' => $dimensionSlot, 'value' => (string) $value,
                    'options' => $this->registry->getGroupableColumns($dataset)];
            }

            $slots[$dimensionSlot] = $resolved;
        }

        // Every accumulated filter, not just the newest. One unresolvable
        // column among several would otherwise be dropped on the way to SQL,
        // and a dropped filter widens the answer without saying so.
        $filters = [];

        foreach ($state->get('filters') ?? [] as $filter) {
            $resolved = $this->registry->resolveGroupColumn($dataset, (string) ($filter['column'] ?? ''));

            if (!$resolved) {
                return ['valid' => false, 'slot' => 'filter_column', 'value' => (string) ($filter['column'] ?? ''),
                    'options' => $this->registry->getGroupableColumns($dataset)];
            }

            $filters[] = ['column' => $resolved, 'value' => $filter['value'] ?? null];
        }

        if ($filters) {
            $slots['filters'] = $filters;
        }

        return ['valid' => true, 'state' => new QueryState($slots, $state->seq, $state->refinements)];
    }

    /** Wording for the slot that could not be resolved. */
    public function question(array $failure): string
    {
        $value = $failure['value'] ?? '';
        $options = implode(', ', array_slice($failure['options'] ?? [], 0, 8));

        return match ($failure['slot'] ?? '') {
            'metric' => "I don't have a measure called \"{$value}\"."
                . ($options ? " Did you mean one of: {$options}?" : ''),
            'group_by' => "I can't break this down by \"{$value}\"."
                . ($options ? " Available: {$options}." : ''),
            'filter_column' => "I can't filter on \"{$value}\"."
                . ($options ? " Available: {$options}." : ''),
            default => 'Which dataset should I look at?'
                . ($options ? " Available: {$options}." : ''),
        };
    }
}
