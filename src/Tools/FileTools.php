<?php

namespace Rekhu\LaravelDev\Tools;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Rekhu\LaravelDev\Support\Platform;
use Rekhu\LaravelDev\Support\Workspace;
use RuntimeException;
use ZipArchive;

/**
 * The account's library inside the app: the templates, notes and pictures kept in the Rekhu account, and the files
 * of this app's project. "add" brings one into the app: a template is unpacked in storage/app/rekhu/templates/<name>
 * (to read from while building Blade views) and its stylesheets, scripts, images and fonts are copied to
 * public/templates/<name>, where the views can link them. Both count as the run's files, so undo removes them.
 */
class FileTools
{
    private const ASSETS = ['css', 'js', 'mjs', 'map', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'avif', 'svg', 'ico', 'woff', 'woff2', 'ttf', 'otf', 'eot', 'mp4', 'webm', 'json'];

    public static function files(array $input, array $meta): array
    {
        $action = $input['action'] ?? 'list';
        $list = Platform::client(30)->get('/v1/templates')->throw()->json('templates') ?? [];
        if ($action === 'list') {
            return ['files' => array_map(fn ($t) => array_filter(['id' => $t['id'], 'name' => $t['name'], 'role' => $t['role'] ?? null, 'kind' => $t['kind'] ?? null, 'pages' => $t['pages'] ?: null, 'project' => ! empty($t['project_id']), 'url' => $t['url'] ?? null]), $list)];
        }
        if ($action !== 'add') {
            throw new RuntimeException('action is list or add');
        }
        $file = collect($list)->first(fn ($t) => $t['id'] === ($input['file'] ?? '') || $t['name'] === ($input['file'] ?? ''));
        if (! $file) {
            throw new RuntimeException('no file "'.($input['file'] ?? '').'" in the account; list them first');
        }
        Workspace::begin($meta['run']);
        $response = Platform::client(120)->get('/v1/templates/'.$file['id'].'/download')->throw();
        $slug = Str::slug(pathinfo($file['name'], PATHINFO_FILENAME)) ?: 'template';
        $source = storage_path('app/rekhu/templates/'.$slug);
        $public = 'public/templates/'.$slug;
        File::deleteDirectory($source);
        File::ensureDirectoryExists($source);

        if (($file['kind'] ?? '') === 'zip') {
            $zipPath = $source.'.zip';
            file_put_contents($zipPath, $response->body());
            $zip = new ZipArchive;
            if ($zip->open($zipPath) !== true) {
                throw new RuntimeException('the template is not a readable zip');
            }
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                // no paths outside the folder, no macOS leftovers
                if (str_contains($name, '..') || str_starts_with($name, '/') || str_contains($name, '__MACOSX') || str_ends_with($name, '/')) {
                    continue;
                }
                File::ensureDirectoryExists(dirname($source.'/'.$name));
                file_put_contents($source.'/'.$name, $zip->getFromIndex($i));
            }
            $zip->close();
            unlink($zipPath);
        } else {
            file_put_contents($source.'/'.$file['name'], $response->body());
        }

        // a zip with one top folder: work from inside it
        $entries = array_values(array_diff(scandir($source) ?: [], ['.', '..']));
        $root = count($entries) === 1 && is_dir($source.'/'.$entries[0]) ? $source.'/'.$entries[0] : $source;

        $pages = [];
        $assets = 0;
        foreach (File::allFiles($root) as $f) {
            $relative = ltrim(substr($f->getPathname(), strlen($root)), '/');
            $ext = strtolower($f->getExtension());
            if (in_array($ext, ['html', 'htm'], true)) {
                $pages[] = ltrim(substr($f->getPathname(), strlen(base_path())), '/');
            } elseif (in_array($ext, self::ASSETS, true)) {
                $target = $public.'/'.$relative;
                Workspace::noteCreated($meta['run'], $target);
                File::ensureDirectoryExists(dirname(base_path($target)));
                copy($f->getPathname(), base_path($target));
                $assets++;
            }
        }

        return [
            'file' => $file['name'],
            'pages' => $pages,
            'read_from' => ltrim(substr($root, strlen(base_path())), '/'),
            'assets' => $assets ? $public.' ('.$assets.' files; link them with asset(\'templates/'.$slug.'/…\'))' : 'none',
            'next' => 'Read the pages with code-read, then build the views with code-write.',
        ];
    }
}
