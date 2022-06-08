<?php

/**
 * @package    Grav\Common\Page
 *
 * @copyright  Copyright (c) 2015 - 2022 Trilby Media, LLC. All rights reserved.
 * @license    MIT License; see LICENSE file for details.
 */

namespace Grav\Common\Page\Medium;

use Grav\Common\Config\Config;
use Grav\Common\Debugger;
use Grav\Common\File\CompiledYamlFile;
use Grav\Common\Grav;
use Grav\Common\Language\Language;
use Grav\Common\Media\Interfaces\MediaCollectionInterface;
use Grav\Common\Media\Interfaces\MediaObjectInterface;
use Grav\Common\Media\Interfaces\MediaUploadInterface;
use Grav\Common\Media\MediaIndex;
use Grav\Common\Media\Traits\MediaUploadTrait;
use Grav\Common\Page\Pages;
use Grav\Common\Utils;
use Grav\Framework\Compat\Serializable;
use InvalidArgumentException;
use PHPExif\Reader\Reader;
use RocketTheme\Toolbox\ArrayTraits\Export;
use RocketTheme\Toolbox\ArrayTraits\ExportInterface;
use RocketTheme\Toolbox\ResourceLocator\UniformResourceLocator;
use RuntimeException;
use function count;
use function in_array;
use function is_array;
use function strlen;

/**
 * Class AbstractMedia
 * @package Grav\Common\Page\Medium
 */
abstract class AbstractMedia implements ExportInterface, MediaCollectionInterface, MediaUploadInterface, \Serializable
{
    use Export;
    use MediaUploadTrait;
    use Serializable;

    /** @var string[] */
    static public array $ignore = ['frontmatter.yaml', 'media.json'];

    /** @var string */
    protected const VERSION = '2';

    protected ?string $id = null;
    protected ?string $path = null;
    protected ?string $url = null;
    protected bool $exists = false;
    protected ?array $index = null;
    protected ?array $grouped = null;
    /** @var array<string,array|MediaObjectInterface> */
    protected array $items = [];
    protected ?array $media_order = null;
    protected array $config = [];
    protected array $settings = [];
    /** @var string[] */
    protected array $standard_exif = ['FileSize', 'MimeType', 'height', 'width'];
    protected ?string $indexFolder = null;
    protected ?string $indexFile = 'media.json';
    protected int $indexTimeout = 0;
    protected ?string $timestamp = null;
    /** @var bool Hack to make Iterator work together with unset(). */
    private bool $iteratorUnset = false;

    /**
     * @return string
     */
    public function getId(): string
    {
        return md5($this->getType() . ':' . ($this->id ?? $this->path));
    }

    /**
     * @return string
     */
    abstract public function getType(): string;

    /**
     * @return string
     */
    abstract public function getName(): string;

    /**
     * Return media path.
     *
     * @param string|null $filename
     * @return string|null
     */
    abstract public function getPath(string $filename = null): ?string;

    /**
     * @param string $filename
     * @return string
     */
    abstract public function getUrl(string $filename): string;

    /**
     * @param string $filename
     * @return string|null
     */
    public function getMediaUri(string $filename): ?string
    {
        return null;
    }

    /**
     * @return bool
     */
    public function exists(): bool
    {
        return $this->exists;
    }

    /**
     * @return int
     */
    public function count(): int
    {
        return count($this->items);
    }

    /**
     * @param string $offset
     * @return bool
     */
    public function offsetExists($offset): bool
    {
        return isset($this->items[$offset]);
    }

    /**
     * @param string $offset
     * @return MediaObjectInterface|null
     */
    public function offsetGet($offset): ?MediaObjectInterface
    {
        $instance = $this->items[$offset] ?? null;
        if ($instance && !$instance instanceof MediaObjectInterface) {
            // Initialize media object.
            $this->items[$offset] = $instance = $this->initMedium($offset);
        }

        return $instance ? (clone $instance)->setTimestamp($this->timestamp) : null;
    }

    /**
     * @param string|null $offset
     * @param MediaObjectInterface $value
     * @return void
     */
    public function offsetSet($offset, $value): void
    {
        if (!$value instanceof MediaObjectInterface) {
            throw new InvalidArgumentException('Parameter $value needs to be instance of MediaObjectInterface');
        }

        if (null === $offset) {
            $this->items[$value->filename] = $value;
        } else {
            $this->items[$offset] = $value;
        }
    }

