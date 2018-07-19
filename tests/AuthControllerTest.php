<?php

use App\Events;
use App\Models\User;
use App\Mail\ForgotPassword;
use App\Services\Facades\Option;
use Illuminate\Support\Facades\Mail;
use Illuminate\Foundation\Testing\WithoutMiddleware;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\DatabaseTransactions;

class AuthControllerTest extends TestCase
{
    use DatabaseTransactions;

    public function testLogin()
    {
        $this->get('/auth/login')->assertSee('Log in');
    }

    public function testHandleLogin()
    {
        $this->expectsEvents(Events\UserTryToLogin::class);
        $this->expectsEvents(Events\UserLoggedIn::class);

        $user = factory(User::class)->create();
        $user->changePassword('12345678');
        $player = factory(App\Models\Player::class)->create(
            [
                'uid' => $user->uid
            ]
        );

        // Should return a warning if `identification` is empty
        $this->postJson(
            '/auth/login', [], [
            'X-Requested-With' => 'XMLHttpRequest'
        ])->assertJson([
            'errno' => 1,
            'msg' => trans('validation.required', ['attribute' => trans('auth.identification')])
        ]);

        // Should return a warning if `password` is empty
        $this->postJson(
            '/auth/login', [
            'identification' => $user->email
        ], [
            'X-Requested-With' => 'XMLHttpRequest'
        ])->assertJson([
            'errno' => 1,
            'msg' => trans('validation.required', ['attribute' => 'password'])
        ]);

        // Should return a warning if length of `password` is lower than 6
        $this->postJson(
            '/auth/login', [
            'identification' => $user->email,
            'password' => '123'
        ], [
            'X-Requested-With' => 'XMLHttpRequest'
        ])->assertJson([
            'errno' => 1,
            'msg' => trans('validation.min.string', ['attribute' => 'password', 'min' => 6])
        ]);

        // Should return a warning if length of `password` is greater than 32
        $this->postJson(
            '/auth/login', [
            'identification' => $user->email,
            'password' => str_random(80)
        ], [
            'X-Requested-With' => 'XMLHttpRequest'
        ])->assertJson([
            'errno' => 1,
            'msg' => trans('validation.max.string', ['attribute' => 'password', 'max' => 32])
        ]);

        $this->flushSession();

        // Logging in should be failed if password is wrong
        $this->postJson(
            '/auth/login', [
            'identification' => $user->email,
            'password' => 'wrong-password'
        ])->assertJson(
            [
                'errno' => 1,
                'msg' => trans('auth.validation.password'),
                'login_fails' => 1
            ]
        )->assertSessionHas('login_fails', 1);

        $this->flushSession();

        // Should check captcha if there are too many fails
        $this->withSession(
            [
                'login_fails' => 4,
                'phrase' => 'a'
            ]
        )->postJson(
            '/auth/login', [
            'identification' => $user->email,
            'password' => '12345678',
            'captcha' => 'b'
        ])->assertJson([
            'errno' => 1,
            'msg' => trans('auth.validation.captcha')
        ]);

        $this->flushSession();

        // Should return a warning if user isn't existed
        $this->postJson(
            '/auth/login', [
            'identification' => 'nope@nope.net',
            'password' => '12345678'
        ])->assertJson([
            'errno' => 2,
            'msg' => trans('auth.validation.user')
        ]);

        $this->flushSession();

        // Should clean the `login_fails` session if logged in successfully
        $this->withSession(['login_fails' => 1])->postJson('/auth/login', [
            'identification' => $user->email,
            'password' => '12345678'
        ])->assertJson(
            [
                'errno' => 0,
                'msg' => trans('auth.login.success'),
                'token' => $user->getToken()
            ]
        )->assertSessionMissing('login_fails');

        $this->flushSession();

        // Logged in should be in success if logged in with player name
        $this->postJson(
            '/auth/login', [
            'identification' => $player->player_name,
            'password' => '12345678'
        ]
        )->assertJson(
            [
                'errno' => 0,
                'msg' => trans('auth.login.success'),
                'token' => $user->getToken()
            ]
        )->assertCookie('uid', $user->uid)
            ->assertCookie('token', $user->getToken())
            ->assertSessionHasAll(
                [
                    'uid' => $user->uid,
                    'token' => $user->getToken()
                ]
            );
    }

