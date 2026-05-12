<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Carbon\Carbon;
use App\Models\User;
use App\Models\Meal;
use App\Services\NutritionAIService;
use App\Models\ProcessedMessage;

class BaleWebhookController extends Controller
{
    private $ai;

    public function __construct(NutritionAIService $ai)
    {
        $this->ai = $ai;
    }

    public function handle(Request $request)
    {
        $data = $request->all();
        \Log::info('BALE DATA', $data);
        $messageId = $data['message']['message_id'] ?? null;

        if ($messageId) {
            if (ProcessedMessage::where('message_id', $messageId)->exists()) {
                return response()->json(['ok' => true]);
            }

            ProcessedMessage::create(['message_id' => $messageId]);
        }

        /* ---------- Callbacks ---------- */

        if (isset($data['callback_query'])) {
            $this->handleCallback($data['callback_query']);
            return response()->json(['ok' => true]);
        }

        /* ---------- Validation ---------- */

        if (!isset($data['message']['text'])) {
            return response()->json(['ok' => true]);
        }

        $text = trim($data['message']['text']);
        $chat_id = $data['message']['chat']['id'];

        $user = User::firstOrCreate([
            'bale_chat_id' => $chat_id
        ]);

        /* ---------- Start ---------- */

        if ($text === '/start') {

            if ($user->step === 'completed') {
                $this->sendMainMenu(
                    $chat_id,
                    "قبلاً ثبت‌نام کردی ✅\nاز منوی زیر استفاده کن."
                );

                return response()->json(['ok' => true]);
            }

            $user->profile()->firstOrCreate([]);

            $this->sendMessage(
                $chat_id,
                "👋 سلام، خوش اومدی!\n\n".
                "من بهت کمک می‌کنم:\n".
                "• کالری و پروتئین غذات رو حساب کنی\n".
                "• مصرف روزانه‌ات رو ببینی\n".
                "• راحت‌تر به هدفت (کاهش یا افزایش وزن) برسی\n\n".
                "برای اینکه مقدار کالری مناسب بدنت رو محاسبه کنم،\n".
                "چند سوال کوتاه ازت می‌پرسم.\n\n".
                "شروع کنیم 👇"
            );

            $user->update([
                'step' => 'awaiting_gender'
            ]);

            $this->askGender($chat_id);

            return response()->json(['ok' => true]);
        }

        /* ---------- Main Menu ---------- */

        if ($user->step === 'completed' || str_starts_with($user->step, 'awaiting_') || str_starts_with($user->step, 'editing_')) {

            if ($text === '🍳 ثبت صبحانه') {

                $existingMeal = Meal::where('user_id', $user->id)
                    ->where('meal_type', 'breakfast')
                    ->whereDate('meal_time', Carbon::today())
                    ->exists();

                $user->update(['step' => 'awaiting_breakfast']);

                if ($existingMeal) {
                    $this->sendMessage($chat_id,
                        "برای امروز صبحانه ثبت شده.\n\n".
                        "اگر می‌خواهی ادامه بدهی غذا را بفرست.\n".
                        "اگر می‌خواهی از اول ثبت شود دکمه زیر را بزن.",
                        [
                            'keyboard' => [
                                [['text' => '🔄 پاک کردن صبحانه']],
                                [['text' => '✅ تمام شد']]
                            ],
                            'resize_keyboard' => true
                        ]
                    );
                } else {
                    $message = "صبحانه چی خوردی؟ 🍳\n\n".
                        "لطفاً مقدار تقریبی را هم بنویس.\n\n".
                        "مثال:\n".
                        "یک عدد تخم مرغ\n".
                        "2 عدد تخم مرغ\n".
                        "یک کف دست نان\n".
                        "30 گرم پنیر\n".
                        "یک لیوان شیر";

                    $this->sendMessage($chat_id, $message);
                }

                return response()->json(['ok' => true]);
            }

            if ($text === '🍱 ثبت ناهار') {

                $existingMeal = Meal::where('user_id', $user->id)
                    ->where('meal_type', 'lunch')
                    ->whereDate('meal_time', Carbon::today())
                    ->exists();

                $user->update(['step' => 'awaiting_lunch']);

                if ($existingMeal) {
                    $this->sendMessage($chat_id,
                        "برای امروز ناهار ثبت شده.\n\n".
                        "اگر می‌خواهی ادامه بدهی غذا را بفرست.\n".
                        "یا برای شروع دوباره دکمه زیر را بزن.",
                        [
                            'keyboard' => [
                                [['text' => '🔄 پاک کردن ناهار']],
                                [['text' => '✅ تمام شد']]
                            ],
                            'resize_keyboard' => true
                        ]
                    );
                } else {
                    $message = "ناهار چی خوردی؟ 🍽️\n\n".
                        "مثال:\n".
                        "یک بشقاب برنج\n".
                        "8 قاشق برنج\n".
                        "120 گرم مرغ\n".
                        "یک کاسه ماست";

                    $this->sendMessage($chat_id, $message);
                }

                return response()->json(['ok' => true]);
            }

            if ($text === '🍗 ثبت شام') {

                $existingMeal = Meal::where('user_id', $user->id)
                    ->where('meal_type', 'dinner')
                    ->whereDate('meal_time', Carbon::today())
                    ->exists();

                $user->update(['step' => 'awaiting_dinner']);

                if ($existingMeal) {
                    $this->sendMessage($chat_id,
                        "برای امروز شام ثبت شده.\n\n".
                        "اگر می‌خواهی ادامه بدهی غذا را بفرست.\n".
                        "یا برای شروع دوباره دکمه زیر را بزن.",
                        [
                            'keyboard' => [
                                [['text' => '🔄 پاک کردن شام']],
                                [['text' => '✅ تمام شد']]
                            ],
                            'resize_keyboard' => true
                        ]
                    );
                } else {
                    $message = "شام چی خوردی؟ 🌙\n\n".
                        "مثال:\n".
                        "دو کف دست نان\n".
                        "80 گرم مرغ\n".
                        "یک کاسه ماست";

                    $this->sendMessage($chat_id, $message);
                }

                return response()->json(['ok' => true]);
            }

            if ($text === '🍎 میان وعده') {

                $existingMeal = Meal::where('user_id', $user->id)
                    ->where('meal_type', 'snack')
                    ->whereDate('meal_time', Carbon::today())
                    ->exists();

                $user->update(['step' => 'awaiting_snack']);

                if ($existingMeal) {
                    $this->sendMessage($chat_id,
                        "برای امروز میان وعده ثبت شده.\n\n".
                        "اگر می‌خواهی ادامه بدهی غذا را بفرست.\n".
                        "یا برای شروع دوباره دکمه زیر را بزن.",
                        [
                            'keyboard' => [
                                [['text' => '🔄 پاک کردن میان وعده']],
                                [['text' => '✅ تمام شد']]
                            ],
                            'resize_keyboard' => true
                        ]
                    );
                } else {
                    $message = "میان وعده چی خوردی؟ 🍎\n\n".
                        "مثال:\n".
                        "یک سیب\n".
                        "10 عدد بادام\n".
                        "یک لیوان شیر";

                    $this->sendMessage($chat_id, $message);
                }

                return response()->json(['ok' => true]);
            }

            if ($text === '🔄 پاک کردن صبحانه') {
                $this->resetMeal($user, 'breakfast');
                $user->update(['step' => 'awaiting_breakfast']);

                $this->sendMessage($chat_id,
                    "صبحانه پاک شد ✅\nحالا صبحانه جدید را بفرست.",
                    [
                        'keyboard' => [
                            [['text' => '✅ تمام شد']]
                        ],
                        'resize_keyboard' => true
                    ]
                );

                return response()->json(['ok' => true]);
            }

            if ($text === '🔄 پاک کردن ناهار') {
                $this->resetMeal($user, 'lunch');
                $user->update(['step' => 'awaiting_lunch']);

                $this->sendMessage($chat_id,
                    "ناهار پاک شد ✅\nحالا ناهار جدید را بفرست.",
                    [
                        'keyboard' => [
                            [['text' => '✅ تمام شد']]
                        ],
                        'resize_keyboard' => true
                    ]
                );

                return response()->json(['ok' => true]);
            }

            if ($text === '🔄 پاک کردن شام') {
                $this->resetMeal($user, 'dinner');
                $user->update(['step' => 'awaiting_dinner']);

                $this->sendMessage($chat_id,
                    "شام پاک شد ✅\nحالا شام جدید را بفرست.",
                    [
                        'keyboard' => [
                            [['text' => '✅ تمام شد']]
                        ],
                        'resize_keyboard' => true
                    ]
                );

                return response()->json(['ok' => true]);
            }

            if ($text === '🔄 پاک کردن میان وعده') {
                $this->resetMeal($user, 'snack');
                $user->update(['step' => 'awaiting_snack']);

                $this->sendMessage($chat_id,
                    "میان وعده پاک شد ✅\nحالا میان وعده جدید را بفرست.",
                    [
                        'keyboard' => [
                            [['text' => '✅ تمام شد']]
                        ],
                        'resize_keyboard' => true
                    ]
                );

                return response()->json(['ok' => true]);
            }

            if ($text === '👤 پروفایل من') {

                $profile = $user->profile;

                $goalText = [
                    'loss' => 'کاهش وزن',
                    'gain' => 'افزایش وزن',
                    'maintain' => 'ثابت نگه داشتن'
                ];

                $activityText = [
                    'low' => 'کم',
                    'medium' => 'متوسط',
                    'high' => 'زیاد'
                ];

                $genderText = $profile->gender === 'male' ? 'آقا' : 'خانم';

                $message =
                    "👤 پروفایل شما\n\n".
                    "جنسیت: {$genderText}\n".
                    "سن: {$profile->age}\n".
                    "وزن: {$profile->weight} کیلو\n".
                    "قد: {$profile->height} سانتی‌متر\n".
                    "هدف: ".$goalText[$profile->goal]."\n".
                    "فعالیت: ".$activityText[$profile->activity_level]."\n\n".
                    "کالری روزانه هدف: {$profile->calorie_target} kcal";

                $this->sendMessage($chat_id, $message, [
                    'keyboard' => [
                        [['text' => '✏️ ویرایش وزن']],
                        [['text' => '✏️ ویرایش هدف']],
                        [['text' => '✏️ ویرایش فعالیت']],
                        [['text' => '🔙 بازگشت']]
                    ],
                    'resize_keyboard' => true
                ]);

                return response()->json(['ok' => true]);
            }

            if ($text === '✏️ ویرایش وزن') {
                $user->update([
                    'step' => 'editing_weight'
                ]);

                $this->sendMessage($chat_id, "وزن جدید را وارد کن.\nمثال: 78");
                return response()->json(['ok' => true]);
            }

            if ($text === '✏️ ویرایش هدف') {
                $user->update([
                    'step' => 'editing_goal'
                ]);

                $this->askGoal($chat_id);
                return response()->json(['ok' => true]);
            }

            if ($text === '✏️ ویرایش فعالیت') {
                $user->update([
                    'step' => 'editing_activity'
                ]);

                $this->askActivity($chat_id);
                return response()->json(['ok' => true]);
            }

            if ($text === '🔙 بازگشت') {
                $user->update([
                    'step' => 'completed'
                ]);

                $this->sendMainMenu($chat_id, "منوی اصلی");
                return response()->json(['ok' => true]);
            }

            if ($text === '📊 گزارش امروز') {
                $this->sendTodayReport($user, $chat_id);
                return response()->json(['ok' => true]);
            }

            if ($text === '✅ تمام شد') {
                $user->update(['step' => 'completed']);
                $this->sendMainMenu($chat_id, "وعده ثبت شد ✅");
                return response()->json(['ok' => true]);
            }
        }

        /* ---------- State Machine ---------- */

        switch ($user->step) {

            case 'awaiting_age':

                if (!is_numeric($text)) {
                    $this->sendMessage($chat_id, "سن معتبر وارد کنید");
                    break;
                }

                $user->profile->update([
                    'age' => $text
                ]);

                $user->update([
                    'step' => 'awaiting_weight'
                ]);

                $this->sendMessage($chat_id, "وزن شما چند کیلوگرم است؟");

                break;

            case 'awaiting_weight':

                if (!is_numeric($text)) {
                    $this->sendMessage($chat_id, "وزن معتبر وارد کنید");
                    break;
                }

                $user->profile->update([
                    'weight' => $text
                ]);

                $user->update([
                    'step' => 'awaiting_height'
                ]);

                $this->sendMessage($chat_id, "قد شما چند سانتی‌متر است؟");

                break;

            case 'awaiting_height':

                if (!is_numeric($text)) {
                    $this->sendMessage($chat_id, "قد معتبر وارد کنید");
                    break;
                }

                $user->profile->update([
                    'height' => $text
                ]);

                $user->update([
                    'step' => 'awaiting_goal'
                ]);

                $this->askGoal($chat_id);

                break;

            case 'editing_weight':

                if (!is_numeric($text)) {
                    $this->sendMessage($chat_id, "فقط عدد وارد کن");
                    break;
                }

                $user->profile->update([
                    'weight' => $text
                ]);

                $newCalories = $this->calculateCalories($user->profile);

                $user->profile->update([
                    'calorie_target' => $newCalories
                ]);

                $user->update([
                    'step' => 'completed'
                ]);

                $this->sendMainMenu(
                    $chat_id,
                    "✅ وزن بروزرسانی شد\nکالری جدید: {$newCalories} kcal"
                );

                break;

            case 'awaiting_breakfast':
            case 'awaiting_lunch':
            case 'awaiting_dinner':
            case 'awaiting_snack':

                $this->recordMealItem($user, $text);

                $this->sendMessage($chat_id,
                    "✅ ثبت شد\nاگر چیز دیگری خوردی بفرست یا دکمه «تمام شد» را بزن",
                    [
                        'keyboard' => [
                            [['text' => '✅ تمام شد']]
                        ],
                        'resize_keyboard' => true
                    ]
                );

                break;
        }

        return response()->json(['ok' => true]);
    }