    /**
     * @param string $offset
     * @return void
     */
    public function offsetUnset($offset): void
    {
        // Hack to make Iterator trait work together with unset.
        if ((string)$offset === (string)key($this->items)) {
            $this->iteratorUnset = true;
        }

        unset($this->items[$offset]);
    }

    /**
     * @return MediaObjectInterface|null
     */
    public function current(): ?MediaObjectInterface
    {
        $instance = current($this->items);
        if (false !== $instance && !$instance instanceof MediaObjectInterface) {
            // Initialize media object.
            $key = $this->key();
            $instance = $this->initMedium($key);

            $this->items[$key] = $instance;
        }

        return $instance ? (clone $instance)->setTimestamp($this->timestamp) : null;
    }

    /**
     * @return string|null
     */
    public function key(): ?string
    {
        $key = key($this->items);

        return $key !== null ? (string)$key : null;
    }

    /**
     * @return void
     */
    public function next(): void
    {
        if ($this->iteratorUnset) {
            // If current item was unset, position is already in the next element (do nothing).
            $this->iteratorUnset = false;
        } else {
            next($this->items);
        }
    }

    /**
     * @return void
     */
    public function rewind(): void
    {
        $this->iteratorUnset = false;
        reset($this->items);
    }

    /**
     * @return bool
     */
    public function valid(): bool
    {
        return key($this->items) !== null;
    }

    /**
     * Set file modification timestamps (query params) for all the media files.
     *
     * @param string|int|null $timestamp
     * @return $this
     */
    public function setTimestamps($timestamp = null)
    {
        $this->timestamp = null !== $timestamp ? (string)$timestamp : null;

        return $this;
    }

    /**
     * Get a list of all media.
     *
     * @return array<string,MediaObjectInterface>
     */
    public function all(): array
    {
        // Reorder.
        $this->items = $this->orderMedia($this->items);

        $list = [];
        /**
         * @var string $filename
         * @var MediaObjectInterface $file
         */
        foreach ($this as $filename => $file) {
            $instance = clone $file;
            $list[$filename] = $instance->setTimestamp($this->timestamp);
        }

        return $list;
    }

    /**
     * Get a list of all image media.
     *
     * @return array<string,MediaObjectInterface>
     */
    public function images(): array
    {
        $list = [];
        foreach ($this->all() as $filename => $file) {
            if ($file->type === 'image') {
                $list[$filename] = (clone $file)->setTimestamp($this->timestamp);
            }
        }

        return $list;
    }

    /**
     * Get a list of all video media.
     *
     * @return array<string,MediaObjectInterface>
     */
    public function videos(): array
    {
        $list = [];
        foreach ($this->all() as $filename => $file) {
            if ($file->type === 'video') {
                $list[$filename] = (clone $file)->setTimestamp($this->timestamp);
            }
        }

        return $list;
    }

    /**
     * Get a list of all audio media.
     *
     * @return array<string,MediaObjectInterface>
     */
    public function audios(): array
    {
        $list = [];
        foreach ($this->all() as $filename => $file) {
            if ($file->type === 'audio') {
                $list[$filename] = (clone $file)->setTimestamp($this->timestamp);
            }
        }

        return $list;
    }

    /**
     * Get a list of all file media.
     *
     * @return array<string,MediaObjectInterface>
     */
    public function files(): array
    {
        $list = [];
        foreach ($this->all() as $filename => $file) {
            if (!in_array($file->type, ['image', 'video', 'audio'])) {
                $list[$filename] = (clone $file)->setTimestamp($this->timestamp);
            }
        }

        return $list;
    }

    /**
     * Get medium by filename.
     *
     * @param string $filename
     * @return MediaObjectInterface|null
     */
    public function get(string $filename): ?MediaObjectInterface
    {
        return $this->offsetGet($filename);
    }

    /**
     * @param string $name
     * @param MediaObjectInterface|null $file
     * @return void
     */
    public function add(string $name, ?MediaObjectInterface $file): void
    {
        if (null === $file) {
            return;
        }

        $this->offsetSet($name, $file);
    }

    /**
     * @param string $name
     * @return void
     */
    public function hide(string $name): void
    {
        $this->offsetUnset($name);
    }

