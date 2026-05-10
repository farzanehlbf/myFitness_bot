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
        $targetProtein,
        $actualCalories,
        $actualProtein
    )
    {
        $age = $profile->age;
        $height = $profile->height;
        $weight = $profile->weight;
        $activity = $profile->activity_level;
        $goal = $profile->goal;
        $gender = $profile->gender;

        $prompt = "
اطلاعات کاربر:
سن: $age | قد: $height | وزن: $weight | جنسیت: $gender | سطح فعالیت: $activity | هدف: $goal

اهداف تعیین شده:
- کالری هدف: $targetCalories
- پروتئین هدف: $targetProtein گرم

مقادیر واقعی مصرف امروز (محاسبه شده از دیتابیس):
- کالری مصرفی: $actualCalories
- پروتئین مصرفی: $actualProtein گرم

لیست غذاهای مصرف شده امروز:
$dailyFoodText

دستورالعمل مهم:
کالری و پروتئین مصرفی از قبل محاسبه شده است. از همین اعداد استفاده کن و دوباره محاسبه یا تخمین نزن.

وظیفه تو:
1. لیست غذاها را به تفکیک وعده‌ها (صبحانه، ناهار، شام، میان‌وعده) مرتب کن.
2. بر اساس مقادیر واقعی مصرفی و اهداف کاربر تحلیل انجام بده.
3. وضعیت مصرف کالری و پروتئین را با اهداف مقایسه کن.
4. بخش‌های 'بیشترین منبع کالری'، 'کمبود اصلی' و 'بهترین وعده' را مشخص کن.
5. پاسخ را دقیقاً در قالب فرمت زیر به زبان فارسی ارائه بده.

فرمت مورد نظر برای پاسخ:

📅 گزارش تغذیه امروز

🍽 غذاهای ثبت شده:

☀️ صبحانه
• [لیست آیتم‌ها]

🍛 ناهار
• [لیست آیتم‌ها]

🌙 شام
• [لیست آیتم‌ها]

🍎 میان وعده
• [لیست آیتم‌ها یا 'ثبت نشده']

━━━━━━━━━━━━

🔥 کالری مصرفی: $actualCalories
🎯 کالری هدف: $targetCalories

💪 پروتئین مصرفی: $actualProtein g
🎯 پروتئین هدف: $targetProtein g

━━━━━━━━━━━━

📊 تحلیل امروز

✅ نکات مثبت
• [مورد اول]
• [مورد دوم]

❌ نکات قابل بهبود
• [مورد اول]
• [مورد دوم]

🍚 بیشترین منبع کالری امروز
[نام غذا یا گروه غذایی]

⚠️ کمبود اصلی امروز
[مثلاً فیبر، پروتئین، آب یا ...]

⭐ بهترین وعده امروز
[نام وعده و علت کوتاه]

💡 پیشنهاد فردا
• [یک توصیه کاربردی]
";

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . env('GROQ_API_KEY'),
            'Content-Type' => 'application/json',
        ])->post('https://api.groq.com/openai/v1/chat/completions', [
            'model' => 'llama-3.3-70b-versatile',
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'تو یک مربی تغذیه حرفه‌ای و دقیق هستی که به زبان فارسی پاسخ می‌دهی.'
                ],
                [
                    'role' => 'user',
                    'content' => $prompt
                ]
            ],
            'temperature' => 0.4,
            'max_tokens' => 1500
        ]);

        return $response['choices'][0]['message']['content']
            ?? "متأسفانه در حال حاضر قادر به تحلیل گزارش نیستم. لطفا دوباره تلاش کنید.";
    }
}
