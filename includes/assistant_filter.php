<?php
// Setlo Buddy: a local check for abusive messages (profanity, hate speech, threats of violence) that runs before anything
// is sent to the AI. It is a word-list filter, so it is instant and free but not perfect: it catches the usual
// spellings and tricks (f.u.c.k, fuuuck, f*ck, leetspeak) in English and Filipino, and the AI's own instructions
// handle anything it misses. To add words, add them to the lists below (lower-case letters only).

declare(strict_types=1);

/** The word lists: single words and short phrases, by category. 'fil' lists are Filipino (the notice is then in Filipino). */
function assistant_filter_lists(): array
{
    return [
        'profanity' => [
            'en'  => ['fuck', 'motherfucker', 'mothafucker', 'mutherfucker', 'motherfuck', 'mothafuck', 'shit', 'bullshit', 'dipshit', 'bitch', 'asshole', 'ass hole', 'dumbass', 'jackass', 'dick', 'dickhead',
                      'pussy', 'cunt', 'bastard', 'slut', 'whore', 'douche', 'douchebag', 'wanker', 'twat', 'prick', 'cocksucker', 'blowjob',
                      'jerk off', 'piss off', 'bollocks', 'suck my dick', 'eat shit'],
            'fil' => ['putangina', 'putang ina', 'puta', 'tangina', 'tang ina', 'gago', 'ulol', 'bobo', 'tarantado', 'pakyu', 'pak yu', 'pakshet',
                      'leche', 'lintik', 'hinayupak', 'burat', 'tite', 'titi', 'puki', 'pukinang ina', 'kantot', 'kantutan', 'jakol', 'putragis', 'punyeta',
                      'hayop ka', 'walang kwenta ka'],
        ],
        'hate' => [
            'en'  => ['nigger', 'nigga', 'niga', 'niger', 'chink', 'gook', 'spic', 'wetback', 'kike', 'coon', 'faggot', 'fag', 'dyke', 'tranny',
                      'retard', 'retarded', 'white power', 'heil hitler', 'go back to your country', 'subhuman', 'sand nigger', 'towelhead'],
            'fil' => ['bakla ka', 'tomboy ka', 'intsik na', 'negro ka', 'bading ka'],
        ],
    ];
}

/** Threats and violent wishes, as regular expressions over the normalised text (letters and single spaces only). */
function assistant_filter_violence(): array
{
    $aim = '(?:you|u|ya|him|her|them|everyone|everybody|ur|your)';
    return [
        'en' => [
            // "i will / gonna / want to ... kill ... you", but not "shoot you a message"
            '/\b(?:i|ill|i ll|we|we ll|imma|ima|gonna|going to|will|wanna|want to|should|gotta|got to|let me|lets)\b[a-z ]{0,20}?\b(?:kill|murder|stab|shoot|strangle|behead|rape|bomb|burn down|beat up)\b[a-z ]{0,15}?\b' . $aim . '\b(?!\s+(?:a|an|the|my|some|over|up|back)\b)/',
            '/\b(?:kill|hurt|harm|off)\s+(?:yourself|urself|ur self)\b/',
            '/\bkys\b/',
            '/\bgo\s+(?:die|kill yourself)\b/',
            '/\bdie\s+in\s+a\s+fire\b/',
            '/\b(?:bomb|shoot up|blow up)\s+(?:the|this|a)?\s*(?:school|mall|building|office|place|house|church|bank)\b/',
            '/\bkill\s+all\b/',
        ],
        'fil' => [
            '/\b(?:papatayin|patayin|sasaksakin|saksakin|babarilin|barilin|bubugbugin|gagahasain|susunugin)\s+(?:kita|ka|kayo|siya|sila|namin)\b/',
            '/\bmamatay\s+(?:ka|kayo|na ka)\b/',
        ],
    ];
}

/** Lower-case, accents and look-alike characters folded to plain letters. */
function assistant_filter_normalize(string $text): string
{
    $t = mb_strtolower($text, 'UTF-8');
    if (class_exists('Normalizer')) {
        $t = (string) preg_replace('/\p{Mn}+/u', '', (string) Normalizer::normalize($t, Normalizer::FORM_D));
    }
    return strtr($t, ['0' => 'o', '1' => 'i', '3' => 'e', '4' => 'a', '5' => 's', '7' => 't', '@' => 'a', '$' => 's', '!' => 'i', '|' => 'i', '+' => 't']);
}

/** A regex for one list word: repeated letters in the text are allowed ("fuuuck"), double letters in the word stay double. */
function assistant_filter_word_regex(string $word): string
{
    $parts = [];
    foreach (explode(' ', $word) as $w) {
        preg_match_all('/(.)\1*/', $w, $runs);
        $parts[] = implode('', array_map(fn ($run) => preg_quote($run[0], '/') . '{' . strlen($run) . ',}', $runs[0]));
    }
    return implode('\s+', $parts);
}

