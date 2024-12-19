<?php

namespace FammSupport\Models;

use FammSupport\Models\Traits\UseQuery;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

/**
 * @property string $generation_type
 * @property string $base_collection
 * @property mixed $disk
 * @property mixed $path
 * @property mixed $name
 */
class FileUpload extends Model
{
    use SoftDeletes;
    use UseQuery;

    protected $primaryKey = 'id';

    protected $table = '_files';

    protected $fillable = [
        'name',
        'mime',
        'disk',
        'path',
        'updated_at',
    ];

    protected $hidden = [
        'base_collection',
        'disk',
        'path',
        'morph_type',
        'morph_id',
        //        'created_at',
        //        'updated_at',
        'datasource_id',
        'deleted_at',
    ];

    protected $appends = [
        'download_url',
    ];

    public function getDownloadUrlAttribute()
    {
        if ($this->disk == 'local') {
            $path = Storage::disk($this->disk)->path($this->path);

            return route('download', [
                'key' => encrypt([
                    'id' => $this->id,
                    'expired_at' => now()->addMinutes(60)->timestamp,
                ]),
            ]);
        }

        return '';
    }

    public static function createFile($path, $filename = null, $disk = 'local')
    {
        if (File::exists($path)) {
            if (! $filename) {
                $filename = basename($path);
            }
            $save_path = 'uploads/'.now()->format('Ymd').'/'.uniqid().'.'.md5($filename).'.'.File::extension($path);
            $file = Storage::disk($disk)->putFileAs($path, $save_path);

            return static::query()->create([
                'name' => $filename,
                'mime' => Storage::disk($disk)->mimeType($save_path),
                'disk' => $disk,
                'path' => $save_path,
            ]);
        }

        return false;
    }

    public function morph()
    {
        return $this->morphTo('morph', 'morph_type', 'morph_id');
    }

    public function getBody()
    {
        return Storage::disk($this->disk)->get($this->path);
    }

    public function getPath()
    {
        return Storage::disk($this->disk)->path($this->path);
    }
}
