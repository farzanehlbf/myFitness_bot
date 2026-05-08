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

        $text = $data['message']['text'];
        $chat_id = $data['message']['chat']['id'];

        $user = User::firstOrCreate([
            'bale_chat_id' => $chat_id
        ]);

        if ($text == '/start' || ($user->step == 'idle' && !$user->profile)) {

            $user->update(['step' => 'awaiting_gender']);

            $this->sendMessage(
                $chat_id,
                "سلام 👋\nبرای شروع باید پروفایل شما را بسازیم.\n\nجنسیت شما؟ (آقا / خانم)"
            );

            return response()->json(['ok' => true]);
        }

        switch ($user->step) {

            case 'awaiting_gender':

                $user->profile()->create([
                    'gender' => $text == 'آقا' ? 'male' : 'female'
                ]);

                $user->update(['step' => 'awaiting_age']);

                $this->sendMessage($chat_id, "سن شما چند سال است؟");

                break;

            case 'awaiting_age':

                $user->profile->update([
                    'age' => (int)$text
                ]);

                $user->update(['step' => 'awaiting_weight']);

                $this->sendMessage($chat_id, "وزن شما چند کیلوگرم است؟");

                break;

            case 'awaiting_weight':

                $user->profile->update([
                    'weight' => (int)$text
                ]);

                $user->update(['step' => 'awaiting_height']);

                $this->sendMessage($chat_id, "قد شما چند سانتی‌متر است؟");

                break;

            case 'awaiting_height':

                $user->profile->update([
                    'height' => (int)$text
                ]);

                $user->update(['step' => 'awaiting_goal']);

                $this->sendMessage($chat_id, "هدف شما چیست؟ (کاهش / افزایش / تثبیت)");

                break;

            case 'awaiting_goal':

                $goal = 'maintain';

                if (str_contains($text, 'کاهش')) $goal = 'loss';
                if (str_contains($text, 'افزایش')) $goal = 'gain';

                $user->profile->update([
                    'goal' => $goal
                ]);

                $user->update(['step' => 'awaiting_activity']);

                $this->sendMessage($chat_id, "میزان فعالیت شما؟ (کم / متوسط / زیاد)");

                break;

            case 'awaiting_activity':

                $user->profile->update([
                    'activity_level' => 'medium'
                ]);

                $user->update(['step' => 'completed']);

                $this->sendMessage(
                    $chat_id,
                    "✅ پروفایل شما تکمیل شد.\n\nحالا می‌توانید غذای خود را بنویسید.\nمثال:\nناهار: برنج و مرغ"
                );

                break;

            case 'completed':

                $this->sendMessage($chat_id, "پیام دریافت شد ✅");

                break;
        }

        return response()->json(['ok' => true]);
    }

    private function sendMessage($chat_id, $text)
    {
        Http::post(
            "https://tapi.bale.ai/bot" . env('BALE_BOT_TOKEN') . "/sendMessage",
            [
                'chat_id' => $chat_id,
                'text' => $text
            ]
        );
    }
}
