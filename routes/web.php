<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Request;

Route::get('/', function () {
    return response()->json([
        'status' => 'ok',
        'message' => 'Laravel Bale Webhook is running!'
    ]);
});

Route::post('/bale/webhook', function (Request $request) {

    $data = $request->all();

    if (!isset($data['message']['text'])) {
        return response()->json(['ok' => true]);
    }

    $text = $data['message']['text'];
    $chat_id = $data['message']['chat']['id'];

    // ارسال متن به AI
    $ai = Http::withHeaders([
        'Authorization' => 'Bearer ' . env('GROQ_API_KEY'),
        'Content-Type' => 'application/json',
    ])->post('https://api.groq.com/openai/v1/chat/completions', [

        'model' => 'llama-3.3-70b-versatile',

        'messages' => [
            [
                'role' => 'system',
                'content' => 'You are a nutrition analyzer. Extract foods and estimate calories and protein. Respond only with JSON in this format: {"items":[{"name":"food","calories":number,"protein":number}],"total_calories":number,"total_protein":number}'
            ],
            [
                'role' => 'user',
                'content' => $text
            ]
        ],

        'temperature' => 0.2
    ]);

    $content = $ai['choices'][0]['message']['content'];

    // ارسال پاسخ به بله
    Http::post("https://tapi.bale.ai/bot".env('BALE_BOT_TOKEN')."/sendMessage", [
        'chat_id' => $chat_id,
        'text' => $content
    ]);

    return response()->json(['ok' => true]);
});
Route::get('/test-ai', function () {

    $response = Http::withHeaders([
        'Authorization' => 'Bearer ' . env('GROQ_API_KEY'),
        'Content-Type' => 'application/json',
    ])->post('https://api.groq.com/openai/v1/chat/completions', [

        'model' => 'llama-3.3-70b-versatile',

        'messages' => [
            [
                'role' => 'system',
                'content' => 'You are a nutrition analyzer. Extract foods from Persian text and estimate calories and protein. Respond ONLY with raw JSON. Do not use markdown or code blocks.'
            ],
            [
                'role' => 'user',
                'content' => 'صبحانه خوردم: یک لیوان شیر و دو عدد خرما'
            ]
        ],

        'temperature' => 0.2
    ]);

    return $response->json();
});