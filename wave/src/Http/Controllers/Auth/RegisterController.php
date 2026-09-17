<?php

namespace Wave\Http\Controllers\Auth;

use App\Events\SendUserEmail;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserToken;
use App\Rules\Recaptcha;
use App\Services\PasswordPolicy;
use Carbon\Carbon;
use Filament\Notifications\Notification;
use Illuminate\Auth\Events\Registered;
use Illuminate\Foundation\Auth\RegistersUsers;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

class RegisterController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | Register Controller
    |--------------------------------------------------------------------------
    |
    | This controller handles the registration of new users as well as their
    | validation and creation. By default this controller uses a trait to
    | provide this functionality without requiring any additional code.
    |
    */

    use RegistersUsers;

    /**
     * Where to redirect users after registration.
     *
     * @var string
     */
    protected $redirectTo = '/auth/home';

    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->middleware('guest', ['except' => [
            'complete',
        ]]);
    }

    /**
     * Get a validator for an incoming registration request.
     *
     * @return \Illuminate\Contracts\Validation\Validator
     */
    protected function validator(array $data)
    {
        if (setting('auth.username_in_registration') && setting('auth.username_in_registration') == 'yes') {
            return Validator::make($data, [
                'name' => 'required|string|max:255',
                'email' => 'required|string|email|max:255|unique:users',
                'username' => 'required|string|max:20|unique:users',
                'password' => [
                    'required',
                    'min:'.PasswordPolicy::minLength(),
                    'regex:'.PasswordPolicy::regex(),
                    'confirmed',
                ],
                'g-recaptcha-response' => ['required', new Recaptcha],
            ]);
        }

        return Validator::make($data, [
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => [
                'required',
                'min:'.PasswordPolicy::minLength(),
                'regex:'.PasswordPolicy::regex(),
                'confirmed',
            ],
            'g-recaptcha-response' => ['required', new Recaptcha],
        ]);
    }

    /**
     * Create a new user instance after a valid registration.
     *
     * @return User
     */
    public function create(array $data)
    {
        $role = Role::where('name', '=', setting('auth.default_role'))->first();

        $verification_code = null;
        $verified = 1;
        $email_verified_at = now();

        if (setting('auth.verify_email', false)) {
            $verification_code = Str::random(30);
            $verified = 0;
            $email_verified_at = null;
        }

        if (isset($data['username']) && ! empty($data['username'])) {
            $username = $data['username'];
        } elseif (isset($data['name']) && ! empty($data['name'])) {
            $username = Str::slug($data['name']);
        } else {
            $username = $this->getUniqueUsernameFromEmail($data['email']);
        }

        $username_original = $username;
        $counter = 1;

        while (User::where('username', '=', $username)->first()) {
            $username = $username_original.(string) $counter;
            $counter += 1;
        }

        $trial_days = intval(setting('billing.trial_days', 14));
        $trial_ends_at = null;
        // if trial days is not zero we will set trial_ends_at to ending date
        if ($trial_days > 0) {
            $trial_ends_at = now()->addDays($trial_days);
        }

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'username' => $username,
            'password' => bcrypt($data['password']),
            'verification_code' => $verification_code,
            'verified' => $verified,
            'email_verified_at' => $email_verified_at,
            'trial_ends_at' => $trial_ends_at,
            'avatar' => 'avatars/default.png',

        ]);

        // Assign the role from the admin setting (overrides the default set in User::boot())
        if ($role) {
            $user->syncRoles([$role->name]);
        }

        $cid = 0;
        $vid = 0;
        $uid = $user->id;
        $gid = $user->id;
        $payload = (object) [
            'message' => "new user created: {$data['email']}",
        ];
        addEvent($payload, 'ADD_USER', 'SUCCESS', 'ACTIVITY', $cid, $vid, $uid, $gid);

        if (setting('auth.verify_email', false)) {
            $this->sendVerificationEmail($user);
        }

        return $user;
    }

    /**
     * Complete a new user registration after they have purchased
     *
     * @return redirect
     */
    public function complete(Request $request)
    {

        if (setting('auth.username_in_registration') && setting('auth.username_in_registration') == 'yes') {
            $request->validate([
                'name' => 'required|string|min:3|max:255',
                'username' => 'required|string|max:20|unique:users,username,'.auth()->user()->id,
                'password' => [
                    'required',
                    'string',
                    'min:'.PasswordPolicy::minLength(),
                    'regex:'.PasswordPolicy::regex(),
                ],
            ]);
        } else {
            $request->validate([
                'name' => 'required|string|min:3|max:255',
                'password' => [
                    'required',
                    'string',
                    'min:'.PasswordPolicy::minLength(),
                    'regex:'.PasswordPolicy::regex(),
                ],
            ]);
        }

        // Update the user info
        $user = auth()->user();
        $user->name = $request->name;
        $user->username = $request->username;
        $user->password = bcrypt($request->password);
        $user->save();

        // add the tokens record
        $tokens = UserToken::create([
            'user_id' => $user->id,
            'input_tokens_available' => 10000,
            'output_tokens_available' => 10000,
            'total_tokens_available' => 20000,
        ]);

        $cid = 0;
        $vid = 0;
        $uid = $user->id;
        $gid = $user->id;
        $payload = (object) [
            'message' => 'user register complete}',
        ];
        addEvent($payload, 'CHG_USER', 'SUCCESS', 'ACTIVITY', $cid, $vid, $uid, $gid);

        Notification::make()
            ->title('Successfully updated your profile information.')
            ->success()
            ->persistent()
            ->send();

        return redirect($this->redirectTo);

    }

    private function sendVerificationEmail($user)
    {
        $data = [
            'title' => 'Verify Email',
            'name' => $user->name,
            'username' => $user->username,
            'uid' => $user->id,
            'email' => $user->email,
            'to' => $user->email,
            'plans' => '',
            'daysleft' => '',
            'since' => $user->created_at,
            'body' => '',
            'subject' => 'sos-vault Verify Email',
            'type' => 'verifyEmail',
            'token' => $user->verification_code,
        ];

        SendUserEmail::dispatch($data);
    }

    public function showRegistrationForm()
    {
        if (setting('billing.card_upfront')) {
            return redirect('/pricing');
        }

        return view('theme::auth.register');
    }

    public function verify(Request $request, $verification_code)
    {
        $user = User::where('verification_code', '=', $verification_code)->first();

        if (! isset($user) || ! $user) {
            return redirect('/login');
        }

        $user->verification_code = null;
        $user->verified = 1;
        $user->email_verified_at = Carbon::now();
        $user->save();

        $this->guard()->login($user);

        if (session()->has('checkout_price_id')) {
            $priceId = session()->pull('checkout_price_id');

            return redirect('/settings/subscription?checkout='.$priceId);
        }

        return redirect($this->redirectTo);
    }

    /**
     * Handle a registration request for the application.
     *
     * @return Response
     */
    public function register(Request $request)
    {
        $this->validator($request->all())->validate();

        if (setting('auth.verify_email')) {
            $title = 'Check your inbox';
            $message = "We've sent a verification link to your email. Please verify your email to complete the sign-up process and log in to your new account.";
            Notification::make()
                ->title("<span class='text-2xl text-zinc-800'>$title</span>")
                ->body("<span class='text-lg text-zinc-600'>$message</span>")
                ->success()
                ->persistent()
                ->send();

            event(new Registered($user = $this->create($request->all())));

            if ($request->filled('price_id')) {
                session(['checkout_price_id' => $request->price_id]);
            }

            return redirect('/register');
        } else {
            event(new Registered($user = $this->create($request->all())));

            $this->guard()->login($user);

            Notification::make()
                ->title("<span class='text-lg text-zinc-800'>Thanks for signing up!'</span>")
                ->success()
                ->persistent()
                ->send();

            if ($request->filled('price_id')) {
                return redirect('/settings/subscription?checkout='.$request->price_id);
            }

            return $this->registered($request, $user) ?: redirect($this->redirectTo);
        }
    }

    public function getUniqueUsernameFromEmail($email)
    {
        $username = strtolower(trim(Str::slug(explode('@', $email)[0])));

        $new_username = $username;

        $user_exists = \Wave\User::where('username', '=', $username)->first();
        $counter = 1;
        while (isset($user_exists->id)) {
            $new_username = $username.$counter;
            $counter += 1;
            $user_exists = \Wave\User::where('username', '=', $new_username)->first();
        }

        $username = $new_username;

        if (strlen($username) < 4) {
            $username = $username.uniqid();
        }

        return strtolower($username);
    }
}