    /**
     * @param array|null $files
     * @return void
     */
    public function updateIndex(array $files = null): void
    {
        $mediaIndex = $this->getIndex();
        if (!$mediaIndex) {
            return;
        }

        $mediaIndex->lock();

        $id = $this->getId();
        [$index,] = $mediaIndex->get($id, true);

        if ($files === null) {
            $files = $index['files'] ?? [];
            $timestamp = 0;
        } else {
            // Add new files and remove the old ones.
            $files += $index['files'] ?? [];
            $files = array_filter($files, static function($val) { return $val !== null; } );
            $timestamp = time();
        }

        $index = $this->generateIndex($files, null, $timestamp);

        $mediaIndex->save($id, $index);
    }

    /**
     * Create Medium from a file.
     *
     * @param  string $filename
     * @param  array  $params
     * @return Medium|null
     */
    abstract public function createFromFile(string $filename, array $params = []): ?MediaObjectInterface;

    /**
     * Create a new ImageMedium by scaling another ImageMedium object.
     *
     * @param  MediaObjectInterface $medium
     * @param  int $from
     * @param  int $to
     * @return MediaObjectInterface|null
     */
    public function scaledFromMedium(MediaObjectInterface $medium, int $from, int $to = 1): ?MediaObjectInterface
    {
        $result = MediumFactory::scaledFromMedium($medium, $from, $to);

        return is_array($result) ? $result['file'] : $result;
    }

    /**
     * Create Medium from array of parameters
     *
     * @param  array          $items
     * @return Medium|null
     */
    abstract public function createFromArray(array $items = []): ?MediaObjectInterface;

    /**
     * @return array
     */
    public function __serialize(): array
    {
        return [
            'version' => static::VERSION,
            'index' => $this->index ?? [],
            'grouped' => $this->grouped,
            'path' => $this->path,
            'url' => $this->url,
            'exists' => $this->exists,
            'media_order' => $this->media_order,
            'standard_exif' => $this->standard_exif,
            'indexFolder' => $this->indexFolder,
            'indexFile' => $this->indexFile,
            'indexTimeout' => $this->indexTimeout
        ];
    }

    /**
     * @param array $data
     * @return void
     */
    public function __unserialize(array $data): void
    {
        $version = $data['version'] ?? null;
        if ($version !== static::VERSION) {
            throw new RuntimeException('Cannot unserialize: version mismatch');
        }

        $this->index = $data['index'];
        $this->grouped = $data['grouped'];
        $this->path = $data['path'];
        $this->url = $data['url'];
        $this->exists = $data['exists'];
        $this->media_order = $data['media_order'];
        $this->standard_exif = $data['standard_exif'];
        $this->indexFolder = $data['indexFolder'];
        $this->indexFile = $data['indexFile'];
        $this->indexTimeout = $data['indexTimeout'];

        // Initialize items.
        $this->items = $this->grouped;
    }

    /**
     * @param string $filename
     * @param array|null $info
     * @return string
     * @throws RuntimeException
     */
    abstract public function readFile(string $filename, array $info = null): string;

    /**
     * @param string $filename
     * @param array|null $info
     * @return resource
     * @throws RuntimeException
     */
    abstract public function readStream(string $filename, array $info = null);

    /**
     * Order the media based on the page's media_order
     *
     * @param array $media
     * @return array<string,MediaObjectInterface>
     */
    protected function orderMedia(array $media): array
    {
        if (null === $this->media_order) {
            $path = $this->getPath();
            if (null !== $path) {
                /** @var Pages $pages */
                $pages = Grav::instance()['pages'];
                $page = $pages->get($path);
                if ($page && isset($page->header()->media_order)) {
                    $this->media_order = array_map('trim', explode(',', $page->header()->media_order));
                }
            }
        }

        if (!empty($this->media_order) && is_array($this->media_order)) {
            $media = Utils::sortArrayByArray($media, $this->media_order);
        } else {
            ksort($media, SORT_NATURAL | SORT_FLAG_CASE);
        }

        return $media;
    }

    /**
     * @param string $filename
     * @return bool
     */
    abstract protected function fileExists(string $filename): bool;

    /**
     * @param string $filename
     * @param array|null $info
     * @return array
     */
    abstract protected function readImageSize(string $filename, array $info = null): array;

