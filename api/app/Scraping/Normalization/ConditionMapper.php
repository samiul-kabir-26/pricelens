<?php

namespace App\Scraping\Normalization;

class ConditionMapper
{
    public static function map(mixed $raw): string
    {
        if (! is_string($raw) || trim($raw) === '') {
            return 'unknown';
        }

        $v = strtolower(preg_replace('#^https?://(www\.)?schema\.org/#i', '', trim($raw)) ?? '');

        return match (true) {
            (bool) preg_match('/refurb|renewed|reconditioned/', $v) => 'refurbished',
            (bool) preg_match('/used|pre-?owned|damaged|open box|second-?hand/', $v) => 'used',
            (bool) preg_match('/^new|newcondition|brand new/', $v) => 'new',
            default => 'unknown',
        };
    }
}