/** One compiled whole-word pattern per list (word endings like -ing, -er, -s are allowed). */
function assistant_filter_patterns(): array
{
    static $compiled = null;
    if ($compiled === null) {
        $compiled = [];
        foreach (assistant_filter_lists() as $category => $byLang) {
            foreach ($byLang as $lang => $words) {
                $alt = implode('|', array_map('assistant_filter_word_regex', $words));
                $compiled[] = [$category, $lang, '/(?<![a-z])(?:' . $alt . ')(?:ing|ers?|ed|es|s|y|ie|in)?(?![a-z])/'];
            }
        }
    }
    return $compiled;
}

/** Does a masked word like "f*ck" or "sh#t" stand for one of the listed single words? Returns [category, lang] or null. */
function assistant_filter_masked(string $token): ?array
{
    $stems = [$token, (string) preg_replace('/(ing|ers?|ed|es|s|y)$/', '', $token)];
    foreach ($stems as $stem) {
        if (strlen($stem) < 3 || !preg_match('/^[a-z*#%&.]+$/', $stem) || !preg_match('/[*#%&]/', $stem)) {
            continue;
        }
        $re = '/^';
        foreach (str_split($stem) as $ch) {
            $re .= str_contains('*#%&.', $ch) ? '[a-z]' : preg_quote($ch, '/');
        }
        $re .= '$/';
        foreach (assistant_filter_lists() as $category => $byLang) {
            foreach ($byLang as $lang => $words) {
                foreach ($words as $w) {
                    if (!str_contains($w, ' ') && strlen($w) === strlen($stem) && preg_match($re, $w)) {
                        return [$category, $lang];
                    }
                }
            }
        }
    }
    return null;
}

/**
 * Is this message abusive? Returns ['category' => 'profanity'|'hate'|'violence', 'lang' => 'en'|'fil'], or null when it
 * is fine. Threats win over hate, hate over plain profanity.
 */
function assistant_moderate(string $text): ?array
{
    $n = assistant_filter_normalize($text);

    // Texts to check: the message with everything but letters turned into spaces, plus any "f u c k" / "f.u.c.k" runs squashed
    $spaced = trim((string) preg_replace('/\s+/', ' ', (string) preg_replace('/[^a-z]+/', ' ', $n)));
    $candidates = [$spaced];
    if (preg_match_all('/(?<![a-z])[a-z](?:[\s._\-*]+[a-z]){2,}(?![a-z])/', $n, $runs)) {
        foreach ($runs[0] as $run) {
            $candidates[] = (string) preg_replace('/[^a-z]+/', '', $run);
        }
    }

    foreach (['violence' => assistant_filter_violence()] as $category => $byLang) {
        foreach ($byLang as $lang => $patterns) {
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $spaced)) {
                    return ['category' => $category, 'lang' => $lang];
                }
            }
        }
    }
    $found = [];
    foreach (assistant_filter_patterns() as [$category, $lang, $pattern]) {
        foreach ($candidates as $c) {
            if ($c !== '' && preg_match($pattern, $c)) {
                $found[$category] ??= $lang;
            }
        }
    }
    foreach (preg_split('/\s+/', $n) ?: [] as $token) {
        if (($hit = assistant_filter_masked(trim($token, ".,;:?\"'()[]{}"))) !== null) {
            $found[$hit[0]] ??= $hit[1];
        }
    }
    foreach (['hate', 'profanity'] as $category) {
        if (isset($found[$category])) {
            return ['category' => $category, 'lang' => $found[$category]];
        }
    }
    return null;
}

/** The calm reply for a flagged message. It never repeats what the person wrote. */
function assistant_moderation_notice(array $hit): string
{
    $fil = $hit['lang'] === 'fil';
    switch ($hit['category']) {
        case 'violence':
            return $fil
                ? 'Hindi kita matutulungan sa pagbabanta o karahasan. Kung may nasa panganib, tumawag sa 911 o sa lokal na emergency services. Nandito ako para sa mga tanong tungkol sa Setlo.'
                : 'I can’t help with threats or violence. If someone is in danger, please contact local emergency services (911 in the Philippines). I’m here for Setlo questions.';
        case 'hate':
            return $fil
                ? 'Hindi ako sasagot sa mapanirang o mapanghating salita. Nandito ako para tumulong sa Setlo.'
                : 'I won’t respond to hateful or discriminatory language. I’m here to help with Setlo.';
        default:
            return $fil
                ? 'Igalang natin ang isa’t isa 🙂 Masaya akong tumulong sa mga bill, gastos, o kung paano gumagana ang Setlo.'
                : 'Let’s keep it respectful 🙂 I’m happy to help with your bills, spending, or how Setlo works.';
    }
}