    /**
     * @param string $filename
     * @param array|null $info
     * @return array
     */
    protected function readVectorSize(string $filename, array $info = null): array
    {
        // Make sure that getting image size is supported.
        if (\extension_loaded('simplexml')) {
            $data = $this->readFile($filename, $info);
            $xml = @simplexml_load_string($data);
            $attr = $xml ? $xml->attributes() : null;
            if ($attr instanceof \SimpleXMLElement) {
                // Get the size from svg image.
                if ($attr->width && $attr->height) {
                    $width = (string)$attr->width;
                    $height = (string)$attr->height;
                } elseif ($attr->viewBox && 4 === count($size = explode(' ', (string)$attr->viewBox))) {
                    [,$width,$height,] = $size;
                }

                if (isset($width, $height)) {
                    return ['width' => (int)$width, 'height' => (int)$height, 'mime' => 'image/svg+xml'];
                }
            }

            throw new RuntimeException(sprintf('Cannot read image size from %s', $filename));
        }

        return [];
    }

    /**
     * Load file listing from the filesystem.
     *
     * @return array
     */
    abstract protected function loadFileInfo(): array;

    /**
     * Prepare file information for media.
     *
     * Removes all non-media files and adds some additional metadata.
     *
     * @param iterable $files
     * @param array $media_types
     * @param array|null $cached
     * @return array
     */
    protected function prepareFileInfo(iterable $files, array $media_types, ?array $cached): array
    {
        $lookup = [];
        if ($cached) {
            foreach ($cached as $filename => $info) {
                $name = $info['name'] ?? null;
                if (!$name) {
                    $name = Utils::basename($info['meta']['remote_url'] ?? '');
                }
                if ($name && $name !== $filename) {
                    $info['filename'] = $filename;
                    $lookup[$name] = $info;
                }
            }
        }

        $list = [];
        foreach ($files as $filename => $info) {
            // Ignore markdown, frontmatter and dot files. Also ignore all files which are not listed in media types.
            $extension = Utils::pathinfo($filename, PATHINFO_EXTENSION);
            $params = $media_types[strtolower($extension)] ?? [];
            if (!$params || $extension === 'md' || str_starts_with($filename, '.') || in_array($filename, static::$ignore, true)) {
                continue;
            }

            $info['mime'] = null;
            if (null !== $cached) {
                try {
                    $type = $params['type'] ?? 'file';
                    $existing = $lookup[$filename] ?? $cached[$filename] ?? null;
                    if (isset($existing['filename'])) {
                        $filename = $existing['filename'];
                        unset($existing['filename']);
                    }
                    if ($existing && $existing['size'] === $info['size'] && (!isset($existing['modified']) || $existing['modified'] === $info['modified'])) {
                        // Append cached data.
                        $info += $existing;
                    } else if ($type === 'image') {
                        $info += $this->readImageSize($filename, $info + ($info['meta'] ?? []));
                    } elseif ($type === 'vector') {
                        $info += $this->readVectorSize($filename, $info + ($info['meta'] ?? []));
                    }
                } catch (RuntimeException $e) {
                    /** @var Debugger $debugger */
                    $debugger = Grav::instance()['debugger'];
                    $debugger->addMessage(sprintf('Could not initialize %s for index: %s', $filename, $e->getMessage()), 'warning');

                    // TODO: Maybe we want to handle this..?
                }
            }
            if (!isset($info['mime'])) {
                $info['mime'] = $params['mime'];
            }

            // Add basic file info to metadata.
            $meta = $info['meta'] ?? [];
            unset($info['meta']);
            $info['meta'] = $meta + $info;

            $list[$filename] = $info;
        }

        ksort($list, SORT_NATURAL);

        return $list;
    }

