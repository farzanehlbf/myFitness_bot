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

        if (!isset($data['message']['text'])) {
            return response()->json(['ok' => true]);
        }

        $text = trim($data['message']['text']);
        $chat_id = $data['message']['chat']['id'];

        $user = User::firstOrCreate([
            'bale_chat_id' => $chat_id
        ]);

        if ($text == '/start' || ($user->step == 'idle' && !$user->profile)) {

            $user->update(['step' => 'awaiting_gender']);

            $this->askGender($chat_id);

            return response()->json(['ok' => true]);
        }

        switch ($user->step) {

            case 'awaiting_gender':

                if (!in_array($text, ['آقا', 'خانم'])) {
                    $this->askGender($chat_id);
                    break;
                }

                $user->profile()->create([
                    'gender' => $text == 'آقا' ? 'male' : 'female'
                ]);

                $user->update(['step' => 'awaiting_age']);

                $this->sendMessage($chat_id, "سن شما چند سال است؟");

                break;

            case 'awaiting_age':

                if (!is_numeric($text) || $text < 5 || $text > 100) {
                    $this->sendMessage($chat_id, "لطفاً سن معتبر وارد کنید.");
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

            case 'awaiting_goal':

                if (!in_array($text, ['کاهش وزن', 'افزایش وزن', 'تثبیت وزن'])) {
                    $this->askGoal($chat_id);
                    break;
                }

                $goal = 'maintain';

                if ($text == 'کاهش وزن') $goal = 'loss';
                if ($text == 'افزایش وزن') $goal = 'gain';

                $user->profile->update([
                    'goal' => $goal
                ]);

                $user->update(['step' => 'awaiting_activity']);

                $this->askActivity($chat_id);

                break;

            case 'awaiting_activity':

                if (!in_array($text, ['کم', 'متوسط', 'زیاد'])) {
                    $this->askActivity($chat_id);
                    break;
                }

                $level = 'medium';

                if ($text == 'کم') $level = 'low';
                if ($text == 'زیاد') $level = 'high';

                $user->profile->update([
                    'activity_level' => $level
                ]);

                $user->update(['step' => 'completed']);

                $this->sendMessage(
                    $chat_id,
                    "✅ پروفایل شما تکمیل شد.\n\nحالا غذای خود را بنویسید.\nمثال:\nناهار: برنج و مرغ",
                    [
                        'remove_keyboard' => true
                    ]
                );

                break;

            case 'completed':

                $this->sendMessage($chat_id, "پیام شما دریافت شد ✅");

                break;
        }

        return response()->json(['ok' => true]);
    }


    private function askGender($chat_id)
    {
        $this->sendMessage(
            $chat_id,
            "سلام 👋\nجنسیت شما چیست؟",
            [
                'keyboard' => [
                    [
                        ['text' => 'آقا'],
                        ['text' => 'خانم']
                    ]
                ],
                'resize_keyboard' => true
            ]
        );
    }


    private function askGoal($chat_id)
    {
        $this->sendMessage(
            $chat_id,
            "هدف شما چیست؟",
            [
                'keyboard' => [
                    [
                        ['text' => 'کاهش وزن'],
                        ['text' => 'افزایش وزن']
                    ],
                    [
                        ['text' => 'تثبیت وزن']
                    ]
                ],
                'resize_keyboard' => true
            ]
        );
    }


    private function askActivity($chat_id)
    {
        $this->sendMessage(
            $chat_id,
            "میزان فعالیت شما؟",
            [
                'keyboard' => [
                    [
                        ['text' => 'کم'],
                        ['text' => 'متوسط'],
                        ['text' => 'زیاد']
                    ]
                ],
                'resize_keyboard' => true
            ]
        );
    }


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
}
