<?php

namespace App\Http\Controllers;

use App\Events\SendUserEmail;
use App\Rules\Recaptcha;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class MailController extends Controller
{
    protected function validator(array $data)
    {
        return Validator::make($data, [
            'email' => 'required|string|email|max:255',
            'subject' => 'required|string|max:255',
            'message' => 'required|string|min:6',
            'g-recaptcha-response' => ['required', new Recaptcha],
        ]);
    }

    public function send(Request $request)
    {
        $user = auth()->user();
        if (! $user) {
            $m2 = 'Please reach us via email at '.config('mail.from.address');
            switch ($request->type) {
                case '/answersuggestion':
                    $message = 'Error while sending your suggestion.';
                    break;
                case '/answerinquiry':
                    $message = 'Error while sending your inquiry.';
                    break;
                case '/answercomplain':
                    $message = 'Error while sending your complain.';
                    break;
            }
            abort('500', "{$message} {$m2}");
        }

        $internal = 'jlrueda@gmail.com';
        switch ($request->type) {
            case '/answersuggestion':
                $title = 'Suggestion';
                $subject = 'User Suggestion';
                $uiresponse = 'Thank you for your suggestion! It has been submitted for review.';
                break;
            case '/answerinquiry':
                $title = 'Inquiry';
                $subject = 'User Inquiry';
                $uiresponse = 'Your question has been logged, and a response is on its way soon. Thank you!';
                break;
            case '/answercomplain':
                $title = 'Complain';
                $subject = 'User Complain';
                $uiresponse = 'We’ve received your complaint and will attend to it promptly. Thank you for your patience.';
                break;
        }

        $data = [
            'title' => $title,
            'name' => $user->name,
            'username' => $user->username,
            'uid' => $user->id,
            'email' => $user->email,
            'to' => $internal,
            'plans' => $user->role->display_name,
            'daysleft' => $user->daysLeftOnTrial(),
            'since' => $user->created_at,
            'body' => $request->message,
            'subject' => $subject,
            'type' => 'internal',
        ];

        SendUserEmail::dispatch($data);

        $data = [$uiresponse];

        return response()->json(['success' => true, 'data' => $data]);
    }

    public function index(Request $request)
    {
        return view('theme::contact_us')->with(['mailOnHisWay' => false]);
    }

    public function contactus(Request $request)
    {
        $this->validator($request->all())->validate();

        $from = $request->email;
        $subject = $request->subject;
        $internal = 'jlrueda@gmail.com';

        $data = [
            'title' => 'Message from the Contac-Us Form',
            'name' => 'Contact Form',
            'username' => '',
            'uid' => '',
            'email' => $request->email,
            'to' => $internal,
            'plans' => '',
            'daysleft' => 0,
            'since' => date('c'),
            'body' => $request->message,
            'subject' => $subject,
            'type' => 'internal',
        ];

        SendUserEmail::dispatch($data);

        return view('theme::contact_us')->with(['mailOnHisWay' => true]);
    }
}
