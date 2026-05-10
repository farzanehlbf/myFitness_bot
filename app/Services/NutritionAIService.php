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
    public function analyzeDailyReport(
        $dailyFoodText,
        $profile,
        $targetCalories,
        $targetProtein
    )
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

کالری هدف روزانه:
$targetCalories

پروتئین هدف روزانه:
$targetProtein گرم


وظیفه تو:

1- تخمین بزن امروز چند کالری مصرف شده
2- تخمین بزن چند گرم پروتئین مصرف شده
3- وضعیت امروز را تحلیل کن
4- پاسخ کوتاه و کاربردی باشد
5- از ایموجی استفاده کن


فرمت پاسخ:


🔥 کالری مصرفی امروز: n
🎯 کالری هدف روزانه: $targetCalories

💪 پروتئین مصرفی امروز: n
🎯 پروتئین هدف روزانه: $targetProtein


📊 جمع‌بندی کوتاه

❌ نکات منفی امروز

✅ نکات مثبت امروز

💡 توصیه کوتاه
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

        return $response['choices'][0]['message']['content']
            ?? "تحلیل انجام نشد";
    }
}
