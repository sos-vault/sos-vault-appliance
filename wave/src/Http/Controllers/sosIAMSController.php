<?php

namespace Wave\Http\Controllers;

use App\Events\SendUserEmail;
use App\Http\Controllers\Controller;
use App\Models\User;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Foundation\Auth\AuthenticatesUsers;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Spatie\Permission\Models\Role;

class sosIAMSController extends Controller
{
    use AuthenticatesUsers;

    private $valid_providers = ['google', 'facebook', 'github'];

    public function __construct()
    {
        $this->middleware('guest')->except('logout');
    }

    public function login(Request $request, $provider)
    {
        if (! in_array($provider, $this->valid_providers)) {
            $type = 'danger';
            $message = 'Invalid service.';
            alertBadge($message, $type);

            return view('theme::auth.login');
        }

        try {

            return Socialite::driver($provider)->redirect();

        } catch (\Exception $e) {
            Log::error($e->getMessage());

            return redirect('/login')->withErrors(['msg' => $e->getMessage()]);
        }
    }

    public function callback(Request $request, $provider)
    {

        if (! in_array($provider, $this->valid_providers)) {
            $type = 'danger';
            $message = 'Invalid service.';
            alertBadge($message, $type);

            return view('theme::auth.login');
        }

        try {
            $user = Socialite::driver($provider)->stateless()->user();
        } catch (InvalidStateException $e) {
            $message = 'connection: '.$e->getMessage();
            Log::error($message);

            return redirect('/');
        } catch (ConnectionException $e) {
            $message = 'connection: '.$e->getMessage();
            Log::error($message);

            return redirect('/');
        } catch (RequestException $e) {
            $message = 'request: '.$e->getMessage();
            Log::error($message);

            return redirect('/');
        } catch (Exception $e) {
            $message = 'error: '.$e->getMessage();
            Log::error($message);

            return redirect('/');
        }

        $existingUser = User::where('email', $user->getEmail())->first();

        if ($existingUser) {
            // Log in the user
            Auth::login($existingUser);

            $existingUser->provider = $provider;
            $existingUser->provider_id = $user->getId();

            $payload = (object) [
                'message' => 'login success',
                'email' => $existingUser->email,
                'via' => $provider,
                'session' => session()->getId(),
            ];
            addEvent($payload, 'LOGIN', 'SUCCESS', 'ACTIVITY', 0, 0, $existingUser->id, $existingUser->id);

            return redirect('/dashboard');

        } else {
            $role = Role::where('name', setting('auth.default_role'))->first();

            $verification_code = Str::random(30);
            $verified = 1;
            $email_verified_at = now();

            $username = $this->getUniqueUsernameFromEmail($user->getEmail());
            $username_original = $username;
            $counter = 1;

            while (User::where('username', '=', $username)->first()) {
                $username = $username_original.(string) $counter;
                $counter += 1;
            }

            $trial_days = intval(setting('billing.trial_days', 14));
            $trial_ends_at = null;
            if ($trial_days > 0) {
                $trial_ends_at = now()->addDays($trial_days);
            }

            // Register a new user
            $newUser = User::create([
                'provider' => $provider,
                'provider_id' => $user->getId(),
                'name' => $user->getName(),
                'email' => $user->getEmail(),
                'username' => $username,
                'password' => bcrypt(bin2hex(openssl_random_pseudo_bytes(16))),
                'verification_code' => $verification_code,
                'verified' => $verified,
                'email_verified_at' => $email_verified_at,
                'trial_ends_at' => $trial_ends_at,
                'avatar' => 'avatars/default.png',
            ]);

            if ($role) {
                $newUser->syncRoles([$role->name]);
            }

            // Explicitly persist trial_ends_at — the created model hook (syncRoles)
            // can cause the in-memory model to diverge from the DB row.
            if ($trial_ends_at) {
                $newUser->update(['trial_ends_at' => $trial_ends_at]);
            }

            $cid = 0;
            $vid = 0;
            $uid = $newUser->id;
            $gid = $newUser->id;
            $payload = (object) [
                'message' => "new user created: {$newUser->email}",
            ];
            addEvent($payload, 'ADD_USER', 'SUCCESS', 'ACTIVITY', $cid, $vid, $uid, $gid);

            $data = [
                'title' => 'Thanks for Signing Up with sos-vault',
                'name' => $newUser->name,
                'username' => $newUser->username,
                'uid' => $newUser->id,
                'email' => $newUser->email,
                'to' => $newUser->email,
                'plans' => '',
                'daysleft' => '',
                'since' => $newUser->created_at,
                'body' => '',
                'subject' => 'Welcome to sos-vault',
                'type' => 'welcomeEmail',
                'token' => $user->verification_code,
            ];
            SendUserEmail::dispatch($data);

            Auth::login($newUser);

            $payload = (object) [
                'message' => 'login success',
                'email' => $newUser->email,
                'via' => $provider,
                'session' => session()->getId(),
            ];
            addEvent($payload, 'LOGIN', 'SUCCESS', 'ACTIVITY', 0, 0, $newUser->id, $newUser->id);

            return redirect('/dashboard');
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