    /**
     * @param string $filename
     * @param array|null $info
     * @return void
     */
    protected function addMediaDefaults(string $filename, ?array &$info): void
    {
        if (null === $info) {
            return;
        }

        $pathInfo = Utils::pathinfo($filename);
        $info['basename'] = $pathInfo['filename'];
        $info['filename'] = $pathInfo['basename'];
        if (!isset($info['path'])) {
            $info['path'] = $pathInfo['dirname'] === '.' ? $this->getPath() : $pathInfo['dirname'];
        }
        unset($pathInfo['dirname'], $pathInfo['basename']);
        $info += $pathInfo;

        $config = $this->getConfig();
        $ext = $info['extension'] ?? '';
        $media_params = $ext ? $config->get('media.types.' . strtolower($ext)) : null;
        if (!is_array($media_params)) {
            $info = null;

            return;
        }

        if (!isset($info['filepath'])) {
            $info['filepath'] = $info['path'] . '/' . $info['filename'];
        }

        // Remove empty 'image' attribute
        if (isset($media_params['image']) && empty($media_params['image'])) {
            unset($media_params['image']);
        }

        // Add default settings for undefined variables.
        $info += $media_params + (array)$config->get('media.types.defaults');
        $info += [
            'thumb' => 'media/thumb.png',
            'path' => $this->getPath(),
            'thumbnails' => []
        ];

        $locator = $this->getLocator();
        $file = $locator->findResource("image://{$info['thumb']}");
        if ($file) {
            $info['thumbnails']['default'] = $file;
        }
    }

    /**
     * Initialize class.
     *
     * @return void
     */
    protected function init(): void
    {
        // Handle special cases where the object doesn't exist in filesystem.
        if (!$this->exists()) {
            return;
        }

        $config = $this->getConfig();

        // Get file media listing. Use cached version if possible to avoid I/O.
        if (null === $this->index) {
            $now = time();
            [$data, $timestamp] = $this->loadIndex();
            $timeout = $this->indexTimeout;
            if (!$timestamp || ($timeout && $timestamp < $now - $timeout)) {
                $media_types = $config->get('media.types');
                $files = $this->prepareFileInfo($this->loadFileInfo(), $media_types, $data['files'] ?? []);
                $oldChecksum = $data['checksum'] ?? null;
                $newChecksum = md5(serialize($files));
                if ($oldChecksum !== $newChecksum) {
                    $this->saveIndex($files, $newChecksum, $now);
                } else {
                    $this->touchIndex($now);
                }
            } else {
                $files = $data['files'] ?? [];
            }

            $this->index = $files;
        }

        // Group images by base name.
        $media = [];
        foreach ($this->index as $filename => $info) {
            $info = ['filename' => $filename] + $info;
            // Find out what type we're dealing with
            [$basename, $extension, $type, $extra] = $this->getFileParts($filename);

            if ($this->url) {
                $info['url'] = "{$this->url}/{$filename}";
            }
            $filename = "{$basename}.{$extension}";
            if ($type === 'alternative') {
                $media[$filename][$type][$extra] = $info;
            } elseif (isset($media[$filename][$type])) {
                $media[$filename][$type] += $info;
            } else {
                $media[$filename][$type] = $info;
            }
        }

        $media = $this->orderMedia($media);

        $this->grouped = $media;
        $this->items = $media;
    }

