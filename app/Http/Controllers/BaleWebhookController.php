<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use App\Models\User;
use App\Models\Meal;
use App\Models\MealItem;
use Carbon\Carbon;

class BaleWebhookController extends Controller
{
    public function handle(Request $request)
    {
        $data = $request->all();

        /* ---------------- 1. Handling Callbacks (Profile Setup) ---------------- */
        if (isset($data['callback_query'])) {
            $this->handleCallback($data['callback_query']);
            return response()->json(['ok' => true]);
        }

        /* ---------------- 2. Basic Validation ---------------- */
        if (!isset($data['message']['text'])) {
            return response()->json(['ok' => true]);
        }

        $text = trim($data['message']['text']);
        $chat_id = $data['message']['chat']['id'];

        $user = User::firstOrCreate(['bale_chat_id' => $chat_id]);

        /* ---------------- 3. Global Commands (Start) ---------------- */
        if ($text == '/start') {
            $user->profile()->firstOrCreate([]);
            $user->update(['step' => 'awaiting_gender']);
            $this->askGender($chat_id);
            return response()->json(['ok' => true]);
        }

        /* ---------------- 4. Main Menu Logic (If Completed) ---------------- */
        if ($user->step === 'completed' || in_array($user->step, ['awaiting_breakfast', 'awaiting_lunch', 'awaiting_dinner', 'awaiting_snack'])) {

            if ($text === '🍳 ثبت صبحانه') {
                $user->update(['step' => 'awaiting_breakfast']);
                $this->sendMessage($chat_id, "صبحانه چی خوردی؟ (مثلاً: نان و پنیر و گردو)");
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
                $this->sendMainMenu($chat_id, "وعده شما ثبت شد. به منوی اصلی برگشتیم.");
                return response()->json(['ok' => true]);
            }
        }

        /* ---------------- 5. State Machine Logic ---------------- */
        switch ($user->step) {

            // --- Profile Steps ---
            case 'awaiting_age':
                if (!is_numeric($text) || $text < 5 || $text > 100) {
                    $this->sendMessage($chat_id, "لطفاً سن معتبر (۵ تا ۱۰۰) وارد کنید.");
                    break;
                }
                $user->profile->update(['age' => (int)$text]);
                $user->update(['step' => 'awaiting_weight']);
                $this->sendMessage($chat_id, "وزن شما چند کیلوگرم است؟");
                break;

            case 'awaiting_weight':
                if (!is_numeric($text) || $text < 20 || $text > 300) {
                    $this->sendMessage($chat_id, "لطفاً وزن معتبر وارد کنید.");
                    break;
                }
                $user->profile->update(['weight' => (int)$text]);
                $user->update(['step' => 'awaiting_height']);
                $this->sendMessage($chat_id, "قد شما چند سانتی‌متر است؟");
                break;

            case 'awaiting_height':
                if (!is_numeric($text) || $text < 100 || $text > 250) {
                    $this->sendMessage($chat_id, "لطفاً قد معتبر وارد کنید.");
                    break;
                }
                $user->profile->update(['height' => (int)$text]);
                $user->update(['step' => 'awaiting_goal']);
                $this->askGoal($chat_id);
                break;

            // --- Meal Recording Steps ---
            case 'awaiting_breakfast':
            case 'awaiting_lunch':
            case 'awaiting_dinner':
            case 'awaiting_snack':
                $this->recordMealItem($user, $text);
                $this->sendMessage($chat_id, "ثبت شد ✅\nچیز دیگری هم خوردی؟ (اگر نه، دکمه «تمام شد» را بزنید)", [
                    'keyboard' => [[['text' => '✅ تمام شد']]],
                    'resize_keyboard' => true
                ]);
                break;
        }

        return response()->json(['ok' => true]);
    }

    /* ---------------- Helpers for Meals ---------------- */

    private function recordMealItem($user, $description)
    {
        $type = str_replace('awaiting_', '', $user->step);

        // پیدا کردن یا ساختن رکورد اصلی وعده برای امروز
        $meal = Meal::firstOrCreate([
            'user_id'   => $user->id,
            'meal_type' => $type,
            'meal_time' => Carbon::today(),
        ]);

        // اضافه کردن آیتم به لیست غذاها (فعلا بدون کالری تا مرحله AI)
        $meal->items()->create([
            'name'     => $description,
            'calories' => 0,
            'protein'  => 0
        ]);
    }

