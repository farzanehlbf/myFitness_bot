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
    public function analyzeDailyReport($dailyFoodText, $profile)
    {
        $age = $profile->age;
        $height = $profile->height;
        $weight = $profile->weight;
        $activity = $profile->activity_level;
        $goal = $profile->goal;
        $gender = $profile->gender;

        $prompt = "

غذاهای مصرف شده امروز:

$dailyFoodText


اطلاعات کاربر:

سن: $age
قد: $height
وزن: $weight
جنسیت: $gender
سطح فعالیت: $activity
هدف: $goal


بر اساس اطلاعات بالا تحلیل کن:

1- امروز تقریبا چند کالری مصرف شده
2- کالری مناسب روزانه برای این فرد چقدر است
3- پروتئین مصرفی امروز چقدر است
4- پروتئین مناسب روزانه چقدر است

پاسخ کوتاه و قابل فهم بده و از ایموجی استفاده کن.

فرمت خروجی دقیقا این باشد:

امروز n کالری دریافت کردی که مقدار مناسب برای تو n کالری است.

امروز n گرم پروتئین دریافت کردی که مقدار مناسب برای تو n گرم است.

📊 جمع‌بندی کوتاه

❌ نکات منفی امروز

✅ نکات مثبت امروز

💡 توصیه های کوتاه
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