    /* ---------- Record Meal ---------- */

    private function recordMealItem($user, $text)
    {
        $type = str_replace('awaiting_', '', $user->step);

        $meal = Meal::firstOrCreate(
            [
                'user_id' => $user->id,
                'meal_type' => $type,
                'meal_time' => Carbon::today()
            ],
            [
                'total_calories' => 0,
                'total_protein' => 0
            ]
        );

        $foods = $this->ai->analyze($text);

        $totalCalories = 0;
        $totalProtein = 0;

        foreach ($foods as $food) {
            $cal = $food['calories'] ?? 0;
            $pro = $food['protein'] ?? 0;

            $meal->items()->create([
                'name' => $food['name'] ?? 'food',
                'calories' => $cal,
                'protein' => $pro
            ]);

            $totalCalories += $cal;
            $totalProtein += $pro;
        }

        $meal->increment('total_calories', $totalCalories);
        $meal->increment('total_protein', $totalProtein);
    }

    /* ---------- Report ---------- */

    private function sendTodayReport($user, $chat_id)
    {
        $meals = Meal::where('user_id', $user->id)
            ->whereDate('meal_time', Carbon::today())
            ->with('items')
            ->get()
            ->keyBy('meal_type');

        if ($meals->isEmpty()) {
            $this->sendMessage($chat_id, "امروز هنوز غذایی ثبت نکردی 🍽️");
            return;
        }

        $mealTypes = [
            'breakfast' => 'صبحانه',
            'lunch' => 'ناهار',
            'dinner' => 'شام',
            'snack' => 'میان وعده'
        ];

        $dailyFoodText = '';
        $actualCalories = 0;
        $actualProtein = 0;

        foreach ($mealTypes as $type => $title) {
            $dailyFoodText .= "{$title}:\n";

            if (isset($meals[$type]) && $meals[$type]->items->count()) {

                foreach ($meals[$type]->items as $item) {
                    $dailyFoodText .= "• {$item->name}\n";
                }

                $actualCalories += $meals[$type]->total_calories ?? 0;
                $actualProtein += $meals[$type]->total_protein ?? 0;

            } else {
                $dailyFoodText .= "• ثبت نشده\n";
            }

            $dailyFoodText .= "\n";
        }

        $profile = $user->profile;

        $targetCalories = $profile->calorie_target;
        $targetProtein = round($profile->weight * 1.6);

        $analysis = $this->ai->analyzeDailyReport(
            $dailyFoodText,
            $profile,
            $targetCalories,
            $targetProtein,
            $actualCalories,
            $actualProtein
        );

        $this->sendMessage($chat_id, $analysis);
    }

