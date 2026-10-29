<?php
declare(strict_types=1);
$root = rtrim(getenv('FLARUM_ROOT') ?: '', '/');
if (!str_starts_with($root, '/tmp/')) throw new RuntimeException('Disposable lab required');
chdir($root); require $root.'/vendor/autoload.php'; $app = (require $root.'/site.php')->bootApp();
$db = $app->getContainer()->make(\Illuminate\Database\ConnectionInterface::class);
if (!str_starts_with($db->getDatabaseName(), 'fr_f2_acceptance_')) throw new RuntimeException('Unexpected database');
$fixture = json_decode(file_get_contents(getenv('F2_AUTH_FILE')), true, 512, JSON_THROW_ON_ERROR);
$member = \Flarum\User\User::findOrFail($fixture['users']['member']['id']);
$peer = \Flarum\User\User::findOrFail($fixture['users']['peer']['id']);
$settings = $app->getContainer()->make(\Flarum\Settings\SettingsRepositoryInterface::class);
$field = \FoF\Masquerade\Field::findOrFail($fixture['brandField']);
$before = ['required' => $field->required, 'force' => $settings->get('masquerade.force-profile-completion')];
$checks = 0;
$check = static function (bool $ok, string $label) use (&$checks): void { if (!$ok) throw new RuntimeException('FAIL '.$label); $checks++; echo 'PASS '.$label."\n"; };
try {
    $field->required = true; $field->save(); $settings->set('masquerade.force-profile-completion', '1');
    $middleware = $app->getContainer()->make(\FoF\Masquerade\Middleware\DemandProfileCompletion::class);
    $handler = new class implements \Psr\Http\Server\RequestHandlerInterface {
        public function handle(\Psr\Http\Message\ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface { return new \Laminas\Diactoros\Response\EmptyResponse(204); }
    };
    $request = static fn ($actor, string $path, string $route = '') => \Flarum\Http\RequestUtil::withActor((new \Laminas\Diactoros\ServerRequest())->withUri(new \Laminas\Diactoros\Uri($fixture['baseUrl'].$path))->withAttribute('routeName', $route), $actor);
    $check(!\FoF\Masquerade\Field::allRequiredCompletedFor($peer->id), 'missing required profile answer detected');
    $r = $middleware->process($request($peer, '/'), $handler);
    $check($r->getStatusCode() === 302 && str_ends_with($r->getHeaderLine('Location'), '/u/'.$peer->username.'/masquerade'), 'incomplete member redirected to own profile form');
    $check($middleware->process($request($peer, '/u/'.$peer->username.'/masquerade'), $handler)->getStatusCode() === 204, 'completion form does not redirect into a loop');
    $check($middleware->process($request($peer, '/logout', 'logout'), $handler)->getStatusCode() === 204, 'incomplete member may log out');
    $check($middleware->process($request(new \Flarum\User\Guest(), '/'), $handler)->getStatusCode() === 204, 'guest is not sent to a member completion form');
    $check(\FoF\Masquerade\Field::allRequiredCompletedFor($member->id) && $middleware->process($request($member, '/'), $handler)->getStatusCode() === 204, 'completed existing answer allows normal member navigation');
    $r = $app->getContainer()->make(\Flarum\Api\Client::class)->withActor($member)->withBody([(string) $field->id => ''])->post('/masquerade-answers/configure/'.$member->id);
    $check($r->getStatusCode() === 422, 'required profile field rejects blank answer through actual API');
    $check($db->table('fof_masquerade_answers')->where('field_id', $field->id)->where('user_id', $member->id)->value('content') === 'Chevrolet', 'failed required-field save preserves existing answer');
    echo "F2_MASQUERADE_COMPLETION=PASS CHECKS={$checks}\n";
} finally {
    $field->required = $before['required']; $field->save();
    if ($before['force'] === null) $settings->delete('masquerade.force-profile-completion'); else $settings->set('masquerade.force-profile-completion', $before['force']);
}
