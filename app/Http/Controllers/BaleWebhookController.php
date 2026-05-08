<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use App\Models\User;
use App\Models\UserProfile;

class BaleWebhookController extends Controller
{
    public function handle(Request $request)
    {
        $data = $request->all();

        /* ---------------- callback buttons ---------------- */

        if (isset($data['callback_query'])) {

            $callback = $data['callback_query']['data'];
            $chat_id = $data['callback_query']['message']['chat']['id'];

            $user = User::firstOrCreate([
                'bale_chat_id' => $chat_id
            ]);

            if (!$user->profile) {
                $user->profile()->create([]);
            }

            if ($callback == 'gender_male') {

                $user->profile->update([
                    'gender' => 'male'
                ]);

                $user->update(['step' => 'awaiting_age']);

                $this->sendMessage($chat_id, "سن شما چند سال است؟");

            } elseif ($callback == 'gender_female') {

                $user->profile->update([
                    'gender' => 'female'
                ]);

                $user->update(['step' => 'awaiting_age']);

                $this->sendMessage($chat_id, "سن شما چند سال است؟");

            } elseif ($callback == 'goal_loss') {

                $user->profile->update(['goal' => 'loss']);
                $user->update(['step' => 'awaiting_activity']);

                $this->askActivity($chat_id);

            } elseif ($callback == 'goal_gain') {

                $user->profile->update(['goal' => 'gain']);
                $user->update(['step' => 'awaiting_activity']);

                $this->askActivity($chat_id);

            } elseif ($callback == 'goal_maintain') {

                $user->profile->update(['goal' => 'maintain']);
                $user->update(['step' => 'awaiting_activity']);

                $this->askActivity($chat_id);

            } elseif ($callback == 'activity_low') {

                $user->profile->update(['activity_level' => 'low']);
                $this->finishProfile($user, $chat_id);

            } elseif ($callback == 'activity_medium') {

                $user->profile->update(['activity_level' => 'medium']);
                $this->finishProfile($user, $chat_id);

            } elseif ($callback == 'activity_high') {

                $user->profile->update(['activity_level' => 'high']);
                $this->finishProfile($user, $chat_id);
            }

            return response()->json(['ok' => true]);
        }

        /* ---------------- text messages ---------------- */

        if (!isset($data['message']['text'])) {
            return response()->json(['ok' => true]);
        }

        $text = trim($data['message']['text']);
        $chat_id = $data['message']['chat']['id'];

        $user = User::firstOrCreate([
            'bale_chat_id' => $chat_id
        ]);

        if ($text == '/start' || ($user->step == 'idle' && !$user->profile)) {

            $user->profile()->firstOrCreate([]);

            $user->update(['step' => 'awaiting_gender']);

            $this->askGender($chat_id);

            return response()->json(['ok' => true]);
        }

        switch ($user->step) {

            case 'awaiting_age':

                if (!is_numeric($text) || $text < 5 || $text > 100) {
                    $this->sendMessage($chat_id, "سن معتبر وارد کنید.");
                    break;
                }

                $user->profile->update([
                    'age' => (int)$text
                ]);

                $user->update(['step' => 'awaiting_weight']);

                $this->sendMessage($chat_id, "وزن شما چند کیلوگرم است؟");

                break;


            case 'awaiting_weight':

                if (!is_numeric($text) || $text < 20 || $text > 300) {
                    $this->sendMessage($chat_id, "وزن معتبر وارد کنید.");
                    break;
                }

                $user->profile->update([
                    'weight' => (int)$text
                ]);

                $user->update(['step' => 'awaiting_height']);

                $this->sendMessage($chat_id, "قد شما چند سانتی‌متر است؟");

                break;


            case 'awaiting_height':

                if (!is_numeric($text) || $text < 100 || $text > 250) {
                    $this->sendMessage($chat_id, "قد معتبر وارد کنید.");
                    break;
                }

                $user->profile->update([
                    'height' => (int)$text
                ]);

                $user->update(['step' => 'awaiting_goal']);

                $this->askGoal($chat_id);

                break;


            case 'completed':

                $this->sendMessage($chat_id, "پیام شما دریافت شد ✅");

                break;
        }

        return response()->json(['ok' => true]);
    }


