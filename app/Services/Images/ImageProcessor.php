<?php

namespace App\Services\Images;

use App\Models\PinImage;
use App\Models\PinTemplate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Drivers\Imagick\Driver as ImagickDriver;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageInterface;
use Throwable;

/**
 * Validates, stores and renders Pin images. Uses Imagick when available, GD otherwise.
 * Originals are kept so the user can re-crop without quality loss.
 */
class ImageProcessor
{
    private ImageManager $manager;

    public function __construct()
    {
        $this->manager = new ImageManager(extension_loaded('imagick') ? new ImagickDriver : new GdDriver);
    }

    /**
     * Validates raw image bytes. Returns [mime, width, height].
     *
     * @return array{0:string,1:int,2:int}
     */
    public function inspect(string $bytes): array
    {
        $maxBytes = (int) config('pinbot.fetch.max_image_bytes');
        if ($bytes === '' || strlen($bytes) > $maxBytes) {
            throw new ImageProcessingException('The image is empty or larger than '.round($maxBytes / 1048576).' MB.');
        }

        // Trust the actual bytes, not the server's Content-Type header.
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) ?: '';
        if (! in_array($mime, config('pinbot.images.allowed_mimes'), true)) {
            throw new ImageProcessingException("Unsupported image format ({$mime}). Use JPEG, PNG, WebP or GIF.");
        }

        $size = @getimagesizefromstring($bytes);
        if (! $size) {
            throw new ImageProcessingException('The image file is corrupt or unreadable.');
        }
        [$width, $height] = $size;

        if ($width < config('pinbot.images.min_width') || $height < config('pinbot.images.min_height')) {
            throw new ImageProcessingException("The image is too small ({$width}×{$height}). Minimum is ".config('pinbot.images.min_width').'×'.config('pinbot.images.min_height').'.');
        }
        if ($width * $height > 40_000_000) {
            throw new ImageProcessingException('The image dimensions are too large to process.');
        }

        return [$mime, $width, $height];
    }

    /** Stores the original bytes and returns the storage path. */
    public function storeOriginal(string $bytes, int $userId, int $pinId, string $mime): string
    {
        $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'][$mime] ?? 'img';
        $path = "pins/{$userId}/{$pinId}/original-".Str::uuid().".{$ext}";
        Storage::disk($this->disk())->put($path, $bytes);

        return $path;
    }

    /**
     * Renders the Pinterest-ready 2:3 image from the original using the stored crop.
     * Crop is {x,y,width,height} as fractions (0..1) of the original. Null = centred 2:3 crop.
     */
    public function render(PinImage $image, ?PinTemplate $template = null, ?string $overlayText = null): PinImage
    {
        $disk = Storage::disk($this->disk());
        if (! $image->original_path || ! $disk->exists($image->original_path)) {
            throw new ImageProcessingException('The original image file is missing.');
        }

        try {
            $img = $this->manager->read($disk->get($image->original_path));
            $this->applyCrop($img, $image->crop);

            // Never upscale: keep the source's native quality when it is smaller than the target.
            $targetW = min((int) config('pinbot.images.output_width'), $img->width());
            $targetH = (int) round($targetW * 1.5);
            $img->cover($targetW, $targetH);

            if ($template?->text_overlay && $overlayText) {
                $this->overlay($img, $template, $overlayText);
            }

            $encoded = (string) $img->toJpeg((int) config('pinbot.images.jpeg_quality'));
        } catch (ImageProcessingException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new ImageProcessingException('The image could not be processed: '.$e->getMessage(), 0, $e);
        }

        $old = $image->path;
        $path = preg_replace('#/original-[^/]+$#', '/pin-'.Str::uuid().'.jpg', $image->original_path);
        $disk->put($path, $encoded);
        if ($old && $old !== $path) {
            $disk->delete($old);
        }

        $image->fill([
            'path' => $path,
            'mime' => 'image/jpeg',
            'width' => $targetW,
            'height' => $targetH,
            'size_bytes' => strlen($encoded),
            'status' => 'ready',
            'error_message' => null,
        ])->save();

        return $image;
    }

    private function applyCrop(ImageInterface $img, ?array $crop): void
    {
        $w = $img->width();
        $h = $img->height();

        if ($crop && isset($crop['x'], $crop['y'], $crop['width'], $crop['height'])) {
            $cx = (int) round(max(0, min(1, $crop['x'])) * $w);
            $cy = (int) round(max(0, min(1, $crop['y'])) * $h);
            $cw = (int) round(max(0.05, min(1, $crop['width'])) * $w);
            $ch = (int) round(max(0.05, min(1, $crop['height'])) * $h);
            $img->crop(min($cw, $w - $cx), min($ch, $h - $cy), $cx, $cy);
        }
        // Remaining aspect correction happens in cover().
    }

    private function overlay(ImageInterface $img, PinTemplate $template, string $text): void
    {
        $font = $this->fontPath();
        if (! $font) {
            return; // No TTF font available on this server; skip overlay rather than render unreadable text.
        }

        $w = $img->width();
        $h = $img->height();
        $fontSize = (int) round($template->font_size * ($w / 1000));
        $boxH = (int) round($h * 0.24);
        $boxY = $template->overlay_position === 'top' ? 0 : $h - $boxH;
        $alpha = max(0, min(100, $template->background_opacity)) / 100;
        [$r, $g, $b] = sscanf(ltrim($template->background_color, '#'), '%02x%02x%02x');

        $img->drawRectangle(0, $boxY, function ($rect) use ($w, $boxH, $r, $g, $b, $alpha) {
            $rect->size($w, $boxH);
            $rect->background("rgba({$r}, {$g}, {$b}, {$alpha})");
        });

        $img->text(Str::limit($text, 90), (int) ($w / 2), $boxY + (int) ($boxH / 2), function ($f) use ($font, $fontSize, $template, $w) {
            $f->filename($font);
            $f->size($fontSize);
            $f->color($template->text_color);
            $f->align('center');
            $f->valign('middle');
            $f->lineHeight(1.3);
            $f->wrap((int) ($w * 0.88));
        });
    }

    public function fontPath(): ?string
    {
        $candidates = array_filter([
            config('pinbot.images.overlay_font'),
            '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
            '/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf',
            'C:/Windows/Fonts/arialbd.ttf',
            '/System/Library/Fonts/Supplemental/Arial Bold.ttf',
        ]);
        foreach ($candidates as $path) {
            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    public function disk(): string
    {
        return config('pinbot.images.disk');
    }
}
