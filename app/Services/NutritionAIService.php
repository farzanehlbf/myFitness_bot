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
    public function analyzeDailyReport($dailyFoodText)
    {

        $prompt = "

$dailyFoodText

این ها چیز هایی هست که من امروز خوردم با دقت نگاه کن و بگو به صورت میانگین چند کالری دریافت کردم و چند کالری باید دریافت میکردم .
همچنین پروتئین را هم بررسی کن.

اطلاعات من:
سن: 26
قد: 164
وزن: 74

فعالیت:
- روزانه 60 تا 90 دقیقه پیاده روی
- شغل پشت سیستم

در پاسخ:
- خلاصه بنویس
- از ایموجی استفاده کن

پایان پاسخ حتما این ساختار باشد:

امروز n کالری دریافت کردی که نرمالش n تا بوده (برای کم کردن 5 کیلو در 30 روز)

امروز n پروتئین دریافت کردی که پروتئین نرمالش n تا بوده (برای کم کردن 5 کیلو در 30 روز)

بعد این بخش ها را بده:

📊 برایند کوتاه

❌ چیزهای بد امروز

✅ چیزهای خوب امروز

توصیه های کوتاه
";

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . env('GROQ_API_KEY'),
            'Content-Type' => 'application/json',
        ])->post('https://api.groq.com/openai/v1/chat/completions', [

            'model' => 'llama-3.3-70b-versatile',

            'messages' => [
                [
                    'role' => 'user',
                    'content' => $prompt
                ]
            ],

            'temperature' => 0.4
        ]);

        return $response['choices'][0]['message']['content'] ?? "تحلیل انجام نشد";
    }
}
