<?php

namespace App\Models;

use App\Providers\DataTools;
use App\Providers\VaultTools;
use App\Services\VaultAccess;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use stdClass;
use Sushi\Sushi;

class FileContent extends Model
{
    use Sushi;

    protected static array $parameters = [];

    public function case(): BelongsTo
    {
        return $this->belongsTo(SupportCase::class, 'case_id');
    }

    public static function withParameters(array $params): self
    {
        // log::info(var_export($params, true));
        static::$parameters = $params;

        return new static; // Return a new instance for chaining
    }

    public function setRows(array $data = [])
    {
        if (! isset(self::$parameters['fid'])) {
            Log::error('FileContent model fid missing');

            return [];
        }

        if (! isset(self::$parameters['did'])) {
            Log::error('FileContent model did missing');

            return [];
        }

        if (! isset(self::$parameters['vid'])) {
            Log::error('FileContent model vid missing');

            return [];
        }

        if (! isset(self::$parameters['cid'])) {
            Log::error('FileContent model cid missing');

            return [];
        }

        $vid = self::$parameters['vid'];
        $did = self::$parameters['did'];
        $fid = self::$parameters['fid'];
        $cid = self::$parameters['cid'];
        $uid = auth()->user()->id;
        $currentUser = auth()->user();

        if (! (isset($vid) && isset($did) && isset($fid) && isset($cid))) {
            return [];
        }

        // Owner / group / admin manage the document (share, lock, annotate).
        // A non-manager who can READ a shared document may ALSO annotate it, but
        // only when it is unlocked and only its note content (title/acetate) —
        // never its share / lock / expiry state, and never to initialise a share
        // where none exists.
        $canManage = VaultAccess::canManage($currentUser, $vid);
        if (! $canManage) {
            if (! VaultAccess::allows($currentUser, $vid, $cid, $did, $fid)) {
                Log::error("FileContent::setRows denied for user {$uid} on vault {$vid} (no access)");

                return [];
            }
            if (VaultAccess::isDocumentLocked($vid, $did, $fid)) {
                return [];
            }
            // Restrict a non-manager to annotation content; drop any share/lock
            // control fields they may have submitted.
            $data = array_intersect_key($data, array_flip(['title', 'acetate']));
            if ($data === []) {
                return [];
            }
        }

        // Only a manager may create or modify the ContentsRequest (the share
        // record / lock / expiry). Non-managers fall straight through to the
        // annotation-content write below.
        if ($canManage) {
            $contents_fields = [
                'shared' => 'status',
                'locked' => 'status',
                'expire' => 'expire',
            ];

            $creq = ContentsRequest::where('vault_id', $vid)
                ->where('dir_id', $did)
                ->where('file_id', $fid)
                ->first();

            if (! $creq) {
                // Unguessable share token: a random capability, NOT a hash of
                // the (enumerable) file ids — so a share URL cannot be derived
                // or probed by anyone who knows the owner/vault/dir/file ids.
                $hash = Str::random(40);
                $url = url("sosShared/{$hash}");

                $creq = ContentsRequest::create([
                    'vault_id' => $vid,
                    'dir_id' => $did,
                    'file_id' => $fid,
                    'case_id' => $cid,
                    'status' => 'VALID',
                    'comments' => '',
                    'url' => $url,
                    'owner' => $uid,
                    'group' => $uid,
                    'perms' => '750',
                ]);
            }

            if ($creq) {
                foreach ($data as $key => $value) {
                    if (in_array($key, array_keys($contents_fields))) {
                        $dkey = $contents_fields[$key];
                        $creq->{$dkey} = $value;
                    }
                }
                $creq->save();
            }
        }

        $annotation_fields = [
            'astatus' => 'status',
            'aexpire' => 'expire',
            'alocked' => 'locked',
            'title' => 'title',
            'acetate' => 'acetate',
        ];

        $annot = Annotation::where('vault_id', $vid)
            ->where('dir_id', $did)
            ->where('file_id', $fid)
            ->first();

        $isNewAnnotation = ! $annot;

        if (! $annot) {
            $annot = Annotation::create([
                'vault_id' => $vid,
                'dir_id' => $did,
                'file_id' => $fid,
                'owner' => $uid,
                'group' => $uid,
                'perms' => '750',
                'status' => 'PRIVATE',
            ]);
        }

        if ($annot) {
            foreach ($data as $key => $value) {
                if (in_array($key, array_keys($annotation_fields))) {
                    $dkey = $annotation_fields[$key];
                    $annot->{$dkey} = $value;
                }
            }
            $annot->save();

            if (isset($data['title']) || isset($data['acetate'])) {
                $notePayload = (object) [
                    'message' => $isNewAnnotation ? 'annotation added' : 'annotation changed',
                    'fid' => $fid,
                ];
                addEvent($notePayload, $isNewAnnotation ? 'ADD_NOTE' : 'CHG_NOTE', 'SUCCESS', 'NORMAL', $cid, $vid, $uid, $uid);
            }
        }

    }