    /* ---------------- buttons ---------------- */

    private function sendMessage($chat_id, $text, $reply_markup = null)
    {
        $payload = [
            'chat_id' => $chat_id,
            'text' => $text
        ];

        if ($reply_markup) {
            $payload['reply_markup'] = json_encode($reply_markup, JSON_UNESCAPED_UNICODE);
        }

        Http::post(
            "https://tapi.bale.ai/bot" . env('BALE_BOT_TOKEN') . "/sendMessage",
            $payload
        );
    }

    private function askActivity($chat_id)
    {
        $this->sendMessage(
            $chat_id,
            "میزان فعالیت شما؟",
            [
                'inline_keyboard' => [
                    [
                        ['text' => 'کم', 'callback_data' => 'activity_low']
                    ],
                    [
                        ['text' => 'متوسط', 'callback_data' => 'activity_medium']
                    ],
                    [
                        ['text' => 'زیاد', 'callback_data' => 'activity_high']
                    ]
                ]
            ]
        );
    }

    private function finishProfile($user, $chat_id)
    {
        $user->update(['step' => 'completed']);

        $user->load('profile');

        $calories = $this->calculateCalories($user->profile);

        $user->profile->update([
            'daily_calories' => $calories
        ]);

        $this->sendMessage(
            $chat_id,
            "✅ پروفایل شما تکمیل شد.\n\n" .
            "🔹 کالری مورد نیاز روزانه شما: *{$calories} kcal*\n\n" .
            "حالا می‌توانید غذای روزانه خود را ارسال کنید 🍽️\n" .
            "مثال:\nناهار: برنج و مرغ\nشام: سالاد و ماهی 🎯",
            [
                'parse_mode' => 'Markdown'
            ]
        );
    }

    private function calculateCalories($profile)
    {
        $weight = $profile->weight;
        $height = $profile->height;
        $age = $profile->age;

        if ($profile->gender == 'male') {
            $bmr = (10 * $weight) + (6.25 * $height) - (5 * $age) + 5;
        } else {
            $bmr = (10 * $weight) + (6.25 * $height) - (5 * $age) - 161;
        }

        $activityMultiplier = 1.55;

        if ($profile->activity_level == 'low') $activityMultiplier = 1.2;
        if ($profile->activity_level == 'high') $activityMultiplier = 1.725;

        $tdee = $bmr * $activityMultiplier;

        if ($profile->goal == 'loss') $tdee -= 500;
        if ($profile->goal == 'gain') $tdee += 300;

        return round($tdee);
    }


    /* ---------------- send message ---------------- */

    private function askGender($chat_id)
    {
        $this->sendMessage(
            $chat_id,
            "سلام 👋\nجنسیت شما چیست؟",
            [
                'inline_keyboard' => [
                    [
                        [
                            'text' => 'آقا',
                            'callback_data' => 'gender_male'
                        ],
                        [
                            'text' => 'خانم',
                            'callback_data' => 'gender_female'
                        ]
                    ]
                ]
            ]
        );
    }

    private function askGoal($chat_id)
    {
        $this->sendMessage(
            $chat_id,
            "هدف شما چیست؟",
            [
                'inline_keyboard' => [
                    [
                        ['text' => 'کاهش وزن', 'callback_data' => 'goal_loss']
                    ],
                    [
                        ['text' => 'افزایش وزن', 'callback_data' => 'goal_gain']
                    ],
                    [
                        ['text' => 'تثبیت وزن', 'callback_data' => 'goal_maintain']
                    ]
                ]
            ]
        );
    }

}
