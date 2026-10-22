<?php

namespace Wave\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Annotation;
use App\Models\ContentsRequest;
use App\Models\SupportCase;
use App\Providers\DataTools;
use App\Providers\VaultTools;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Wave\PaddleSubscription;
use Wave\Plan;
use Wave\User;

class SvaultController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:web', ['except' => ['vaultState']]);
        $this->middleware('vault', ['except' => ['vaultState']]);
    }

    public function getDir(Request $request, $did)
    {

        // Log::info(var_export(auth()->user(),1));

        /*
        $authorized = auth()->user()->can('browse', app('SupportCase'));

        if(!$authorized){
            abort(403, 'Unauthorized');
        }
        */

        $vtools = new VaultTools(auth()->user());
        if (! $vtools->isOpen()) {
            abort(403, 'Vault closed');
        }

        $dir = $vtools->getDirById($did);

        if (! $dir) {
            abort(404, 'File not found');
        }

        $path = $vtools->getMountPoint().'/'.$dir->name;

        $dirContents = $vtools->getContents($path);

        return response()->json($dirContents);
    }

    public function getTools(Request $request)
    {
        // object with all available tools
        $vtools = new VaultTools(auth()->user());
        if (! $vtools->isOpen()) {
            abort(403, 'Vault closed');
        }

        $tools = $vtools->getTools();

        if (! $tools) {
            abort(404, 'Tools not found');
        }

        return response()->json($tools);
    }

    public function getToolOutput(Request $request, $vid, $did, $tool, $cid, $sme)
    {
        $closeFlag = 0;
        $uid = auth()->user()->id;

        $vtools = new VaultTools(auth()->user());

        if (! $vtools) {
            abort(404, 'Vault not found');
        }

        if ($vtools->getVaultId() != $vid) {
            if ($sme == 0) {
                abort(403, 'Wrong vault');
            }

            // ok this is someone else's vault. Switch vault and close it after serving the docuemnt

            $meta = ContentsRequest::where('vault_id', $vid)->where('dir_id', $did)->where('tool_name', $tool)->first();
            if (! isset($meta)) {
                abort(403, 'Wrong vault');
            }

            if (isset($meta) && $meta) {
                // switch vault just once
                $ouser = User::where('id', $meta->owner)->first();
                $vtools = new VaultTools($ouser);

                // only close the vault if it's not in use
                // if sme is 2 do not close the vault as is in use.
                $closeFlag = $sme == 1 ? 1 : 0;
            }
        }

        if (! $vtools->isOpen()) {
            abort(403, 'Vault closed');
        }

        if (! isset($meta)) {
            // generate the short url
            $hash = hash('sha256', "{$uid}/{$vid}/{$did}/{$tool}");
            $url = url("sosShared/{$hash}");

            // check for an already existing contents in the database:
            $meta = ContentsRequest::where('vault_id', $vid)->where('dir_id', $did)->where('tool_name', $tool)->where('url', $url)->first();
        }

        // if it is too old delete the record to generate a new one
        if (isset($meta) && $meta && ! $sme) {
            // check the expire...
            $expire = strtotime($meta->expire);
            $now = time();
            if (! ($expire - $now > 0 && $meta->status == 'VALID') || $meta->status == 'EXPIRED') {
                // atm I delete the record so the database do not grow needlessly
                // is the record is expired is useless
                $meta->delete();
                // abort(403, 'Record expired');
            }
        }

        if (! $meta) {
            if (auth()->user()->role->name == 'Free') {
                $plan_id = '0';
                $subscription_id = '0';
            } elseif (auth()->user()->role->name == 'admin') {
                $plan_id = '0';
                $subscription_id = '1';
            } else {
                $plan = Plan::where('role_id', '=', auth()->user()->role_id)->first();

                if ($plan) {
                    $plan_id = $plan->monthly_price_id;
                }

                $subscription_id = PaddleSubscription::where('user_id', '=', auth()->user()->id)->first()->id;
            }

            // Warning: No TZ
            $days = 7;
            $expire = date('Y-m-d H:i:s', strtotime("+{$days} days"));

            // add record to the database
            $meta = ContentsRequest::create([
                'vault_id' => $vid,
                'dir_id' => $did,
                'file_id' => 0,
                'case_id' => $cid,
                'tool_name' => $tool,
                'owner' => $uid,
                'group' => $uid,
                'perms' => '750',
                'status' => 'VALID',
                'expire' => $expire,
                'comments' => '',
                'subscription_id' => $subscription_id,
                'plan_id' => $plan_id,
                'role_id' => auth()->user()->role_id,
                'url' => $url,
            ]);
        }

        $response = (object) [
            'metaData' => null,
            'contents' => null,
            'chunked' => false,
            'chunksize' => 0,
        ];

        $toolContents = $vtools->getToolOutput($tool, $vid, $did, $cid);

        if (! $toolContents) {
            abort(404, 'Tool not found');
        }

        $response->contents = $toolContents->contents;
        $response->chunked = $toolContents->chunked;
        $response->chunksize = $vtools->chunkSize;

        $dtools = new DataTools($vtools, $vid, $did);
        $host = $dtools->getHostData();

        // file information
        $response->metaData = $meta;
        $response->metaData->title = $toolContents->title;
        $response->metaData->name = $toolContents->name;
        $response->metaData->lines = $toolContents->lines;
        $response->metaData->path = $toolContents->path;
        $response->metaData->size = $toolContents->size;
        $response->metaData->date = $toolContents->date;
        $response->metaData->time = $toolContents->time;
        $response->metaData->offset = 0;
        $response->metaData->chunkSize = $vtools->chunkSize;
        $response->metaData->tooBig = $vtools->tooBig;
        $response->metaData->host = $host->hostname;

        // auth information
        $response->metaData->user = auth()->user()->username;
        $response->metaData->uname = auth()->user()->name;
        $response->metaData->group = auth()->user()->group;
        $response->metaData->email = auth()->user()->email;
        $response->metaData->mode = auth()->user()->theme_mode;
        $response->metaData->avatar = auth()->user()->theme_avatar;
        $response->metaData->end = auth()->user()->trial_ends_at;

        // case information
        $case = SupportCase::where('id', $cid)->first();

        $response->metaData->case = $case->case;
        $response->metaData->customer = $case->customer;
        $response->metaData->version = $case->version;
        $response->metaData->serial = $case->serial;

        // we can put annotation to tool outputs as well!!

        if ($closeFlag) {
            // close vault
            $vtools->CloseVault();
        }

        return response()->json($response);
    }

    public function setAnnotations(Request $request, $vid, $did, $fid, $sme)
    {
        $uid = auth()->user()->id;

        $vtools = new VaultTools(auth()->user());

        if (! $vtools) {
            abort(404, 'Vault not found');
        }

        if ($vtools->getVaultId() != $vid) {
            if ($sme == 0) {
                abort(403, 'Wrong vault');
            }

            // if the document is not locked, the other user can add notes (but not delete)
            $record = Annotation::where('vault_id', $vid)->where('dir_id', $did)->where('file_id', $fid)->first();
            if (! isset($record)) {
                abort(403, 'Wrong document');
            }

            if ($record->locked) {
                abort(200, 'Document is owner-locked thus cannot be annotated');
            }

            if (isset($record) && $record) {
                $record->update([
                    'acetate' => $request->acetate,
                ]);

                $message = 'Annotation updated.';
                $event = 'CHG_NOTE';

                $cid = 0;
                $uid = auth()->user()->id;
                $gid = auth()->user()->id;

                $payload = (object) [
                    'message' => $message,
                    'dir_id' => $did,
                    'file_id' => $fid,
                    'title' => $request->title,
                    'contents' => $this->extractNotes($request->acetate),
                ];
                addEvent($payload, $event, 'SUCCESS', 'NORMAL', $cid, $vid, $uid, $gid);

                $resp = ['status' => 'OK'];

                return response()->json($resp);
            }
        }

        // check for an already existing annotation in the database:
        $record = Annotation::where('vault_id', $vid)->where('dir_id', $did)->where('file_id', $fid)->first();

        if (auth()->user()->role->name == 'Free') {
            $plan_id = '0';
            $subscription_id = '0';
        } elseif (auth()->user()->role->name == 'admin') {
            $plan_id = '0';
            $subscription_id = '1';
        } else {
            $plan = Plan::where('role_id', '=', auth()->user()->role_id)->first();

            if ($plan) {
                $plan_id = $plan->monthly_price_id;
            }

            $subscription_id = PaddleSubscription::where('user_id', '=', auth()->user()->id)->first()->id;
        }

        // Warning: No TZ
        $expirationDays = 30;
        $expire = str_replace('T', ' ', preg_replace("/\+..*/", '', date('c', strtotime(date('c')." + {$expirationDays} days"))));

        if (! (isset($record) && $record)) {
            // add record to the database
            $record = Annotation::create([
                'vault_id' => $vid,
                'dir_id' => $did,
                'file_id' => $fid,
                'owner' => $uid,
                'group' => $uid,
                'perms' => '750',
                'status' => 'PRIVATE',
                'expire' => $expire,
                'subscription_id' => $subscription_id,
                'plan_id' => $plan_id,
                'role_id' => auth()->user()->role_id,
                'title' => $request->title,
                'acetate' => $request->acetate,
                'locked' => ($request->locked == 'true') ? 1 : 0,
            ]);
            $message = 'Annotation created.';
            $event = 'ADD_NOTE';
        } else {
            $record->update([
                'expire' => $expire,
                'title' => $request->title,
                'acetate' => $request->acetate,
                'locked' => $request->locked,
                'status' => $request->status,
            ]);
            $message = 'Annotation updated.';
            $event = 'CHG_NOTE';
        }

        $resp = ['status' => 'OK'];

        $cid = 0;
        $uid = auth()->user()->id;
        $gid = auth()->user()->id;

        $payload = (object) [
            'message' => $message,
            'dir_id' => $did,
            'file_id' => $fid,
            'title' => $request->title,
            'contents' => $this->extractNotes($request->acetate),
        ];
        addEvent($payload, $event, 'SUCCESS', 'NORMAL', $cid, $vid, $uid, $gid);

        return response()->json($resp);
    }

    public function extractNotes($jsonacetate)
    {
        $acetate = json_decode($jsonacetate);
        $pathnames = [];
        $values = [];
        $headers = [];
        foreach ($acetate->node->childNodes as $node) {
            if (isset($node->id)) {
                if (preg_match("/^note\d+$/", $node->id)) {
                    $note = $node;
                    $pathnames[$note->id] = '';
                    $values[$note->id] = '';
                    $headers[$note->id] = '';
                    foreach ($note->childNodes as $node) {
                        if (isset($node->id)) {
                            if (preg_match("/^noteText\d+$/", $node->id)) {
                                $pathnames[$note->id] = $node->ownerDocument->location->pathname;
                                if (isset($node->value)) {
                                    $values[$note->id] = $node->value;
                                }
                            }
                            if (preg_match("/^noteSubHeader\d+$/", $node->id)) {
                                $header = '';
                                foreach ($node->childNodes as $subnode) {
                                    if (preg_match('/^LABEL$/', $subnode->nodeName)) {
                                        foreach ($subnode->childNodes as $textnode) {
                                            if (preg_match('/^#text$/', $textnode->nodeName)) {
                                                $header .= $textnode->data;
                                                $header .= ';';
                                            }
                                        }
                                    }
                                }
                                $headers[$note->id] = $header;
                                Log::info($header);
                            }
                        }
                    }
                }
            }
        }

        $notes = [];
        foreach ($values as $id => $value) {
            $note = (object) [];
            $meta = explode(';', $headers[$id]);

            $note->value = $value;
            $note->path = $pathnames[$id];
            $note->id = $id;
            $note->filename = $meta[0];

            if (count($meta) == 3) {
                $note->owner = '';
                $note->uid = '';
                $note->date = $meta[1];
            } elseif (count($meta) == 5) {
                $note->owner = $meta[1];
                $note->uid = $meta[2];
                $note->date = $meta[3];
            }

            $notes[] = $note;
        }

        return json_encode($notes);
    }

    public function userInfo()
    {
        $info = [
            'id' => auth()->user()->id,
            'name' => auth()->user()->name,
            'username' => auth()->user()->username,
            'mail' => auth()->user()->email,
            'avatar' => auth()->user()->avatar,
        ];

        return json_encode($info);
    }

    public function download(Request $request, $vid, $did, $fid)
    {
        $vtools = new VaultTools(auth()->user());
        if ($vtools->getVaultId() != $vid) {
            abort(403, 'Wrong vault');
        }

        if (! $vtools->isOpen()) {
            abort(403, 'Vault closed');
        }

        $dir = $vtools->getDirById($did);
        $mountp = $vtools->getMountPoint();

        if (! $dir) {
            abort(404, 'Directory not found');
        }

        $tree = $vtools->getContents("{$mountp}/{$dir->name}");
        if ($tree) {
            $file = $vtools->find_node_by_attr($tree->nodes[0]->nodes, 'id', $fid);
        }

        if (! $file) {
            abort(404, 'File not found');
        }

        $path = $file->path && ! $file->realpath ? $file->path : $file->realpath;

        $filepath = "{$mountp}/{$dir->name}/{$path}/{$file->name}";

        if (! file_exists($filepath)) {
            abort(404, 'Path not found');
        } else {
            $filename = basename($filepath);
            $cid = 0;
            $uid = auth()->user()->id;
            $gid = auth()->user()->id;

            $message = 'File download success';
            $payload = (object) [
                'message' => $message,
                'filename' => $filename,
                'dir_id' => $did,
                'file_id' => $fid,
            ];
            addEvent($payload, 'DOWLOAD', 'SUCCESS', 'NORMAL', $cid, $vid, $uid, $gid);

            header('Content-Description: File Transfer');
            header('Content-Type: application/octet-stream');
            header("Content-Disposition: attachment; filename={$filename}");
            header('Expires: 0');
            header('Cache-Control: must-revalidate');
            header('Pragma: public');
            header('Content-Length: '.filesize($filepath));
            flush();
            readfile($filepath);
            exit;
        }
    }

    public function getBookmarks(Request $request)
    {
        $vtools = new VaultTools(auth()->user());
        if (! $vtools->isOpen()) {
            abort(403, 'Vault closed');
        }

        $bookmarks = $vtools->getBookmarks();

        if (! $bookmarks) {
            abort(404, 'File not found');
        }

        return response()->json($bookmarks);
    }

    public function setBookmark(Request $request, $did, $fid)
    {
        $uid = auth()->user()->id;
        $vtools = new VaultTools(auth()->user());
        $vid = $vtools->getVaultId();

        $vtools->setBookmarks($request->bookmarks);
        $resp = ['status' => 'OK'];

        return response()->json($resp);
    }

    public function getFileContents(Request $request, $vid, $did, $fid, $cid, $sme)
    {
        $closeFlag = 0;
        $uid = auth()->user()->id;

        $vtools = new VaultTools(auth()->user());

        if (! $vtools) {
            abort(404, 'Vault not found');
        }

        if ($vtools->getVaultId() != $vid) {
            if ($sme == 0) {
                abort(403, 'Wrong vault');
            }

            // ok this is someone else's vault. Switch vault and close it after serving the docuemnt

            $meta = ContentsRequest::where('vault_id', $vid)->where('dir_id', $did)->where('file_id', $fid)->first();
            if (! isset($meta)) {
                abort(403, 'Wrong vault');
            }

            if (isset($meta) && $meta) {
                // switch vault just once
                $ouser = User::where('id', $meta->owner)->first();
                $vtools = new VaultTools($ouser);

                // only close the vault if it's not in use
                // if sme is 2 do not close the vault as is in use.
                $closeFlag = $sme == 1 ? 1 : 0;
            }
        }

        if (! $vtools->isOpen()) {
            abort(403, 'Vault closed');
        }

        if (! isset($meta)) {
            // generate the short url
            $hash = hash('sha256', "{$uid}/{$vid}/{$did}/{$fid}");
            $url = url("sosShared/{$hash}");

            // check for an already existing contents in the database:
            $meta = ContentsRequest::where('vault_id', $vid)->where('dir_id', $did)->where('file_id', $fid)->where('url', $url)->first();
        }

        // if it is too old delete the record to generate a new one
        if (isset($meta) && $meta && ! $sme) {
            // check the expire...
            $expire = strtotime($meta->expire);
            $now = time();
            if (! ($expire - $now > 0 && $meta->status == 'VALID') || $meta->status == 'EXPIRED') {
                // atm I delete the record so the database do not grow needlessly
                // is the record is expired is useless
                $meta->delete();
                // abort(403, 'Record expired');
            }
        }

        if (! $meta) {
            if (auth()->user()->role->name == 'Free') {
                $plan_id = '0';
                $subscription_id = '0';
            } elseif (auth()->user()->role->name == 'admin') {
                $plan_id = '0';
                $subscription_id = '1';
            } else {
                $plan = Plan::where('role_id', '=', auth()->user()->role_id)->first();

                if ($plan) {
                    $plan_id = $plan->monthly_price_id;
                }

                $subscription_id = PaddleSubscription::where('user_id', '=', auth()->user()->id)->first()->id;
            }

            // Warning: No TZ
            $days = 7;
            $expire = date('Y-m-d H:i:s', strtotime("+{$days} days"));

            // add record to the database
            $meta = ContentsRequest::create([
                'vault_id' => $vid,
                'dir_id' => $did,
                'file_id' => $fid,
                'case_id' => $cid,
                'owner' => $uid,
                'group' => $uid,
                'perms' => '750',
                'status' => 'VALID',
                'expire' => $expire,
                'comments' => '',
                'subscription_id' => $subscription_id,
                'plan_id' => $plan_id,
                'role_id' => auth()->user()->role_id,
                'url' => $url,
            ]);
        }

        // check for an already existing annotation in the database:
        $annot = Annotation::where('vault_id', $vid)->where('dir_id', $did)->where('file_id', $fid)->first();

        $status = $annot ? $annot->status : 'PRIVATE';
        $locked = $annot ? $annot->locked : 0;

        $response = (object) [
            'metaData' => null,
            'acetate' => null,
            'contents' => null,
            'chunked' => false,
        ];

        $fileContents = $vtools->getFileContentsById($vid, $did, $fid, 0, $cid);

        if (! $fileContents) {
            abort(403, 'No contents found');
        }

        $response->chunked = $fileContents->chunked;
        $response->chunksize = $vtools->chunkSize;

        $dtools = new DataTools($vtools, $vid, $did);
        $host = $dtools->getHostData();

        // file information
        $response->metaData = $meta;
        $response->metaData->status = $status;
        $response->metaData->locked = $locked;
        $response->metaData->title = $fileContents->title;
        $response->metaData->name = $fileContents->name;
        $response->metaData->lines = $fileContents->lines;
        $response->metaData->path = $fileContents->path;
        $response->metaData->size = $fileContents->size;
        $response->metaData->date = $fileContents->date;
        $response->metaData->time = $fileContents->time;
        $response->metaData->offset = 0;
        $response->metaData->chunkSize = $vtools->chunkSize;
        $response->metaData->tooBig = $vtools->tooBig;
        $response->metaData->host = $host->hostname;

        // auth information
        $response->metaData->user = auth()->user()->username;
        $response->metaData->uname = auth()->user()->name;
        $response->metaData->group = auth()->user()->group;
        $response->metaData->email = auth()->user()->email;
        $response->metaData->mode = auth()->user()->theme_mode;
        $response->metaData->avatar = auth()->user()->theme_avatar;
        $response->metaData->end = auth()->user()->trial_ends_at;

        // case information
        $case = SupportCase::where('id', $cid)->first();

        $response->metaData->case = $case->case;
        $response->metaData->customer = $case->customer;
        $response->metaData->version = $case->version;
        $response->metaData->serial = $case->serial;

        if (isset($annot) && isset($annot->acetate)) {
            // check to see if the annotation is still has notes and/or highlights
            $hasNotes = 0;
            $hasHighlights = 0;
            $dom = json_decode($annot->acetate);

            foreach ($dom->node->childNodes as $element) {
                // find notes
                if (isset($element->id) && preg_match("/note\d+/", $element->id)) {
                    $hasNotes++;
                }

                // find highlights
                if (isset($element->id) && preg_match('/pre/', $element->id)) {
                    $hasHighlights = (count($element->childNodes) - 1) / 2;
                }
            }

            if (! $hasNotes && ! $hasHighlights) {
                $annot->update(['acetate' => null]);
                if (isset($fileContents->contents)) {
                    $response->contents = $this->ansi2html(htmlspecialchars($fileContents->contents));
                }
            } else {
                $response->acetate = $annot->acetate;
            }
        } elseif (isset($fileContents->contents)) {
            $response->contents = $this->ansi2html(htmlspecialchars($fileContents->contents));
        }

        if ($closeFlag) {
            // close vault
            $vtools->CloseVault();
        }

        return response()->json($response);
    }

    public function fetchLogChunk(Request $request, $vid, $did, $fid)
    {
        // for files larger than $vtools->tooBig we serve in $vtools->chunkSize chunks
        $uid = auth()->user()->id;

        $vtools = new VaultTools(auth()->user());

        if (! $vtools) {
            abort(404, 'Vault not found');
        }

        if ($vtools->getVaultId() != $vid) {
            abort(403, 'Wrong vault');
        }

        if (! $vtools->isOpen()) {
            abort(403, 'Vault closed');
        }

        $found = $vtools->getFilePathById($vid, $did, $fid);
        if (! $found) {
            abort(404, 'File not found');
        }
        $filePath = $found->filePath;

        $offset = $request->offset;
        $chunkSize = $vtools->chunkSize;

        return response()->stream(function () use ($filePath, $offset, $chunkSize) {
            $fileHandle = fopen($filePath, 'rb');
            fseek($fileHandle, $offset);
            echo $this->ansi2html(htmlspecialchars(fread($fileHandle, $chunkSize)));
            fclose($fileHandle);
        }, 200, [
            'Content-Type' => 'text/plain',
            'Offset' => $offset + $chunkSize,
        ]);
    }

    public function ansi2html($shellstring)
    {
        $dictionary = [
            '[0;30m' => '<span style="color:black">',
            '[0;31m' => '<span style="color:red">',
            '[0;32m' => '<span style="color:green">',
            '[0;33m' => '<span style="color:yellow">',
            '[0;34m' => '<span style="color:blue">',
            '[0;35m' => '<span style="color:magenta">',
            '[0;36m' => '<span style="color:cyan">',
            '[0;37m' => '<span style="color:white">',
            '[0;39m' => '<span>',
            '[1;30m' => '<span style="color:black">',
            '[1;31m' => '<span style="color:red">',
            '[1;32m' => '<span style="color:green">',
            '[1;33m' => '<span style="color:yellow">',
            '[1;34m' => '<span style="color:blue">',
            '[1;35m' => '<span style="color:magenta">',
            '[1;36m' => '<span style="color:cyan">',
            '[1;37m' => '<span style="color:white">',
            '[1;39m' => '<span>',
            '[0;1;30m' => '<span style="color:black">',
            '[0;1;31m' => '<span style="color:red">',
            '[0;1;32m' => '<span style="color:green">',
            '[0;1;33m' => '<span style="color:yellow">',
            '[0;1;34m' => '<span style="color:blue">',
            '[0;1;35m' => '<span style="color:magenta">',
            '[0;1;36m' => '<span style="color:cyan">',
            '[0;1;37m' => '<span style="color:white">',
            '[0;1;39m' => '<span>',
            '[0m' => '</span>',
            '[0' => '',
            '[K' => '',
        ];
        $htmlString = str_replace(array_keys($dictionary), $dictionary, $shellstring);

        return $htmlString;
    }

    public function deleteReport(Request $request, $vid, $did, $cid)
    {
        $uid = auth()->user()->id;

        $vtools = new VaultTools(auth()->user());

        if (! $vtools) {
            abort(404, 'Vault not found');
        }

        if ($vtools->getVaultId() != $vid) {
            if ($sme == 0) {
                abort(403, 'Wrong vault');
            }
        }

        if (! $vtools->isOpen()) {
            abort(403, 'Vault closed');
        }

        $mountp = $vtools->getMountPoint();
        $dir = $vtools->getDirById($did);
        $path = "{$mountp}/{$dir->name}";

        /*
        $identifier = "{$path}/.identifier";
        if(!is_file($identifier)){
            abort(403, 'No identifier found');
        }

        $id = rtrim(file_get_contents($identifier));
        */

        $reportFile = "{$path}/.report.txt";
        if (! is_file($reportFile)) {
            abort(403, 'No report found');
        }

        unlink($reportFile);
        Log::info("{$reportFile} was deleted");

        // generate an event
        $message = 'Ai Report deleted successfully.';
        $event = 'DEL_REPORT';

        $gid = $uid;

        $payload = (object) [
            'message' => $message,
            'dir_id' => $did,
        ];
        addEvent($payload, $event, 'SUCCESS', 'ACTIVITY', $cid, $vid, $uid, $gid);

        $resp = ['status' => 'OK'];

        return response()->json($resp);
    }

    public function generateReport(Request $request, $vid, $did, $cid)
    {

        $vtools = new VaultTools(auth()->user());

        if (! $vtools) {
            abort(404, 'Vault not found');
        }

        if ($vtools->getVaultId() != $vid) {
            if ($sme == 0) {
                abort(403, 'Wrong vault');
            }
        }

        if (! $vtools->isOpen()) {
            abort(403, 'Vault closed');
        }

        $dtools = new DataTools($vtools, $vid, $did);
        $report = $dtools->getAIStatusReport();

        $resp = ['status' => 'OK', 'data' => $report];

        return response()->json($resp);
    }

    public function vaultState(Request $request)
    {
        if (! auth()->user()) {
            Log::error('User not set. Terminating session...');
            abort(401, 'Unauthorized');
        }

        $lifetime = config()->get('session.lifetime') * 60;
        // session timeout plus 2 minutes or
        // 2 minutes after the vault has been closed
        $lifetime += 2 * 60;
        $now = date('U');

        $last_activity = auth()->user()->last_activity;

        $uid = auth()->user()->id;
        $vtools = new VaultTools(auth()->user());

        if (! $vtools) {
            Log::error('Vault not set. Terminating session...');
            abort(404, 'Vault not found');
        }

        $status = intval($vtools->isOpen());

        if (! $status && $last_activity < ($now - $lifetime)) {
            Log::error('Vault was closed. Terminating session...');
            auth()->logout();
            abort(419, 'Session ended');
        }

        if ($status && $last_activity < ($now - $lifetime)) {
            Log::error('Session timed out. Terminating session...');
            auth()->logout();
            abort(419, 'Session ended');
        }

        $info = [
            'open' => $status,
        ];

        return json_encode($info);
    }
}
