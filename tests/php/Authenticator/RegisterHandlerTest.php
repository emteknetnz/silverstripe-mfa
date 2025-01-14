<?php

namespace SilverStripe\MFA\Tests\Authenticator;

use PHPUnit\Framework\MockObject\MockObject;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\FunctionalTest;
use SilverStripe\MFA\Authenticator\MemberAuthenticator;
use SilverStripe\MFA\Extension\MemberExtension;
use SilverStripe\MFA\Method\Handler\RegisterHandlerInterface;
use SilverStripe\MFA\Method\MethodInterface;
use SilverStripe\MFA\Service\MethodRegistry;
use SilverStripe\MFA\State\Result;
use SilverStripe\MFA\Store\SessionStore;
use SilverStripe\MFA\Tests\Stub\BasicMath\Method;
use SilverStripe\Security\Member;
use SilverStripe\Security\Security;
use SilverStripe\SecurityExtensions\Service\SudoModeServiceInterface;
use SilverStripe\Security\SecurityToken;

/**
 * Class RegisterHandlerTest
 *
 * @package SilverStripe\MFA\Tests\Authenticator
 */
class RegisterHandlerTest extends FunctionalTest
{
    private const URL = 'Security/login/default/mfa/register/basic-math/';

    protected static $fixture_file = 'RegisterHandlerTest.yml';

    protected function setUp(): void
    {
        parent::setUp();
        Config::modify()->set(MethodRegistry::class, 'methods', [Method::class]);

        Injector::inst()->load([
            Security::class => [
                'properties' => [
                    'authenticators' => [
                        'default' => '%$' . MemberAuthenticator::class,
                    ]
                ]
            ]
        ]);

        /** @var SudoModeServiceInterface&MockObject $sudoModeService */
        $sudoModeService = $this->createMock(SudoModeServiceInterface::class);
        $sudoModeService->expects($this->any())->method('check')->willReturn(true);
        Injector::inst()->registerService($sudoModeService, SudoModeServiceInterface::class);
    }

    /**
     * Tests that the registration flow can't be started without being logged in (or past basic auth)
     */
    public function testRegisterRouteIsPrivateWithGETMethod()
    {
        $response = $this->get(self::URL);
        $this->assertEquals(403, $response->getStatusCode());
    }

    /**
     * Tests that the registration flow can't be finished without being logged in (or past basic auth)
     */
    public function testRegisterRouteIsPrivateWithPOSTMethod()
    {
        // See https://github.com/silverstripe/silverstripe-framework/pull/8987 for why we have to provide $data.
        $response = $this->post(self::URL, ['dummy' => 'data']);
        $this->assertEquals(403, $response->getStatusCode());
    }

    /**
     * Tests that a member can't register a new method during login if they've already registered one before
     */
    public function testStartRegistrationFailsWhenInvalidMethodIsPassed()
    {
        /** @var Member $freshMember */
        $freshMember = $this->objFromFixture(Member::class, 'fresh-member');

        $this->scaffoldPartialLogin($freshMember);

        $response = $this->get('Security/login/default/mfa/register/inert/');
        $this->assertEquals(400, $response->getStatusCode());
        $this->assertStringContainsString('No such method is available', $response->getBody());
    }

    /**
     * Tests that a member can't register a new method during login if they've already registered one before
     */
    public function testStartRegistrationFailsWhenRegisteredMethodExists()
    {
        /** @var Member $staleMember */
        $staleMember = $this->objFromFixture(Member::class, 'stale-member');

        $this->scaffoldPartialLogin($staleMember);

        $response = $this->get(self::URL);
        $this->assertEquals(400, $response->getStatusCode());
        $this->assertStringContainsString('This member already has an MFA method', $response->getBody());
    }

    /**
     * Tests that a member can't register the same method twice
     */
    public function testStartRegistrationFailsWhenMethodIsAlreadyRegistered()
    {
        $this->logInAs('stale-member');

        $response = $this->get(self::URL);
        $this->assertEquals(400, $response->getStatusCode());
        $this->assertStringContainsString(
            'That method has already been registered against this Member',
            $response->getBody()
        );
    }

    /**
     * Assuming the member passed the above checks, tests that the member can get context for registering a method
     */
    public function testStartRegistrationSucceeds()
    {
        /** @var Member $freshMember */
        $freshMember = $this->objFromFixture(Member::class, 'fresh-member');

        $this->scaffoldPartialLogin($freshMember);

        $response = $this->get(self::URL);
        $this->assertEquals(200, $response->getStatusCode(), sprintf('Body: %s', $response->getBody()));
    }

    public function testStartRegistrationProvidesACSRFToken()
    {
        SecurityToken::enable();

        /** @var Member $freshMember */
        $freshMember = $this->objFromFixture(Member::class, 'fresh-member');

        $this->scaffoldPartialLogin($freshMember);

        $response = $this->get(self::URL);
        $this->assertEquals(200, $response->getStatusCode(), sprintf('Body: %s', $response->getBody()));
        $this->assertSame(SecurityToken::inst()->getValue(), json_decode($response->getBody())->SecurityID);
    }

