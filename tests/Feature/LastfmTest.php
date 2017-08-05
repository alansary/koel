<?php

namespace Tests\Feature;

use App\Events\SongLikeToggled;
use App\Events\SongStartedPlaying;
use App\Http\Controllers\API\LastfmController;
use App\Listeners\LoveTrackOnLastfm;
use App\Listeners\UpdateLastfmNowPlaying;
use App\Models\Interaction;
use App\Models\Song;
use App\Models\User;
use App\Services\Lastfm;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Foundation\Testing\WithoutMiddleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Redirector;
use Mockery as m;
use Tymon\JWTAuth\JWTAuth;

class LastfmTest extends TestCase
{
    use WithoutMiddleware;

    public function testGetSessionKey()
    {
        $client = m::mock(Client::class, [
            'get' => new Response(200, [], file_get_contents(__DIR__.'../../blobs/lastfm/session-key.xml')),
        ]);

        $api = new Lastfm(null, null, $client);

        $this->assertEquals('foo', $api->getSessionKey('bar'));
    }

    public function testSetSessionKey()
    {
        $user = factory(User::class)->create();
        $this->postAsUser('api/lastfm/session-key', ['key' => 'foo'], $user);
        $user = User::find($user->id);
        $this->assertEquals('foo', $user->lastfm_session_key);
    }

    public function testControllerConnect()
    {
        $redirector = m::mock(Redirector::class);
        $redirector->shouldReceive('to')->once();

        $guard = m::mock(Guard::class, ['user' => factory(User::class)->create()]);
        $auth = m::mock(JWTAuth::class, [
            'parseToken' => '',
            'getToken' => '',
        ]);

        (new LastfmController($guard))->connect($redirector, new Lastfm(), $auth);
    }

    public function testControllerCallback()
    {
        $request = m::mock(Request::class);
        $request->token = 'foo';
        $lastfm = m::mock(Lastfm::class, ['getSessionKey' => 'bar']);

        $user = factory(User::class)->create();
        $guard = m::mock(Guard::class, ['user' => $user]);

        (new LastfmController($guard))->callback($request, $lastfm);

        $this->assertEquals('bar', $user->lastfm_session_key);
    }

    public function testControllerDisconnect()
    {
        $user = factory(User::class)->create(['preferences' => ['lastfm_session_key' => 'bar']]);
        $this->deleteAsUser('api/lastfm/disconnect', [], $user);
        $user = User::find($user->id);
        $this->assertNull($user->lastfm_session_key);
    }

    public function testLoveTrack()
    {
        $this->withoutEvents();
        $this->createSampleMediaSet();

        $user = factory(User::class)->create(['preferences' => ['lastfm_session_key' => 'bar']]);

        $interaction = Interaction::create([
            'user_id' => $user->id,
            'song_id' => Song::first()->id,
        ]);

        $lastfm = m::mock(Lastfm::class, ['enabled' => true]);
        $lastfm->shouldReceive('toggleLoveTrack')
            ->with($interaction->song->title, $interaction->song->album->artist->name, 'bar', false);

        (new LoveTrackOnLastfm($lastfm))->handle(new SongLikeToggled($interaction, $user));
    }

    public function testUpdateNowPlaying()
    {
        $this->withoutEvents();
        $this->createSampleMediaSet();

        $user = factory(User::class)->create(['preferences' => ['lastfm_session_key' => 'bar']]);
        $song = Song::first();

        $lastfm = m::mock(Lastfm::class, ['enabled' => true]);
        $lastfm->shouldReceive('updateNowPlaying')
            ->with($song->album->artist->name, $song->title, $song->album->name, $song->length, 'bar');

        (new UpdateLastfmNowPlaying($lastfm))->handle(new SongStartedPlaying($song, $user));
    }
}
