<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Classifies a leaf image using a free vision model on OpenRouter.
 *
 * The model is prompted to choose exactly one of the known class labels and
 * return {class, confidence} — the same shape scripts/predict.py produces — so
 * the rest of the prediction flow stays identical regardless of which model ran.
 */
class OpenRouterClassifier
{
    /**
     * Selectable free vision models: request key => OpenRouter model id.
     * Both are NVIDIA-hosted (not Google AI Studio's saturated shared free pool),
     * so they stay available far more reliably.
     */
    public const MODELS = [
        'nemotron'    => 'nvidia/nemotron-3-nano-omni-30b-a3b-reasoning:free',
        'nemotron-vl' => 'nvidia/nemotron-nano-12b-v2-vl:free',
    ];

    /** Exact class labels — must match Plant.common_name and predict.py. */
    public const CLASSES = [
        'Arjun Leaf',
        'Curry Leaf',
        'Marsh Pennywort Leaf',
        'Mint Leaf',
        'Neem Leaf',
        'Rubble Leaf',
    ];

    public static function supports(string $modelKey): bool
    {
        return array_key_exists($modelKey, self::MODELS);
    }

    /**
     * @return array{class: string, confidence: float}
     */
    public function classify(string $imagePath, string $modelKey): array
    {
        if (! self::supports($modelKey)) {
            throw new RuntimeException("Unknown OpenRouter model: {$modelKey}");
        }

        $apiKey = config('services.openrouter.key');
        if (empty($apiKey)) {
            throw new RuntimeException('OPENROUTER_API_KEY is not set in .env.');
        }

        $classList = implode(', ', self::CLASSES);
        $prompt = "You are a botanical leaf classifier. Identify the leaf in the image as EXACTLY one of these classes: {$classList}. "
            . 'Respond with ONLY a compact JSON object and nothing else, in this exact form: '
            . '{"class": "<one of the class names above, copied verbatim>", "confidence": <number between 0 and 1>}. '
            . 'Do not add explanations or markdown.';

        $endpoint = rtrim(config('services.openrouter.base_url'), '/') . '/chat/completions';

        $payload = [
            'model'       => self::MODELS[$modelKey],
            'temperature' => 0,
            'messages'    => [[
                'role'    => 'user',
                'content' => [
                    ['type' => 'text', 'text' => $prompt],
                    ['type' => 'image_url', 'image_url' => ['url' => $this->toDataUrl($imagePath)]],
                ],
            ]],
        ];

        // Retry transient upstream errors (e.g. a free provider briefly returning 429/503).
        $response = null;
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $response = Http::withToken($apiKey)
                ->withHeaders([
                    // Optional but recommended by OpenRouter for free-tier attribution.
                    'HTTP-Referer' => config('app.url'),
                    'X-Title'      => config('app.name'),
                ])
                ->acceptJson()
                ->timeout(60)
                ->post($endpoint, $payload);

            if (! $response->failed() || ! in_array($response->status(), [429, 502, 503], true)) {
                break;
            }
            if ($attempt < 3) {
                usleep(1_500_000); // 1.5s backoff before retrying
            }
        }

        if ($response->failed()) {
            throw new RuntimeException('OpenRouter request failed (' . $response->status() . '): ' . $response->body());
        }

        $content = $response->json('choices.0.message.content');
        if (! is_string($content) || trim($content) === '') {
            throw new RuntimeException('OpenRouter returned an empty response.');
        }

        return $this->parseResult($content);
    }

    private function toDataUrl(string $imagePath): string
    {
        if (! is_file($imagePath)) {
            throw new RuntimeException("Image not found: {$imagePath}");
        }

        $mime = function_exists('mime_content_type') ? mime_content_type($imagePath) : null;
        if (! $mime || ! str_starts_with($mime, 'image/')) {
            $mime = 'image/jpeg';
        }

        return 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($imagePath));
    }

    /**
     * @return array{class: string, confidence: float}
     */
    private function parseResult(string $content): array
    {
        // Free models sometimes wrap JSON in prose/fences — grab the first object.
        if (preg_match('/\{.*\}/s', $content, $matches)) {
            $content = $matches[0];
        }

        $data = json_decode($content, true);
        if (! is_array($data) || ! isset($data['class'])) {
            throw new RuntimeException('Could not parse classifier JSON: ' . $content);
        }

        $class = $this->normalizeClass((string) $data['class']);
        if ($class === null) {
            throw new RuntimeException('Model returned an unknown class: ' . $data['class']);
        }

        $confidence = isset($data['confidence']) ? (float) $data['confidence'] : 0.0;

        return [
            'class'      => $class,
            'confidence' => max(0.0, min(1.0, $confidence)),
        ];
    }

    /** Match the model's free-text label to a known class (case-insensitive). */
    private function normalizeClass(string $raw): ?string
    {
        $raw = trim($raw);
        foreach (self::CLASSES as $label) {
            if (strcasecmp($raw, $label) === 0) {
                return $label;
            }
        }
        return null;
    }
}
