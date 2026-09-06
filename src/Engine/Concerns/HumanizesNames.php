<?php

namespace Jayanta\Jeeves\Engine\Concerns;

/**
 * Turn column names into words a person would use.
 *
 * Shared by the answer sentence and the follow-up suggestions so that a column
 * is described the same way wherever the user meets it: "Top 5 regions by
 * revenue" and "Bottom 5 regions instead" should not disagree about what a
 * `region` is called.
 */
trait HumanizesNames
{
    /** region → region, product_category → product category. */
    protected function humanize(string $name): string
    {
        return trim(str_replace('_', ' ', trim($name)));
    }

    /**
     * A plural noun for the answer sentence: region → regions,
     * customer_name → customers, status → statuses, category → categories.
     */
    protected function humanizePlural(string $column): string
    {
        $words = $this->humanize($column);

        // "customer_name" describes customers, not customer names.
        $words = (string) preg_replace('/\s+(name|title|label)$/i', '', $words);

        if ($words === '') {
            return 'entries';
        }

        return $this->pluralize($words);
    }

    protected function pluralize(string $phrase): string
    {
        $parts = explode(' ', $phrase);
        $last = array_pop($parts);

        if (preg_match('/(s|x|z|ch|sh)$/i', $last)) {
            $last .= 'es';
        } elseif (preg_match('/[^aeiou]y$/i', $last)) {
            $last = substr($last, 0, -1) . 'ies';
        } elseif (!preg_match('/s$/i', $last)) {
            $last .= 's';
        }

        $parts[] = $last;

        return implode(' ', $parts);
    }
}
