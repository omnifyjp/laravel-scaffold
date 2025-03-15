<?php

namespace FammSupport\Models;

use FammSupport\Models\Traits\UseQuery;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * @property string $generation_type
 * @property string $base_collection
 * @property mixed $disk
 * @property mixed $path
 * @property mixed $name
 * @property mixed|null $expired_at
 */
class FileUpload extends Model
{
    use SoftDeletes;
    use UseQuery;

    protected $primaryKey = 'id';

    protected $table = '_files';

    protected $fillable = [
        'uid',
        'name',
        'mime',
        'disk',
        'path',
        'updated_at',
        'expired_at'
    ];

    protected $hidden = [
        'id',
        'disk',
        'path',
        'morph_type',
        'morph_id',
        'deleted_at',
    ];

    protected $appends = [
        'url',
    ];

    public function getUrlAttribute(): string
    {
        if ($this->disk == 'local') {
            return route('download', [
                'key' => encrypt([
                    'id' => $this->id,
                    'expired_at' => now()->addMinutes(60)->timestamp,
                ]),
            ]);
        } elseif ($this->disk == 'public') {
            $url = Storage::disk($this->disk)->url($this->path);
            return str_starts_with($url, 'http') ? $url : url($url);
        } elseif ($this->disk == 's3') {
            return Storage::disk($this->disk)->temporaryUrl($this->path, now()->addMinutes(10));
        }

        return '';
    }

    public static function createFile($path, $filename = null, $disk = 'local')
    {
        if (File::exists($path)) {
            if (!$filename) {
                $filename = basename($path);
            }
            $save_path = 'uploads/' . now()->format('Ymd') . '/' . uniqid() . '.' . md5($filename) . '.' . Str::lower(File::extension($filename ?? $path));
            Storage::disk($disk)->putFileAs($path, $save_path);

            return static::query()->create([
                'uid' => Str::orderedUuid(),
                'name' => $filename,
                'mime' => Storage::disk($disk)->mimeType($save_path),
                'disk' => $disk,
                'path' => $save_path,
                'expired_at' => now()->addMinutes(1440),
            ]);
        }

        return false;
    }

    public function morph(): MorphTo
    {
        return $this->morphTo('morph', 'morph_type', 'morph_id');
    }

    public function getBody(): ?string
    {
        return Storage::disk($this->disk)->get($this->path);
    }

    public function getPath(): string
    {
        return Storage::disk($this->disk)->path($this->path);
    }
}
