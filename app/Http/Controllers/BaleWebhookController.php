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

            // اگر قبلا ثبت نام کامل شده
            if ($user->step === 'completed') {

                $this->sendMainMenu(
                    $chat_id,
                    "قبلاً ثبت‌نام کردی ✅\nاز منوی زیر استفاده کن."
                );

                return response()->json(['ok' => true]);
            }

            $user->profile()->firstOrCreate([]);

            $user->update([
                'step' => 'awaiting_gender'
            ]);

            $this->askGender($chat_id);

            return response()->json(['ok' => true]);
        }

        /* ---------- Main Menu ---------- */

        if ($user->step === 'completed' || str_starts_with($user->step, 'awaiting_')) {

            if ($text === '🍳 ثبت صبحانه') {
                $user->update(['step' => 'awaiting_breakfast']);
                $this->sendMessage($chat_id, "صبحانه چی خوردی؟");
                return response()->json(['ok' => true]);
            }

            if ($text === '🍱 ثبت ناهار') {
                $user->update(['step' => 'awaiting_lunch']);
                $this->sendMessage($chat_id, "ناهار چی خوردی؟");
                return response()->json(['ok' => true]);
            }

            if ($text === '🍗 ثبت شام') {
                $user->update(['step' => 'awaiting_dinner']);
                $this->sendMessage($chat_id, "شام چی خوردی؟");
                return response()->json(['ok' => true]);
            }

            if ($text === '🍎 میان وعده') {
                $user->update(['step' => 'awaiting_snack']);
                $this->sendMessage($chat_id, "میان وعده چی خوردی؟");
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

                $user->profile->update([
                    'weight' => $text
                ]);

                $user->update([
                    'step' => 'awaiting_height'
                ]);

                $this->sendMessage($chat_id, "قد شما چند سانتی متر است؟");

                break;

            case 'awaiting_height':

                $user->profile->update([
                    'height' => $text
                ]);

                $user->update([
                    'step' => 'awaiting_goal'
                ]);

                $this->askGoal($chat_id);

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

        $meal = Meal::firstOrCreate([
            'user_id' => $user->id,
            'meal_type' => $type,
            'meal_time' => Carbon::today()
        ]);

        $meal->items()->delete();

        $meal->update([
            'total_calories' => 0,
            'total_protein' => 0
        ]);

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

        $meal->update([
            'total_calories' => $totalCalories,
            'total_protein' => $totalProtein
        ]);
    }

    /* ---------- Report ---------- */

    private function sendTodayReport($user, $chat_id)
    {
        $meals = Meal::where('user_id', $user->id)
            ->whereDate('meal_time', Carbon::today())
            ->with('items')
            ->get();

        $totalCal = $meals->sum('total_calories');
        $totalProt = $meals->sum('total_protein');

        $target = $user->profile->daily_calories ?? 2000;

        $text = "📊 گزارش امروز\n\n";

        foreach ($meals as $meal) {

            $items = $meal->items->pluck('name')->implode(' ، ');

            $text .= $meal->meal_type . ":\n";
            $text .= $items . "\n\n";
        }

        $text .= "🔥 کالری: $totalCal / $target\n";
        $text .= "💪 پروتئین: $totalProt g";

        $this->sendMessage($chat_id, $text);
    }

    /* ---------- Callbacks ---------- */

    private function handleCallback($callback)
    {
        $data = $callback['data'];
        $chat_id = $callback['message']['chat']['id'];

        $user = User::where('bale_chat_id', $chat_id)->first();

        if (str_contains($data, 'gender')) {

            $gender = str_replace('gender_', '', $data);

            $user->profile->update([
                'gender' => $gender
            ]);

            $user->update([
                'step' => 'awaiting_age'
            ]);

            $this->sendMessage($chat_id, "سن شما چند سال است؟");
        }

        if (str_contains($data, 'goal')) {

            $goal = str_replace('goal_', '', $data);

            $user->profile->update([
                'goal' => $goal
            ]);

            $user->update([
                'step' => 'awaiting_activity'
            ]);

            $this->askActivity($chat_id);
        }

        if (str_contains($data, 'activity')) {

            $activity = str_replace('activity_', '', $data);

            $user->profile->update([
                'activity_level' => $activity
            ]);

            $this->finishProfile($user, $chat_id);
        }
    }

    /* ---------- Finish Profile ---------- */

    private function finishProfile($user, $chat_id)
    {
        $profile = $user->profile;

        $calories = $this->calculateCalories($profile);

        $profile->update([
            'daily_calories' => $calories
        ]);

        $user->update([
            'step' => 'completed'
        ]);

        $this->sendMainMenu($chat_id,
            "✅ پروفایل ساخته شد\n\nهدف کالری روزانه شما: $calories kcal"
        );
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
                [['text'=>'🍳 ثبت صبحانه'],['text'=>'🍱 ثبت ناهار']],
                [['text'=>'🍗 ثبت شام'],['text'=>'🍎 میان وعده']],
                [['text'=>'📊 گزارش امروز']]
            ],
            'resize_keyboard'=>true
        ];

        $this->sendMessage($chat_id,$text,$keyboard);
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