    public function testLogout()
    {
        $user = factory(User::class)->create();

        $this->withSession(
            [
                'uid' => $user->uid,
                'token' => $user->getToken()
            ]
        )->postJson('/auth/logout')->assertJson(
            [
                'errno' => 0,
                'msg' => trans('auth.logout.success')
            ]
        )->assertSessionMissing(['uid', 'token']);

        $this->flushSession();
        $this->postJson('/auth/logout')
            ->assertJson([
                'errno' => 1,
                'msg' => trans('auth.logout.fail')
            ]);
    }

    public function testRegister()
    {
        $this->get('/auth/register')->assertSee('Register');

        option(['user_can_register' => false]);
        $this->get('/auth/register')->assertSee(trans('auth.register.close'));
    }

    public function testHandleRegister()
    {
        $this->expectsEvents(Events\UserRegistered::class);

        // Should return a warning if `captcha` is wrong
        $this->withSession(['phrase' => 'a'])
            ->postJson(
                '/auth/register', [], [
                'X-Requested-With' => 'XMLHttpRequest'
            ])->assertJson([
                'errno' => 1,
                'msg' => trans('auth.validation.captcha')
            ]);

        // Once we have sent session in the last assertion,
        // we don't need to send it again until we flush it.
        // Should return a warning if `email` is empty
        $this->postJson(
            '/auth/register',
            ['captcha' => 'a'],
            ['X-Requested-With' => 'XMLHttpRequest']
        )->assertJson([
            'errno' => 1,
            'msg' => trans('validation.required', ['attribute' => 'email'])
        ]);

        // Should return a warning if `email` is invalid
        $this->postJson(
            '/auth/register',
            [
                'email' => 'not_an_email',
                'captcha' => 'a'
            ],
            ['X-Requested-With' => 'XMLHttpRequest']
        )->assertJson([
            'errno' => 1,
            'msg' => trans('validation.email', ['attribute' => 'email'])
        ]);

        // Should return a warning if `password` is empty
        $this->postJson(
            '/auth/register',
            [
                'email' => 'a@b.c',
                'captcha' => 'a'
            ],
            ['X-Requested-With' => 'XMLHttpRequest']
        )->assertJson([
            'errno' => 1,
            'msg' => trans('validation.required', ['attribute' => 'password'])
        ]);

        // Should return a warning if length of `password` is lower than 8
        $this->postJson(
            '/auth/register',
            [
                'email' => 'a@b.c',
                'password' => '1',
                'captcha' => 'a'
            ],
            ['X-Requested-With' => 'XMLHttpRequest']
        )->assertJson([
            'errno' => 1,
            'msg' => trans('validation.min.string', ['attribute' => 'password', 'min' => 8])
        ]);

        // Should return a warning if length of `password` is greater than 32
        $this->postJson(
            '/auth/register',
            [
                'email' => 'a@b.c',
                'password' => str_random(33),
                'captcha' => 'a'
            ],
            ['X-Requested-With' => 'XMLHttpRequest']
        )->assertJson([
            'errno' => 1,
            'msg' => trans('validation.max.string', ['attribute' => 'password', 'max' => 32])
        ]);

        // Should return a warning if `nickname` is empty
        $this->postJson(
            '/auth/register',
            [
                'email' => 'a@b.c',
                'password' => '12345678',
                'captcha' => 'a'
            ],
            ['X-Requested-With' => 'XMLHttpRequest']
        )->assertJson([
            'errno' => 1,
            'msg' => trans('validation.required', ['attribute' => 'nickname'])
        ]);

        // Should return a warning if `nickname` is invalid
        $this->postJson(
            '/auth/register',
            [
                'email' => 'a@b.c',
                'password' => '12345678',
                'nickname' => '\\',
                'captcha' => 'a'
            ],
            ['X-Requested-With' => 'XMLHttpRequest']
        )->assertJson([
            'errno' => 1,
            'msg' => trans('validation.no_special_chars', ['attribute' => 'nickname'])
        ]);

        // Should return a warning if `nickname` is too long
        $this->postJson(
            '/auth/register',
            [
                'email' => 'a@b.c',
                'password' => '12345678',
                'nickname' => str_random(256),
                'captcha' => 'a'
            ],
            ['X-Requested-With' => 'XMLHttpRequest']
        )->assertJson([
            'errno' => 1,
            'msg' => trans('validation.max.string', ['attribute' => 'nickname', 'max' => 255])
        ]);

        // Should be forbidden if registering is closed
        Option::set('user_can_register', false);
        $this->postJson(
            '/auth/register',
            [
                'email' => 'a@b.c',
                'password' => '12345678',
                'nickname' => 'nickname',
                'captcha' => 'a'
            ],
            ['X-Requested-With' => 'XMLHttpRequest']
        )->assertJson([
            'errno' => 7,
            'msg' => trans('auth.register.close')
        ]);

        // Reopen for test
        Option::set('user_can_register', true);

        // Should be forbidden if registering's count current IP is over
        Option::set('regs_per_ip', -1);
        $this->postJson(
            '/auth/register',
            [
                'email' => 'a@b.c',
                'password' => '12345678',
                'nickname' => 'nickname',
                'captcha' => 'a'
            ]
        )->assertJson([
            'errno' => 7,
            'msg' => trans('auth.register.max', ['regs' => option('regs_per_ip')])
        ]);

        Option::set('regs_per_ip', 100);

        // Should return a warning if using a duplicated email
        $existedUser = factory(User::class)->create();
        $this->postJson(
            '/auth/register',
            [
                'email' => $existedUser->email,
                'password' => '12345678',
                'nickname' => 'nickname',
                'captcha' => 'a'
            ]
        )->assertJson([
            'errno' => 5,
            'msg' => trans('auth.register.registered')
        ]);

        // Database should be updated if succeeded
        $response = $this->postJson(
            '/auth/register',
            [
                'email' => 'a@b.c',
                'password' => '12345678',
                'nickname' => 'nickname',
                'captcha' => 'a'
            ]
        );
        $newUser = User::where('email', 'a@b.c')->first();
        $response->assertJson([
            'errno' => 0,
            'msg' => trans('auth.register.success'),
            'token' => $newUser->getToken()
        ])->assertCookie('uid', $newUser->uid)
            ->assertCookie('token', $newUser->getToken());
        $this->assertTrue($newUser->verifyPassword('12345678'));
        $this->assertDatabaseHas('users', [
            'email' => 'a@b.c',
            'nickname' => 'nickname',
            'score' => option('user_initial_score'),
            'ip' => '127.0.0.1',
            'permission' => User::NORMAL
        ]);
    }