    /* ---------- Callbacks ---------- */

    private function handleCallback($callback)
    {
        $data = $callback['data'];
        $chat_id = $callback['message']['chat']['id'];

        $user = User::where('bale_chat_id', $chat_id)->first();

        if (!$user) {
            return;
        }

        if (str_contains($data, 'gender')) {

            $gender = str_replace('gender_', '', $data);

            $user->profile()->firstOrCreate([]);

            $user->profile->update([
                'gender' => $gender
            ]);

            $user->update([
                'step' => 'awaiting_age'
            ]);

            $this->sendMessage($chat_id, "سن شما چند سال است؟");
            return;
        }

        if (str_contains($data, 'goal')) {

            $goal = str_replace('goal_', '', $data);

            $user->profile->update([
                'goal' => $goal
            ]);

            if ($user->step === 'editing_goal') {

                $newCalories = $this->calculateCalories($user->profile);

                $user->profile->update([
                    'calorie_target' => $newCalories
                ]);

                $user->update([
                    'step' => 'completed'
                ]);

                $this->sendMainMenu(
                    $chat_id,
                    "✅ هدف بروزرسانی شد\nکالری جدید: {$newCalories} kcal"
                );

            } else {

                $user->update([
                    'step' => 'awaiting_activity'
                ]);

                $this->askActivity($chat_id);
            }

            return;
        }

        if (str_contains($data, 'activity')) {

            $activity = str_replace('activity_', '', $data);

            $user->profile->update([
                'activity_level' => $activity
            ]);

            if ($user->step === 'editing_activity') {

                $newCalories = $this->calculateCalories($user->profile);

                $user->profile->update([
                    'calorie_target' => $newCalories
                ]);

                $user->update([
                    'step' => 'completed'
                ]);

                $this->sendMainMenu(
                    $chat_id,
                    "✅ سطح فعالیت بروزرسانی شد\nکالری جدید: {$newCalories} kcal"
                );

            } else {

                $this->finishProfile($user, $chat_id);
            }

            return;
        }
    }