    public function getRows(): array
    {
        $response = new stdClass;

        if (! isset(self::$parameters['fid'])) {
            Log::error('FileContent model fid missing');

            return [];
        }

        if (! isset(self::$parameters['did'])) {
            Log::error('FileContent model did missing');

            return [];
        }

        if (! isset(self::$parameters['vid'])) {
            Log::error('FileContent model vid missing');

            return [];
        }

        if (! isset(self::$parameters['cid'])) {
            Log::error('FileContent model cid missing');

            return [];
        }

        if (! isset(self::$parameters['format'])) {
            Log::error('FileContent model format missing');

            return [];
        }

        $vid = self::$parameters['vid'];
        $did = self::$parameters['did'];
        $fid = self::$parameters['fid'];
        $cid = self::$parameters['cid'];
        $format = self::$parameters['format'];

        $uid = auth()->user()->id;

        if (! (isset($vid) && isset($did) && isset($fid) && isset($cid))) {
            return [];
        }

        $vtools = new VaultTools(resolveVaultUser($vid, $cid, $did, $fid), $vid);
        if (! isset($vtools)) {
            return [];
        }

        if ($vtools->getVaultId() != $vid) {
            return [];
        }

        $vtools->openVault();

        if (! $vtools->isOpen()) {
            return [];
        }

        $offset = session('offset') ?? 0;
        $fileContents = $vtools->getFileContentsById($vid, $did, $fid, $offset, $cid);

        if (! isset($fileContents) || empty($fileContents)) {
            return [];
        }

        $creq = ContentsRequest::where('vault_id', $vid)->where('dir_id', $did)->where('file_id', $fid)->first();

        $annot = Annotation::where('vault_id', $vid)->where('dir_id', $did)->where('file_id', $fid)->first();

        $dtools = new DataTools($vtools, $vid, $did);

        $response->vault_id = (int) $vid;
        $response->dir_id = (int) $did;
        $response->file_id = (int) $fid;
        $response->case_id = (int) $cid;
        $response->user_id = (int) $uid;
        $response->user = auth()->user()->username;
        $response->uname = auth()->user()->name;
        $response->group = auth()->user()->group;
        $response->email = auth()->user()->email;
        $response->mode = auth()->user()->theme_mode;
        $response->avatar = auth()->user()->theme_avatar;
        $response->trial_ends_at = auth()->user()->trial_ends_at;

        // document (content request)
        $response->status = '';
        $response->shared = 0;
        $response->locked = 0;
        $response->expire = '';
        $response->url = '';
        $response->comments = '';
        $response->name = '';
        $response->path = '';
        $response->lines = 0;
        $response->totalLines = 0;
        $response->size = 0;
        $response->date = '';
        $response->time = '';
        $response->chunked = false;
        $response->offset = 0;
        $response->chunkSize = $vtools->chunkSize;
        $response->tooBig = $vtools->tooBig;

        // annotations
        $response->astatus = '';
        $response->aexpire = '';
        $response->alocked = '';
        $response->title = '';
        $response->acetate = '';

        $response->host = '';
        $response->os = '';
        $response->uname = '';
        $response->group = '';
        $response->email = '';
        $response->mode = '';
        $response->avatar = '';
        $response->trial_ends_at = '';
        $response->isLogFile = false;
        $response->isTable = false;
        $response->separator = '';
        $response->has_header = false;
        $response->header_row = -1;
        $response->headers = '';
        $response->columns = 0;
        $response->ini_time = '';
        $response->fin_time = '';
        $response->ini_date = '';
        $response->fin_date = '';
        $response->tz = '';

        if (isset($creq)) {
            $response->status = $creq->status;
            $response->shared = ($creq->status == 'SHARED' || $creq->status == 'LOCKED');
            $response->locked = $creq->status == 'LOCKED'; // locked implies shared
            $response->expire = $creq->expire;
            $response->url = $creq->url;
            $response->comments = $creq->comments;
        }

        if (isset($annot)) {
            $response->astatus = $annot->status;
            $response->aexpire = $annot->expire;
            $response->alocked = $annot->locked;
            $response->title = $annot->title;
            $response->acetate = $annot->acetate;
        }

        if (isset($fileContents)) {
            $response->chunked = boolval($fileContents->chunked);
            $response->name = $fileContents->name;
            $response->lines = $fileContents->lines;
            $response->totalLines = $fileContents->totalLines;
            $response->path = $fileContents->path;
            $response->size = $fileContents->size;
            $response->date = $fileContents->date;
            $response->time = $fileContents->time;

            // file classification
            $response->isLogFile = $fileContents->isLogFile;
            $response->isTable = $fileContents->isTable;
            $response->separator = $fileContents->separator;
            $response->has_header = $fileContents->has_header;
            $response->header_row = $fileContents->header_row;
            $response->headers = $fileContents->headers;
            $response->columns = $fileContents->columns;
            $response->ini_time = $fileContents->ini_time;
            $response->ini_date = $fileContents->ini_date;
            $response->fin_time = $fileContents->fin_time;
            $response->fin_date = $fileContents->fin_date;
            $response->tz = $fileContents->tz;

            if ($format == 'table') {
                $response->records = $fileContents->records;
            }
        }

        $host = $dtools->getHostData();
        if (isset($host)) {
            $response->host = $host->hostname;
            $response->os = $host->{'os version'};
        }

        $filename = basename($response->name);

        if ($response->isLogFile && $format == 'table' && ! empty($response->records)) {
            // this is a log file
            return collect($response->records)->toArray();
        }

        $records = [];
        if ($response->isTable && ! $response->isLogFile) {
            $fileArray = [];

            $separators = [
                'space' => '/ {1,}/',
                "\t" => "/\t+/",
                ';' => '/;+/',
                // "|"    => "/\|+/",
                ',' => '/,+/',
            ];

            $fileArray = explode("\n", $fileContents->contents);

            $headers = $response->headers !== '' ? explode('|', $response->headers) : [];

            if ($response->has_header) {
                // this is because there may have been white lines stripped from the isTable analysis...
                if (isset($response->header_row)) {
                    for ($i = $response->header_row; $i < 10; $i++) {
                        if (! empty($fileArray[$i])) {
                            $response->header_row = $i;
                            break;
                        }
                    }

                    if (isset($response->separator) && isset($fileArray[$response->header_row]) && isset($separators[$response->separator])) {
                        $headerLine = $fileArray[$response->header_row];

                        // Some sos commands (e.g. journalctl --list-boots) emit column
                        // labels that contain a space, so a space-delimited split would
                        // wrongly count them as separate columns. Glue the known
                        // multi-word labels together before splitting.
                        if ($response->separator === 'space') {
                            $headerLine = strtr($headerLine, [
                                'BOOT ID' => 'BOOT_ID',
                                'FIRST ENTRY' => 'FIRST_ENTRY',
                                'LAST ENTRY' => 'LAST_ENTRY',
                            ]);
                        }

                        $headers = preg_split(
                            $separators[$response->separator],
                            $headerLine,
                            $response->columns
                        );
                        $response->headers = implode('|', $headers);
                    }
                }

            } else {
                $headers = [];
                for ($i = 1; $i <= $response->columns; $i++) {
                    $headers[] = "Column{$i}";
                }
                $response->headers = implode('|', $headers);
                $response->has_header = 1;
                $response->header_row = -1;
            }

            if (empty($headers)) {
                for ($i = 1; $i <= $response->columns; $i++) {
                    $headers[] = "Column{$i}";
                }
                $response->headers = implode('|', $headers);
            }

            if ($format == 'table' && ! empty($fileArray)) {
                // convert into table
                foreach ($fileArray as $lineno => $line) {
                    if (empty($line) || ($response->has_header && $lineno == $response->header_row)) {
                        continue;
                    }

                    $fields = preg_split($separators[$response->separator], $line, $response->columns);

                    if (! $response->has_header) {
                        $records[] = $fields;
                    } else {
                        $record = [];
                        $usedKeys = [];
                        foreach ($headers as $i => $header) {
                            $key = preg_replace('/^(\d)/', 'col_$1', preg_replace('/[^a-zA-Z0-9_]/', '_', $header));

                            if ($key === '') {
                                $key = "col_{$i}";
                            }

                            // SQLite column names are case-insensitive: a header named "id"
                            // would collide with Sushi's auto-increment primary key, and any
                            // repeated header would create a duplicate column. Disambiguate.
                            if (strtolower($key) === 'id' || isset($usedKeys[strtolower($key)])) {
                                $key = "{$key}_{$i}";
                            }
                            $usedKeys[strtolower($key)] = true;

                            $record[$key] = $fields[$i] ?? ' ';
                        }

                        if (! empty($record)) {
                            $records[] = $record;
                        }
                    }
                }
            } elseif ($format == 'raw') {
                $records[] = json_decode(json_encode($response), true);
            }
        } elseif ($format == 'raw') {
            $records[] = json_decode(json_encode($response), true);
        }

        return collect($records)->toArray();
    }
}
