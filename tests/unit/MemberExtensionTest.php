<?php

namespace Firesphere\BootstrapMFA\Tests;

use Firesphere\BootstrapMFA\Extensions\MemberExtension;
use Firesphere\BootstrapMFA\Models\BackupCode;
use Firesphere\BootstrapMFA\Tests\Helpers\CodeHelper;
use SilverStripe\Control\Controller;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\Debug;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\Tab;
use SilverStripe\Forms\TabSet;
use SilverStripe\Security\Member;
use SilverStripe\Security\Security;

class MemberExtensionTest extends SapphireTest
{
    protected static $fixture_file = '../fixtures/member.yml';

    public function testMemberCodesExpired()
    {
        /** @var Member $member */
        $member = $this->objFromFixture(Member::class, 'member1');

        $member->updateMFA = true;
        $member->write();

        /** @var DataList|BackupCode $codes */
        $codes = $member->BackupCodes();

        $member->updateMFA = true;
        $member->write();

        foreach ($codes as $code) {
            /** @var BackupCode $backup */
            $backup = BackupCode::get()->byID($code->ID);
            $this->assertNull($backup);
        }
    }

    public function testMemberCodesNotExpired()
    {
        /** @var Member $member */
        $member = $this->objFromFixture(Member::class, 'member1');

        $member->updateMFA = true;
        $member->write();

        /** @var DataList|BackupCode $codes */
        $codes = $member->BackupCodes();

        $member->write();

        foreach ($codes as $code) {
            /** @var BackupCode $backup */
            $backup = BackupCode::get()->byID($code->ID);
            $this->assertNotNull($backup);
        }
    }

    public function testUpdateCMSFields()
    {
        $fields = FieldList::create([TabSet::create('Root')]);

        /** @var MemberExtension $extension */
        $extension = Injector::inst()->get(MemberExtension::class);

        // Something something in session
        Controller::curr()->getRequest()->getSession()->set('tokens', '123456');
        $extension->updateCMSFields($fields);

        $this->assertNull(Controller::curr()->getRequest()->getSession()->get('tokens'));
    }

    public function testUpdateCMSFieldsNoTokens()
    {
        $fields = FieldList::create([TabSet::create('Root')]);

        $extension = Injector::inst()->get(MemberExtension::class);

        $extension->updateCMSFields($fields);

        $this->assertFalse($fields->hasField('BackupTokens'));
    }

    public function testOnAfterWrite()
    {
        /** @var MemberExtension $extension */
        $extension = Injector::inst()->get(MemberExtension::class);
        /** @var Member $member */
        $member = $this->objFromFixture(Member::class, 'member1');
        $member->updateMFA = true;

        Security::setCurrentUser($member);
        $extension->setOwner($member);

        $extension->onAfterWrite();

        $session = Controller::curr()->getRequest()->getSession();

        $this->assertEquals(15, count(CodeHelper::getCodesFromSession()));
    }
}
