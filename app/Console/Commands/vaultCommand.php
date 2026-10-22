<?php

namespace App\Console\Commands;

use App\Models\ITSMProvider;
use App\Models\User;
use App\Models\UserThread;
use App\Models\UserToken;
use App\Models\Vault;
use App\Providers\VaultTools;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Wave\Plan;

class vaultCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'vault:Admin {--describe : Display detailed help for vault:Admin} {--y : anser yes to all questions} {--yes : anser yes to all questions} {action?} {username?} {amount?}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = "User's vault administration";

    /**
     * Execute the console command.
     */
    public function handle()
    {
        //
        if ($this->option('describe')) {
            $this->info("\n\nUsage: artisan vault:Admin action username [amount]\n\n");
            $this->info("\tvault:Admin allows you to perform different actions on a user's vault when needed.\n\n");
            $this->info("\tvalid actions are: Status, Info, Create, Open, Close, Delete, Expand, Shrink, AddNewPass, DelOldPass, HeaderBackup, HeaderRestore, UpdateContents, Check, CloseDevice, CloseAll, Features DeleteUser\n");
            $this->info("\tusername: an existing username\n");
            $this->info("\tamount: the amount of disk space to be expanded or shrinked expressed in MB\n");
            exit;
        }

        $command = $this->argument('action');
        $username = $this->argument('username');
        $amount = $this->argument('amount');

        if (! $command) {
            $this->info('A command is required. Aborting...');

            return;
        }

        if ($command != 'Features' && ! $username) {
            $this->info('A username is required. Aborting...');

            return;
        }

        if ($command === 'Expand' || $command === 'Shrink') {
            if (! $amount) {
                $this->info('A disk space amount expressed in MB is required. Aborting...');

                return;
            }
        }

        if (! $this->option('y') && ! $this->option('yes')) {
            if (! $this->confirm("About to {$command} {$username}'s vault. Do you want to continue? (y/n)")) {
                $this->info('Aborting...');

                return;
            }
        }

        if ($command != 'Features') {
            $user = User::where('username', '=', $username)->first();

            if (! $user) {
                $this->info("{$username} does not exists in the database. Aborting...");

                return;
            }

            $vault = new VaultTools($user);

            if (! $vault) {
                $this->info("Seems like {$username} does not have a valid vault. Aborting...");

                return;
            }
        }

        switch ($command) {
            case 'Info':
            case 'Status':
                $message = $vault->infoVault();
                $this->info($message);

                return;
                break;
            case 'Create':
                $vault->createVault();
                $message = 'CREATED';
                break;
            case 'Open':
                $vault->openVault();
                $message = 'OPENED';
                break;
            case 'Close':
                $vault->closeVault();
                $message = 'CLOSED';
                break;
            case 'Expand':
                $payload = (object) [
                    'description' => "Console vault expansion in {$amount} MB",
                    'plan_id' => 0,
                    'message' => '',
                ];

                $vault->expandVault($amount, $payload);
                $message = 'EXPANDED';
                break;
            case 'Shrink':
                $payload = (object) [
                    'description' => "Console vault shrink in {$amount} MB",
                    'plan_id' => 0,
                    'message' => '',
                ];

                $vault->shrinkVault($amount, $payload);
                $message = 'SHRINKED';
                break;
            case 'Check':
                $vault->fscheckVault();
                $message = 'FS CHECKED';
                break;
            case 'Delete':
                $vault->destroyVault();
                $message = 'DELETED';
                break;
            case 'DeleteUser':
                // this deletes users that are in cancelled or Free roles
                if ($user->role->name == 'Free' || $user->role->name == 'cancelled') {
                    // delete user_tokens
                    $token = UserToken::where('user_id', $user->id)->first();
                    $token && $token->delete();

                    // delete user_threads
                    $thread = UserThread::where('user_id', $user->id)->first();
                    $thread && $thread->delete();

                    // delete itsmproviders
                    $itsm = ITSMProvider::where('uid', $user->id)->first();
                    $itsm && $itsm->delete();

                    // Sysevents, supportCases, contentsRequests, ApiKeys and Annotations are deleted in destroyVault
                    $vault->destroyVault();

                    $user->delete();
                }
                $message = 'USER DELETED';
                break;
            case 'AddNewPass':
                $vault->addPass4Vault();
                $message = 'NEW PASS ADDED';
                break;
            case 'DelOldPass':
                $vault->delPass4Vault();
                $message = 'OLD PASS DELETED';
                break;
            case 'HeaderBackup':
                $vault->headerBackup();
                $message = 'BACKED-UP';
                break;
            case 'HeaderRestore':
                $vault->headerRestore();
                $message = 'RESTORED';
                break;
            case 'UpdateContents':
                $vault->updateContents();
                $message = 'UPDATED';
                break;
            case 'CloseDevice':
                $vault->closeDevice();
                $message = 'CLOSED (DEVICE)';
                break;
            case 'CloseAll':
                $this->closeAll();
                $message = 'CLOSED (ALL)';
                break;
            case 'Features':
                $this->updatePlanFeatures();
                $message = 'Plan Feautures Updated';
                $this->info($message);

                return;
                break;
            default:
                $this->info('Unknown command. Aborting...');

                return;
                break;
        }

        $this->info("The vault associated with {$username} has been {$message}");
    }

    public function closeAll(): bool
    {
        // Close all open vaults
        $vaults = Vault::where('status', '=', 'OPEN')->get();
        foreach ($vaults as $vault) {
            $user = User::where('id', '=', $vault->owner)->first();
            $vtools = new VaultTools($user);
            if ($vtools) {
                $vtools->closeVault();
                $message = 'CLOSED';
                $this->info("User's {$user->name} vault {$message}");
            }
        }

        return true;
    }

    public function updatePlanFeatures()
    {
        $dir = dirname(__FILE__).'/../../phrases';
        $featFiles = "{$dir}/plans.json";

        $allFeat = [];
        if (! is_file($featFiles)) {
            Log::error("No file found {$featFile}");

            return false;
        }

        $allFeat = json_decode(file_get_contents($featFiles), 1);

        if (! $allFeat) {
            switch (json_last_error()) {
                case JSON_ERROR_NONE:
                    $mesg = 'No errors';
                    break;
                case JSON_ERROR_DEPTH:
                    $mesg = 'Maximum stack depth exceeded';
                    break;
                case JSON_ERROR_STATE_MISMATCH:
                    $mesg = 'Underflow or the modes mismatch';
                    break;
                case JSON_ERROR_CTRL_CHAR:
                    $mesg = 'Unexpected control character found';
                    break;
                case JSON_ERROR_SYNTAX:
                    $mesg = 'Syntax error, malformed JSON';
                    break;
                case JSON_ERROR_UTF8:
                    $mesg = 'Malformed UTF-8 characters, possibly incorrectly encoded';
                    break;
                default:
                    $mesg = ' - Unknown error';
                    break;
            }
            Log::error($mesg);

            return false;
        }

        foreach ($allFeat as $name => $features) {
            $plan = Plan::whereEnglishName($name)
                ->where('type', 'service')
                ->first();

            $plan->features = json_encode($features);
            $plan->save();
        }
    }
}
