<?php

namespace App\Support;

use Closure;

/**
 * Finds wording that would present this demonstration as legally valid, or as authorised by or
 * affiliated with a government body: the claims owner decision 1 rules out. Used to refuse such
 * text in what an Admin can publish (the site notice and the service catalogue).
 *
 * Markup, entities, invisible characters, spacing and case are normalised first, so they cannot
 * hide a claim. A phrase is allowed only when the same sentence negates it before the phrase
 * ("E-Notary is not legally valid", "not affiliated with any government body"); a question is
 * allowed only when the next sentence answers "No".
 */
final class DemoClaims
{
    private const CLAIMS = [
        '/\blegal(?:ly)?[\s-]*(?:valid|validity|binding|enforceable|recogni[sz]ed)\b/u',
        '/\bgovernment[\s-]*(?:authori[sz]ed|approved|recogni[sz]ed|certified|endorsed|backed|affiliated|licensed|sanctioned|registered)\b/u',
        '/\b(?:authori[sz]ed|approved|recogni[sz]ed|certified|endorsed|licensed|sanctioned|accredited|registered|empanelled) (?:by|with|under) (?:the |any |a )?(?:government|ministry|state|court|bar council)\b/u',
        '/\baffiliated (?:with|to) (?:the |a |any )?(?:government|ministry)\b/u',
        '/\b(?:official|a) government (?:service|portal|website|body)\b/u',
        '/\bgov(?:t)?\.in\b|\bgovernment of india\b|\bministry of\b|\bdigital india\b|\bbar council\b|\budin\b|\be-?stamp(?:ed|s)?\b/u',
    ];

    /** The first claim found in $text, or null when there is none. */
    public static function find(string $text): ?string
    {
        $text = preg_replace('#<(script|style)\b.*?</\1>#is', ' ', $text);
        $text = html_entity_decode(strip_tags((string) $text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = (string) preg_replace('/[\x{200B}-\x{200D}\x{FEFF}\x{00AD}]/u', '', $text);
        $text = mb_strtolower(trim((string) preg_replace('/[\s\x{00A0}]+/u', ' ', $text)));

        $sentences = preg_split('/(?<=[.!?])\s+/u', $text) ?: [];

        foreach ($sentences as $i => $sentence) {
            foreach (self::CLAIMS as $claim) {
                if (! preg_match($claim, $sentence, $m, PREG_OFFSET_CAPTURE)) {
                    continue;
                }

                $negated = preg_match("/\\b(?:not|no|never|without|neither|nor)\\b|n't/u", substr($sentence, 0, $m[0][1])) === 1;
                $answeredNo = str_ends_with($sentence, '?') && preg_match('/^\W*no\b/u', $sentences[$i + 1] ?? '') === 1;

                if (! $negated && ! $answeredNo) {
                    return $m[0][0];
                }
            }
        }

        return null;
    }

    /** A validation rule refusing any such claim. */
    public static function rule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (is_string($value) && ($claim = self::find($value)) !== null) {
                $fail('This text cannot be published: "'.$claim.'" would present E-Notary as legally valid or government-backed. E-Notary is a demonstration only.');
            }
        };
    }
}