    private function sendTodayReport($user, $chat_id)
    {
        $meals = Meal::where('user_id', $user->id)
            ->whereDate('meal_time', Carbon::today())
            ->with('items')
            ->get();

        $totalCal = $meals->sum('total_calories');
        $totalProt = $meals->sum('total_protein');
        $target = $user->profile->daily_calories ?? 2000;

        $report = "📊 *گزارش تغذیه امروز*\n\n";

        if($meals->isEmpty()) {
            $report .= "هنوز هیچ وعده‌ای ثبت نکرده‌ای! 🍽";
        } else {
            foreach($meals as $meal) {
                $items = $meal->items->pluck('name')->implode('، ');
                $report .= "🔸 " . ucfirst($meal->meal_type) . ": " . $items . "\n";
            }
            $report .= "\n🔥 مجموع کالری: " . $totalCal . " از " . $target;
            $report .= "\n💪 مجموع پروتئین: " . $totalProt . " گرم";
        }

        $this->sendMessage($chat_id, $report, null, 'Markdown');
    }

    /* ---------------- Profile & Navigation Helpers ---------------- */

    private function handleCallback($callback_query)
    {
        $callback = $callback_query['data'];
        $chat_id = $callback_query['message']['chat']['id'];
        $user = User::where('bale_chat_id', $chat_id)->first();

        if (str_starts_with($callback, 'gender_')) {
            $user->profile->update(['gender' => str_replace('gender_', '', $callback)]);
            $user->update(['step' => 'awaiting_age']);
            $this->sendMessage($chat_id, "سن شما چند سال است؟");
        }
        elseif (str_starts_with($callback, 'goal_')) {
            $user->profile->update(['goal' => str_replace('goal_', '', $callback)]);
            $user->update(['step' => 'awaiting_activity']);
            $this->askActivity($chat_id);
        }
        elseif (str_starts_with($callback, 'activity_')) {
            $user->profile->update(['activity_level' => str_replace('activity_', '', $callback)]);
            $this->finishProfile($user, $chat_id);
        }
    }

    private function finishProfile($user, $chat_id)
    {
        $calories = $this->calculateCalories($user->profile);
        $user->profile->update(['daily_calories' => $calories]);
        $user->update(['step' => 'completed']);

        $msg = "✅ پروفایل شما با موفقیت ساخته شد!\n\n🔹 هدف کالری روزانه: *{$calories} کالری*\n\nحالا می‌توانید وعده‌های غذایی خود را ثبت کنید.";
        $this->sendMainMenu($chat_id, $msg);
    }

    private function sendMainMenu($chat_id, $text)
    {
        $keyboard = [
            'keyboard' => [
                [['text' => '🍳 ثبت صبحانه'], ['text' => '🍱 ثبت ناهار']],
                [['text' => '🍗 ثبت شام'], ['text' => '🍎 میان وعده']],
                [['text' => '📊 گزارش امروز']]
            ],
            'resize_keyboard' => true
        ];

        $this->sendMessage($chat_id, $text, $keyboard, 'Markdown');
    }

    private function calculateCalories($profile)
    {
        $weight = $profile->weight;
        $height = $profile->height;
        $age = $profile->age;

        $bmr = ($profile->gender == 'male')
            ? (10 * $weight) + (6.25 * $height) - (5 * $age) + 5
            : (10 * $weight) + (6.25 * $height) - (5 * $age) - 161;

        $multipliers = ['low' => 1.2, 'medium' => 1.55, 'high' => 1.725];
        $tdee = $bmr * ($multipliers[$profile->activity_level] ?? 1.2);

        if ($profile->goal == 'loss') $tdee -= 500;
        if ($profile->goal == 'gain') $tdee += 300;

        return round($tdee);
    }

    /* ---------------- Base Communications ---------------- */

    private function sendMessage($chat_id, $text, $reply_markup = null, $parse_mode = null)
    {
        $payload = ['chat_id' => $chat_id, 'text' => $text];
        if ($reply_markup) $payload['reply_markup'] = json_encode($reply_markup);
        if ($parse_mode) $payload['parse_mode'] = $parse_mode;

        Http::post("https://tapi.bale.ai/bot" . env('BALE_BOT_TOKEN') . "/sendMessage", $payload);
    }

    private function askGender($chat_id) {
        $this->sendMessage($chat_id, "جنسیت شما؟", [
            'inline_keyboard' => [[['text' => 'آقا', 'callback_data' => 'gender_male'], ['text' => 'خانم', 'callback_data' => 'gender_female']]]
        ]);
    }

    private function askGoal($chat_id) {
        $this->sendMessage($chat_id, "هدف شما؟", [
            'inline_keyboard' => [[['text' => 'کاهش وزن', 'callback_data' => 'goal_loss']], [['text' => 'افزایش وزن', 'callback_data' => 'goal_gain']], [['text' => 'تثبیت', 'callback_data' => 'goal_maintain']]]
        ]);
    }

    private function askActivity($chat_id) {
        $this->sendMessage($chat_id, "سطح فعالیت؟", [
            'inline_keyboard' => [[['text' => 'کم', 'callback_data' => 'activity_low']], [['text' => 'متوسط', 'callback_data' => 'activity_medium']], [['text' => 'زیاد', 'callback_data' => 'activity_high']]]
        ]);
    }
}