    /**
     * @param string $name
     * @return MediaObjectInterface|null
     */
    protected function initMedium(string $name): ?MediaObjectInterface
    {
        $types = $this->grouped[$name];

        // Prepare the alternatives in case there is no base medium.
        if (!empty($types['alternative'])) {
            /**
             * @var string|int $ratio
             * @var array $alt
             */
            foreach ($types['alternative'] as $ratio => &$alt) {
                $alt['file'] = $this->createFromFile($alt['filename']);
                if (empty($alt['file'])) {
                    unset($types['alternative'][$ratio]);
                }
            }
            unset($alt);
        }

        // Create the base medium.
        $file_path = null;
        if (empty($types['base'])) {
            if (!isset($types['alternative'])) {
                /** @var Debugger $debugger */
                $debugger = Grav::instance()['debugger'];
                $debugger->addMessage(sprintf('Could not create base media %s', $name), 'warning');

                return null;
            }

            $max = max(array_keys($types['alternative']));
            $medium = $types['alternative'][$max]['file'];
            $file_path = $medium->path();
            $medium = $this->scaledFromMedium($medium, $max);
        } else {
            $medium = $this->createFromFile($types['base']['filename']);
            if ($medium) {
                $medium->set('size', $types['base']['size']);
                $file_path = $medium->path();
            }
        }

        if (!$medium) {
            /** @var Debugger $debugger */
            $debugger = Grav::instance()['debugger'];
            $debugger->addMessage(sprintf('Could not initialize %s', $name), 'warning');

            return null;
        }

        $meta_path = $file_path ? ($types['meta']['filename'] ?? $file_path . '.meta.yaml') : null;
        if ($meta_path && !file_exists($meta_path) && $exifReader = $this->getExifReader()) {
            try {
                $meta = $exifReader->read($file_path);
                $meta_data = $meta->getData();
                $meta_trimmed = array_diff_key($meta_data, array_flip($this->standard_exif));
                if ($meta_trimmed) {
                    $locator = $this->getLocator();
                    if ($locator->isStream($meta_path)) {
                        $file = CompiledYamlFile::instance($locator->findResource($meta_path, true, true));
                    } else {
                        $file = CompiledYamlFile::instance($meta_path);
                    }
                    $file->save($meta_trimmed);
                    $types['meta']['filename'] = $meta_path;
                }
            } catch (RuntimeException $e) {
                /** @var Debugger $debugger */
                $debugger = Grav::instance()['debugger'];
                $debugger->addMessage(sprintf('Could not create image meta for %s: %s', $name, $e->getMessage()), 'warning');
            }
        }

        if (!empty($types['meta']['filename'])) {
            $medium->addMetaFile($this->getPath($types['meta']['filename']));
        }

        if (!empty($types['thumb']['filename'])) {
            // We will not turn it into medium yet because user might never request the thumbnail
            // not wasting any resources on that, maybe we should do this for medium in general?
            $medium->set('thumbnails.page', $this->getPath($types['thumb']['filename']));
        }

        // Build missing alternatives.
        if (!empty($types['alternative'])) {
            $alternatives = $types['alternative'];
            $max = max(array_keys($alternatives));

            for ($i=$max; $i > 1; $i--) {
                if (!isset($alternatives[$i])) {
                    $scaled = $this->scaledFromMedium($alternatives[$max]['file'], $max, $i);
                    if ($scaled) {
                        $types['alternative'][$i] = $scaled;
                    } else {
                        /** @var Debugger $debugger */
                        $debugger = Grav::instance()['debugger'];
                        $debugger->addMessage(sprintf('Could not create alternative image for %s', $medium->filename), 'warning');
                    }
                }
            }

            foreach ($types['alternative'] as $altMedium) {
                if ($altMedium['file'] !== $medium) {
                    $altWidth = $altMedium['file']->get('width');
                    $medWidth = $medium->get('width');
                    if ($altWidth && $medWidth) {
                        $ratio = $altWidth / $medWidth;
                        $medium->addAlternative($ratio, $altMedium['file']);
                    }
                }
            }
        }

        return $medium;
    }

    /**
     * @param array $files
     * @param string|null $checksum
     * @param int|null $timestamp
     * @return array
     */
    protected function generateIndex(array $files, string $checksum = null, ?int $timestamp = null): array
    {
        // Clear files array.
        ksort($files, SORT_NATURAL);
        foreach ($files as $filename => &$info) {
            $meta = $info['meta'] ?? [];
            if ($meta) {
                $metaName = $meta['name'] ?? null;
                if ($metaName === $filename) {
                    unset($meta['name']);
                }
                unset($info['meta']);
                $info['meta'] = array_diff_assoc($meta, $info);
            }
        }
        unset($info);

        return [
            'type' => $this->getType(),
            'name' => $this->getName(),
            'version' => static::VERSION,
            'checksum' => $checksum ?? md5(serialize($files)),
            'timestamp' => $timestamp ?? time(),
            'folder' => $this->path,
            'url' => $this->url,
            'files' => $files,
        ];
    }

    /**
     * Get index file, which stores media file index.
     *
     * @return MediaIndex|null
     */
    protected function getIndex(): ?MediaIndex
    {
        if (null === $this->indexFolder || null === $this->indexFile) {
            return null;
        }

        $indexFolder = $this->indexFolder;
        if (!str_starts_with($indexFolder, '/') && !str_contains($indexFolder, '://')) {
            $indexFolder = GRAV_WEBROOT . '/' . $indexFolder;
        }

        return MediaIndex::getInstance($indexFolder . '/' . $this->indexFile);
    }