    /* ---------- Finish Profile ---------- */

    private function finishProfile($user, $chat_id)
    {
        $profile = $user->profile;

        $calories = $this->calculateCalories($profile);

        $profile->update([
            'calorie_target' => $calories
        ]);

        $user->update([
            'step' => 'completed'
        ]);

        $this->sendMainMenu($chat_id,
            "✅ پروفایل ساخته شد\n\nهدف کالری روزانه شما: $calories kcal"
        );
    }

    private function resetMeal($user, $mealType)
    {
        $meal = Meal::where('user_id', $user->id)
            ->where('meal_type', $mealType)
            ->whereDate('meal_time', Carbon::today())
            ->with('items')
            ->first();

        if (!$meal) {
            return;
        }

        $meal->items()->delete();

        $meal->update([
            'total_calories' => 0,
            'total_protein' => 0
        ]);
    }


    /* ---------- Calories ---------- */

    private function calculateCalories($profile)
    {
        $weight = $profile->weight;
        $height = $profile->height;
        $age = $profile->age;

        if ($profile->gender === 'male') {
            $bmr = 10*$weight + 6.25*$height - 5*$age + 5;
        } else {
            $bmr = 10*$weight + 6.25*$height - 5*$age - 161;
        }

        $activity = [
            'low' => 1.2,
            'medium' => 1.55,
            'high' => 1.72
        ];

        $tdee = $bmr * ($activity[$profile->activity_level] ?? 1.2);

        if ($profile->goal === 'loss') {
            $tdee -= 500;
        }

        if ($profile->goal === 'gain') {
            $tdee += 300;
        }

        return round($tdee);
    }

