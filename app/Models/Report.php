<?php

namespace App\Models;

use App\Filament\Forms\Components\RichEditor\RichContentCustomBlocks\ActivityReportBlock;
use App\Filament\Forms\Components\RichEditor\RichContentCustomBlocks\AnnotationsBlock;
use App\Filament\Forms\Components\RichEditor\RichContentCustomBlocks\CpuBlock;
use App\Filament\Forms\Components\RichEditor\RichContentCustomBlocks\DiskBlock;
use App\Filament\Forms\Components\RichEditor\RichContentCustomBlocks\HostBlock;
use App\Filament\Forms\Components\RichEditor\RichContentCustomBlocks\MemBlock;
use App\Filament\Forms\Components\RichEditor\RichContentCustomBlocks\ProcBlock;
use App\Filament\Forms\Components\RichEditor\RichContentCustomBlocks\TcpSocketsBlock;
use App\Filament\Forms\Components\RichEditor\RichContentCustomBlocks\UnixSocketsBlock;
use Filament\Forms\Components\RichEditor\Models\Concerns\InteractsWithRichContent;
use Filament\Forms\Components\RichEditor\Models\Contracts\HasRichContent;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Report extends Model implements HasRichContent
{
    use HasFactory, InteractsWithRichContent;

    protected $table = 'reports';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'case_id',
        'vault_id',
        'dir_id',
        'name',
        'title',
        'excerpt',
        'document',
        'image',
        'description',
        'keywords',
        'type',
        'status',
        'is_public',
        'updated_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'updated_at' => 'datetime',
        'document' => 'array',
    ];

    public function case()
    {
        return $this->belongsTo(SupportCase::class, 'case_id');
    }

    public function user()
    {
        return $this->belongsTo(config('auth.providers.users.model'));
    }

    public function hiddenByUsers()
    {
        return $this->belongsToMany(User::class, 'user_hidden_reports');
    }

    public function setUpRichContent(): void
    {
        $this->registerRichContent('document')
            ->customBlocks([
                HostBlock::class => [
                    'vid' => $this->vault_id,
                    'did' => $this->dir_id,
                    'cid' => $this->case_id,
                    'type' => 'host',
                    'indx' => 0,
                ],
                CpuBlock::class => [
                    'vid' => $this->vault_id,
                    'did' => $this->dir_id,
                    'cid' => $this->case_id,
                    'type' => 'cpu',
                    'indx' => 0,
                ],
                MemBlock::class => [
                    'vid' => $this->vault_id,
                    'did' => $this->dir_id,
                    'cid' => $this->case_id,
                    'type' => 'memory',
                    'indx' => 0,
                ],
                DiskBlock::class => [
                    'vid' => $this->vault_id,
                    'did' => $this->dir_id,
                    'cid' => $this->case_id,
                    'type' => 'disk',
                    'indx' => 0,
                ],
                ProcBlock::class => [
                    'vid' => $this->vault_id,
                    'did' => $this->dir_id,
                    'cid' => $this->case_id,
                    'type' => 'procs',
                    'indx' => 0,
                ],
                TcpSocketsBlock::class => [
                    'vid' => $this->vault_id,
                    'did' => $this->dir_id,
                    'cid' => $this->case_id,
                    'type' => 'conn',
                    'indx' => 0,
                ],
                UnixSocketsBlock::class => [
                    'vid' => $this->vault_id,
                    'did' => $this->dir_id,
                    'cid' => $this->case_id,
                    'type' => 'conn',
                    'indx' => 1,
                ],
                ActivityReportBlock::class => [
                    'vid' => $this->vault_id,
                    'cid' => $this->case_id,
                ],
                AnnotationsBlock::class => [
                    'vid' => $this->vault_id,
                    'cid' => $this->case_id,
                ],
            ])
            ->mergeTags([
                'Name' => fn (): string => $this->user->name,
                'Today' => now()->toFormattedDateString(),
                'Title' => fn (): string => $this->title ?? '',
                'Description' => fn (): string => $this->description ?? '',
                'Type' => fn (): string => $this->type ?? '',
                'Status' => fn (): string => $this->status ?? '',
                'Case_Id' => fn (): string => $this->case->case ?? '',
                'Case_Date' => fn (): string => $this->case->created_at ?? '',
                'Case_Description' => fn (): string => $this->case->description ?? '',
                'case_Root_Cause' => fn (): string => $this->case->root_cause ?? '',
                'case_Recommendation' => fn (): string => $this->case->recommendation ?? '',
                'OS_Version' => fn (): string => $this->case->os_version ?? '',
                'sos_Version' => fn (): string => $this->case->sos_version ?? '',
            ]);
    }
}
