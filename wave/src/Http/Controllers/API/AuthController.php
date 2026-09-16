<?php

namespace Wave\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\ApiKey;
use App\Models\User;
use App\Providers\DataTools;
use App\Providers\VaultTools;
use App\Services\PasswordPolicy;
use Carbon\Carbon;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Encryption\Encrypter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Spatie\Permission\Models\Role;
use Tymon\JWTAuth\Facades\JWTAuth;

class AuthController extends Controller
{
    /**
     * Create a new AuthController instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->middleware('auth:api', ['except' => ['login', 'token', 'register', 'upload']]);
    }

    /**
     * Get a JWT via given credentials.
     *
     * @return JsonResponse
     */
    public function login()
    {
        $credentials = request(['email', 'password']);

        if (! $token = JWTAuth::attempt($credentials)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $cid = 0;
        $vid = 0;
        $uid = 0;
        $gid = $uid;
        $payload = (object) [
            'message' => 'login success',
            'email' => $credentials['email'] ?? null,
            'via' => 'api',
            'session' => session()->getId(),
        ];
        addEvent($payload, 'LOGIN', 'SUCCESS', 'ACTIVITY', $cid, $vid, $uid, $gid);

        return $this->respondWithToken($token);
    }

    /**
     * Log the user out (Invalidate the token).
     *
     * @return JsonResponse
     */
    public function logout()
    {
        // LOGOUT event is recorded by App\Listeners\RecordLogoutEvent (fired on auth()->logout()).
        auth()->logout();

        return response()->json(['message' => 'Successfully logged out']);
    }

    public function token()
    {
        $request = app('request');

        if (isset($request->key)) {

            $key = ApiKey::where('key', '=', $request->key)->where('name', '!=', 'upload-pass')->first();

            if (isset($key->id)) {
                $key->update([
                    'last_used_at' => Carbon::now(),
                ]);

                return response()->json(['access_token' => JWTAuth::fromUser($key->user, ['exp' => config('wave.api.key_token_expires', 1)])]);
            } else {
                abort('400', 'Invalid Api Key');
            }

        } else {
            abort('401', 'Unauthorized');
        }

    }

    public function upload()
    {
        $closeOnExit = true;
        $this->DEBUG = 0;

        // allows to upload a file using the upload-pass token
        $request = app('request');

        if (isset($_SERVER['PHP_AUTH_USER']) && isset($_SERVER['PHP_AUTH_PW'])) {

            $user = User::where('email', $_SERVER['PHP_AUTH_USER'])->first();
            if (! isset($user)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Unauthorized',
                ], 401);
            }

            // Apply the user's stored locale so all __() calls in this request use the right language.
            // The web SetLocale middleware does not run for API routes.
            if ($user->locale && array_key_exists($user->locale, config('app.supported_locales', []))) {
                App::setLocale($user->locale);
            }

            $cid = 0;
            $vid = 0;
            $uid = $user->id;
            $gid = $user->id;

            $key = ApiKey::where('user_id', $user->id)->where('name', '=', 'upload-pass')->first();
            if (! isset($key)) {
                $message = 'File upload failed: No upload-pass';
                $payload = (object) [
                    'message' => $message,
                    'name' => '',
                    'via' => 'api',
                ];
                addEvent($payload, 'UPLOAD', 'FAILED', 'NORMAL', $cid, $vid, $uid, $gid);

                return response()->json([
                    'status' => 'error',
                    'message' => $message,
                ], 401);
            }

            try {
                $decryptkey = new Encrypter(key: getSvaultKey('svault0'), cipher: config('app.cipher'))->decrypt($key->key);
            } catch (DecryptException $e) {
                $message = 'File upload failed: Could not decrypt upload-pass';
                $payload = (object) [
                    'message' => $message,
                    'name' => '',
                    'via' => 'api',
                ];
                addEvent($payload, 'UPLOAD', 'FAILED', 'NORMAL', $cid, $vid, $uid, $gid);

                return response()->json([
                    'status' => 'error',
                    'message' => 'Unauthorized',
                ], 401);
            }

            if (! isset($decryptkey)) {
                $message = 'File upload failed: Decrypted upload-pass is empty';
                $payload = (object) [
                    'message' => $message,
                    'name' => '',
                    'via' => 'api',
                ];
                addEvent($payload, 'UPLOAD', 'FAILED', 'NORMAL', $cid, $vid, $uid, $gid);

                return response()->json([
                    'status' => 'error',
                    'message' => 'Unauthorized',
                ], 401);
            }

            $key->update([
                'last_used_at' => Carbon::now(),
            ]);

            $vtools = new VaultTools($user);

            $vid = $vtools->getVaultId();

            if (! $vtools->vaultExists()) {
                $message = "No vault associated to {$user->username}";
                Log::error($message);
                $payload = (object) [
                    'message' => $message,
                    'name' => '',
                    'via' => 'api',
                ];
                addEvent($payload, 'UPLOAD', 'FAILED', 'NORMAL', $cid, $vid, $uid, $gid);

                return response()->json([
                    'status' => 'error',
                    'message' => 'Unauthorized',
                ], 401);
            }

            if (! $vtools->isOpen()) {
                // open user vault
                if (! $vtools->openVault()) {
                    $message = "Could not open {$user->username}'s vault";
                    Log::error($message);
                    $payload = (object) [
                        'message' => $message,
                        'name' => '',
                        'via' => 'api',
                    ];
                    addEvent($payload, 'UPLOAD', 'FAILED', 'NORMAL', $cid, $vid, $uid, $gid);
                    // Original: sprintf("The automatic upload failed because: %s", $message)
                    $notmessage = __('notifications.upload_auto_failed', ['reason' => $message]);
                    notifyUser($user, $notmessage, 'error');

                    return response()->json([
                        'status' => 'error',
                        'message' => $message,
                    ], 500);
                }
            } else {
                // if the vault is open chances are that the user is logged in.
                $closeOnExit = false;
            }

            // check is there is enough space in users vault
            if (! $vtools->doesItFit($request->file('file')->getSize())) {
                $closeOnExit && $vtools->closeVault();
                $message = "There is not enough space left on {$user->username}'s vault";
                Log::error($message);
                $payload = (object) [
                    'message' => $message,
                    'name' => $request->file('file')->getClientOriginalName(),
                    'via' => 'api',
                ];
                addEvent($payload, 'UPLOAD', 'FAILED', 'NORMAL', $cid, $vid, $uid, $gid);
                // Original: sprintf("The automatic upload failed because: %s", $message)
                $notmessage = __('notifications.upload_auto_failed', ['reason' => $message]);
                notifyUser($user, $notmessage, 'error');

                return response()->json([
                    'status' => 'error',
                    'message' => $message,
                ], 500);
            }

            $max_size = (int) ini_get('upload_max_filesize') * 1000;

            $extensions = implode(',', [
                'gpg',
                'gz',
                'xz',
                'rar',
                'tar',
                'zip',
                'tgz',
                'tar.gz',
                'tar.xz',
                'tar.gz.gpg',
                'tar.xz.gpg',
            ]);

            $mimes = implode(',', [
                'application/octet-stream',
                'application/pgp-encrypted',
                'application/gzip',
                'application/x-xz',
                'application/vnd.rar',
                'application/tar',
                'application/zip',
                'application/tar+gzip',
            ]);

            $file = '';
            if ($request->hasFile('file')) {
                $file = $request->file('file');

                $filename = $file->getClientOriginalName();
                // Safe-charset middle only (no shell metacharacters / spaces /
                // slashes): the name is stored on disk and fed to the gpg/tar
                // unpack commands and used to build the on-disk path. The old
                // permissive `..*` allowed command injection / path traversal.
                if (! preg_match('/^(secured-)?sosreport-[A-Za-z0-9._-]+\.(gpg|gz|xz)$/', $filename)) {
                    $closeOnExit && $vtools->closeVault();
                    $message = 'Invalid sosreport filename';
                    Log::error($message);
                    $payload = (object) [
                        'message' => $message,
                        'name' => $file->getClientOriginalName(),
                        'via' => 'api',
                    ];
                    addEvent($payload, 'UPLOAD', 'FAILED', 'NORMAL', $cid, $vid, $uid, $gid);
                    // Original: sprintf("The automatic upload failed because: %s", $message)
                    $notmessage = __('notifications.upload_auto_failed', ['reason' => $message]);
                    notifyUser($user, $notmessage, 'error');

                    return response()->json([
                        'status' => 'error',
                        'message' => $message,
                    ], 500);
                }

                $validated = $request->validate([
                    'file' => [
                        'required',
                        'file',
                        'extensions:'.$extensions,
                        'mimetypes:'.$mimes,
                        'max:'.$max_size,
                    ],
                ]);
            }

            // does a packed file with exactly the same name alredy exists in the vault
            $newfile = $vtools->getMountPoint().'/'.$filename;

            $this->DEBUG && Log::info('newfile: '.var_export($newfile, 1));

            if (is_file($newfile)) {
                $closeOnExit && $vtools->closeVault();

                $message = 'This sosreport file is already in your vault.';
                $payload = (object) [
                    'message' => $message,
                    'name' => $file->getClientOriginalName(),
                    'via' => 'api',
                ];
                addEvent($payload, 'UPLOAD', 'FAILED', 'NORMAL', $cid, $vid, $uid, $gid);
                // Original: sprintf("The automatic upload failed because: %s", $message)
                $notmessage = __('notifications.upload_auto_failed', ['reason' => $message]);
                notifyUser($user, $notmessage, 'error');

                return response()->json([
                    'status' => 'error',
                    'message' => $message,
                ], 500);
            }

            // does an unpacked dir with corresponding name alredy exists in the vault
            $fdata = $vtools->parseFilename($filename);

            $this->DEBUG && Log::info('fdata: '.var_export($fdata, 1));

            $newpath = $vtools->getMountPoint().'/'.$fdata->path;

            $this->DEBUG && Log::info('newpath: '.var_export($newpath, 1));

            if (is_dir($newpath)) {
                $closeOnExit && $vtools->closeVault();

                $message = 'This sosreport is alredy uploaded and unpacked as a directory.';
                $payload = (object) [
                    'message' => $message,
                    'name' => $file->getClientOriginalName(),
                    'via' => 'api',
                ];
                addEvent($payload, 'UPLOAD', 'FAILED', 'NORMAL', $cid, $vid, $uid, $gid);
                // Original: sprintf("The automatic upload failed because: %s", $message)
                $notmessage = __('notifications.upload_auto_failed', ['reason' => $message]);
                notifyUser($user, $notmessage, 'error');

                return response()->json([
                    'status' => 'error',
                    'message' => $message,
                ], 500);
            }

            // all good. continue
            $options = [
                'disk' => 'vault',
                'path' => $vtools->getVaultName().'/'.$file->getClientOriginalName(),
                'visibility' => 'private',
                'user_name' => $user->username,
            ];

            $fileContent = fopen($file, 'r+');

            if (! $fileContent) {
                $closeOnExit && $vtools->closeVault();
                $message = 'Could not read file '.$file->getClientOriginalName().' from disk...';
                Log::error($message);
                $payload = (object) [
                    'message' => $message,
                    'name' => $file->getClientOriginalName(),
                    'via' => 'api',
                ];
                addEvent($payload, 'UPLOAD', 'FAILED', 'NORMAL', $cid, $vid, $uid, $gid);
                // Original: sprintf("The automatic upload failed because: %s", $message)
                $notmessage = __('notifications.upload_auto_failed', ['reason' => $message]);
                notifyUser($user, $notmessage, 'error');

                return response()->json([
                    'status' => 'error',
                    'message' => $message,
                ], 500);
            }

            $path = Storage::disk($options['disk'])->put($options['path'], $fileContent, $options['visibility']);

            if (! $path) {
                $closeOnExit && $vtools->closeVault();
                $message = 'Could not store file '.$file->getClientOriginalName().' path to disk...';
                Log::error($message);
                $payload = (object) [
                    'message' => $message,
                    'name' => $file->getClientOriginalName(),
                    'via' => 'api',
                ];
                addEvent($payload, 'UPLOAD', 'FAILED', 'NORMAL', $cid, $vid, $uid, $gid);
                // Original: sprintf("The automatic upload failed because: %s", $message)
                $notmessage = __('notifications.upload_auto_failed', ['reason' => $message]);
                notifyUser($user, $notmessage, 'error');

                return response()->json([
                    'status' => 'error',
                    'message' => $message,
                ], 500);
            }

            // generate a new contents file
            if (! $vtools->updateContents()) {
                $closeOnExit && $vtools->closeVault();
                $message = "Contents generation failed on {$user->username}'s vault";
                Log::error($message);
                $payload = (object) [
                    'message' => $message,
                    'name' => $file->getClientOriginalName(),
                    'via' => 'api',
                ];
                addEvent($payload, 'UPLOAD', 'FAILED', 'NORMAL', $cid, $vid, $uid, $gid);
                // Original: sprintf("The automatic upload failed because: %s", $message)
                $notmessage = __('notifications.upload_auto_failed', ['reason' => $message]);
                notifyUser($user, $notmessage, 'error');

                return response()->json([
                    'status' => 'error',
                    'message' => $message,
                ], 500);
            }

            $payload = (object) [
                'message' => 'File upload success',
                'name' => $file->getClientOriginalName(),
                'via' => 'api',
            ];
            addEvent($payload, 'UPLOAD', 'SUCCESS', 'NORMAL', $cid, $vid, $uid, $gid);

            // Original: 'File upload success' . ' ' . $file->getClientOriginalName()
            $notmessage = __('notifications.upload_file_success', ['filename' => $file->getClientOriginalName()]);
            notifyUser($user, $notmessage, 'success');

            // try to unpack
            $files = $vtools->getFiles();
            if (! $files) {
                $closeOnExit && $vtools->closeVault();
                $message = 'No files found in vault.';
                Log::error($message);
                $payload = (object) [
                    'message' => $message,
                    'name' => $file->getClientOriginalName(),
                    'via' => 'api',
                ];
                addEvent($payload, 'UNPACK', 'FAILED', 'NORMAL', $cid, $vid, $uid, $gid);

                // Original: sprintf("The automatic unpack failed because: %s", $message)
                $notmessage = __('notifications.unpack_auto_failed', ['reason' => $message]);
                notifyUser($user, $notmessage, 'warning');

                return response()->json([
                    'status' => 'warning',
                    'message' => $message,
                ], 200);
            }

            foreach ($files as $fileobj) {
                if ($fileobj->name == $file->getClientOriginalName()) {
                    // WARNING: here the $file variable changes type
                    $file = $fileobj;
                    break;
                }
            }

            if (! $file) {
                $closeOnExit && $vtools->closeVault();
                $message = 'Could not find the file '.$file->getClientOriginalName().' in the vault.';
                Log::error($message);
                $payload = (object) [
                    'message' => $message,
                    'name' => $file->name,
                    'via' => 'api',
                ];
                addEvent($payload, 'UNPACK', 'FAILED', 'NORMAL', $cid, $vid, $uid, $gid);

                // Original: sprintf("File uploaded correctly but the automatic unpack failed because: %s", $message)
                $notmessage = __('notifications.upload_success_unpack_failed', ['reason' => $message]);
                notifyUser($user, $notmessage, 'warning');

                return response()->json([
                    'status' => 'warning',
                    'message' => $message,
                ], 200);
            }

            $this->DEBUG && Log::info('file found: '.var_export($file, 1));

            $path = $vtools->getMountPoint().'/';

            $this->emessage = null;
            $this->cid = null;
            $this->did = null;

            // if there is an existing decrypt-pass key, try to unpack...
            // or if the file is not encrypted, try to unpack...
            $key = (object) ['key' => 'anything'];
            foreach ($user->apiKeys as $apiKey) {
                if ($apiKey->name == 'decrypt-pass') {
                    $key = $apiKey;
                    break;
                }
            }

            $fdata = $vtools->parseFilename($file->name);

            $statusfile = "/tmp/{$file->id}.json";
            $statuslock = "/tmp/{$file->id}.lock";

            if ($statusfile) {
                $statusdata = [
                    'phase' => 'Processing',
                    'percentage' => 1,
                    'filename' => $file->name,
                ];
                file_put_contents($statusfile, json_encode($statusdata));
                sleep(1);
            }

            $returnCodeFromdoDecryptAndExtract = 'success';

            if ((isset($key) && $key) || ! $fdata->gpg) {
                if (! $vtools->doDecryptAndExtract($file->name, $path, $key->key, $this->did, $this->cid, $this->emessage, $statusfile)) {
                    $returnCodeFromdoDecryptAndExtract = 'error';
                    // Retry with the upload-pass only when the failure was during decryption (not after).
                    // $vtools->ePhase === 'extract' means decryption already succeeded — retrying is pointless.
                    if ($vtools->ePhase !== 'extract') {
                        $returnCodeFromdoDecryptAndExtract = 'success';

                        // let's try with the upload-pass key...
                        if (! $vtools->doDecryptAndExtract($file->name, $path, $_SERVER['PHP_AUTH_PW'], $this->did, $this->cid, $this->emessage, $statusfile)) {
                            $returnCodeFromdoDecryptAndExtract = 'error';

                            $message = 'The file extraction failed.';

                            if ($this->emessage) {
                                $type = 'error';
                                // populeated during decryption or extraction
                                $message = $this->emessage;
                            }

                            $closeOnExit && $vtools->closeVault();
                            Log::error($message);
                            $payload = (object) [
                                'message' => $message,
                                'name' => $file->name,
                                'via' => 'api',
                            ];
                            addEvent($payload, 'UNPACK', 'FAILED', 'NORMAL', $cid, $vid, $uid, $gid);

                            // Original: sprintf("File %s was uploaded correctly but the automatic unpack failed: %s. File is available in vault.", $file->name, $message)
                            $notmessage = __('notifications.file_upload_unpack_failed', ['filename' => $file->name, 'reason' => $message]);
                            notifyUser($user, $notmessage, 'warning');

                            return response()->json([
                                'status' => 'warning',
                                'message' => $message,
                            ], 200);
                        }
                    }
                }
            } else {
                // let's try with the upload-pass key then...
                if (! $vtools->doDecryptAndExtract($file->name, $path, $_SERVER['PHP_AUTH_PW'], $this->did, $this->cid, $this->emessage, $statusfile)) {
                    $returnCodeFromdoDecryptAndExtract = 'error';

                    $message = 'The file extraction failed.';

                    if ($this->emessage) {
                        // populeated during decryption or extraction
                        $message = $this->emessage;
                    }

                    $closeOnExit && $vtools->closeVault();
                    Log::error($message);
                    $payload = (object) [
                        'message' => $message,
                        'name' => $file->name,
                        'via' => 'api',
                    ];
                    addEvent($payload, 'UNPACK', 'FAILED', 'NORMAL', $cid, $vid, $uid, $gid);

                    $notmessage = __('notifications.file_upload_unpack_failed', ['filename' => $file->name, 'reason' => $message]);
                    notifyUser($user, $notmessage, 'warning');

                    return response()->json([
                        'status' => 'warning',
                        'message' => $message,
                    ], 200);
                }
            }

            if ($returnCodeFromdoDecryptAndExtract == 'error') {
                $message = 'The file extraction failed.';

                if ($this->emessage) {
                    // populeated during decryption or extraction
                    $message = $this->emessage;
                }

                $closeOnExit && $vtools->closeVault();
                Log::error($message);
                $payload = (object) [
                    'message' => $message,
                    'name' => $file->name,
                    'via' => 'api',
                ];
                addEvent($payload, 'UNPACK', 'FAILED', 'NORMAL', $cid, $vid, $uid, $gid);

                $notmessage = __('notifications.file_upload_unpack_failed', ['filename' => $file->name, 'reason' => $message]);
                notifyUser($user, $notmessage, 'warning');

                return response()->json([
                    'status' => 'warning',
                    'message' => $message,
                ], 200);
            } else {
                // Original: 'File extraction complete.'
                $message = __('notifications.unpack_extraction_complete');
            }

            $this->DEBUG && Log::info('file unpacked successfully');

            if (isset($this->did)) {
                // pre extract data for summary tool
                $this->DEBUG && Log::info('Generiating Summary tool data');

                $dtools = new DataTools($vtools, $vid, $this->did);
                $dtools->summaryData($this->cid);
            }

            $payload = (object) [
                'message' => $message,
                'name' => $file->name,
                'id' => $file->id,
                'via' => 'api',
            ];
            addEvent($payload, 'UNPACK', 'SUCCESS', 'NORMAL', $this->cid, $vid, $uid, $gid);

            // Original: sprintf("Automatic file upload success. Automatic %s", $message)
            $notmessage = __('notifications.upload_auto_complete', ['details' => $message]);
            notifyUser($user, $notmessage, 'success');

            $closeOnExit && $vtools->closeVault();

            return response()->json([
                'status' => 'success',
                'message' => $message,
            ], 200);

        } else {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized',
            ], 401);
        }
    }

    /**
     * Refresh a token.
     *
     * @return JsonResponse
     */
    public function refresh()
    {
        return $this->respondWithToken(auth()->refresh());
    }

    /**
     * Get the token array structure.
     *
     * @param  string  $token
     * @return JsonResponse
     */
    protected function respondWithToken($token)
    {
        return response()->json([
            'access_token' => $token,
            'token_type' => 'bearer',
            'expires_in' => config('wave.api.auth_token_expires', 60),
        ]);
    }

    public function register(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'username' => 'required|string|max:250',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => [
                'required',
                'min:'.PasswordPolicy::minLength(),
                'regex:'.PasswordPolicy::regex(),
                'confirmed',
            ],
        ]);

        // Enforce the rules above: without this the endpoint created a user from
        // raw, unvalidated input — no email format / uniqueness check and no
        // password policy (weak-password accounts, and 500s on empty/duplicate).
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Get the Default role of a user
        $role = Role::where('name', setting('auth.default_role'))->first();

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'username' => $request->username,
            'password' => bcrypt($request->password),
        ]);

        if ($role) {
            $user->syncRoles([$role->name]);
        }

        $credentials = ['email' => $request['email'], 'password' => $request['password']];

        if (! $token = JWTAuth::attempt($credentials)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        return $this->respondWithToken($token);

    }

    protected function validator(array $data)
    {
        return Validator::make($data, [
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => [
                'required',
                'min:'.PasswordPolicy::minLength(),
                'regex:'.PasswordPolicy::regex(),
                'confirmed',
            ],
        ]);
    }
}