    public function testForgot()
    {
        $this->get('/auth/forgot')->assertSee('Forgot Password');

        config(['mail.driver' => '']);
        $this->get('/auth/forgot')->assertSee(trans('auth.forgot.close'));
    }

    public function testHandleForgot()
    {
        Mail::fake();

        // Should return a warning if `captcha` is wrong
        $this->withSession(['phrase' => 'a'])->postJson('/auth/forgot', [
            'captcha' => 'b'
        ])->assertJson([
            'errno' => 1,
            'msg' => trans('auth.validation.captcha')
        ]);

        // Should be forbidden if "forgot password" is closed
        config(['mail.driver' => '']);
        $this->withSession(['phrase' => 'a'])->postJson('/auth/forgot', [
            'captcha' => 'a'
        ])->assertJson([
            'errno' => 1,
            'msg' => trans('auth.forgot.close')
        ]);
        config(['mail.driver' => 'smtp']);

        // Should be forbidden if sending email frequently
        $this->withSession(['last_mail_time' => time()])->postJson('/auth/forgot', [
            'captcha' => 'a'
        ])->assertJson([
            'errno' => 1,
            'msg' => trans('auth.forgot.frequent-mail')
        ]);

        // Should return a warning if user is not existed
        $this->flushSession();
        $user = factory(User::class)->create();
        $this->withSession(['phrase' => 'a'])->postJson('/auth/forgot', [
            'email' => 'nope@nope.net',
            'captcha' => 'a'
        ])->assertJson([
            'errno' => 1,
            'msg' => trans('auth.forgot.unregistered')
        ]);

        $this->postJson('/auth/forgot', [
            'email' => $user->email,
            'captcha' => 'a'
        ])->assertJson([
            'errno' => 0,
            'msg' => trans('auth.mail.success')
        ])->assertSessionHas('last_mail_time');
        Mail::assertSent(ForgotPassword::class, function ($mail) use ($user) {
            return $mail->hasTo($user->email);
        });

        // Should handle exception when sending email
        Mail::shouldReceive('to')
            ->once()
            ->andThrow(new \Mockery\Exception('A fake exception.'));
        $this->flushSession();
        $this->withSession(['phrase' => 'a'])
            ->postJson('/auth/forgot', [
                'email' => $user->email,
                'captcha' => 'a'
            ])->assertJson([
                'errno' => 2,
                'msg' => trans('auth.mail.failed', ['msg' => 'A fake exception.'])
            ]);
    }

