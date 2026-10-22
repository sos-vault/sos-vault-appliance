<?php

namespace Wave\Http\Controllers\Auth;

use App\Events\SendUserEmail;
use App\Http\Controllers\Controller;
use App\Models\PasswordReset;
use App\Models\User;
use App\Rules\Recaptcha;
use App\Services\PasswordPolicy;
use Carbon\Carbon;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Auth\ResetsPasswords;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class ResetPasswordController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | Password Reset Controller
    |--------------------------------------------------------------------------
    |
    | This controller is responsible for handling password reset requests
    | and uses a simple trait to include this behavior. You're free to
    | explore this trait and override any methods you wish to tweak.
    |
    */

    use ResetsPasswords;

    /**
     * Where to redirect users after resetting their password.
     *
     * @var string
     */
    protected $redirectTo = '/dashboard';

    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->middleware('guest', ['except' => ['showResetForm', 'resetPassword']]);
    }

    public function showChangeRequestForm(Request $request)
    {
        return view('theme::auth.passwords.email')->with(['mailOnHisWay' => false]);
    }

    public function sendChangeRequestEmail(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|string|email|max:255|exists:users',
            'g-recaptcha-response' => ['required', new Recaptcha],
        ]);

        if ($validator->fails()) {
            return back()->withErrors($validator)->withInput();
        }

        // find the user with this email
        $user = User::where('email', $request->email)->first();

        if (! $user) {
            return back()->withErrors($validator, 'email');
        }

        // create a password reset request record
        PasswordReset::where('email', $user->email)->delete();
        $token = Str::random(64);
        PasswordReset::create(['email' => $user->email, 'token' => $token]);

        $data = [
            'title' => 'Password Reset',
            'name' => $user->name,
            'username' => $user->username,
            'uid' => $user->id,
            'email' => $user->email,
            'to' => $user->email,
            'plans' => $user->role->display_name,
            'daysleft' => $user->daysLeftOnTrial(),
            'since' => $user->created_at,
            'body' => '',
            'subject' => 'sos-vault Password Reset',
            'type' => 'resetPassword',
            'token' => $token,
        ];

        SendUserEmail::dispatch($data);

        return view('theme::auth.passwords.email')->with(['mailOnHisWay' => true]);
    }

    public function showResetForm(Request $request, $token = null)
    {
        if (! $token) {
            return view('theme::auth.passwords.email')->with(['mailOnHisWay' => false]);
        }

        $reset = PasswordReset::where('token', $token)->first();

        if (! $reset) {
            $message = 'Password change request is not valid. Please request a new password change again.';

            return view('theme::home');
        }

        return view('theme::auth.passwords.reset')->with(
            ['token' => $token, 'email' => $reset->email]
        );
    }

    protected function resetPassword(Request $request)
    {

        $validator = Validator::make($request->all(), [
            'email' => 'required|string|email|max:255|exists:users',
            'token' => 'required|string|max:255',
            'password' => [
                'required',
                'min:'.PasswordPolicy::minLength(),
                'regex:'.PasswordPolicy::regex(),
                'confirmed',
            ],
        ]);

        if ($validator->fails()) {
            return back()->withErrors($validator)->withInput();
        }

        // find the request...
        $reset = PasswordReset::where('email', $request->email)
            ->where('created_at', '>', Carbon::now()->subMinutes(30)->toDateTimeString())
            ->first();

        if (! $reset) {
            $message = "Tokens don't match. Please request a new password change again.";
            Log::error($message);

            Notification::make()
                ->title($message)
                ->warning()
                ->persistent()
                ->send();

            return back();
        }

        if ($request->token != $reset->token) {
            $reset->delete();
            $message = "Tokens don't match. Please request a new password change again.";
            Log::error($message);

            Notification::make()
                ->title($message)
                ->warning()
                ->persistent()
                ->send();

            return back();
        }

        // find the associated user...
        $user = User::where('email', $reset->email)->first();

        // do the password reset...
        $user->forceFill([
            'password' => bcrypt($request->password),
        ])->save();

        $cid = 0;
        $vid = 0;
        $uid = $user->id;
        $gid = $user->id;

        $message = 'Successfully updated your password.';
        Log::info($message);

        $payload = (object) [
            'message' => $message,
        ];
        addEvent($payload, 'PASS_RESET', 'SUCCESS', 'ACTIVITY', $cid, $vid, $uid, $gid);

        $reset->delete();

        Notification::make()
            ->title('Success. Your password has been reset.')
            ->success()
            ->persistent()
            ->send();

        return redirect($this->redirectTo);
    }
}
