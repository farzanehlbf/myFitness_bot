<?php


namespace App\Services;

use Illuminate\Support\Facades\Http;

class NutritionAIService
{
    public function analyze($text)
    {
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . env('GROQ_API_KEY'),
            'Content-Type' => 'application/json',
        ])->post('https://api.groq.com/openai/v1/chat/completions', [

            'model' => 'llama-3.3-70b-versatile',

            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'Extract foods from Persian text and estimate calories and protein. Respond ONLY JSON like: [{"name":"milk","calories":120,"protein":8}]'
                ],
                [
                    'role' => 'user',
                    'content' => $text
                ]
            ],

            'temperature' => 0.2
        ]);

        $content = $response['choices'][0]['message']['content'] ?? '[]';

        $foods = json_decode($content, true);

        if (!$foods) {
            return [];
        }

        return $foods;
    }
}