    public function testReset()
    {
        $user = factory(User::class)->create();

        $this->get(
            URL::temporarySignedRoute('auth.reset', now()->addHour(), ['uid' => $user->uid])
        )->assertSuccessful();
    }

    public function testHandleReset()
    {
        $user = factory(User::class)->create();
        $url = URL::temporarySignedRoute('auth.reset', now()->addHour(), ['uid' => $user->uid]);

        // Should return a warning if `password` is empty
        $this->postJson(
            $url, [], [
            'X-Requested-With' => 'XMLHttpRequest'
        ])->assertJson([
            'errno' => 1,
            'msg' => trans('validation.required', ['attribute' => 'password'])
        ]);

        // Should return a warning if `password` is too short
        $this->postJson(
            $url, [
            'password' => '123'
        ], [
            'X-Requested-With' => 'XMLHttpRequest'
        ])->assertJson([
            'errno' => 1,
            'msg' => trans('validation.min.string', ['attribute' => 'password', 'min' => 8])
        ]);

        // Should return a warning if `password` is too long
        $this->postJson(
            $url, [
            'password' => str_random(33)
        ], [
            'X-Requested-With' => 'XMLHttpRequest'
        ])->assertJson([
            'errno' => 1,
            'msg' => trans('validation.max.string', ['attribute' => 'password', 'max' => 32])
        ]);

        // Success
        $this->postJson(
            $url, [
            'password' => '12345678',
        ])->assertJson([
            'errno' => 0,
            'msg' => trans('auth.reset.success')
        ]);
        // We must re-query the user model,
        // because the old instance hasn't been changed
        // after resetting password.
        $user = User::find($user->uid);
        $this->assertTrue($user->verifyPassword('12345678'));
    }

    public function testCaptcha()
    {
        if (!function_exists('imagettfbbox') || getenv('TRAVIS_PHP_VERSION' == '5.5')) {
            $this->markTestSkipped('There are some problems with PHP 5.5 on Travis CI');
        }

        $this->get('/auth/captcha')
            ->assertSuccessful()
            ->assertHeader('Content-Type', 'image/png')
            ->assertSessionHas('phrase');
    }
}
