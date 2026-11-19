<?php

namespace App\Application\StatusPages;

use App\Infrastructure\Persistence\Eloquent\Environment;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Stores a status page logo. The upload is decoded with GD and re-encoded as a
 * fresh PNG (scaled to fit MAX_WIDTH x MAX_HEIGHT), so the bytes served publicly
 * are never the uploaded bytes. Files live on the private local disk and are
 * served by the public status controller, so no storage symlink is needed.
 */
final class StatusPageLogoService
{
    public const MAX_WIDTH = 800;

    public const MAX_HEIGHT = 200;

    private const DIRECTORY = 'status-logos';

    public function __construct(private readonly StatusPageService $pages)
    {
    }

    /**
     * Root-relative, cache-busting URL for a stored logo (the path embeds a content hash).
     */
    public static function publicUrl(string $slug, ?string $path): ?string
    {
        return $path === null ? null : '/status/'.$slug.'/logo?v='.substr(md5($path), 0, 8);
    }

    public function store(Environment $environment, UploadedFile $file): object
    {
        $page = $this->requirePage($environment);
        $png = $this->reencode((string) file_get_contents($file->getRealPath()));
        $path = sprintf('%s/%d-%s.png', self::DIRECTORY, $page->id, substr(hash('sha256', $png), 0, 16));

        Storage::disk('local')->put($path, $png);
        $this->replacePath($page, $path);

        return $this->pages->find($environment);
    }

    public function remove(Environment $environment): object
    {
        $page = $this->requirePage($environment);
        $this->replacePath($page, null);

        return $this->pages->find($environment);
    }

    private function replacePath(object $page, ?string $path): void
    {
        $old = $page->logo_path;
        DB::table('status_pages')->where('id', $page->id)->update(['logo_path' => $path, 'updated_at' => now()]);
        if ($old !== null && $old !== $path) {
            Storage::disk('local')->delete($old);
        }
        $this->pages->rerender((int) $page->id);
    }

    private function reencode(string $bytes): string
    {
        $source = @imagecreatefromstring($bytes);
        if ($source === false) {
            throw ValidationException::withMessages(['logo' => [__('validation.image', ['attribute' => 'logo'])]]);
        }

        $width = imagesx($source);
        $height = imagesy($source);
        $scale = min(1, self::MAX_WIDTH / $width, self::MAX_HEIGHT / $height);
        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));

        $target = imagecreatetruecolor($targetWidth, $targetHeight);
        imagealphablending($target, false);
        imagesavealpha($target, true);
        imagefill($target, 0, 0, imagecolorallocatealpha($target, 0, 0, 0, 127));
        imagecopyresampled($target, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

        ob_start();
        imagepng($target, null, 9);
        $png = (string) ob_get_clean();
        imagedestroy($source);
        imagedestroy($target);

        return $png;
    }

    private function requirePage(Environment $environment): object
    {
        $page = $this->pages->find($environment);
        abort_if($page === null, 404);

        return $page;
    }
}
