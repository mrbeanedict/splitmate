<?php
// Minimal Groq client (OpenAI-compatible chat completions with tool calling) for Setlo Buddy, the chat assistant.
// It is deliberately separate from includes/gemini.php: receipt scanning uses Gemini, the chat uses Groq, so one can
// never use up the other's rate limits.

declare(strict_types=1);

class GroqException extends RuntimeException
{
}

function groq_available(): bool
{
    return function_exists('curl_init') && (string) config('groq.api_key') !== '';
}

// After Groq turns every chat model away, the chat rests briefly instead of hammering the service.
function groq_rest_file(): string
{
    return sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'setlo_buddy_rest';
}

function groq_rest_left(): int
{
    return max(0, (int) @file_get_contents(groq_rest_file()) - time());
}

/** Tool declarations (as assistant_tool_declarations() writes them, with upper-case types) in Groq's function format. */
function groq_tools(array $declarations): array
{
    $lower = function ($node) use (&$lower) {
        if (!is_array($node)) {
            return $node; // scalars, and the empty-properties object
        }
        $out = [];
        foreach ($node as $k => $v) {
            $out[$k] = $k === 'type' && is_string($v) ? strtolower($v) : $lower($v);
        }
        return $out;
    };
    return array_map(fn ($d) => ['type' => 'function', 'function' => $lower($d)], $declarations);
}

/**
 * One chat turn. $messages is the conversation so far in OpenAI format (system, user, assistant with tool_calls, tool);
 * $tools from groq_tools() (or [] to answer without tools). Returns the assistant's message: ['role' => 'assistant',
 * 'content' => ?string, 'tool_calls' => [...]?]. Tries the fallback models when one is busy or rate-limited.
 * @throws GroqException with a message that is safe to show to the user
 */
function groq_chat(array $messages, array $tools): array
{
    if (!groq_available()) {
        throw new GroqException('Setlo Buddy is not set up on this server (missing Groq API key).');
    }
    $rest = groq_rest_left();
    if ($rest > 0) {
        throw new GroqException('Setlo Buddy is resting for a moment. Try again in about ' . $rest . ' seconds.');
    }

    $models = array_values(array_unique(array_filter(array_merge([(string) config('groq.model')], (array) config('groq.fallback_models')))));
    $status = 0;
    $raw = '';
    $retryAfter = 0;
    foreach ($models as $i => $model) {
        $payload = ['model' => $model, 'messages' => $messages, 'temperature' => 0.3, 'max_tokens' => 1500];
        if (str_starts_with($model, 'openai/gpt-oss')) {
            $payload['reasoning_effort'] = 'low'; // quick answers: this assistant mostly looks things up
        }
        if ($tools) {
            $payload['tools'] = $tools;
            $payload['tool_choice'] = 'auto';
        }
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        [$status, $raw, $retryAfter] = groq_request($body);
        if ($status === 400 && str_contains($raw, 'tool_use_failed')) {
            [$status, $raw, $retryAfter] = groq_request($body); // Llama sometimes writes a tool call badly: once more
        }
        if ($status === 200 || !in_array($status, [0, 404, 429, 500, 502, 503, 504], true)) {
            break;
        }
        error_log("[setlo] Groq chat $model unavailable or busy (HTTP $status)");
        if ($i + 1 < count($models)) {
            sleep(1);
        }
    }

    if ($status !== 200) {
        error_log("[setlo] Groq chat HTTP $status: " . substr($raw, 0, 500));
        if (in_array($status, [0, 429, 500, 502, 503, 504], true)) {
            @file_put_contents(groq_rest_file(), (string) (time() + max(10, min(60, $retryAfter ?: 30))));
            throw new GroqException('Setlo Buddy is busy right now. Try again in a minute.');
        }
        throw new GroqException(in_array($status, [401, 403], true)
            ? 'Setlo Buddy could not sign in to its AI service. The app manager needs to check the Groq key.'
            : 'Setlo Buddy had a problem. Try again in a moment.');
    }

    $msg = json_decode($raw, true)['choices'][0]['message'] ?? null;
    if (!is_array($msg) || (empty($msg['content']) && empty($msg['tool_calls']))) {
        error_log('[setlo] Groq chat returned no content: ' . substr($raw, 0, 500));
        throw new GroqException("I couldn't come up with an answer. Try asking it another way.");
    }
    $out = ['role' => 'assistant', 'content' => $msg['content'] ?? null];
    if (!empty($msg['tool_calls'])) {
        $out['tool_calls'] = $msg['tool_calls'];
    }
    return $out;
}

/** One chat-completions request. Returns [HTTP status (0 = network error or timeout), body, Retry-After seconds]. */
function groq_request(string $body): array
{
    $retryAfter = 0;
    $ch = curl_init((string) (config('groq.base_url') ?: 'https://api.groq.com/openai/v1') . '/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . config('groq.api_key')],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => (int) (config('groq.timeout') ?: 30),
        CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$retryAfter) {
            if (stripos($line, 'retry-after:') === 0) {
                $retryAfter = (int) ceil((float) trim(substr($line, 12)));
            }
            return strlen($line);
        },
    ]);
    $raw = curl_exec($ch);
    $status = $raw === false ? 0 : (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($raw === false) {
        error_log('[setlo] Groq request failed: ' . curl_error($ch));
    }
    curl_close($ch);
    return [$status, (string) $raw, $retryAfter];
}
