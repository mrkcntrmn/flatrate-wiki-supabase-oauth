<?php

declare(strict_types=1);

$root = rtrim(getenv('FLARUM_ROOT') ?: '', '/');
$authFile = getenv('F2_AUTH_FILE') ?: '';
if (! str_starts_with($root, '/tmp/') || ! str_starts_with($authFile, '/tmp/')) throw new RuntimeException('Disposable fixture required');
chdir($root); require_once $root.'/vendor/autoload.php';
$site = require $root.'/site.php'; $app = $site->bootApp();
$db = $app->getContainer()->make(\Illuminate\Database\ConnectionInterface::class);
if (! str_starts_with($db->getDatabaseName(), 'fr_f2_acceptance_')) throw new RuntimeException('Unexpected database');
$fixture = json_decode(file_get_contents($authFile), true, 512, JSON_THROW_ON_ERROR);
$admin = \Flarum\User\User::findOrFail($fixture['users']['admin']['id']);
$peer = \Flarum\User\User::findOrFail($fixture['users']['peer']['id']);
$settings = $app->getContainer()->make(\Flarum\Settings\SettingsRepositoryInterface::class);
$settings->set('fof-upload.mimeTypes', json_encode(['^image/png$' => ['adapter' => 'local', 'template' => 'image-preview']]));
// Give readers download permission so denials must come from the private owner
// boundary, rather than a coarse missing permission. This is a test DB only.
foreach ([2, 3] as $group) $db->table('group_permission')->updateOrInsert(['group_id' => $group, 'permission' => 'fof-upload.download'], []);
$checks = 0;
$check = static function (bool $ok, string $name) use (&$checks): void { if (!$ok) throw new RuntimeException('FAIL '.$name); $checks++; echo 'PASS '.$name."\n"; };
$image = imagecreatetruecolor(8, 8); ob_start(); imagepng($image); $bytes = ob_get_clean(); imagedestroy($image);
$stream = new \Laminas\Diactoros\Stream('php://temp', 'wb+'); $stream->write($bytes); $stream->rewind();
$upload = new \Laminas\Diactoros\UploadedFile($stream, strlen($bytes), UPLOAD_ERR_OK, 'qualification-image.png', 'image/png');
$request = \Flarum\Http\RequestUtil::withActor((new \Laminas\Diactoros\ServerRequest())->withParsedBody(['options' => ['hidden' => true, 'shared' => true]])->withUploadedFiles(['files' => [$upload]]), $admin);
$response = $app->getContainer()->make(\FoF\Upload\Api\Handlers\UploadHandler::class)->handle($request);
$check($response->getStatusCode() === 200 || $response->getStatusCode() === 201, 'real FoF multipart handler accepts private shared PNG');
$payload = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
$file = \FoF\Upload\File::findOrFail($payload['data'][0]['id']);
$check($file->upload_method === 'private-shared', 'private upload storage method');
$check((int) $file->actor_id === (int) $admin->id, 'Media Privacy persists actual uploader ownership');
$check(!str_contains((string) $file->path, 'qualification-image'), 'opaque upload basename omits original filename');
$handler = $app->getContainer()->make(\FoF\Upload\Commands\DownloadHandler::class);
$result = $handler->handle(new \FoF\Upload\Commands\Download($file->uuid, $admin));
$check($result->getStatusCode() === 200, 'owner downloads through actual FoF DownloadHandler');
$stored = $app->getContainer()->make(\Illuminate\Contracts\Filesystem\Factory::class)->disk('private-shared')->get($file->path);
$downloaded = (string) $result->getBody();
$check(hash('sha256', $downloaded) === hash('sha256', $stored), 'downloaded bytes match stored upload after image normalization');
$decoded = imagecreatefromstring($downloaded);
$check($decoded !== false && imagesx($decoded) === 8 && imagesy($decoded) === 8 && (imagecolorat($decoded, 0, 0) & 0xffffff) === 0, 'normalized image retains original dimensions and pixels');
$check($result->getHeaderLine('Cache-Control') === 'private, no-store', 'private no-store download response');
foreach ([$peer, new \Flarum\User\Guest()] as $actor) {
    $denied = false;
    try { $handler->handle(new \FoF\Upload\Commands\Download($file->uuid, $actor)); }
    catch (\Flarum\User\Exception\PermissionDeniedException $e) { $denied = true; }
    $check($denied, 'private bytes denied to '.($actor->isGuest() ? 'guest' : 'non-owner member'));
}
$check(!is_file($root.'/public/assets/files/'.$file->path), 'private bytes are outside public asset storage');
$check(!is_file($root.'/public/storage/'.$file->path), 'private bytes are outside public storage alias');
echo "F2_UPLOAD_PRIVACY=PASS CHECKS={$checks} STORAGE=REAL_LOCAL_PRIVATE_SHARED R2=NOT_RETESTED\n";