    /* ---------- UI ---------- */

    private function sendMainMenu($chat_id, $text)
    {
        $keyboard = [
            'keyboard' => [
                [['text' => '🍳 ثبت صبحانه'], ['text' => '🍱 ثبت ناهار']],
                [['text' => '🍗 ثبت شام'], ['text' => '🍎 میان وعده']],
                [['text' => '📊 گزارش امروز']],
                [['text' => '👤 پروفایل من']]
            ],
            'resize_keyboard' => true
        ];

        $this->sendMessage($chat_id, $text, $keyboard);
    }

    private function askGender($chat_id)
    {
        $this->sendMessage($chat_id,"جنسیت شما؟",[
            'inline_keyboard'=>[
                [
                    ['text'=>'آقا','callback_data'=>'gender_male'],
                    ['text'=>'خانم','callback_data'=>'gender_female']
                ]
            ]
        ]);
    }

    private function askGoal($chat_id)
    {
        $this->sendMessage($chat_id,"هدف شما؟",[
            'inline_keyboard'=>[
                [['text'=>'کاهش وزن','callback_data'=>'goal_loss']],
                [['text'=>'افزایش وزن','callback_data'=>'goal_gain']],
                [['text'=>'ثابت نگه داشتن','callback_data'=>'goal_maintain']]
            ]
        ]);
    }

    private function askActivity($chat_id)
    {
        $this->sendMessage($chat_id,"سطح فعالیت؟",[
            'inline_keyboard'=>[
                [['text'=>'کم','callback_data'=>'activity_low']],
                [['text'=>'متوسط','callback_data'=>'activity_medium']],
                [['text'=>'زیاد','callback_data'=>'activity_high']]
            ]
        ]);
    }

    /* ---------- Send Message ---------- */

    private function sendMessage($chat_id,$text,$reply_markup=null)
    {
        $payload = [
            'chat_id'=>$chat_id,
            'text'=>$text
        ];

        if($reply_markup){
            $payload['reply_markup']=json_encode($reply_markup);
        }

        Http::post(
            "https://tapi.bale.ai/bot".env('BALE_BOT_TOKEN')."/sendMessage",
            $payload
        );
    }
}