    /**
     * @return array
     */
    protected function loadIndex(): array
    {
        // Read media index file.
        $mediaIndex = $this->getIndex();
        if (!$mediaIndex || !$this->exists) {
            return [[], 0];
        }

        $id = $this->getId();
        [$index, $modified] = $mediaIndex->get($id);

        $index['files'] = $index['files'] ?? [];

        $version = $index['version'] ?? null;
        $folder = $index['folder'] ?? null;
        $type = $index['type'] ?? null;
        if ($version !== static::VERSION || $folder !== $this->path || $type !== ($this->config['type'] ?? 'local')) {
            return [$index, 0];
        }

        /*
        $name = $index['name'] ?? null;
        if ($version !== static::VERSION || $folder !== $this->path || $type !== ($this->config['type'] ?? 'local') || $name !== ($this->config['name'] ?? null)) {
            return [[], 0];
        }
        */
        return [$index, $modified];
    }

    /**
     * @param int|null $timestamp
     * @return void
     */
    protected function touchIndex(?int $timestamp = null): void
    {
        $mediaIndex = $this->getIndex();
        if (!$mediaIndex || !$this->exists) {
            return;
        }
        $id = $this->getId();

        $mediaIndex->touch($id, $timestamp);
    }

    /**
     * @param array $files
     * @param string|null $checksum
     * @param int|null $timestamp
     * @return void
     */
    protected function saveIndex(array $files, string $checksum = null, ?int $timestamp = null): void
    {
        $mediaIndex = $this->getIndex();
        if (!$mediaIndex || !$this->exists) {
            return;
        }

        $id = $this->getId();
        $index = $this->generateIndex($files, $checksum, $timestamp);

        $mediaIndex->save($id, $index);
    }

    /**
     * @param string $filename
     * @return string
     */
    protected function getBasename(string $filename): string
    {
        [$base, $ext,,] = $this->getFileParts($filename);

        return "{$base}.{$ext}";
    }

    /**
     * Get filename, extension and meta part.
     *
     * @param  string $filename
     * @return array
     */
    protected function getFileParts(string $filename): array
    {
        $type = null;
        $extra = null;
        if (str_ends_with($filename, $ext = '.meta.yaml')) {
            $type = 'meta';
            $filename = substr($filename, 0, -strlen($ext));
        } elseif (preg_match('/^(.*)\.thumb\.(.*)$/Uu', $filename, $matches)) {
            $type = 'thumb';
            [, $filename, $extra] = $matches;
        }

        $parts = explode('.', $filename);
        $extension = count($parts) > 1 ? array_pop($parts) : null;
        $name = implode('.', $parts);

        if (!$type && $extension) {
            if (preg_match('/^(.*)@(\d+)x$/Uu', $name, $matches)) {
                $type = 'alternative';
                [, $name, $extra] = $matches;
            } else {
                $type = 'base';
            }
        }

        return [$name, $extension, $type, $extra];
    }

    /**
     * @return Grav
     */
    protected function getGrav(): Grav
    {
        return Grav::instance();
    }

    /**
     * @return Debugger
     */
    protected function getDebugger(): Debugger
    {
        return $this->getGrav()['debugger'];
    }

    /**
     * @return UniformResourceLocator
     */
    protected function getLocator(): UniformResourceLocator
    {
        return $this->getGrav()['locator'];
    }

    /**
     * @return Config
     */
    protected function getConfig(): Config
    {
        return $this->getGrav()['config'];
    }

    /**
     * @return Language
     */
    protected function getLanguage(): Language
    {
        return $this->getGrav()['language'];
    }

    /**
     * @return Reader|null
     */
    protected function getExifReader(): ?Reader
    {
        $grav = $this->getGrav();
        $config = $this->getConfig();
        $exifEnabled = !empty($config->get('system.media.auto_metadata_exif'));

        return $exifEnabled && isset($grav['exif']) ? $grav['exif']->getReader() : null;
    }

    /**
     * @return void
     */
    protected function clearCache(): void
    {
        $this->getLocator()->clearCache();
    }

    /**
     * Call object as function to get medium by filename.
     *
     * @param string $filename
     * @return MediaObjectInterface|null
     * @deprecated 1.8 Use $media[$filename] instead
     */
    public function __invoke(string $filename): ?MediaObjectInterface
    {
        user_error(__METHOD__ . '() is deprecated since Grav 1.8, use $media[$filename] instead', E_USER_DEPRECATED);

        return $this->offsetGet($filename);
    }

    /**
     * @return string|null
     * @deprecated 1.6 Use $this->getPath() instead.
     */
    public function path(): ?string
    {
        return $this->getPath();
    }
}