    /**
     * Tests that the start registration step must be called before the completion step
     */
    public function testFinishRegistrationFailsWhenCalledDirectly()
    {
        /** @var Member $freshMember */
        $freshMember = $this->objFromFixture(Member::class, 'fresh-member');

        $this->scaffoldPartialLogin($freshMember);

        $response = $this->post(self::URL, ['dummy' => 'data'], null, $this->session(), json_encode(['number' => 7]));
        $this->assertEquals(400, $response->getStatusCode());
        $this->assertStringContainsString('No registration in progress', $response->getBody());
    }

    /**
     * Tests that a nefarious user can't change the method they're registering halfway through
     */
    public function testFinishRegistrationFailsWhenMethodIsMismatched()
    {
        /** @var Member $freshMember */
        $freshMember = $this->objFromFixture(Member::class, 'fresh-member');

        $this->scaffoldPartialLogin($freshMember, self::class); // Purposefully set to the wrong class

        $response = $this->post(self::URL, ['dummy' => 'data'], null, $this->session(), json_encode(['number' => 7]));
        $this->assertEquals(400, $response->getStatusCode());
        $this->assertStringContainsString('Method does not match registration in progress', $response->getBody());
    }

    public function testFinishRegistrationFailsWhenMethodCannotBeRegistered()
    {
        $registerHandlerMock = $this->createMock(RegisterHandlerInterface::class);
        $registerHandlerMock
            ->expects($this->once())
            ->method('register')
            ->willReturn(Result::create(false, 'No. Bad user'));

        $methodMock = $this->createMock(MethodInterface::class);
        $methodMock
            ->expects($this->once())
            ->method('getRegisterHandler')
            ->willReturn($registerHandlerMock);
        $methodMock
            ->expects($this->once())
            ->method('getURLSegment')
            ->willReturn('mock-method');

        $methodRegistryMock = $this->createMock(MethodRegistry::class);
        $methodRegistryMock
            ->expects($this->once())
            ->method('getMethodByURLSegment')
            ->willReturn($methodMock);

        Injector::inst()->registerService($methodRegistryMock, MethodRegistry::class);

        /** @var Member $freshMember */
        $freshMember = $this->objFromFixture(Member::class, 'fresh-member');

        $this->scaffoldPartialLogin($freshMember, 'mock-method');

        $response = $this->post(self::URL, ['dummy' => 'data'], null, $this->session(), json_encode(['number' => 7]));
        $this->assertEquals(400, $response->getStatusCode());
        $this->assertStringContainsString('No. Bad user', $response->getBody());
    }

    /**
     * Assuming the member passed the above checks, tests that the member can complete a registration attempt
     */
    public function testFinishRegistrationSucceeds()
    {
        /** @var Member&MemberExtension $freshMember */
        $freshMember = $this->objFromFixture(Member::class, 'fresh-member');

        $this->scaffoldPartialLogin($freshMember, 'basic-math');

        $response = $this->post(self::URL, ['dummy' => 'data'], null, $this->session(), json_encode(['number' => 7]));
        $this->assertEquals(201, $response->getStatusCode());

        // Make sure the registration made it into the database
        $registeredMethod = $freshMember->RegisteredMFAMethods()->first();
        $this->assertNotNull($registeredMethod);
        $this->assertEquals('{"number":7}', $registeredMethod->Data);
    }

    public function testFinishRegistrationValidatesCSRF()
    {
        SecurityToken::enable();

        /** @var Member $freshMember */
        $freshMember = $this->objFromFixture(Member::class, 'fresh-member');

        $this->scaffoldPartialLogin($freshMember);

        $response = $this->post(self::URL, ['dummy' => 'data'], null, $this->session(), json_encode(['number' => 7]));
        $this->assertEquals(403, $response->getStatusCode());
        $this->assertStringContainsString('Your request timed out', $response->getBody());

        $this->scaffoldPartialLogin($freshMember, 'basic-math');

        $response = $this->post(
            self::URL,
            [SecurityToken::inst()->getName() => SecurityToken::inst()->getValue()],
            null,
            $this->session(),
            json_encode(['number' => 7])
        );
        $this->assertEquals(201, $response->getStatusCode(), sprintf('Body: %s', $response->getBody()));
    }

    public function testEnforcesSudoMode()
    {
        $sudoModeService = $this->createMock(SudoModeServiceInterface::class);
        $sudoModeService->expects($this->any())->method('check')->willReturn(false);
        Injector::inst()->registerService($sudoModeService, SudoModeServiceInterface::class);

        /** @var Member&MemberExtension $freshMember */
        $freshMember = $this->objFromFixture(Member::class, 'fresh-member');
        $this->scaffoldPartialLogin($freshMember, 'basic-math');

        $response = $this->post(self::URL, ['dummy' => 'data'], null, $this->session(), json_encode(['number' => 7]));
        $this->assertSame(403, $response->getStatusCode());
        $this->assertStringContainsString('You must be logged in or logging in', (string) $response->getBody());
    }

    /**
     * Mark the given user as partially logged in - ie. they've entered their email/password and are currently going
     * through the MFA process
     *
     * @param Member $member
     * @param string $method
     */
    protected function scaffoldPartialLogin(Member $member, $method = null)
    {
        $this->logOut();

        $store = new SessionStore($member);
        if ($method) {
            $store->setMethod($method);
        }

        $this->session()->set(SessionStore::SESSION_KEY, $store);
    }
}
